<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$originalLanguage = $wire->user->language;
$native = static function(string $name, string $typeName, array $settings = []) use ($wire, $template, &$temporaryFields): Field {
	$f = $wire->wire(new Field());
	$f->name = $name;
	$f->type = $wire->modules->get($typeName);
	foreach($settings as $key => $setting) $f->set($key, $setting);
	$temporaryFields[] = $f;
	$wire->fields->save($f);
	$template->fieldgroup->add($f);
	$template->fieldgroup->save();
	return $f;
};
$cases = [
	'text' => ['Text', 'text', [], [], 'Base & text', ['Translated & text', '', '0', ' ']],
	'formatted' => ['Text', 'text', ['textformatters' => []], ['textformatters' => ['TextformatterEntities']], 'Base & text', ['Translated & text', '']],
	'integer' => ['Integer', 'integer', [], ['zeroNotEmpty' => 1], 7, [3, 0, '']],
	'zero' => ['Integer', 'integer', ['zeroNotEmpty' => 1], ['zeroNotEmpty' => 1], 7, [0, 3, '']],
	'float' => ['Float', 'float', [], ['zeroNotEmpty' => 1], 7.5, [2.5, 0.0, '']],
	'date' => ['Datetime', 'datetime', ['dateOutputFormat' => 'Y/m/d'], ['dateOutputFormat' => 'd.m.Y'], '2026-10-01', ['2026-11-02', '']],
	'checkbox' => ['Checkbox', 'checkbox', [], [], 1, [0, 1]],
	'toggle' => ['Toggle', 'Toggle', ['formatType' => 2], ['formatType' => 1], 1, [0, 1, '']],
	'page' => ['Page', 'page', ['derefAsPage' => 2], ['derefAsPage' => 2], 1, [$second->id, 0]],
	'pages' => ['Page', 'page', ['derefAsPage' => 0], ['derefAsPage' => 0], [1], [[$second->id], []]],
];
$pairs = [];
$schema = [];
foreach($cases as $name => [$nativeType, $inputType, $baseSettings, $altSettings]) {
	$base = 'flex_alt_' . $name . '_' . bin2hex(random_bytes(3));
	$pairs[$name] = [$native($base, 'Fieldtype' . $nativeType, $baseSettings), $native($base . '_de', 'Fieldtype' . $nativeType, $altSettings)];
	$schema[$name] = ['type' => $inputType] + $baseSettings;
	$schema[$name . '_de'] = ['type' => $inputType] + $altSettings;
}
foreach(['file', 'image'] as $kind) {
	$settings = ['extensions' => $kind === 'image' ? 'png' : 'txt', 'maxFiles' => 1];
	$name = 'flex_alt_' . $kind . '_' . bin2hex(random_bytes(3));
	$pairs[$kind] = [$native($name, 'Fieldtype' . ucfirst($kind), $settings), $native($name . '_de', 'Fieldtype' . ucfirst($kind), ['outputFormat' => 1] + $settings)];
	$schema[$kind] = ['type' => $kind] + $settings;
	$schema[$kind . '_de'] = ['type' => $kind, 'outputFormat' => 1] + $settings;
}
$f = $createField('flex_alternates', $schema);

// Core discovers alternate names during initialization, before ordinary requests.
// Reinitialize after creating owned Fields, replacing only its initialization hooks.
$support = $wire->modules->get('LanguageSupportFields');
foreach($support->getHooks('*') as $hook) {
	if($hook['toObject'] === $support && in_array($hook['toMethod'], ['fieldtypeSleepValue', 'pageFinderGetQuery', 'hookFieldtypeFormatValue'], true)) {
		$support->removeHook($hook['id']);
	}
}
$support->LS_init();
$shape = static function($value) {
	if($value instanceof Page) return ['page', (int) $value->id];
	if($value instanceof PageArray) return ['pages', array_map('intval', $value->explode('id'))];
	return $value;
};

