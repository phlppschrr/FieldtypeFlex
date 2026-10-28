<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$meta = [
	'body' => ['type' => 'TinyMCE', 'inheritField' => $sourceField->name, 'required' => true],
	'html' => ['type' => 'textarea', 'contentType' => FieldtypeTextarea::contentTypeHTML],
	'token' => ['type' => 'hidden'],
	'help' => ['type' => 'markup', 'value' => '<p>Metadata help</p>'],
	'author' => ['type' => 'page', 'inputfield' => 'InputfieldSelect', 'derefAsPage' => 2, 'parent_id' => 1],
	'contributors' => ['type' => 'page', 'inputfield' => 'InputfieldAsmSelect', 'derefAsPage' => 0, 'parent_id' => 1],
];
$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/metadata-inputs.png');
$im = imagecreatetruecolor(32, 32); imagepng($im, $fixture); imagedestroy($im);
$reference = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => 'metadata-reference-' . bin2hex(random_bytes(4)), 'title' => 'Metadata reference']);
$reference->save(); $pages[] = $reference; $referenceId = (int) $reference->id;
foreach(['image', 'file'] as $kind) {
	$f = $createField('flex_media_inputs', ['asset' => ['type' => $kind, 'extensions' => 'png', 'customFields' => $meta]]);
	$page = $wire->pages->getFresh($id); $page->of(false);
	$value = $page->get($f->name); $value->asset->add($fixture); $file = $value->asset->first();
	$file->body = '<p>A <strong>rich</strong> caption</p>';
	$file->html = '<p>Plain HTML textarea</p>'; $file->token = 'hidden-value';
	$file->author = $second; $file->contributors = [$referenceId];
	$page->save($f->name);
	$page = $wire->pages->getFresh($id); $page->of(false);
	$value = $page->get($f->name); $file = $value->asset->first();
	$same('<p>A <strong>rich</strong> caption</p>', $file->body, "$kind rich text roundtrip preserves raw HTML");
	$same('<p>Plain HTML textarea</p>', $file->html, "$kind HTML textarea roundtrip");
	$same('hidden-value', $file->token, "$kind hidden metadata roundtrip");
	$same((int) $second->id, (int) $file->author->id, "$kind single reference hydrates a Page");
	$assert($file->contributors instanceof PageArray, "$kind multi reference hydrates PageArray");
	$same([$referenceId], array_values($file->contributors->explode('id')), "$kind multi reference retains target IDs");
	$raw = $value->rawArray()['asset']['items'][0]['values'];
	$same((int) $second->id, $raw['author'], "$kind single reference persists numeric ID");
	$same([$referenceId], $raw['contributors'], "$kind multi reference persists ID list");
	$assert(!array_key_exists('help', $raw), "$kind Markup has no stored value");
	$file->contributors->add($second);
	$assert($file->isChanged() && $value->isChanged(), "$kind PageArray mutation propagates change tracking");
	$file->save();
	$same([$referenceId, (int) $second->id], array_values($wire->pages->getFresh($id)->getUnformatted($f->name)->asset->first()->contributors->explode('id')), "$kind file save persists PageArray mutation");
	$previousContributors = $file->contributors;
	$assetId = $file->assetId();
	$file->contributors = '-' . $second->id;
	$same([$referenceId], array_values($file->contributors->explode('id')), "$kind negative ID removes only selected metadata reference");
	$same([$referenceId, (int) $second->id], array_values($previousContributors->explode('id')), "$kind negative ID leaves prior metadata container intact");
	$file->save();
	$read = $wire->pages->getFresh($id)->getUnformatted($f->name)->asset->first();
	$same([$referenceId], array_values($read->contributors->explode('id')), "$kind negative reference removal persists through selective file save");
	$same($assetId, $read->assetId(), "$kind reference removal retains asset identity");
	$file->contributors = $second;
	$file->save();
	$copy = $value->asset->clone($file); $copy->contributors->remove($second);
	$same(2, count($file->contributors), "$kind file duplication isolates reference containers");
	$cloned = clone $value; $cloned->asset->first()->contributors->remove($second);
	$same(2, count($file->contributors), "$kind cloned Flex view isolates reference containers");
	$page->of(true); $out = $page->get($f->name)->asset->first();
	$out->contributors->remove($second); $out->getFieldValue('contributors', false)->remove($second);
	$same(2, count($file->contributors), "$kind formatted reads and raw bridge isolate reference containers");
	$page->of(false);
	if($kind === 'image') {
		$variation = $file->size(16, 16); $variation->contributors->remove($second);
		$same(2, count($file->contributors), 'variation cannot mutate original PageArray metadata');
	}
	$schema = $type->getDefinitions($f, $page);
	$input = $schema->getInputfields($value, $f->name . '__', '', $value, null, $f->name)->getChildByName($f->name . '__asset');
	$input->renderReady();
	$inputs = $input->getItemInputfields($file);
	$prefix = FlexPath::inputPrefix($f->name, 'asset.' . $file->assetId());
	$assert($inputs->getChildByName($prefix . 'body') instanceof InputfieldTinyMCE, "$kind native rich editor constructed");
	$assert(str_contains($inputs->render(), 'data-flex-metadata-property="token"'), "$kind hidden transport carries its metadata scope without a wrapper");
	$same(7, (int) $inputs->getChildByName($prefix . 'body')->rows, "$kind rich editor inherits source settings");
	$assert(str_contains($inputs->render(), 'Metadata help'), "$kind native Markup renders");
	$process = new \ReflectionMethod($input, '___processItemInputfields'); $process->setAccessible(true);
	$post = [FlexPath::controlName($f->name, 'asset.' . $file->assetId(), 'metadata') => 1,
		$prefix . 'body' => '<p>Editor <em>value</em></p>', $prefix . 'html' => '<p>HTML edit</p>',
		$prefix . 'token' => 'posted-hidden', $prefix . 'author' => $referenceId, $prefix . 'contributors' => [$second->id],
	];
	$process->invoke($input, $file, $inputs, 'asset_' . $file->hash, $wire->wire(new WireInputData($post)));
	$same([], $input->getErrors(), "$kind native metadata submission accepts rich text and references");
	$same('<p>Editor <em>value</em></p>', $file->body, "$kind native TinyMCE processing retains markup");
	$same('posted-hidden', $file->token, "$kind Hidden is processed as an ordinary metadata value");
	$same($referenceId, (int) $file->author->id, "$kind native selection replaces single reference");
	$same([(int) $second->id], array_values($file->contributors->explode('id')), "$kind native selection replaces PageArray");
	$post[$prefix . 'body'] = ''; unset($post[$prefix . 'contributors']); $post[$prefix . 'author'] = '';
	$process->invoke($input, $file, $inputs, 'asset_' . $file->hash, $wire->wire(new WireInputData($post)));
	$assert((bool) $input->getErrors(), "$kind required rich metadata rejects blank input");
	$same(0, count($file->contributors), "$kind omitted multi-reference clears selection");
	$assert($file->author instanceof NullPage, "$kind blank single reference follows derefAsPage");
	$input->getErrors(true); $inputs->getErrors(true);
	$post[$prefix . 'body'] = '<p>Recovered rich text</p>';
	$process->invoke($input, $file, $inputs, 'asset_' . $file->hash, $wire->wire(new WireInputData($post)));
	$same([], $input->getErrors(), "$kind required rich metadata recovers");
	$file->save();
	$same('<p>Recovered rich text</p>', $wire->pages->getFresh($id)->getUnformatted($f->name)->asset->first()->body, "$kind rich metadata persists after error recovery");
	$throws(fn() => $wire->pages->findIDs("id=$id, {$f->name}.asset.author.title=Second"), "$kind Page metadata traversal remains unsupported", PageFinderSyntaxException::class);
}

