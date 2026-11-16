<?php namespace ProcessWire;

/** Compile editor title templates once and evaluate their text-only parts. @internal */
final class FlexRowTitle {
	private const IDENTIFIER = '[a-zA-Z_][a-zA-Z0-9_]*';
	private const CLASS_NAME = '(?:-?[a-zA-Z_]|--)[a-zA-Z0-9_-]*';
	private const SPACE = '[\p{Z}\x{FEFF}\x09-\x0D]';
	private const SPECIAL = '{}[]|\'"\\';
	public const BUILT_INS = ['@type', '@index', '@path'];

	private int $offset = 0;

	private function __construct(private string $source) {}

	public static function compile(string $source): array {
		$source = self::trim(str_replace(["\r\n", "\r", "\n"], ' ', $source));
		static $compiled = [];
		if(!isset($compiled[$source])) {
			$parser = new self($source === '' ? '[{@type}]{.flex-badge} #{@index}' : $source);
			try {
				$compiled[$source] = $parser->sequence(false);
			} catch(\InvalidArgumentException) {
				$compiled[$source] = [['text' => $source]];
			}
		}
		return $compiled[$source];
	}

	/** @return \Generator<string> All reference alternatives in template order. */
	public static function references(array $nodes): \Generator {
		foreach($nodes as $node) {
			if(isset($node['children'])) yield from self::references($node['children']);
			foreach($node['choices'] ?? [] as $choice) {
				if(isset($choice['ref'])) yield $choice['ref'];
			}
		}
	}

	private function take(string $pattern): ?string {
		if(!preg_match('/\G(?:' . $pattern . ')/', $this->source, $match, 0, $this->offset)) return null;
		$this->offset += strlen($match[0]);
		return $match[0];
	}

	private function peek(): string {
		return $this->source[$this->offset] ?? '';
	}

	private function invalid(): never {
		throw new \InvalidArgumentException('Invalid row title template');
	}

	private function character(): string {
		$character = $this->peek();
		++$this->offset;
		if($character !== '\\') return $character;
		$character = $this->peek();
		if($character === '' || !str_contains(self::SPECIAL, $character)) $this->invalid();
		++$this->offset;
		return $character;
	}

	private function token(): array {
		$choices = [];
		do {
			++$this->offset; // Opening brace or fallback separator.
			$this->take('[ \t]*');
			$quote = $this->peek();
			if($quote === '"' || $quote === "'") {
				++$this->offset;
				$text = '';
				while($this->peek() !== $quote) {
					if($this->peek() === '') $this->invalid();
					$text .= $this->character();
				}
				++$this->offset;
				$choices[] = ['text' => $text];
			} else {
				$reference = $this->take('@' . self::IDENTIFIER . '|' . self::IDENTIFIER . '(?:\.' . self::IDENTIFIER . ')*');
				if($reference === null) $this->invalid();
				$choices[] = ['ref' => $reference];
			}
			$this->take('[ \t]*');
		} while($this->peek() === '|');
		if($this->peek() !== '}') $this->invalid();
		++$this->offset;
		return $choices;
	}

	private function sequence(bool $group): array {
		$nodes = [];
		$text = '';
		while(($next = $this->peek()) !== '') {
			if($next === ']' && $group) break;
			if($next === '}' || $next === ']' || ($next === '[' && $group) || $next === "\n" || $next === "\r") $this->invalid();
			if($next !== '[' && $next !== '{') {
				$text .= $this->character();
				continue;
			}
			if($text !== '') $nodes[] = ['text' => $text];
			$text = '';
			if($next === '{') {
				$node = ['choices' => $this->token()];
			} else {
				++$this->offset;
				$node = ['children' => $this->sequence(true)];
				if($this->peek() !== ']') $this->invalid();
				++$this->offset;
			}
			$attributes = $this->take('\{\.' . self::CLASS_NAME . '(?:[ \t]+\.' . self::CLASS_NAME . ')*\}');
			if($attributes !== null) $node['classes'] = preg_split('/[ \t]+\./', substr($attributes, 2, -1));
			$nodes[] = $node;
		}
		if($text !== '') $nodes[] = ['text' => $text];
		return $nodes;
	}