try {
	foreach($cases as $name => [$_, $__, $baseSettings, $altSettings, $baseValue, $values]) {
		[$base, $alt] = $pairs[$name];
		$assert(in_array($alt->name, $support->getAlternateFields($base->name), true), "$name oracle uses native alternate discovery");
		foreach($values as $alternateValue) {
			$wire->user->language = $defaultLanguage;
			$p = $wire->pages->getFresh($id);
			$p->of(false);
			$p->set($base->name, $base->type->getBlankValue($p, $base));
			$p->set($alt->name, $alt->type->getBlankValue($p, $alt));
			$p->set($base->name, $baseValue);
			$p->set($alt->name, $alternateValue);
			$p->set($f->name, [$name => $baseValue, $name . '_de' => $alternateValue]);
			$p->save($base->name);
			$p->save($alt->name);
			$p->save($f->name);
			$p = $wire->pages->getFresh($id);
			$raw = $p->getUnformatted($f->name)->rawArray();
			foreach([$defaultLanguage, $language] as $active) {
				$wire->user->language = $active;
				foreach([false, true] as $of) {
					$p->of($of);
					$value = $p->get($f->name);
					$same($shape($p->get($base->name)), $shape($value->get($name)), "$name saved native parity language=$active->name OF=" . (int) $of);
					$same($shape($p->get($alt->name)), $shape($value->get($name . '_de')), "$name direct alternate remains explicit");
					$same($raw, $value->rawArray(), "$name output preserves canonical values");
					$same(false, $value->isChanged(), "$name reads do not dirty values");
				}
			}
			$p->of(false);
		}
	}

	// Owned media must keep the alternate's collection/single settings and address.
	foreach(['file', 'image'] as $kind) {
		[$base, $alt] = $pairs[$kind];
		$source = $trackFile(dirname(__DIR__, 2) . '/.dev/alternate-' . bin2hex(random_bytes(4)) . ($kind === 'image' ? '.png' : '.txt'));
		if($kind === 'image') {
			$image = imagecreatetruecolor(12, 12);
			imagepng($image, $source);
			imagedestroy($image);
		} else {
			file_put_contents($source, 'Language alternate fixture');
		}
		$wire->user->language = $defaultLanguage;
		$p = $wire->pages->getFresh($id);
		$p->of(false);
		$p->set($f->name, []);
		$p->get($base->name)->add($source);
		$p->get($f->name)->get($kind)->add($source);
		$p->save($base->name);
		$p->save($f->name);
		foreach([false, true] as $populated) {
			if($populated) {
				$p->get($alt->name)->add($source);
				$p->get($f->name)->get($kind . '_de')->add($source);
				$p->save($alt->name);
				$p->save($f->name);
			}
			$p = $wire->pages->getFresh($id);
			$raw = $p->getUnformatted($f->name)->rawArray();
			foreach([$defaultLanguage, $language] as $active) {
				$wire->user->language = $active;
				$p->of(true);
				$value = $p->get($f->name);
				$expected = $p->get($base->name);
				$actual = $value->get($kind);
				$same($expected instanceof Pagefiles, $actual instanceof Pagefiles, "$kind native single/collection parity populated=" . (int) $populated . " language=$active->name");
				$chosen = $populated && $active === $language ? $kind . '_de' : $kind;
				$item = $actual instanceof Pagefiles ? $actual->first() : $actual;
				$same($raw[$chosen]['items'][0]['basename'], $item->basename, "$kind returns the chosen property's owned file");
				$same(hash_file('sha256', $source), hash_file('sha256', $item->filename), "$kind preserves original bytes");
				$same($raw[$kind]['items'][0]['basename'], $value->getUnformatted($kind)->first()->basename, "$kind explicit raw access retains base media");
				$throws(fn() => $item->description = 'Forbidden', "$kind resolved formatted media retains write guards");
				$same($raw, $value->rawArray(), "$kind read retains both envelopes");
			}
			$p->of(false);
		}
	}

	// Shared value access resolves local siblings in Objects, typed/nested Rows and metadata.
	$children = ['heading' => ['type' => 'text'], 'heading_de' => ['type' => 'text', 'textformatters' => ['TextformatterEntities']]];
	$nested = $createField('flex_alternate_scopes', $children + [
		'object' => ['type' => 'object', 'children' => $children],
		'blocks' => ['type' => 'rows', 'types' => ['item' => ['children' => $children + [
			'child' => ['type' => 'rows', 'types' => ['item' => ['children' => $children]]],
		]]]],
		'image' => ['type' => 'image', 'maxFiles' => 1, 'customFields' => $children],
	]);
	$p = $wire->pages->getFresh($id);
	$p->of(false);
	$p->set($nested->name, ['heading' => 'Root', 'heading_de' => 'Root & DE', 'object' => ['heading' => 'Object', 'heading_de' => 'Object & DE']]);
	$value = $p->get($nested->name);
	$row = $value->blocks->addRow('item', ['heading' => 'Row', 'heading_de' => 'Row & DE']);
	$row->child->addRow('item', ['heading' => 'Child', 'heading_de' => 'Child & DE']);
	$value->image->add($source);
	$value->image->first()->heading = 'Metadata';
	$value->image->first()->heading_de = 'Metadata & DE';
	$p->save($nested->name);
	$p = $wire->pages->getFresh($id);
	$wire->user->language = $language;
	$p->of(true);
	$value = $p->get($nested->name);
	foreach(['Root' => $value, 'Object' => $value->object, 'Row' => $value->blocks->first(), 'Child' => $value->blocks->first()->child->first()] as $label => $scope) {
		$same($label . ' &amp;amp; DE', $scope->heading, "$label uses local formatted alternate");
		$same($label, $scope->getUnformatted('heading'), "$label explicit raw access bypasses alternates");
		$same($label, $scope->unformattedView()->heading, "$label raw view bypasses alternates");
		$same($label . ' &amp;amp; DE', $scope->getArray()['heading'], "$label runtime array resolves alternates");
	}
	$same('Metadata &amp;amp; DE', $value->image->heading, 'file custom metadata resolves local alternate');
	$same('Root', json_decode(json_encode($value), true)['heading'], 'JSON retains canonical base');
	$wire->user->language = $defaultLanguage;
	$same('Root', $value->heading, 'retained formatted view responds to language changes');
	$wire->user->language = $language;
	$same('Root &amp;amp; DE', $value->heading, 'repeated reads format from the original alternate');
	$noLang = $p->template->noLang;
	try {
		$p->template->noLang = 1;
		$same('Root &amp;amp; DE', $value->heading, 'template noLang does not suppress alternate substitution');
		[$nativeBase, $nativeAlt] = $pairs['text'];
		$p->of(false);
		$p->set($nativeBase->name, 'Default');
		$p->set($nativeAlt->name, 'Translated');
		$p->of(true);
		$same('Translated', $p->get($nativeBase->name), 'native alternate hook also ignores template noLang');
	} finally {
		$p->template->noLang = $noLang;
	}

	$same([], $wire->pages->findIDs("id=$id, {$nested->name}.heading=Root & DE"), 'SQL selector retains explicit base property');
	$same([$id], $wire->pages->findIDs("id=$id, {$nested->name}.heading_de=Root & DE"), 'SQL selector can query explicit alternate');
	$p->of(false);
	$editable = $p->get($nested->name);
	$editable->heading = 'Edited base';
	$p->save($nested->name);
	$reloaded = $wire->pages->getFresh($id)->getUnformatted($nested->name);
	$same('Edited base', $reloaded->heading, 'assignment in a secondary language updates the named base');
	$same('Root & DE', $reloaded->heading_de, 'base assignment preserves explicit alternate');

	// Missing/unknown alternates stay local, and normalized language names use underscores.
	$defs = new FlexFieldDefs($f, $p, ['heading' => ['type' => 'text']]);
	$unknown = new FlexValue($p, $f, $defs, ['heading' => 'Known', 'heading_de' => 'Unknown']);
	$unknown->of(true);
	$same('Known', $unknown->heading, 'retained unknown keys cannot become alternates');
	$same(null, $unknown->missing, 'unknown base does not resolve a sibling');
	$regional = clone $language;
	$regional->name = 'de-at.test';
	$wire->user->language = $regional;
	$defs = new FlexFieldDefs($f, $p, ['heading' => ['type' => 'text'], 'heading_de_at_test' => ['type' => 'text']]);
	$regionalValue = new FlexValue($p, $f, $defs, ['heading' => 'Default', 'heading_de_at_test' => 'Regional']);
	$regionalValue->of(true);
	$same('Regional', $regionalValue->heading, 'hyphens and dots in language names resolve to underscores');
	$wire->user->language = $language;

	$rowsDefinition = ['type' => 'rows', 'types' => ['item' => ['children' => $children]]];
	$structuredDefs = new FlexFieldDefs($f, $p, [
		'heading' => ['type' => 'text'], 'heading_de' => ['type' => 'text'],
		'object' => ['type' => 'object', 'children' => $children],
		'object_de' => ['type' => 'object', 'children' => $children],
		'blocks' => $rowsDefinition, 'blocks_de' => $rowsDefinition,
		'choices' => ['type' => 'checkboxes', 'options' => ['a' => 'A', 'b' => 'B']],
		'choices_de' => ['type' => 'checkboxes', 'options' => ['a' => 'A', 'b' => 'B']],
		'language' => ['type' => 'text'], 'language_de' => ['type' => 'text'],
	]);
	$p->of(false);
	$structured = new FlexValue($p, $f, $structuredDefs, [
		'heading' => '', 'heading_de' => 'Translated', 'object' => ['heading' => 'Base object'],
		'choices' => ['a'], 'language' => 'Base language', 'language_de' => 'Other language',
	]);
	$structured->blocks->addRow('item', ['heading' => 'Base row']);
	$structured->resetTrackChanges();
	$structured->of(true);
	$same('Translated', $structured->heading, 'populated alternate can supply an empty base');
	$same(false, $structured->hasValue('heading'), 'content checks continue to describe the explicit base');
	$same('Base object', $structured->object->heading, 'empty alternate Object falls back');
	$same('Base row', $structured->blocks->first()->heading, 'empty alternate Rows fall back');
	$same(['a'], $structured->choices, 'empty multiple-choice alternate falls back');
	$same('Base language', $structured->language, 'language property retains native exemption');
	$structured->of(false);
	$structured->object_de->heading = 'Alternate object';
	$structured->blocks_de->addRow('item', ['heading' => 'Alternate row'])->setHidden(true);
	$structured->choices_de = ['b'];
	$structured->of(true);
	$same('Alternate object', $structured->object->heading, 'populated alternate Object is selected');
	$same(0, count($structured->blocks), 'hidden alternate Rows are populated before visibility filtering');
	$same(['b'], $structured->choices, 'populated multiple-choice alternate is selected');

	// Mask capabilities on this value only; do not uninstall shared fixture modules.
	foreach([false, true] as $withLanguages) {
		$scope = new class($wire, $withLanguages) extends ProcessWire {
			private ProcessWire $delegate;
			private bool $withLanguages;
			public function __construct(ProcessWire $delegate, bool $withLanguages) {
				$this->delegate = $delegate;
				$this->withLanguages = $withLanguages;
			}
			public function __destruct() {}
			public function __get($name) {
				if($name === 'languages' && !$this->withLanguages) return null;
				if($name === 'modules') return new class($this->delegate->modules) {
					private Modules $delegate;
					public function __construct(Modules $delegate) { $this->delegate = $delegate; }
					public function isInstalled($name) { return $name === 'LanguageSupportFields' ? false : $this->delegate->isInstalled($name); }
				};
				return $this->delegate->wire($name);
			}
		};
		$plain = new FlexValue($p, $f, new FlexFieldDefs($f, $p, $children), ['heading' => 'Default', 'heading_de' => 'Alternate']);
		$plain->of(true);
		$same('Alternate', $plain->heading, 'capability probe starts with a resolvable alternate');
		$plain->setWire($scope);
		$plain->of(true);
		$same('Default', $plain->heading, $withLanguages ? 'missing LanguageSupportFields disables substitution' : 'missing Languages API disables substitution');
	}

} finally {
	$wire->user->language = $originalLanguage;
	$page->of(false);
}