// Layout-only customFields must render even without persisted properties.
$layout = new FlexFieldDefs($f, $page, ['asset' => ['type' => 'image', 'customFields' => ['help' => $meta['help']]]]);
$layoutValue = new FlexValue($page, $f, $layout); $layoutValue->asset->add($fixture);
$layoutInput = $layout->getInputfields($layoutValue, 'layout__')->getChildByName('layout__asset');
$assert(str_contains($layoutInput->getItemInputfields($layoutValue->asset->first())->render(), 'Metadata help'), 'Markup-only metadata renders without stored leaves');
foreach(['description', 'tags'] as $name) {
	$throws(fn() => new FlexFieldDefs($f, $page, ['asset' => ['type' => 'image', 'customFields' => [$name => $meta['help']]]]), "Markup cannot replace native $name");
}
$richDescription = new FlexFieldDefs($f, $page, ['asset' => ['type' => 'image', 'customFields' => ['description' => ['type' => 'TinyMCE']]]]);
$same('InputfieldTinyMCE', $richDescription->property('asset.description')['input']->className(), 'native description supports a rich editor');
foreach(['InputfieldPageAutocomplete', 'InputfieldPageListSelect', 'InputfieldPageListSelectMultiple'] as $widget) {
	$throws(fn() => new FlexFieldDefs($f, $page, ['asset' => ['type' => 'image', 'customFields' => ['author' => ['type' => 'page', 'inputfield' => $widget]]]]), "$widget is outside basic metadata Page support", FlexDefinitionException::class);
}
$defaultPage = new FlexFieldDefs($f, $page, ['asset' => ['type' => 'image', 'customFields' => ['author' => ['type' => 'page']]]]);
$same('InputfieldSelect', $defaultPage->property('asset.author')['input']->inputfield, 'Page metadata defaults to basic Select widget');

