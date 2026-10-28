<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

// Native database-backed Fields are the oracle, never API.md or copied expected values.
$native = static function(string $typeName, array $settings = []) use ($wire, $template, &$temporaryFields): Field {
	$f = $wire->wire(new Field());
	$f->name = 'flex_native_' . bin2hex(random_bytes(5));
	$f->type = $wire->modules->get($typeName);
	foreach($settings as $key => $value) $f->set($key, $value);
	$temporaryFields[] = $f;
	$wire->fields->save($f);
	$template->fieldgroup->add($f);
	$template->fieldgroup->save();
	return $f;
};
$shape = static function($value) {
	if($value instanceof Page) return [get_class($value), (int) $value->id, $value->of()];
	if($value instanceof PageArray) return ['PageArray', array_map('intval', $value->explode('id'))];
	return $value;
};
$read = static function(Page $p, Field $f, bool $of) {
	$p->of($of);
	return $p->get($f->name);
};

// Page references: actual native sanitize/save/wakeup/format, all dereference modes.
$reference = $second;
$hidden = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => 'flex-native-hidden-' . bin2hex(random_bytes(4)), 'title' => 'Hidden target']);
$hidden->addStatus(Page::statusHidden);
$hidden->save();
$pages[] = $hidden;
$unpublished = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => 'flex-native-unpub-' . bin2hex(random_bytes(4)), 'title' => 'Unpublished target']);
$unpublished->addStatus(Page::statusUnpublished);
$unpublished->save();
$pages[] = $unpublished;
foreach([0, 1, 2] as $mode) {
	foreach([0, 1] as $allow) {
		$settings = ['derefAsPage' => $mode, 'allowUnpub' => $allow, 'parent_id' => 1, 'inputfield' => $mode ? 'InputfieldSelect' : 'InputfieldAsmSelect'];
		$n = $native('FieldtypePage', $settings);
		$f = $createField('flex_page_comparison', ['ref' => ['type' => 'page'] + $settings]);
		$cases = ['blank' => null, 'published' => $reference, 'hidden' => $hidden, 'unpublished' => $unpublished, 'missing' => 2147483647, 'self' => $page, 'wrong_parent' => $wire->pages->get(1)];
		foreach($cases as $case => $input) {
			$p = $wire->pages->getFresh($id);
			$p->of(false);
			// Native multi assignment appends. Start both sides with an empty owned value.
			$p->set($n->name, $n->type->getBlankValue($p, $n));
			$p->set($f->name, []);
			$p->set($n->name, $input);
			$p->get($f->name)->ref = $input;
			foreach([false, true] as $of) {
				$expected = $shape($read($p, $n, $of));
				$same($expected, $shape($read($p, $f, $of)->ref), "Page mode=$mode allow=$allow $case assignment OF=" . (int) $of);
			}
			$p->of(false);
			$p->save($n->name);
			$p->save($f->name);
			$p = $wire->pages->getFresh($id);
			foreach([false, true] as $of) {
				$same($shape($read($p, $n, $of)), $shape($read($p, $f, $of)->ref), "Page mode=$mode allow=$allow $case reload OF=" . (int) $of);
			}
		}
		$p->of(false);
		$p->set($n->name, $n->type->getBlankValue($p, $n));
		$p->set($f->name, []);
		foreach([$reference->id, $hidden->id, $reference->path, $reference->title] as $input) {
			$p->set($n->name, $input);
			$p->get($f->name)->ref = $input;
			$same($shape($p->get($n->name)), $shape($p->get($f->name)->ref), "Page mode=$mode scalar assignment semantics");
		}
		if($mode === 0) {
			$replacement = $wire->pages->newPageArray();
			$replacement->add($reference);
			$p->set($n->name, clone $replacement);
			$p->get($f->name)->ref = clone $replacement;
			$p->get($n->name)->add($hidden);
			$p->get($f->name)->ref->add($hidden);
			$p->get($n->name)->remove($reference);
			$p->get($f->name)->ref->remove($reference);
			$p->save();
			$p = $wire->pages->getFresh($id);
			$p->of(false);
			$same($shape($p->get($n->name)), $shape($p->get($f->name)->ref), 'PageArray mutation and whole-page save match native');
		}
	}
}

