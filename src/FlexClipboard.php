<?php namespace ProcessWire;

/** Portable row transport for the editor. Clipboard leaves never enter raw hydration. @internal */
final class FlexClipboard extends Wire {
	private const FORMAT = 'fieldtype-flex-row';
	private const BATCH_FORMAT = 'fieldtype-flex-rows';
	private const VERSION = 1;
	private const MAX_BYTES = 25165824;
	private const MAX_INLINE_BYTES = 16777216;

	private array $warnings = [];
	private array $skippedFiles = [];
	private array $assets = [];
	private string $source = '';
	private int $sourcePage = 0;
	private bool $sameSite = false;
	private int $remainingBytes = self::MAX_INLINE_BYTES;
	private int $inlineRemaining = 0;

	/** @param list<FlexRow> $rows */
	public function export(array $rows): string {
		$this->inlineRemaining = $this->inlineBudget();
		$source = $rows[0];
		$batch = count($rows) > 1;
		$data = [
			'format' => $batch ? self::BATCH_FORMAT : self::FORMAT,
			'version' => self::VERSION,
			'site' => $this->site(),
			'source' => $source->ownerPage()->httpUrl(),
			'page' => (int) $source->ownerPage()->id,
		];
		if($batch) $data['rows'] = array_map(fn(FlexRow $row) => $this->exportRow($row), $rows);
		else $data['row'] = $this->exportRow($source);
		$text = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, FlexPath::MAX_JSON_DEPTH);
		unset($data);
		if(strlen($text) > self::MAX_BYTES) throw new WireException($this->_('The row is too large for the clipboard.'));
		return $text;
	}

	/** Import in clipboard order. The editor registers the entire batch or abandons it. @return list<FlexRow> */
	public function paste(FlexRows $rows, array $definition, mixed $text, string $token, int $existing = 0): array {
		if(!is_string($text) || strlen($text) > self::MAX_BYTES) throw $this->invalid();
		if(strlen($text) > $this->pasteMemoryLimit()) {
			throw new WireException($this->_('The clipboard content exceeds the available server memory.'));
		}
		try {
			$data = json_decode($text, true, FlexPath::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch(\JsonException) {
			throw $this->invalid();
		}
		if(($data['version'] ?? null) !== self::VERSION || !$this->httpUrl($data['source'] ?? null)) throw $this->invalid();
		$entries = match($data['format'] ?? null) {
			self::FORMAT => [$data['row'] ?? null],
			self::BATCH_FORMAT => $data['rows'] ?? null,
			default => null,
		};
		if(!is_array($entries) || !$entries || !array_is_list($entries) || count($entries) > 2000) throw $this->invalid();
		$max = (int) ($definition['settings']['maxItems'] ?? 0);
		if($max && $existing + count($entries) > $max) throw new WireException(__('Maximum repeatable item count reached'));
		foreach($entries as $entry) {
			if(!$this->validRow($entry)) throw $this->invalid();
			if(!isset($definition['types'][$entry['type']])) {
				throw new WireException(sprintf($this->_('Row type %s is unavailable in this collection.'), $entry['type']));
			}
		}
		$this->source = $data['source'];
		$site = $this->site();
		$this->sameSite = $site !== '' && is_string($data['site'] ?? null) && hash_equals($site, $data['site']);
		$this->sourcePage = is_int($data['page'] ?? null) ? $data['page'] : 0;
		$imported = [];
		foreach($entries as $index => $entry) {
			$row = $rows->addRow($entry['type']);
			$this->importRow($row, $entry, $token, count($entries) > 1 ? ($index + 1) . '. ' : '');
			$imported[] = $row;
		}
		return $imported;
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
					$item = [
						'name' => $file->basename(), 'url' => $file->httpUrl(),
						'descriptions' => $descriptions, 'tags' => (string) $file->tags,
						'focus' => $file instanceof Pageimage ? $file->filedata('focus') : null,
						'values' => (object) $custom,
					];
					$inline = $this->inlineFileData($file->filename());
					if($inline !== null) $item['data'] = $inline;
					$items[] = $item;
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
				if(!$language || !$language->id || !is_string($translation) || str_contains($translation, "\0")) {
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
			'integer' => $value === '' || is_int($value),
			'float' => $value === '' || ((is_float($value) || is_int($value)) && is_finite((float) $value)),
			'date' => $value === '' || $this->validDate($value),
			default => is_string($value) && !str_contains($value, "\0"),
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
		$extensions = preg_split(
			'/[\s,]+/',
			strtolower((string) ($settings['extensions'] ?? ($files instanceof Pageimages ? 'jpg jpeg gif png' : 'pdf doc docx xls xlsx gif jpg jpeg png'))),
			-1,
			PREG_SPLIT_NO_EMPTY
		);
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$bad = preg_split('/\s+/', strtolower((string) $this->wire()->config->uploadBadExtensions));
		$upload = $this->wire(new WireUpload('flex_clipboard'));
		$fieldMax = (int) ($settings['maxFilesize'] ?? 0);
		if(!$url || $name === '' || $name !== basename($name) || str_contains($name, "\\")
			|| $name !== rawurldecode(basename($url['path'] ?? ''))
			|| isset($url['query']) || $definition['input']->noUpload || !in_array($extension, $extensions, true)
			|| in_array($extension, $bad, true) || str_starts_with($extension, 'php') || !$upload->validateFilename($name, $extensions)) {
			$this->skippedFiles[] = $label;
			return;
		}
		$tempDir = null;
		$staged = null;
		try {
			$files->checkLimit(true);
			$filename = $upload->validateFilename($name, $extensions);
			$local = $this->sameSite ? $this->localFile($files->field->type, $name, $data['url']) : null;
			if($local) {
				// Stage the copy first so FileValidator modules never mutate the live source original.
				$size = filesize($local);
				if(!$size || ($fieldMax && $size > $fieldMax)) throw $this->invalid();
				if($files instanceof Pageimages && !@getimagesize($local)) throw $this->invalid();
				$files->draft($token);
				$staged = $files->importClipboardFile($local, $filename);
				$stagedPath = $staged->filename();
				if($this->wire()->sanitizer->validateFile($stagedPath, ['page' => $files->page, 'field' => $files->field]) === false) {
					throw $this->invalid();
				}
				// Re-verify size and image integrity on the final bytes after potentially sanitizing validators.
				clearstatcache(true, $stagedPath);
				$finalSize = @filesize($stagedPath);
				if(!$finalSize || ($fieldMax && $finalSize > $fieldMax)) throw $this->invalid();
				if($files instanceof Pageimages && !@getimagesize($stagedPath)) throw $this->invalid();
				$file = $staged;
				$staged = null;
			} else {
				$encoded = $data['data'] ?? null;
				$maxBytes = min($fieldMax ?: self::MAX_INLINE_BYTES, self::MAX_INLINE_BYTES, $this->remainingBytes, $this->pasteMemoryLimit());
				if(!is_string($encoded) || $encoded === '' || $maxBytes <= 0 || strlen($encoded) > (int) ceil($maxBytes * 4 / 3) + 4) {
					throw $this->invalid();
				}
				$decoded = base64_decode($encoded, true);
				$size = is_string($decoded) ? strlen($decoded) : 0;
				$tempDir = $this->wire()->files->tempDir('FlexClipboard-' . bin2hex(random_bytes(8)));
				$temp = $tempDir->get() . $filename;
				if($decoded === false || $size === 0 || $size > $maxBytes || @file_put_contents($temp, $decoded, LOCK_EX) !== $size) {
					throw $this->invalid();
				}
				unset($decoded);
				if($files instanceof Pageimages && !@getimagesize($temp)) throw $this->invalid();
				if($this->wire()->sanitizer->validateFile($temp, ['page' => $files->page, 'field' => $files->field]) === false) throw $this->invalid();
				clearstatcache(true, $temp);
				$finalSize = @filesize($temp);
				if(!$finalSize || $finalSize > $maxBytes || ($fieldMax && $finalSize > $fieldMax)) throw $this->invalid();
				if($files instanceof Pageimages && !@getimagesize($temp)) throw $this->invalid();
				$this->remainingBytes -= max($size, $finalSize);
				$files->draft($token);
				$file = $files->importClipboardFile($temp, $filename);
			}
			$this->assets[] = $file->assetId();
		} catch(WireException) {
			$this->skippedFiles[] = $label;
			return;
		} finally {
			// Always clean up an unaccepted staged copy, even if a third-party validator throws a non-WireException.
			if($staged !== null) {
				$files->field->type->media()->abandon($staged->assetId(), $files->page, $files->field, $token);
				if($files->has($staged)) $files->remove($staged);
			}
			if($tempDir !== null) $tempDir->remove();
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

	/** Parse a PHP shorthand byte limit ini setting; returns 0 when unset or unlimited. */
	public static function iniBytes(string $setting): int {
		$raw = trim((string) ini_get($setting));
		if($raw === '' || str_starts_with($raw, '-')) return 0;
		if(!preg_match('/^(\d+)\s*([kmgt])?b?$/i', $raw, $match)) return 0;
		return (int) $match[1] * match(strtolower($match[2] ?? '')) {
			'k' => 1024,
			'm' => 1048576,
			'g' => 1073741824,
			't' => 1099511627776,
			default => 1,
		};
	}

	/** Bound inline Base64 payloads by the module cap, post_max_size and available PHP memory. */
	private function inlineBudget(): int {
		$budget = self::MAX_INLINE_BYTES;
		$postMax = self::iniBytes('post_max_size');
		if($postMax > 0) $budget = min($budget, (int) floor($postMax * 0.6));
		$memLimit = self::iniBytes('memory_limit');
		if($memLimit > 0) {
			// Base64 + JSON + AJAX response envelope temporarily hold ~5x raw bytes; keep 8 MiB headroom.
			$headroom = $memLimit - memory_get_usage(true) - 8388608;
			$budget = min($budget, max(0, (int) floor($headroom / 5)));
		}
		return max(0, $budget);
	}

	/** Bound target JSON/base64 decoding against the receiving server's memory_limit. */
	private function pasteMemoryLimit(): int {
		$memLimit = self::iniBytes('memory_limit');
		if($memLimit <= 0) return self::MAX_BYTES;
		// JSON decoding + base64 expansion temporarily hold ~4x input bytes; keep 8 MiB headroom.
		$headroom = $memLimit - memory_get_usage(true) - 8388608;
		return min(self::MAX_BYTES, max(0, (int) floor($headroom / 4)));
	}

	private function inlineFileData(string $path): ?string {
		if($this->inlineRemaining <= 0 || is_link($path) || !is_file($path) || !is_readable($path)) return null;
		$size = filesize($path);
		if(!$size || $size > $this->inlineRemaining) return null;
		$bytes = @file_get_contents($path);
		if(!is_string($bytes) || strlen($bytes) !== $size) return null;
		$encoded = base64_encode($bytes);
		unset($bytes);
		$this->inlineRemaining -= $size;
		return $encoded;
	}
}