// Language/formatting and native wrapper definitions use the ordinary Flex pipeline.
$richSchema = ['asset' => ['type' => 'image', 'customFields' => [
	'body' => ['type' => 'TinyMCE', 'useLanguages' => true, 'textformatters' => ['TextformatterEntities']],
	'token' => $meta['token'], 'help' => $meta['help'], 'author' => $meta['author'],
]]];
$richDefs = new FlexFieldDefs($f, $page, $richSchema);
$richValue = new FlexValue($page, $f, $richDefs); $richValue->asset->add($fixture); $richFile = $richValue->asset->first();
$richFile->body = [$defaultLanguage->id => '<p>Default & HTML</p>', $language->id => '<p>Deutsch</p>'];
$same('<p>Deutsch</p>', $richFile->body->getLanguageValue($language), 'rich metadata supports language values');
$same('&lt;p&gt;Default &amp; HTML&lt;/p&gt;', $richFile->getFieldValue('body', true), 'rich metadata applies explicit formatters without changing raw HTML');
$same('<p>Default & HTML</p>', $richFile->rawCustomValues()['body'][$defaultLanguage->id], 'formatted rich metadata leaves canonical language map raw');
$native = $wire->wire(new InputfieldWrapper());
foreach($richSchema['asset']['customFields'] as $name => $settings) {
	$child = $wire->modules->get('Inputfield' . ($settings['type'] === 'page' ? 'Page' : ($settings['type'] === 'hidden' ? 'Hidden' : ($settings['type'] === 'markup' ? 'Markup' : $settings['type']))));
	foreach($settings as $key => $setting) if($key !== 'type') $child->set($key, $setting);
	$child->name = $name; $native->add($child);
}
$nativeImage = $wire->modules->get('InputfieldImage'); $nativeImage->name = 'asset'; $nativeImage->customFields = $native;
$nativeRoot = $wire->wire(new InputfieldWrapper()); $nativeRoot->add($nativeImage);
$nativeDefs = new FlexFieldDefs($f, $page, $nativeRoot);
$same('InputfieldTinyMCE', $nativeDefs->property('asset.body')['input']->className(), 'native wrapper compiles rich metadata');
$same('page', $nativeDefs->property('asset.author')['kind'], 'native wrapper compiles Page metadata');
$same('InputfieldHidden', $nativeDefs->property('asset.token')['input']->className(), 'native wrapper compiles Hidden metadata');

$unpublished = $wire->pages->newPage(['template' => $template, 'parent' => 1, 'name' => 'metadata-unpublished-' . bin2hex(random_bytes(4)), 'title' => 'Unpublished metadata target']);
$unpublished->addStatus(Page::statusUnpublished); $unpublished->save(); $pages[] = $unpublished;
$shape = static fn($value) => $value instanceof PageArray ? ['PageArray', array_values($value->explode('id'))] : ($value instanceof Page ? [$value->className(), (int) $value->id] : $value);
foreach([0, 1, 2] as $mode) foreach([0, 1] as $allow) {
	$settings = ['derefAsPage' => $mode, 'allowUnpub' => $allow, 'inputfield' => $mode ? 'InputfieldSelect' : 'InputfieldAsmSelect', 'parent_id' => 1];
	$defs = new FlexFieldDefs($f, $page, ['asset' => ['type' => 'image', 'customFields' => ['candidate' => ['type' => 'page'] + $settings]]]);
	$nativeField = $wire->wire(new Field()); $nativeField->name = 'candidate'; $nativeField->type = $wire->modules->get('FieldtypePage');
	foreach($settings as $key => $setting) $nativeField->set($key, $setting);
	foreach(['blank' => null, 'published' => $second, 'unpublished' => $unpublished] as $case => $target) {
		$raw = $richValue->rawArray(); $raw['asset']['items'][0]['values'] = ['candidate' => $mode ? ($target ? (int) $target->id : 0) : ($target ? [(int) $target->id] : [])];
		$view = new FlexValue($page, $f, $defs, $raw);
		$nativeValue = $nativeField->type->wakeupValue($page, $nativeField, $raw['asset']['items'][0]['values']['candidate']);
		foreach([false, true] as $formatted) {
			$expected = $formatted ? $nativeField->type->formatValue($page, $nativeField, $nativeValue) : $nativeValue;
			$same($shape($expected), $shape($view->asset->first()->getFieldValue('candidate', $formatted)), "Page metadata matches native mode=$mode allowUnpub=$allow $case formatting=" . (int) $formatted);
		}
	}
}
