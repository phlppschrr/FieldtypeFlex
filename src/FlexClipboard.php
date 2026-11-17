<?php namespace ProcessWire;

/** Portable row transport for the editor. Clipboard leaves never enter raw hydration. @internal */
final class FlexClipboard extends Wire {
	private const FORMAT = 'fieldtype-flex-row';
	private const VERSION = 1;
	private const MAX_BYTES = 8388608;
	private const MAX_MEDIA_BYTES = 20971520;
	private const REMOTE_FILE_TYPES = [
		'image/jpeg', 'image/gif', 'image/png', 'image/webp', 'image/avif', 'image/bmp', 'image/tiff',
		'audio/mpeg', 'audio/wav', 'video/mp4', 'video/quicktime', 'video/webm',
		'text/plain', 'text/csv', 'application/pdf', 'application/zip', 'application/rtf',
		'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	];

	private array $warnings = [];
	private array $skippedFiles = [];
	private array $assets = [];
	private string $source = '';
	private int $sourcePage = 0;
	private bool $sameSite = false;
	private float $deadline = 0;
	private int $remainingBytes = self::MAX_MEDIA_BYTES;
	private int $downloads = 0;

	public function export(FlexRow $row): string {
		$text = json_encode([
			'format' => self::FORMAT,
			'version' => self::VERSION,
			'site' => $this->site(),
			'source' => $row->ownerPage()->httpUrl(),
			'page' => (int) $row->ownerPage()->id,
			'row' => $this->exportRow($row),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, FlexPath::MAX_JSON_DEPTH);
		if(strlen($text) > self::MAX_BYTES) throw new WireException($this->_('The row is too large for the clipboard.'));
		return $text;
	}

	public function paste(FlexRows $rows, array $definition, mixed $text, string $token): FlexRow {
		if(!is_string($text) || strlen($text) > self::MAX_BYTES) throw $this->invalid();
		try {
			$data = json_decode($text, true, FlexPath::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch(\JsonException) {
			throw $this->invalid();
		}
		if(($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION
			|| !$this->validRow($data['row'] ?? null) || !$this->httpUrl($data['source'] ?? null)) {
			throw $this->invalid();
		}
		$this->source = $data['source'];
		$site = $this->site();
		$this->sameSite = $site !== '' && is_string($data['site'] ?? null) && hash_equals($site, $data['site']);
		$this->sourcePage = is_int($data['page'] ?? null) ? $data['page'] : 0;
		$this->deadline = microtime(true) + 8;
		if(!isset($definition['types'][$data['row']['type']])) {
			throw new WireException(sprintf($this->_('Row type %s is unavailable in this collection.'), $data['row']['type']));
		}
		$row = $rows->addRow($data['row']['type']);
		$this->importRow($row, $data['row'], $token, '');
		return $row;
	}

	public function warningSummary(): string {
		$warnings = array_values(array_unique(array_merge($this->skippedFiles, $this->warnings)));
		if(!$warnings) return '';
		$paths = implode(', ', array_slice($warnings, 0, 8));
		if(count($warnings) > 8) $paths .= ', …';
		return sprintf($this->_('Skipped or adjusted %1$d values or files: %2$s'), count($warnings), $paths);
	}

	public function abandon(Page $page, Field $field, string $token): void {
		foreach($this->assets as $id) $field->type->media()->abandon($id, $page, $field, $token);
	}

	private function site(): string {
		$config = $this->wire()->config;
		return $config->userAuthSalt ? hash_hmac('sha256', 'FieldtypeFlex row clipboard ' . $config->urls->httpRoot, $config->userAuthSalt) : '';
	}

	private function invalid(): WireException {
		return new WireException($this->_('The clipboard does not contain a supported FieldtypeFlex row.'));
	}

	private function validRow(mixed $row): bool {
		return is_array($row) && is_string($row['type'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $row['type'])
			&& is_bool($row['hidden'] ?? null) && $this->propertyMap($row['values'] ?? null);
	}

	private function propertyMap(mixed $value): bool {
		return is_array($value) && (!$value || !array_is_list($value));
	}

	private function kind(array $definition): string {
		$input = $definition['input'];
		$kind = match(true) {
			$input instanceof InputfieldToggle => 'toggle',
			$input instanceof InputfieldTinyMCE || (int) $input->contentType >= FieldtypeTextarea::contentTypeHTML => 'html',
			default => $definition['kind'],
		};
		return $kind . ($definition['languages'] ? ':languages' : '');
	}

	private function exportRow(FlexRow $row): array {
		return ['type' => $row->rowType(), 'hidden' => $row->isHidden(), 'values' => (object) $this->exportValues($row)];
	}

	private function exportValues(FlexValue $owner): array {
		$result = [];
		$raw = $owner->rawArray();
		foreach($owner->definitions()->properties() as $name => $definition) {
			$value = $owner->getUnformatted($name);
			$references = [];
			if($definition['kind'] === 'page') {
				$pages = $value instanceof PageArray ? $value : [$value];
				foreach($pages as $page) {
					if(!$page instanceof Page || !$page->id || !$page->template || !$page->viewable(false)) continue;
					$references[$page->id] = [
						'path' => $page->path(),
						'parent' => $page->parent_id ? $page->parent()->path() : '',
						'template' => $page->template->name,
					];
				}
				$value = $raw[$name];
			} else if($value instanceof FlexValue) {
				$value = (object) $this->exportValues($value);
			} else if($value instanceof FlexRows) {
				$items = [];
				foreach($value as $row) $items[] = $this->exportRow($row);
				$value = $items;
			} else if($value instanceof Pagefiles) {
				$items = [];
				foreach($value as $file) {
					$descriptions = $file->description(true, true);
					$custom = $this->exportValues($file->metadataInputValue());
					unset($custom['description'], $custom['tags']);
					$items[] = [
						'name' => $file->basename(), 'url' => $file->httpUrl(),
						'descriptions' => $descriptions, 'tags' => (string) $file->tags,
						'focus' => $file instanceof Pageimage ? $file->filedata('focus') : null,
						'values' => (object) $custom,
					];
				}
				$value = $items;
			} else if($value instanceof LanguagesPageFieldValue) {
				$translations = [];
				foreach($this->wire()->languages as $language) {
					$translations[$language->name] = (string) $value->getLanguageValue($language);
				}
				$value = (object) $translations;
			} else if($definition['kind'] === 'checkbox') {
				$value = $owner->hasValue($name);
			} else {
				$value = $raw[$name];
			}
			$result[$name] = ['kind' => $this->kind($definition), 'value' => $value];
			if($references) $result[$name]['references'] = (object) $references;
		}
		return $result;
	}

	private function importRow(FlexRow $row, array $data, string $token, string $path): void {
		$row->setHidden($data['hidden']);
		$this->importValues($row, $data['values'], $token, $path);
	}

	private function importValues(FlexValue $owner, array $values, string $token, string $prefix): void {
		$definitions = $owner->definitions()->properties();
		$admin = $owner->ownerField()->type->admin();
		foreach($values as $name => $entry) {
			$path = $prefix . $name;
			$definition = $definitions[$name] ?? null;
			if(!$definition || !is_array($entry) || !array_key_exists('value', $entry)
				|| ($entry['kind'] ?? null) !== $this->kind($definition)) {
				$this->warnings[] = $path;
				continue;
			}
			try {
				$admin->editableInput($definition['input']);
			} catch(WirePermissionException) {
				$this->warnings[] = $path;
				continue;
			}
			$value = $entry['value'];
			switch($definition['kind']) {
				case 'object':
					if(!$this->propertyMap($value)) $this->warnings[] = $path;
					else $this->importValues($owner->getUnformatted($name), $value, $token, $path . '.');
					break;
				case 'rows':
					if(!is_array($value) || !array_is_list($value)) {
						$this->warnings[] = $path;
						break;
					}
					$rows = $owner->getUnformatted($name);
					$max = (int) ($definition['settings']['maxItems'] ?? 0);
					foreach($value as $index => $child) {
						$childPath = $path . '[' . ($index + 1) . ']';
						if(!$this->validRow($child) || !isset($definition['types'][$child['type']]) || ($max && count($rows) >= $max)) {
							$this->warnings[] = $childPath;
							continue;
						}
						$this->importRow($rows->addRow($child['type']), $child, $token, $childPath . '.');
					}
					break;
				case 'file':
				case 'image':
					if(!is_array($value) || !array_is_list($value)) $this->warnings[] = $path;
					else foreach($value as $file) $this->importFile($owner->getUnformatted($name), $file, $token, $path);
					break;
				case 'page':
					$references = is_array($entry['references'] ?? null) ? $entry['references'] : [];
					$this->importPages($owner, $name, $definition, $value, $references, $path);
					break;
				default:
					$this->importLeaf($owner, $name, $definition, $value, $path);
			}
		}
	}

	private function importLeaf(FlexValue $owner, string $name, array $definition, mixed $value, string $path): void {
		$kind = $this->kind($definition);
		$input = $definition['input'];
		if($definition['languages']) {
			if(!$this->propertyMap($value)) {
				$this->warnings[] = $path;
				return;
			}
			$current = $owner->getUnformatted($name);
			$languages = $this->wire()->languages;
			foreach($value as $code => $translation) {
				$language = is_string($code) && preg_match('/^[a-z0-9_-]+$/D', $code) ? $languages->get('name=' . $code) : null;
				$languagePath = $path . '.' . $code;
				if(!$language || !$language->id || !is_string($translation) || !$this->validText($input, $translation)) {
					if($translation !== '') $this->warnings[] = $languagePath;
					continue;
				}
				$current->setLanguageValue($language, $kind === 'html:languages' ? $this->safeHtml($translation, $languagePath) : $translation);
			}
			return;
		}
		$options = in_array($kind, ['select', 'array', 'toggle'], true) ? $owner->getOptions($name) : [];
		$valid = match($kind) {
			'checkbox' => is_bool($value),
			'select', 'toggle' => $value === '' || ((is_string($value) || is_int($value)) && array_key_exists($value, $options)),
			'array' => is_array($value) && array_is_list($value),
			'integer' => $value === '' || (is_int($value) && $this->inRange($input, $value)),
			'float' => $value === '' || ((is_float($value) || is_int($value)) && is_finite((float) $value) && $this->inRange($input, $value)),
			'date' => $value === '' || $this->validDate($value),
			default => is_string($value) && $this->validText($input, $value),
		};
		if(!$valid) {
			$this->warnings[] = $path;
			return;
		}
		if($kind === 'array') {
			$allowed = array_values(array_filter($value, static fn($key) => (is_string($key) || is_int($key)) && array_key_exists($key, $options)));
			if(count($allowed) !== count($value)) $this->warnings[] = $path;
			if($value && !$allowed) return;
			$value = array_unique($allowed);
		}
		if($kind === 'tags') {
			$tags = $input->tagStringToArray($value);
			if($input->allowUserTags) {
				$sanitizer = $this->wire()->sanitizer;
				$allowed = array_filter(array_map(static fn($tag) => $sanitizer->text($tag), $tags), 'strlen');
			} else {
				$allowed = array_intersect_key($tags, $input->getTagsList());
			}
			if($input->maxItems) $allowed = array_slice($allowed, 0, (int) $input->maxItems);
			if($allowed !== $tags) $this->warnings[] = $path;
			if($tags && !$allowed) return;
			$value = $input->tagArrayToString($allowed);
		}
		if($kind === 'html') $value = $this->safeHtml($value, $path);
		if($kind === 'text' && $input->stripTags && strip_tags($value) !== $value) {
			$value = strip_tags($value);
			$this->warnings[] = $path;
		}
		if($kind === 'checkbox') $value = $value ? $input->checkedValue : ($input->uncheckedValue === '' ? 0 : $input->uncheckedValue);
		try {
			$previous = $owner->getUnformatted($name);
			$owner->set($name, $value);
			if(($input instanceof InputfieldEmail || $input instanceof InputfieldURL) && $value !== '' && $owner->getUnformatted($name) === '') {
				$owner->set($name, $previous);
				$this->warnings[] = $path;
			}
		} catch(WireException) {
			$this->warnings[] = $path;
		}
	}

	private function validText(Inputfield $input, string $value): bool {
		if(str_contains($value, "\0")) return false;
		if($value === '') return true;
		$length = mb_strlen($value);
		if($length < (int) $input->minlength || ($input->maxlength && $length > (int) $input->maxlength)) return false;
		$pattern = $input->pattern;
		return !$pattern || @preg_match('#' . str_replace('#', '\\#', $pattern) . '#', $value) === 1;
	}

	private function inRange(Inputfield $input, int|float $value): bool {
		return (!is_numeric($input->min) || $value >= $input->min) && (!is_numeric($input->max) || $value <= $input->max);
	}

	private function validDate(mixed $value): bool {
		if(!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d)?$/D', $value, $parts)) return false;
		return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}

	private function importPages(FlexValue $owner, string $name, array $definition, mixed $value, array $references, string $path): void {
		$ids = $definition['multiple'] ? $value : [$value];
		if(!is_array($ids) || !array_is_list($ids) || (!$definition['multiple'] && !is_int($value) && $value !== false)) {
			$this->warnings[] = $path;
			return;
		}
		$pages = $this->wire()->pages;
		$items = $pages->newPageArray();
		$input = $definition['input'];
		$allowedTemplates = $input->getTemplateIDs();
		$hasReferences = false;
		foreach($ids as $id) {
			if($id === 0 || $id === false) continue;
			$hasReferences = true;
			$page = null;
			if(is_int($id) && $id > 0) {
				// Across installations, the source ID only indexes the portable descriptor.
				$page = $this->sameSite ? $pages->get($id) : $this->resolvePageReference($references[$id] ?? null);
			}
			if(!$page || !$page->id || !$page->viewable(false) || ($page->isUnpublished() && !$input->allowUnpub)
				|| ($allowedTemplates && !in_array((int) $page->template?->id, $allowedTemplates, true))
				|| $input->findPagesCode || !InputfieldPage::isValidPage($page, $input, $owner->ownerPage())) {
				$this->warnings[] = $path;
				continue;
			}
			$items->add($page);
		}
		if($hasReferences && !$items->count()) return;
		$owner->set($name, $definition['multiple'] ? $items : ($items->first() ?: 0));
	}

	private function resolvePageReference(mixed $reference): ?Page {
		if(!is_array($reference) || !is_string($reference['path'] ?? null) || strlen($reference['path']) > 2048
			|| !preg_match('~^/(?:[^/\s?#]+/)*$~uD', $reference['path'])
			|| !is_string($reference['parent'] ?? null) || !is_string($reference['template'] ?? null)) return null;
		$page = $this->wire()->pages->getByPath($reference['path'], [
			'allowPartial' => false,
			'allowUrl' => false,
			'allowUrlSegments' => false,
			'useHistory' => false,
			'useLanguages' => false,
		]);
		if(!$page->id || $page->path() !== $reference['path'] || $page->template?->name !== $reference['template']) return null;
		$parentPath = $page->parent_id ? $page->parent()->path() : '';
		return $parentPath === $reference['parent'] ? $page : null;
	}

	private function safeHtml(string $value, string $path): string {
		if($value === '') return '';
		$sanitizer = $this->wire()->sanitizer;
		$clean = $sanitizer->purify($value);
		if($clean !== $value) $this->warnings[] = $path;
		// Relative links belong to the original site and page.
		return $sanitizer->purify($clean, ['URI.Base' => $this->source, 'URI.MakeAbsolute' => true]);
	}

	private function httpUrl(mixed $url): ?array {
		if(!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\\\\\x00-\x20]/', $url)) return null;
		$parts = parse_url($url);
		return in_array($parts['scheme'] ?? '', ['http', 'https'], true) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']) ? $parts : null;
	}

	private function importFile(Pagefiles $files, mixed $data, string $token, string $path): void {
		$name = is_string($data['name'] ?? null) && strlen($data['name']) <= 255 ? $data['name'] : '';
		$label = $name === '' ? $path : "$path ($name)";
		$url = $this->httpUrl($data['url'] ?? null);
		$definition = $files->definition();
		$settings = $definition['settings'];
		$extensions = preg_split('/[\s,]+/', strtolower((string) ($settings['extensions'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$bad = preg_split('/\s+/', strtolower((string) $this->wire()->config->uploadBadExtensions));
		$upload = $this->wire(new WireUpload('flex_clipboard'));
		$limit = min((int) ($settings['maxFilesize'] ?? 0) ?: self::MAX_MEDIA_BYTES, self::MAX_MEDIA_BYTES);
		if(!$url || $name === '' || $name !== basename($name) || str_contains($name, "\\")
			|| $name !== rawurldecode(basename($url['path'] ?? ''))
			|| isset($url['query']) || $definition['input']->noUpload || !in_array($extension, $extensions, true)
			|| in_array($extension, $bad, true) || str_starts_with($extension, 'php') || !$upload->validateFilename($name, $extensions)) {
			$this->skippedFiles[] = $label;
			return;
		}
		$temp = null;
		try {
			$files->checkLimit(true);
			$filename = $upload->validateFilename($name, $extensions);
			$temp = $this->wire()->files->tempDir('FlexClipboard')->get() . $filename;
			$local = $this->sameSite ? $this->localFile($files->field->type, $name, $data['url']) : null;
			if($local) {
				$size = filesize($local);
				if(!$size || $size > $limit || $size > $this->remainingBytes || !@copy($local, $temp)) throw $this->invalid();
				$this->remainingBytes -= $size;
			} else {
				$expected = ltrim($this->wire()->config->fileContentTypes[$extension] ?? '', '+');
				if(!in_array($expected, self::REMOTE_FILE_TYPES, true) || !class_exists(\finfo::class)
					|| !$this->download($data['url'], $url, $temp, $limit)) {
					throw $this->invalid();
				}
				$actual = (new \finfo(FILEINFO_MIME_TYPE))->file($temp);
				if($actual !== $expected && !($extension === 'csv' && $actual === 'text/plain')) throw $this->invalid();
			}
			if($files instanceof Pageimages && !@getimagesize($temp)) throw $this->invalid();
			if($this->wire()->sanitizer->validateFile($temp, ['page' => $files->page, 'field' => $files->field]) === false) throw $this->invalid();
			$files->draft($token);
			$file = $files->importClipboardFile($temp, $filename);
			$this->assets[] = $file->assetId();
		} catch(WireException) {
			$this->skippedFiles[] = $label;
			return;
		} finally {
			if($temp !== null && is_file($temp)) unlink($temp);
		}
		$descriptions = [];
		$languages = $this->wire()->languages;
		foreach(is_array($data['descriptions'] ?? null) ? $data['descriptions'] : [] as $code => $description) {
			if(!is_string($description)) {
				$this->warnings[] = $label;
				continue;
			}
			if($code === 'default') $descriptions[0] = $description;
			else if($description !== '') {
				$language = $languages && is_string($code) && preg_match('/^[a-z0-9_-]+$/D', $code) ? $languages->get('name=' . $code) : null;
				if($language && $language->id && !$language->isDefault()) $descriptions[$language->id] = $description;
				else $this->warnings[] = $label . '.' . $code;
			}
		}
		$description = $files->metadataSchema()->property('description');
		foreach($descriptions as $id => $text) {
			if($description && str_starts_with($this->kind($description), 'html')) $descriptions[$id] = $this->safeHtml($text, $label);
			else $descriptions[$id] = $this->wire()->sanitizer->textarea($text);
		}
		if($descriptions) $file->description(true, $descriptions);
		if(is_string($data['tags'] ?? null)) $file->tags = $this->wire()->sanitizer->text($data['tags']);
		$focus = $data['focus'] ?? null;
		if($file instanceof Pageimage && is_array($focus) && isset($focus['top'], $focus['left'], $focus['zoom'])
			&& is_numeric($focus['top']) && is_numeric($focus['left']) && is_numeric($focus['zoom'])) {
			$file->focus((float) $focus['top'], (float) $focus['left'], (int) $focus['zoom']);
		}
		if($this->propertyMap($data['values'] ?? null)) {
			$metadata = $file->metadataInputValue();
			$values = array_diff_key($data['values'], ['description' => true, 'tags' => true]);
			$before = $metadata->rawArray();
			$this->importValues($metadata, $values, $token, $label . '.');
			foreach($metadata->rawArray() as $key => $value) {
				if($before[$key] !== $value) $file->setFieldValue($key, $metadata->getUnformatted($key));
			}
		}
	}

	private function localFile(FieldtypeFlex $type, string $name, string $url): ?string {
		if($this->sourcePage < 1) return null;
		$page = $this->wire()->pages->get($this->sourcePage);
		if(!$page->id || !$page->viewable(false)) return null;
		$record = $type->media()->assetByName($page, $name);
		if(!$record || $record['basename'] !== $name || !in_array($record['state'], ['committed', 'staged'], true)) return null;
		$field = $this->wire()->fields->get((int) $record['fields_id']);
		if(!$field || !$page->hasField($field) || !$page->editable($field->name)) return null;
		if($record['state'] === 'staged') {
			try {
				$type->admin()->draft($record['draft']);
			} catch(WirePermissionException) {
				return null;
			}
		}
		$manager = $page->filesManager();
		$origin = substr($page->httpUrl(), 0, -strlen($page->url()));
		if($url !== $origin . $manager->url() . $name) return null;
		$path = $manager->path() . $name;
		return !is_link($path) && is_file($path) && is_readable($path) ? $path : null;
	}

	private function download(string $url, array $parts, string $target, int $limit): bool {
		$host = strtolower($parts['host'] ?? '');
		if(($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443
			|| !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z][a-z0-9-]+$/D', $host)
			|| preg_match('/\.(?:internal|local|localhost|test|invalid|example|onion|home\.arpa)$/D', $host)
			|| !function_exists('curl_init') || !defined('FILTER_FLAG_GLOBAL_RANGE') || $this->downloads >= 6
			|| $this->remainingBytes <= 0 || microtime(true) >= $this->deadline) return false;
		$this->downloads++;
		$addresses = @gethostbynamel($host);
		if(!$addresses) return false;
		foreach($addresses as $ip) {
			if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) return false;
		}
		$time = min(3000, (int) (($this->deadline - microtime(true)) * 1000));
		if($time <= 0) return false;
		$request = curl_init($url);
		if(!$request) return false;
		$stream = @fopen($target, 'xb');
		if(!$stream) return false;
		$written = 0;
		try {
			$configured = curl_setopt_array($request, [
				CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
				CURLOPT_RESOLVE => [$host . ':443:' . $addresses[0]],
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_PROXY => '',
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CONNECTTIMEOUT_MS => $time,
				CURLOPT_TIMEOUT_MS => $time,
				CURLOPT_MAXFILESIZE => min($limit, $this->remainingBytes),
				CURLOPT_WRITEFUNCTION => function($handle, string $chunk) use ($stream, $limit, &$written): int {
					$length = strlen($chunk);
					if($written + $length > $limit || $length > $this->remainingBytes) return 0;
					$written += $length;
					$this->remainingBytes -= $length;
					return (int) fwrite($stream, $chunk);
				},
			]);
			return $configured && curl_exec($request) === true && curl_getinfo($request, CURLINFO_HTTP_CODE) === 200 && $written > 0;
		} finally {
			fclose($stream);
		}
	}
}
