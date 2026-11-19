<?php namespace ProcessWire;

/** Column types and the save-time ordering shared by editor and API writes. @internal */
final class FlexTableSort {
	public static function type(Inputfield $input): ?string {
		return match($input->className()) {
			'InputfieldText' => 'text',
			'InputfieldInteger' => 'integer',
			'InputfieldFloat' => 'float',
			default => null,
		};
	}

	public static function locale(Page $page): string {
		$languages = $page->wire()->languages;
		$locale = $languages ? $languages->getLocale(LC_COLLATE, $languages->getDefault()) : '';
		if(preg_match('/(?:^|;)LC_COLLATE=([^;]+)/', (string) $locale, $match)) $locale = $match[1];
		$locale = preg_split('/[.,@]/', trim((string) $locale), 2)[0];
		return preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*$/iD', $locale) ? str_replace('_', '-', $locale) : 'en';
	}

	/** Localized text is ordered by its default-language value on every write. */
	public static function defaultValue(Page $page, $value) {
		if($value instanceof LanguagesValueInterface) {
			return $value->getLanguageValue($page->wire()->languages->getDefault());
		}
		return $value;
	}

	/** Sort a detached save value, or synchronize the cached order from a successful write. */
	public static function apply(FlexValue $value, ?array $saved = null): void {
		foreach($value->definitions()->properties() as $name => $definition) {
			if(!in_array($definition['kind'], ['object', 'rows'], true)) continue;
			$child = $value->getUnformatted($name);
			$stored = $saved[$name] ?? [];
			if($child instanceof FlexValue) {
				self::apply($child, $saved === null ? null : ($stored['values'] ?? []));
				continue;
			}
			if(!$child instanceof FlexRows) continue;
			if($saved === null) {
				$sort = $definition['settings']['_flexTable']['sortOnSave'] ?? null;
				if($sort && count($child) > 1) self::sort($child, $value->ownerPage(), $sort);
				foreach($child as $row) self::apply($row);
			} else {
				$items = $stored['items'] ?? [];
				if(isset($definition['settings']['_flexTable'])) $child->applySavedOrder(array_column($items, 'id'));
				foreach($items as $item) {
					$row = $child->getById($item['id']);
					if($row) self::apply($row, $item['values']);
				}
			}
		}
	}

	private static function sort(FlexRows $rows, Page $page, array $sort): void {
		$keys = [];
		$text = self::type($rows->first()->definitions()->getPropertyInputfield($sort['column'])) === 'text';
		$collator = $text && class_exists(\Collator::class) ? \Collator::create(self::locale($page)) : null;
		if($collator) $collator->setStrength(\Collator::SECONDARY);
		foreach($rows as $row) {
			$key = self::defaultValue($page, $row->getUnformatted($sort['column']));
			$keys[$row->rowId()] = is_string($key) ? trim($key) : $key;
		}
		$direction = $sort['direction'] === 'desc' ? -1 : 1;
		uasort($keys, static function($a, $b) use ($text, $collator, $direction): int {
			$blankA = $a === '' || $a === null;
			$blankB = $b === '' || $b === null;
			if($blankA || $blankB) return $blankA <=> $blankB;
			if(!$text) return $direction * ($a <=> $b);
			$comparison = $collator ? $collator->compare($a, $b) : false;
			return $direction * ($comparison === false ? strcasecmp($a, $b) : $comparison);
		});
		$rows->applySavedOrder(array_keys($keys));
	}
}