// Same field settings, same values, before and after database persistence.
$matrix = [
	'email' => ['FieldtypeEmail', [], ['type' => 'email'], ['', 'valid@example.test', 'invalid', ' user@example.test ', 'user+tag@mail.example.co.uk', 'User@Example.COM']],
	'url' => ['FieldtypeURL', ['textformatters' => ['TextformatterEntities']], ['type' => 'url', 'textformatters' => ['TextformatterEntities']], ['', 'https://example.test/path?a=1&b=2', '/relative', 'example.test', 'javascript:alert(1)']],
	'url_absolute' => ['FieldtypeURL', ['noRelative' => 1, 'textformatters' => []], ['type' => 'url', 'noRelative' => 1, 'textformatters' => []], ['', '/rejected/path/', 'https://example.test/', 'javascript:alert(1)']],
	'float_unrounded' => ['FieldtypeFloat', ['colType' => 'double', 'precision' => -1, 'zeroNotEmpty' => 1], ['type' => 'float', 'precision' => -1, 'zeroNotEmpty' => 1], [3.14159, -2.34567, '12.75123']],
	'hidden' => ['FieldtypeText', ['textformatters' => ['TextformatterEntities']], ['type' => 'hidden', 'textformatters' => ['TextformatterEntities']], ['', 'Hidden & value']],
	'icon' => ['FieldtypeText', ['textformatters' => ['TextformatterEntities']], ['type' => 'icon', 'textformatters' => ['TextformatterEntities']], ['', 'fa-star']],
	'toggle' => ['FieldtypeToggle', ['formatType' => 0], ['type' => 'Toggle'], ['', null, false, true, 0, 1, 2, 'yes', 'off']],
	'text' => ['FieldtypeText', ['textformatters' => ['TextformatterEntities']], ['type' => 'text', 'textformatters' => ['TextformatterEntities']], ['', 'A & <B>', '0']],
	'textarea' => ['FieldtypeTextarea', ['textformatters' => ['TextformatterEntities']], ['type' => 'textarea', 'textformatters' => ['TextformatterEntities']], ['', "A & B\nLine 2"]],
	'html' => ['FieldtypeTextarea', ['contentType' => 1], ['type' => 'textarea', 'contentType' => 1], ['', '<p>A &amp; B</p>']],
	'tinymce' => ['FieldtypeTextarea', ['inputfieldClass' => 'InputfieldTinyMCE', 'contentType' => 1], ['type' => 'TinyMCE'], ['', '<p>Hello <strong>world</strong></p>']],
	'integer' => ['FieldtypeInteger', ['zeroNotEmpty' => 1], ['type' => 'integer', 'zeroNotEmpty' => 1], ['', null, false, true, 0, -17, '42']],
	'float' => ['FieldtypeFloat', ['precision' => 2, 'zeroNotEmpty' => 1], ['type' => 'float', 'precision' => 2, 'zeroNotEmpty' => 1], ['', null, false, true, 0, 1.239, -2.345, '12.75']],
	'datetime' => ['FieldtypeDatetime', ['dateOutputFormat' => 'd.m.Y H:i'], ['type' => 'datetime', 'dateInputFormat' => 'Y-m-d H:i:s', 'dateOutputFormat' => 'd.m.Y H:i'], ['', null, false, 0, '0', 1792326600, '1792326600', new \DateTime('2026-10-18 14:30:00'), '2026-10-18 14:30:00']],
	'checkbox' => ['FieldtypeCheckbox', [], ['type' => 'checkbox'], ['', null, false, true, 0, 1, '0']],
];
foreach($matrix as $kind => [$nativeType, $settings, $definition, $inputs]) {
	$n = $native($nativeType, $settings);
	$f = $createField('flex_native_' . $kind, ['value' => $definition]);
	foreach($inputs as $input) {
		$p = $wire->pages->getFresh($id);
		$p->of(false);
		$p->set($n->name, $input);
		$p->set($f->name, ['value' => $input]);
		foreach([false, true] as $of) {
			$same($read($p, $n, $of), $read($p, $f, $of)->value, "$kind assignment OF=" . (int) $of);
		}
		$p->of(false);
		$p->save($n->name);
		$p->save($f->name);
		$p = $wire->pages->getFresh($id);
		foreach([false, true] as $of) {
			$same($read($p, $n, $of), $read($p, $f, $of)->value, "$kind reload OF=" . (int) $of);
		}
	}
}