	/** Evaluate a compiled template. A missing token inside a group omits that whole group. */
	private static function evaluate(array $nodes, array $values, bool $group = false): array {
		$parts = [];
		$omittedGroup = false;
		foreach($nodes as $node) {
			$classes = isset($node['classes']) ? ['classes' => $node['classes']] : [];
			if(isset($node['children'])) {
				$children = self::evaluate($node['children'], $values, true);
				$omittedGroup = !$children;
				if($children) array_push($parts, ...($classes ? [['children' => $children] + $classes] : $children));
				continue;
			}
			$text = $node['text'] ?? '';
			if($omittedGroup && $text !== '' && preg_match('/' . self::SPACE . '$/uD', $parts[array_key_last($parts)]['text'] ?? '')) {
				$text = preg_replace('/^' . self::SPACE . '+/u', '', $text);
			}
			$position = [];
			foreach($node['choices'] ?? [] as $choice) {
				$candidate = (string) ($choice['text'] ?? $values[$choice['ref']] ?? '');
				if(self::trim($candidate) === '') continue;
				$text = $candidate;
				if(($choice['ref'] ?? '') === '@index') $position = ['index' => true];
				break;
			}
			if($text === '') {
				if($group && isset($node['choices'])) return [];
				continue;
			}
			$parts[] = ['text' => $text] + $classes + $position;
			$omittedGroup = false;
		}
		return $parts;
	}

	public static function parts(array $nodes, array $values): array {
		return self::finish(self::evaluate($nodes, $values), (int) $values['@index']);
	}

	/** Validate the public hook result before sending it to either renderer. */
	public static function override(array $parts, int $index): array {
		return self::trimEdge(self::trimEdge(self::safeParts($parts, $index), false), true);
	}

	private static function safeParts(array $parts, int $index, int $depth = 0): array {
		$safe = [];
		foreach($parts as $part) {
			if(!is_array($part)) continue;
			$classes = array_values(array_unique(array_filter((array) ($part['classes'] ?? []),
				static fn($class) => is_string($class) && preg_match('/^' . self::CLASS_NAME . '$/D', $class))));
			$attributes = $classes ? ['classes' => $classes] : [];
			if(is_scalar($part['text'] ?? null) && (string) $part['text'] !== '') {
				$text = (string) $part['text'];
				$position = ($part['index'] ?? false) === true && $text === (string) $index ? ['index' => true] : [];
				$safe[] = ['text' => $text] + $attributes + $position;
			} else if($depth < 8 && is_array($part['children'] ?? null)) {
				$children = self::safeParts($part['children'], $index, $depth + 1);
				if($children) $safe[] = ['children' => $children] + $attributes;
			}
		}
		return $safe;
	}

	private static function finish(array $parts, int $index): array {
		$parts = self::trimEdge($parts, false);
		$parts = self::trimEdge($parts, true);
		return $parts ?: [['text' => '#'], ['text' => (string) $index, 'index' => true]];
	}

	private static function trimEdge(array $parts, bool $end): array {
		if($end) $parts = array_reverse($parts);
		foreach($parts as $key => &$part) {
			if(isset($part['children'])) {
				$part['children'] = self::trimEdge($part['children'], $end);
				if($part['children']) break;
			} else {
				$part['text'] = preg_replace($end ? '/' . self::SPACE . '+$/uD' : '/^' . self::SPACE . '+/u', '', $part['text']);
				if($part['text'] !== '') break;
			}
			unset($parts[$key]);
		}
		unset($part);
		return $end ? array_reverse($parts) : array_values($parts);
	}

	public static function trim(string $text): string {
		return preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/uD', '', $text) ?? $text;
	}

