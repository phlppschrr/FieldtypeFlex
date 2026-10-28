<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$installed = [];
try {
	foreach(['TextformatterEntities', 'TextformatterStripTags', 'TextformatterNewlineBR'] as $module) {
		if(!$wire->modules->isInstalled($module)) {
			$wire->modules->install($module);
			$installed[] = $module;
		}
	}
	$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/media-formatters.png');
	$image = imagecreatetruecolor(16, 16);
	imagepng($image, $fixture);
	imagedestroy($image);
	$rawDescription = 'A "quote" & <b>B</b>' . "\nNext";
	$definitions = [];
	foreach(['file', 'image'] as $kind) {
		foreach(['default' => null, 'plain' => [], 'entities' => ['TextformatterEntities'], 'chain' => ['TextformatterStripTags', 'TextformatterNewlineBR', 'TextformatterEntities']] as $name => $chain) {
			foreach([1, 2] as $outputFormat) {
				$settings = ['type' => $kind, 'extensions' => 'png', 'outputFormat' => $outputFormat];
				if($chain !== null) $settings['textformatters'] = $chain;
				$definitions[$kind . '_' . $name . '_' . $outputFormat] = $settings;
			}
		}
	}
	$definitions['blocks'] = ['type' => 'rows', 'types' => ['media' => ['children' => ['photo' => ['type' => 'image', 'maxFiles' => 1, 'textformatters' => ['TextformatterEntities']]]]]];
	$f = $createField('flex_description_formatters', $definitions);
	$page->of(false);
	$value = $page->get($f->name);
	foreach($definitions as $name => $settings) {
		if($name === 'blocks') continue;
		$value->$name->add($fixture);
		$file = $value->$name->first();
		$file->description($defaultLanguage, $rawDescription);
		$file->description($language, 'Deutsch "Zitat" & <b>mehr</b>');
	}
	$value->blocks->addRow('media')->photo->add($fixture);
	$value->blocks->first()->photo->first()->description = $rawDescription;
	$page->save($f->name);
	$page = $wire->pages->getFresh($id);
	$page->of(false);
	$rawValue = $page->get($f->name);
	$canonical = $rawValue->rawArray();
	$revision = $rawValue->expectedRevision();
	$wire->user->language = $defaultLanguage;
	$page->of(true);
	$out = $page->get($f->name);
	foreach($definitions as $name => $settings) {
		if($name === 'blocks') continue;
		$readFile = static function() use ($out, $name): Pagefile {
			$value = $out->$name;
			return $value instanceof Pagefiles ? $value->first() : $value;
		};
		$expected = $rawDescription;
		foreach($settings['textformatters'] ?? [] as $module) {
			$wire->modules->get($module)->formatValue($page, $f, $expected);
		}
		$same($expected, $readFile()->description, "$name formats description in configured order");
		$same($expected, $readFile()->description, "$name repeat reads do not encode twice");
		$same($rawDescription, $out->getUnformatted($name)->first()->description($defaultLanguage), "$name public getUnformatted exposes raw descriptions");
		$same($canonical, $out->rawArray(), "$name formatted view retains all canonical metadata");
	}
	$same($wire->sanitizer->entities($rawDescription), $out->blocks->first()->photo->description, 'nested row descriptions use explicitly configured Entities');
	$wire->user->language = $language;
	$same($wire->sanitizer->entities($rawDescription), $out->blocks->first()->photo->description, 'blank translated media description falls back before formatting');
	$same($wire->sanitizer->entities('Deutsch "Zitat" & <b>mehr</b>'), $out->image_entities_2->description, 'same formatted view follows the current language');
	$same($wire->sanitizer->entities($rawDescription), $out->image_entities_2->description($defaultLanguage), 'explicit language description uses configured formatters');
	$same($wire->sanitizer->entities('Deutsch "Zitat" & <b>mehr</b>'), $out->image_entities_2->get('description' . $language->id), 'native language-specific property reads format once');
	$same($rawDescription, $out->image_entities_2->description(true, true)['default'], 'native all-language export remains raw');
	$same($rawDescription, json_decode($out->image_entities_2->description(true), true)['0'], 'native JSON description export remains raw');
	$same($out->image_entities_2->description, $out->image_entities_2->size(8, 8)->description, 'native image variations retain description formatter behavior');
	$same($canonical, $out->rawArray(), 'language formatting retains raw metadata in every language');
	$rawMedia = $out->getUnformatted('image_entities_2');
	$throws(fn() => $rawMedia->first()->description = 'Forbidden', 'unformatted read from a formatted view retains mutation guards');
	$same($revision, $out->expectedRevision(), 'description reads retain expected revision');
	$page->of(false);
	$assert(!$page->isChanged($f->name) && !$rawValue->isChanged(), 'formatted media reads do not dirty Page or raw value');
	$page->save($f->name);
	$same($canonical, $wire->pages->getFresh($id)->getUnformatted($f->name)->rawArray(), 'save after formatting never persists encoded descriptions');

	foreach(['file' => 'FieldtypeFile', 'image' => 'FieldtypeImage'] as $kind => $fieldtype) {
		$native = $wire->wire(new Field());
		$native->name = 'flex_media_source_' . bin2hex(random_bytes(4));
		$native->type = $wire->modules->get($fieldtype);
		$native->textformatters = ['TextformatterStripTags', 'TextformatterEntities'];
		$temporaryFields[] = $native;
		$wire->fields->save($native);
		$settings = ['type' => $kind, 'extensions' => 'png', 'inheritField' => $native->name];
		foreach([null, [], ['TextformatterEntities']] as $override) {
			$property = $settings;
			if($override !== null) $property['textformatters'] = $override;
			$defs = new FlexFieldDefs($f, $page, ['asset' => $property]);
			$same($override ?? $native->textformatters, $defs->property('asset')['settings']['textformatters'], "$kind inherits the configured native list unless explicitly overridden");
			$inherited = new FlexValue($page, $f, $defs);
			$inherited->asset->add($fixture);
			$inherited->asset->first()->description = $rawDescription;
			$before = $inherited->rawArray();
			$expected = $rawDescription;
			foreach($override ?? $native->textformatters as $formatter) {
				$wire->modules->get($formatter)->formatValue($page, $native, $expected);
			}
			$inherited->of(true);
			$asset = $inherited->asset;
			$same($expected, ($asset instanceof Pagefiles ? $asset->first() : $asset)->description, "$kind applies inherited/overridden formatter output");
			$same($before, $inherited->rawArray(), "$kind inherited formatter output preserves raw metadata");

		}
		$native->textformatters = [];
		$wire->fields->save($native);
		$defs = new FlexFieldDefs($f, $page, ['asset' => $settings]);
		$same([], $defs->property('asset')['settings']['textformatters'], "$kind inherits an empty native formatter list without adding Entities");
	}

} finally {
	$wire->user->language = $defaultLanguage;
	foreach(array_reverse($installed) as $module) $wire->modules->uninstall($module);
}