// Access is not silently filtered by FieldtypePage: callers must enforce viewability.
$privateTemplate = $wire->wire(new Template());
$privateTemplate->fieldgroup = $template->fieldgroup;
$privateTemplate->name = 'flex_private_' . bin2hex(random_bytes(4));
$privateTemplate->useRoles = 1;
$privateTemplate->roles = [$wire->roles->get('superuser')];
$wire->templates->save($privateTemplate);
$privatePage = null;
$adminUser = $wire->user;
try {
	$privatePage = $wire->pages->newPage(['template' => $privateTemplate, 'parent' => 1, 'name' => 'flex-private-' . bin2hex(random_bytes(4)), 'title' => 'Private']);
	$privatePage->save();
	foreach([0, 1, 2] as $mode) {
		$settings = ['derefAsPage' => $mode, 'allowUnpub' => 1, 'parent_id' => 1];
		$n = $native('FieldtypePage', $settings);
		$f = $createField('flex_private_ref', ['ref' => ['type' => 'page'] + $settings]);
		$p = $wire->pages->getFresh($id);
		$p->of(false);
		$p->set($n->name, $privatePage);
		$p->set($f->name, ['ref' => $privatePage]);
		$p->save();
		$wire->users->setCurrentUser($wire->users->getGuestUser());
		$assert(!$privatePage->viewable(), 'reference target really is inaccessible to guest');
		$p = $wire->pages->getFresh($id);
		foreach([false, true] as $of) {
			$same($shape($read($p, $n, $of)), $shape($read($p, $f, $of)->ref), "inaccessible Page mode=$mode OF=" . (int) $of);
		}
		$wire->users->setCurrentUser($adminUser);
	}
} finally {
	$wire->users->setCurrentUser($adminUser);
	if($privatePage && $privatePage->id) $wire->pages->delete($privatePage, true);
	$wire->templates->delete($privateTemplate);
}

foreach(['FieldtypeTextLanguage' => 'text', 'FieldtypeTextareaLanguage' => 'TinyMCE'] as $nativeType => $inputType) {
	$settings = ['textformatters' => $inputType === 'text' ? ['TextformatterEntities'] : [], 'langBlankInherit' => 0];
	if($inputType === 'TinyMCE') $settings += ['inputfieldClass' => 'InputfieldTinyMCE', 'contentType' => 1];
	$n = $native($nativeType, $settings);
	$f = $createField('flex_native_language', ['value' => ['type' => $inputType, 'useLanguages' => true, 'langBlankInherit' => 0, 'textformatters' => $settings['textformatters']]]);
	foreach(['populated' => [$defaultLanguage->id => 'Default & text', $language->id => 'Deutsch & Text'], 'fallback' => [$defaultLanguage->id => 'Fallback', $language->id => ''], 'empty' => []] as $case => $translations) {
		$p = $wire->pages->getFresh($id);
		$p->of(false);
		$nv = new LanguagesPageFieldValue($p, $n, $translations);
		$p->set($n->name, $nv);
		$p->set($f->name, ['value' => $translations]);
		$p->save();
		$p = $wire->pages->getFresh($id);
		foreach([$defaultLanguage, $language] as $lang) {
			$wire->user->language = $lang;
			$p->of(false);
			$nv = $p->get($n->name);
			$fv = $p->get($f->name)->value;
			$assert($nv instanceof LanguagesPageFieldValue && $fv instanceof LanguagesPageFieldValue, "$inputType language $case raw native value class");
			$same($nv->getLanguageValue($lang), $fv->getLanguageValue($lang), "$inputType language $case raw language content");
			$p->of(true);
			$same($p->get($n->name), $p->get($f->name)->value, "$inputType language $case formatted native string");
		}
	}
	$wire->user->language = $language;
	$p->of(false);
	$p->set($n->name, 'Current language');
	$p->get($f->name)->value = 'Current language';
	$same($p->get($n->name)->getLanguageValue($language), $p->get($f->name)->value->getLanguageValue($language), 'scalar language assignment uses current user language');
	$same($p->get($n->name)->getLanguageValue($defaultLanguage), $p->get($f->name)->value->getLanguageValue($defaultLanguage), 'scalar language assignment preserves default translation');
	$wire->user->language = $defaultLanguage;
	$p->get($n->name)->setLanguageValue($language, 'Changed');
	$p->get($f->name)->value->setLanguageValue($language, 'Changed');
	$p->save();
	$p = $wire->pages->getFresh($id);
	$p->of(false);
	$same($p->get($n->name)->getLanguageValue($language), $p->get($f->name)->value->getLanguageValue($language), 'language object mutation saves like native');
}

