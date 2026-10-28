<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$installed = [];
try {
	foreach(['TextformatterEntities', 'TextformatterMarkdownExtra', 'TextformatterStripTags', 'TextformatterNewlineBR'] as $module) {
		if(!$wire->modules->isInstalled($module)) {
			$wire->modules->install($module);
			$installed[] = $module;
		}
	}
	$native = static function(string $typeName, array $settings) use ($wire, $template, &$temporaryFields): Field {
		$f = $wire->wire(new Field());
		$f->name = 'flex_formatter_native_' . bin2hex(random_bytes(4));
		$f->type = $wire->modules->get($typeName);
		foreach($settings as $key => $value) $f->set($key, $value);
		$temporaryFields[] = $f;
		$wire->fields->save($f);
		$template->fieldgroup->add($f);
		$template->fieldgroup->save();
		return $f;
	};
	$chains = [[], ['TextformatterEntities'], ['TextformatterMarkdownExtra'],
		['TextformatterEntities', 'TextformatterMarkdownExtra'],
		['TextformatterMarkdownExtra', 'TextformatterEntities'],
		['TextformatterNewlineBR', 'TextformatterStripTags'],
		['TextformatterStripTags', 'TextformatterNewlineBR']];
	$inputs = [
		['text', 'FieldtypeText', []],
		['textarea', 'FieldtypeTextarea', []],
		['textarea', 'FieldtypeTextarea', ['contentType' => 1, 'htmlOptions' => [FieldtypeTextarea::htmlImageLoadingLazy]]],
		['TinyMCE', 'FieldtypeTextarea', ['inputfieldClass' => 'InputfieldTinyMCE', 'contentType' => 1]],
		['email', 'FieldtypeEmail', []],
		['url', 'FieldtypeURL', ['noRelative' => 0, 'addRoot' => 1]],
		['hidden', 'FieldtypeText', ['inputfieldClass' => 'InputfieldHidden']],
		['icon', 'FieldtypeText', ['inputfieldClass' => 'InputfieldIcon']],
	];
	foreach($inputs as [$input, $fieldtype, $settings]) {
		foreach($chains as $index => $chain) {
			$n = $native($fieldtype, ['textformatters' => $chain] + $settings);
			$f = $createField('flex_format_compare', ['body' => ['type' => $input, 'textformatters' => $chain] + $settings]);
			$cases = $input === 'email' ? ['', 'user@example.test'] : ($input === 'url' ? ['', '/relative?a=1&b=2'] : ['', '0', "**Bold** & <em>HTML</em>\nNext", '<img src="/test.png" alt="Test">']);
			foreach($cases as $raw) {
				$p = $wire->pages->getFresh($id);
				$p->of(false);
				$p->set($n->name, $raw);
				$p->set($f->name, ['body' => $raw]);
				$p->save($n->name);
				$p->save($f->name);
				$p = $wire->pages->getFresh($id);
				$p->of(false);
				$unformatted = $p->get($f->name);
				$canonical = $unformatted->rawArray();
				$before = $type->sleepValue($p, $f, $unformatted);
				$same($p->get($n->name), $unformatted->body, "$input chain=$index raw reload");
				$p->of(true);
				$output = $p->get($f->name);
				$same($p->get($n->name), $output->body, "$input chain=$index native formatted reload");
				$same($output->body, $output->body, "$input chain=$index repeated reads start from raw");
				$same($canonical, $output->rawArray(), "$input chain=$index formatted rawArray remains canonical");
				$p->of(false);
				$same($before, $type->sleepValue($p, $f, $unformatted), "$input chain=$index unchanged canonical JSON");
				$assert(!$p->isChanged($f->name) && !$unformatted->isChanged(), "$input chain=$index reads do not mark changes");
			}
		}
	}

	$n = $native('FieldtypeTextarea', ['textformatters' => ['TextformatterMarkdownExtra']]);
	$f = $createField('flex_format_inherit', [
		'body' => ['type' => 'textarea', 'inheritField' => $n->name],
		'plain' => ['type' => 'textarea', 'inheritField' => $n->name, 'textformatters' => []],
		'entities' => ['type' => 'textarea', 'inheritField' => $n->name, 'textformatters' => ['TextformatterEntities']],
		'legacy' => ['type' => 'text'],
	]);
	$v = $type->sanitizeValue($page, $f, array_fill_keys(['body', 'plain', 'entities', 'legacy'], '**Bold** & <em>HTML</em>'));
	$out = $type->formatValue($page, $f, $v);
	$same($n->type->formatValue($page, $n, $v->body), $out->body, 'inheritField copies Fieldtype formatter list');
	$same($v->plain, $out->plain, 'explicit empty list overrides inherited formatters and default encoding');
	$same($wire->sanitizer->entities($v->entities), $out->entities, 'explicit list replaces inherited list entirely');
	$same($wire->sanitizer->entities($v->legacy), $out->legacy, 'omitted formatter setting preserves legacy encoding');
	$f->flexEntityEncode = 0;
	$out = $type->formatValue($page, $f, $v);
	$same($n->type->formatValue($page, $n, $v->body), $out->body, 'explicit formatter runs with flexEntityEncode disabled');
	$same($v->legacy, $out->legacy, 'flexEntityEncode disabled still controls unconfigured text');
	$n->textformatters = [];
	$wire->fields->save($n);
	$v = $type->sanitizeValue($page, $f, ['body' => '<b>Raw</b>']);
	$same('<b>Raw</b>', $type->formatValue($page, $f, $v)->body, 'inheritField with no formatter uses native empty list');

	foreach(['text', 'textarea', 'TinyMCE'] as $input) {
		$n = $native($input === 'text' ? 'FieldtypeTextLanguage' : 'FieldtypeTextareaLanguage', [
			'textformatters' => ['TextformatterMarkdownExtra'], 'langBlankInherit' => 0,
			'inputfieldClass' => $input === 'TinyMCE' ? 'InputfieldTinyMCE' : ($input === 'text' ? 'InputfieldText' : 'InputfieldTextarea'),
		]);
		$f = $createField('flex_format_languages', ['body' => ['type' => $input, 'useLanguages' => true, 'textformatters' => ['TextformatterMarkdownExtra']]]);
		foreach(['**Deutsch** & mehr', ''] as $translation) {
			$p = $wire->pages->getFresh($id);
			$p->of(false);
			$map = [$defaultLanguage->id => '**Default** & text', $language->id => $translation];
			$p->set($n->name, new LanguagesPageFieldValue($p, $n, $map));
			$p->set($f->name, ['body' => $map]);
			$p->save($n->name);
			$p->save($f->name);
			$p = $wire->pages->getFresh($id);
			foreach([$defaultLanguage, $language] as $lang) {
				$wire->user->language = $lang;
				$p->of(true);
				$same($p->get($n->name), $p->get($f->name)->body, "$input language=$lang->name native formatter/fallback");
				$same(['body' => $map], $p->get($f->name)->rawArray(), "$input language reads preserve all translations");
			}
		}
	}
	$wire->user->language = $defaultLanguage;

	$leaf = ['type' => 'textarea', 'textformatters' => ['TextformatterMarkdownExtra']];
	$f = $createField('flex_format_nested', ['sections' => ['type' => 'rows', 'types' => ['section' => ['children' => [
		'blocks' => ['type' => 'rows', 'types' => ['text' => ['children' => ['body' => $leaf, 'translated' => $leaf + ['useLanguages' => true]]]]],
	]]]]]);
	$page->of(false);
	$page->set($f->name, []);
	$row = $page->get($f->name)->sections->addRow('section')->blocks->addRow('text', ['body' => '**Nested** & text', 'translated' => [$defaultLanguage->id => '**Default**', $language->id => '**Deutsch**']]);
	$page->save($f->name);
	$p = $wire->pages->getFresh($id);
	$p->of(false);
	$canonical = $p->get($f->name)->rawArray();
	$p->of(true);
	$out = $p->get($f->name);
	$same('<p><strong>Nested</strong> &amp; text</p>', $out->sections->first()->blocks->first()->body, 'nested rows format with native Markdown');
	$wire->user->language = $language;
	$same('<p><strong>Deutsch</strong></p>', $out->sections->first()->blocks->first()->translated, 'nested multilingual row uses current-language formatter output');
	$wire->user->language = $defaultLanguage;
	$same($canonical, $out->rawArray(), 'nested formatted reads preserve row IDs/types/canonical values');
	$p->of(false);
	$assert(!$p->isChanged($f->name), 'nested formatted reads do not change tracking');

	$context = null;
	$hook = $wire->addHookBefore('FieldtypeTextarea::formatValue', function(HookEvent $event) use (&$context, $f): void {
		$contextField = $event->arguments(1);
		if($contextField->name === $f->name . '_body') $context = [$event->arguments(0), $contextField];
	});
	try {
		$out->sections->first()->blocks->first()->body;
		$assert($context !== null && $context[0] === $p, 'nested native formatter receives actual owning Page');
		$same(0, (int) $context[1]->id, 'formatter Field context has no persisted native field ID');
		$same(['TextformatterMarkdownExtra'], $context[1]->textformatters, 'formatter Field context contains resolved property settings');
	} finally {
		$wire->removeHook($hook);
	}
	$p->save($f->name);
	$same($canonical, $wire->pages->getFresh($id)->getUnformatted($f->name)->rawArray(), 'save after formatted nested reads persists only canonical raw values');

	// Definitions from PHP arrays and native wrappers use the same validation as JSON.
	$defs = new FlexFieldDefs($f, $page, ['body' => $leaf]);
	$same(['TextformatterMarkdownExtra'], $defs->property('body')['settings']['textformatters'], 'PHP definition retains formatter list');
	$wrapper = $wire->wire(new InputfieldWrapper());
	$input = $wire->modules->get('InputfieldText');
	$input->name = 'body';
	$input->textformatters = ['TextformatterEntities'];
	$wrapper->add($input);
	$defs = new FlexFieldDefs($f, $page, $wrapper);
	$same(['TextformatterEntities'], $defs->property('body')['settings']['textformatters'], 'native wrapper retains formatter list');
	foreach(['integer', 'float', 'page', 'TextTags', 'select', 'file', 'rows', 'fieldset'] as $inputType) {
		$throws(fn() => new FlexFieldDefs($f, $page, ['bad' => ['type' => $inputType, 'textformatters' => []]]), "$inputType rejects textformatters", FlexDefinitionException::class, 'bad');
	}
	foreach([null, '', 'TextformatterEntities', ['wrong' => 'TextformatterEntities'], [null], [42], ['TextformatterDoesNotExist'], ['InputfieldText']] as $invalid) {
		$throws(fn() => new FlexFieldDefs($f, $page, ['bad' => ['type' => 'text', 'textformatters' => $invalid]]), 'invalid formatter definition reports field/source/property', FlexDefinitionException::class, 'bad');
	}
} finally {
	$wire->user->language = $defaultLanguage;
	foreach(array_reverse($installed) as $module) $wire->modules->uninstall($module);
}