	public static function display(string $text): string {
		$text = html_entity_decode(preg_replace('/<[^>]*>/u', '', $text) ?? $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = self::trim(preg_replace('/' . self::SPACE . '+/u', ' ', $text) ?? $text);
		return preg_match('/\A.{0,160}/us', $text, $prefix) ? $prefix[0] : $text;
	}

	/** Resolve declared leaves only. A media subproperty reads the first item. */
	public static function property(string $reference, FlexRow $row, FlexValue $root, FlexValue $parent, string $inputName): ?array {
		$path = explode('.', $reference);
		$scope = $row;
		if(count($path) > 1 && in_array($path[0], ['parent', 'root'], true)) {
			$scope = array_shift($path) === 'parent' ? $parent : $root;
		}
		while(($name = array_shift($path)) !== null) {
			$definition = $scope->definitions()->property($name);
			if(!$definition) return null;
			$value = $scope->getUnformatted($name);
			if($value instanceof FlexValue && $path) { $scope = $value; continue; }
			$input = $definition['input'];
			$base = FlexPath::inputPrefix($inputName, $scope->address()) . $name;
			if($value instanceof Pagefiles) return self::media($value, $base, $path, $input);
			if($path || $value instanceof FlexValue || $value instanceof FlexRows) return null;
			$source = ['names' => [$base], 'kind' => $definition['kind']];
			$options = $scope->getOptions($name);
			if($options) {
				$source['options'] = (object) $options;
				$value = implode(', ', array_map(static fn($key) => $options[$key] ?? (string) $key, (array) $value));
			} else if($value instanceof LanguagesPageFieldValue) {
				[$value, $source['names']] = self::languageValue($value, $base, $input, $scope->ownerPage());
			} else if($input instanceof InputfieldPage) {
				$pages = $value instanceof PageArray ? $value->getArray() : [$value];
				$value = implode(', ', array_map(static fn(Page $page) => $input->getPageLabel($page),
					array_filter($pages, static fn($page) => $page instanceof Page && $page->id && $page->viewable(false))));
			} else if($input instanceof InputfieldDatetime && $value) {
				if($input->inputType === 'html') {
					$time = $input->timeStep > 0 && $input->timeStep < 60 ? 'H:i:s' : 'H:i';
					$format = match($input->htmlType) { 'time' => $time, 'datetime' => 'Y-m-d ' . $time, default => 'Y-m-d' };
				} else {
					$format = $input->inputType === 'select' ? 'Y-m-d' : trim($input->dateInputFormat . ' ' . $input->timeInputFormat);
				}
				$value = date($format ?: 'Y-m-d', $value);
			} else if(is_array($value)) {
				$value = implode(', ', array_filter($value, 'is_scalar'));
			}
			if($input instanceof InputfieldCheckbox) $source['unchecked'] = (string) ($input->uncheckedValue === '' ? 0 : $input->uncheckedValue);
			return [self::display(is_scalar($value) ? (string) $value : ''), $source];
		}
		return null;
	}

	private static function languageValue(LanguagesPageFieldValue $value, string $base, Inputfield $input, Page $page): array {
		$language = $input->wire()->user->language;
		$languages = $input->wire()->languages;
		$template = method_exists($languages, 'getPageTemplate') ? $languages->getPageTemplate($page) : $page->template;
		if(!$language || $language->isDefault() || ($template && $template->noLang)) return [$value->getDefaultValue(), [$base]];
		$names = [$base . '__' . $language->id];
		$text = $value->getLanguageValue($language);
		if(!(int) $input->langBlankInherit) {
			$names[] = $base;
			if(self::trim($text) === '') $text = $value->getDefaultValue();
		}
		return [$text, $names];
	}

	private static function media(Pagefiles $files, string $base, array $path, Inputfield $input): ?array {
		$leaf = $path[0] ?? '';
		if(count($path) > 1 || ($leaf !== '' && !in_array($leaf, ['description', 'tags', 'basename'], true))) return null;
		$source = ['names' => [], 'kind' => 'media', 'base' => $base, 'leaf' => $leaf];
		$language = $input->wire()->user->language;
		$source['language'] = !$input->noLang && $language && !$language->isDefault() ? (int) $language->id : 0;
		$file = $files->first();
		if(!$file) return ['', $source];
		if($leaf === '') $text = implode(', ', array_map(static fn(Pagefile $item) => $item->basename(), $files->getArray()));
		else if($leaf === 'description') {
			$languages = $input->wire()->languages;
			$default = $file->rawDescription($languages ? $languages->getDefault() : null);
			$localized = $source['language'] ? $file->rawDescription($language) : '';
			$text = self::trim($localized) === '' ? $default : $localized;
		} else $text = $leaf === 'tags' ? $file->tags() : $file->basename();
		return [self::display($text), $source];
	}

	public static function markup(array $parts): string {
		$html = '';
		foreach($parts as $part) {
			$text = isset($part['children']) ? self::markup($part['children']) : htmlspecialchars($part['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$html .= isset($part['classes']) ? '<span class="' . implode(' ', $part['classes']) . '">' . $text . '</span>' : $text;
		}
		return $html;
	}
}