// Inputfield choices intentionally do not emulate FieldtypeOptions' owned option objects.
$optionsType = $wire->modules->get('FieldtypeOptions');
foreach(['Select', 'SelectMultiple', 'Checkboxes', 'Radios', 'AsmSelect', 'TextTags'] as $inputType) {
	$n = $native('FieldtypeOptions', ['inputfieldClass' => 'Inputfield' . $inputType]);
	$optionsType->manager->setOptionsString($n, "1=Alpha\n2=Beta");
	$f = $createField('flex_native_choice', ['value' => ['type' => $inputType, 'options' => ['1' => 'Alpha', '2' => 'Beta']]]);
	$multiple = in_array($inputType, ['SelectMultiple', 'Checkboxes', 'AsmSelect'], true);
	foreach($multiple ? ['', '1', ['1', '2']] : ['', '1'] as $input) {
		$p = $wire->pages->getFresh($id);
		$p->of(false);
		$p->set($n->name, $input);
		$p->set($f->name, ['value' => $input]);
		$p->save();
		$p = $wire->pages->getFresh($id);
		foreach([false, true] as $of) {
			$nv = $read($p, $n, $of);
			$fv = $read($p, $f, $of)->value;
			$assert($nv instanceof SelectableOptionArray, "$inputType native choices use SelectableOptionArray in both OF modes");
			$same(is_array($input) ? [1, 2] : ($input === '' ? [] : [1]), array_values(array_map('intval', $nv->explode('id'))), "$inputType native selection IDs persist");
			$same($multiple ? (is_array($input) ? $input : ($input === '' ? [] : ['1'])) : $input, $fv, "$inputType Flex deliberately exposes keys rather than option objects");
		}
	}
}

// Native media return modes and common metadata operations, with actual file bytes.
$imagePath = $trackFile(dirname(__DIR__, 2) . '/.dev/native-audit-' . bin2hex(random_bytes(5)) . '.png');
$im = imagecreatetruecolor(40, 30);
imagepng($im, $imagePath);
imagedestroy($im);
$textPath = $trackFile(dirname(__DIR__, 2) . '/.dev/native-audit-' . bin2hex(random_bytes(5)) . '.txt');
file_put_contents($textPath, 'Native and Flex comparison');
foreach(['file', 'image'] as $kind) {
	foreach([0, 1, 2] as $format) {
		foreach([0, 1, 2] as $limit) {
			$settings = ['extensions' => $kind === 'file' ? 'txt' : 'png', 'maxFiles' => $limit, 'outputFormat' => $format, 'noLang' => 1, 'useTags' => 1];
			$n = $native($kind === 'file' ? 'FieldtypeFile' : 'FieldtypeImage', $settings);
			$f = $createField('flex_native_media', ['asset' => ['type' => $kind] + $settings]);
			$p = $wire->pages->getFresh($id);
			$p->of(false);
			$nf = $p->get($n->name);
			$ff = $p->get($f->name)->asset;
			foreach([false, true] as $of) {
				$nv = $read($p, $n, $of);
				$fv = $read($p, $f, $of)->asset;
				$same($nv === null, $fv === null, "$kind $format/$limit empty single shape OF=" . (int) $of);
				if($nv instanceof Pagefiles) $same(count($nv), count($fv), "$kind empty collection count");
			}
			$p->of(false);
			$nf = $p->get($n->name);
			$ff = $p->get($f->name)->asset;
			$path = $kind === 'file' ? $textPath : $imagePath;
			for($i = 0; $i < ($limit === 1 ? 1 : 2); $i++) {
				$nf->add($path);
				$ff->add($path);
			}
			$nf->first()->description = 'A & B';
			$ff->first()->description = 'A & B';
			$nf->first()->tags = 'alpha beta';
			$ff->first()->tags = 'alpha beta';
			$nf->first()->filedata('audit', 'value');
			$ff->first()->filedata('audit', 'value');
			$p->set($n->name, $nf);
			$p->save($n->name);
			$p->save($f->name);
			$p = $wire->pages->getFresh($id);
			foreach([false, true] as $of) {
				$nv = $read($p, $n, $of);
				$fv = $read($p, $f, $of)->asset;
				$same($nv instanceof Pagefiles, $fv instanceof Pagefiles, "$kind $format/$limit populated collection shape OF=" . (int) $of);
				if($nv instanceof Pagefiles) {
					$same(count($nv), count($fv), "$kind formatted/raw count");
					$nv = $nv->first();
					$fv = $fv->first();
				}
				$same($nv->description, $fv->description, "$kind metadata formatting matches native");
				$same($nv->tags, $fv->tags, "$kind tags match native");
				$same($nv->filedata('audit'), $fv->filedata('audit'), "$kind filedata matches native");
				$same(hash_file('sha256', $nv->filename()), hash_file('sha256', $fv->filename()), "$kind independent originals have identical bytes");
				if($kind === 'image') {
					$same($nv->width(), $fv->width(), 'image width matches native');
					$same($nv->size(20, 15)->width(), $fv->size(20, 15)->width(), 'image resize matches native');
				}
			}
			$p->of(false);
			if($format === 0 && $limit === 0) {
				$nf = $p->get($n->name);
				$ff = $p->get($f->name)->asset;
				$nc = $nf->clone($nf->first());
				$fc = $ff->clone($ff->first());
				$same($nc->description, $fc->description, 'media duplicate preserves metadata like native');
				$nf->rename($nc, 'native-renamed.' . ($kind === 'file' ? 'txt' : 'png'));
				$ff->rename($fc, 'flex-renamed.' . ($kind === 'file' ? 'txt' : 'png'));
				if($kind === 'image') {
					$nf->first()->focus(25, 75);
					$ff->first()->focus(25, 75);
				}
				$p->save();
				$p = $wire->pages->getFresh($id);
				$p->of(false);
				$same(count($p->get($n->name)), count($p->get($f->name)->asset), 'media clone and rename persist like native');
				if($kind === 'image') $same($p->get($n->name)->first()->focus(), $p->get($f->name)->asset->first()->focus(), 'image focus metadata saves like native');
			}
			$p->get($n->name)->deleteAll();
			$p->get($f->name)->asset->deleteAll();
			$p->save();
			$p = $wire->pages->getFresh($id);
			$p->of(false);
			$same(count($p->get($n->name)), count($p->get($f->name)->asset), "$kind deletion saves like native");
		}
	}
}

