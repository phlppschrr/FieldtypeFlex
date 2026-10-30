<?php namespace ProcessWire;

/** Native HTML URL/link transforms at the runtime/storage boundary. @internal */
class FlexMarkup {
	/** Shared native text settings for output formatting and markup storage. */
	public static function field(Page $page, Field $owner, string $name, array $definition): Field {
		$input = $definition['input'];
		$type = $input instanceof InputfieldTextarea ? 'FieldtypeTextarea'
			: ($input instanceof InputfieldURL ? 'FieldtypeURL'
				: ($input instanceof InputfieldEmail ? 'FieldtypeEmail' : 'FieldtypeText'));
		$field = $page->wire(new Field());
		$field->type = $page->wire()->modules->get($type);
		$field->name = $owner->name . '_' . $name;
		foreach($definition['settings'] + $input->getArray() as $key => $setting) {
			if(!in_array($key, ['id', 'name', 'type', 'value', 'inheritField'], true)) $field->set($key, $setting);
		}
		return $field;
	}

	/** Transform only declared HTML text, including every supplied language entry. */
	public static function value(Page $page, Field $owner, string $name, array $definition, $value, bool $sleep) {
		if(!$definition['input'] instanceof InputfieldTextarea) return $value;
		$settings = $definition['settings'] + $definition['input']->getArray();
		if(($settings['contentType'] ?? 0) < FieldtypeTextarea::contentTypeHTML) return $value;
		$field = self::field($page, $owner, $name, $definition);
		$qa = new MarkupQA($page, $field);
		$abstract = is_array($field->htmlOptions) && in_array(FieldtypeTextarea::htmlLinkAbstract, $field->htmlOptions);
		$transform = static function($text) use ($qa, $sleep, $abstract) {
			if(!is_string($text) || $text === '') return $text;
			if($sleep) {
				$qa->sleepUrls($text);
				if($abstract) $qa->sleepLinks($text);
			} else {
				if($abstract) $qa->wakeupLinks($text);
				$qa->wakeupUrls($text);
			}
			return $text;
		};
		if($value instanceof LanguagesValueInterface && $page->wire()->languages) {
			$value = clone $value;
			$tracking = $value->trackChanges();
			$value->setTrackChanges(false);
			foreach($page->wire()->languages as $language) {
				$value->setLanguageValue($language, $transform($value->getLanguageValue($language)));
			}
			$value->setTrackChanges($tracking);
			return $value;
		}
		return is_array($value) ? array_map($transform, $value) : $transform($value);
	}

	/** Sleep a detached export; unknown keys and structural metadata are untouched. */
	public static function sleep(Page $page, Field $field, FlexFieldDefs $schema, array $raw): array {
		foreach($schema->properties() as $name => $definition) {
			if(!array_key_exists($name, $raw)) continue;
			if($definition['kind'] === 'object') {
				$raw[$name]['values'] = self::sleep($page, $field, $definition['schema'], $raw[$name]['values']);
			} else if($definition['kind'] === 'rows') {
				foreach($raw[$name]['items'] ?? [] as $index => $row) {
					$children = $definition['types'][$row['type']]['schema'] ?? null;
					if($children && isset($row['values'])) {
						$raw[$name]['items'][$index]['values'] = self::sleep($page, $field, $children, $row['values']);
					}
				}
			} else if(in_array($definition['kind'], ['file', 'image'], true)) {
				foreach($raw[$name]['items'] ?? [] as $index => $meta) {
					$raw[$name]['items'][$index] = self::sleepFile($page, $field, $definition['customFields'], $meta);
				}
			} else {
				$raw[$name] = self::value($page, $field, $name, $definition, $raw[$name], true);
			}
		}
		return $raw;
	}

	/** Native description slots and ordinary custom values share the metadata schema. */
	public static function sleepFile(Page $page, Field $field, FlexFieldDefs $schema, array $meta): array {
		if(isset($meta['values'])) $meta['values'] = self::sleep($page, $field, $schema, $meta['values']);
		$description = $schema->property('description');
		if($description && isset($meta['description'])) {
			$meta['description'] = self::value($page, $field, 'description', $description, $meta['description'], true);
		}
		return $meta;
	}
}