// Keep intentional boundaries executable too; do not advertise complete Fieldtype emulation.
$n = $native('FieldtypeText');
$f = $createField('flex_plain_text', ['value' => ['type' => 'text']]);
$page->of(false);
$page->set($n->name, 'A & B');
$page->set($f->name, ['value' => 'A & B']);
$page->of(true);
$same('A & B', $page->get($n->name), 'native text without a Textformatter does not encode');
$same('A & B', $page->get($f->name)->value, 'Flex plain text matches native output without formatters');
foreach(['integer' => 'FieldtypeInteger', 'float' => 'FieldtypeFloat'] as $kind => $nativeType) {
	$n = $native($nativeType, ['zeroNotEmpty' => 0]);
	$f = $createField('flex_native_zero', ['value' => ['type' => $kind]]);
	$page->of(false);
	$page->set($n->name, 0);
	$page->set($f->name, ['value' => 0]);
	$page->save();
	$p = $wire->pages->getFresh($id);$p->of(false);
	$same($kind === 'integer' ? '' : 0.0, $p->get($n->name), "$kind native zero persistence policy is explicit");
	$same($kind === 'integer' ? 0 : 0.0, $p->get($f->name)->value, "$kind Flex always preserves explicit numeric zero");
	$same($kind === 'integer' ? '' : 0.0, $n->type->sanitizeValue($p, $n, 'invalid'), "$kind native invalid input fallback is explicit");
	$throws(fn() => $p->get($f->name)->set('value', 'invalid'), "$kind Flex invalid input is an explicit error");
}
$n = $native('FieldtypeDatetime');
$f = $createField('flex_native_date_error', ['value' => ['type' => 'datetime']]);
$same('', $n->type->sanitizeValue($page, $n, 'not a date'), 'native invalid date becomes blank');
$page->of(false);
$throws(fn() => $page->get($f->name)->set('value', 'not a date'), 'Flex invalid dates remain explicit errors');
$n = $native('FieldtypeToggle', ['formatType' => FieldtypeToggle::formatBoolean]);
$f = $createField('flex_native_toggle_modes', ['value' => ['type' => 'Toggle']]);
$page->set($n->name, 1);$page->set($f->name, ['value' => 1]);$page->of(true);
$same(true, $page->get($n->name), 'native Toggle optional boolean formatter returns bool');
$same(1, $page->get($f->name)->value, 'Flex Toggle keeps default native integer mode');
$page->of(false);

$sourceSelection = $wire->pages->newPageArray();
$sourceSelection->add($reference)->add($unpublished);
$f = $createField('flex_reference_isolation', ['ref' => ['type' => 'page', 'derefAsPage' => 0, 'allowUnpub' => 0]]);
$page->of(false);
$page->set($f->name, ['ref' => $sourceSelection]);
$same(2, count($sourceSelection), 'native hydration filtering does not mutate a supplied PageArray');
$same(1, count($page->get($f->name)->ref), 'owned hydrated PageArray applies native unpublished filtering');
$sourceSelection->add($hidden);
$same(1, count($page->get($f->name)->ref), 'bulk-assigned PageArray retains independent Flex ownership');
