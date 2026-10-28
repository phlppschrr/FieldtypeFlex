<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

if(PHP_SAPI !== 'cli' || !isset($same, $createField, $temporaryFields, $pages)) {
	http_response_code(404);
	exit;
}

$typedField = $createField('flex_typed_rows', [
	'blocks' => [
		'type'        => 'rows',
		'maxItems'    => 12,
		'rowCollapse' => 0,
		'types'       => [
			'text' => [
				'label'    => 'Text',
				'children' => [
					'value' => ['type' => 'text'],
					'type'  => ['type' => 'text'],
				],
			],
			'number' => [
				'label'    => 'Number',
				'children' => [
					'value' => ['type' => 'integer'],
				],
			],
			'image' => [
				'label'    => 'Image',
				'children' => [
					'caption' => ['type' => 'text'],
					'photo'   => ['type' => 'image'],
				],
			],
		],
	],
	'summary' => ['type' => 'text'],
]);
$typedName = $typedField->name;
$typedPage = $wire->pages->getFresh($id);
$typedPage->of(false);
$typedValue = $type->getBlankValue($typedPage, $typedField);
$typedRows = $typedValue->blocks;
$typedTextRow = $typedRows->addRow('text', ['value' => 'Seventeen', 'type' => 'Editorial text']);
$typedNumberRow = $typedRows->addRow('number', ['value' => 17]);
$typedImagePath = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-typed-fixture.png');
$typedFixtureImage = imagecreatetruecolor(16, 16);
imagepng($typedFixtureImage, $typedImagePath);
imagedestroy($typedFixtureImage);
$typedImageRow = $typedRows->addRow('image', ['caption' => 'With photo']);
$typedImageRow->photo->add($typedImagePath);
$typedEmptyImageRow = $typedRows->addRow('image', ['caption' => 'No photo']);
$typedCopy = $typedRows->duplicateRow($typedNumberRow->rowId());
$typedCopyId = $typedCopy->rowId();
$assert($typedCopy->rowType() === 'number' && $typedCopyId !== $typedNumberRow->rowId(), 'duplicating a row preserves its type and creates a new identity');
$same(17, $typedCopy->value, 'duplicate row copies the value through its type schema');
$same('number', $typedNumberRow->rowType(), 'technical row type is available through rowType()');
$same('Editorial text', $typedTextRow->type, 'a user property named type remains independent of the technical row type');
$typedRows->moveRow($typedCopyId, $typedTextRow->rowId());
$typedRows->moveRow($typedNumberRow->rowId());
$typedPage->{$typedName} = $typedValue;
$typedPage->save($typedName);
$typedBlankMediaPage = $wire->pages->newPage([
	'template' => $template,
	'parent'   => 1,
	'name'     => 'flex-typed-blank-media-' . bin2hex(random_bytes(4)),
	'title'    => 'Typed row without media',
]);
$typedBlankMediaPage->of(false);
$typedBlankMediaValue = $type->getBlankValue($typedBlankMediaPage, $typedField);
$typedBlankMediaValue->blocks->addRow('image', ['caption' => 'Only blank media']);
$typedBlankMediaPage->{$typedName} = $typedBlankMediaValue;
$typedBlankMediaPage->save();
$pages[] = $typedBlankMediaPage;
$typedBlankMediaId = $typedBlankMediaPage->id;

$typedFreshPage = $wire->pages->getFresh($id);
$typedFreshPage->of(false);
$typedSaved = $typedFreshPage->getUnformatted($typedName);
$typedEnvelope = $typedSaved->rawArray()['blocks'];
$same('rows:1', $typedEnvelope['_flex'], 'typed rows use the first public envelope');
$same(['id', 'type', 'values'], array_keys($typedEnvelope['items'][0]), 'each typed row stores identity, type and values');
$same($typedCopyId, $typedEnvelope['items'][0]['id'], 'reordering keeps the copied row identity');
$same('number', $typedEnvelope['items'][0]['type'], 'reordering keeps the copied row type');
$same(17, $typedEnvelope['items'][0]['values']['value'], 'same-named typed leaf persists as an integer in its own type');
$same('text', $typedEnvelope['items'][1]['type'], 'text row type survives reordering and reload');
$same('Seventeen', $typedEnvelope['items'][1]['values']['value'], 'same-named typed leaf persists as text in its own type');
$same('Editorial text', $typedEnvelope['items'][1]['values']['type'], 'user property type is stored in the row values');
$same('number', $typedSaved->blocks->getById($typedNumberRow->rowId())->rowType(), 'the original row type remains stable after reordering');
$same('image', $typedSaved->blocks->getById($typedImageRow->rowId())->rowType(), 'image row type survives save and reload');
$same('image', $typedSaved->blocks->getById($typedEmptyImageRow->rowId())->rowType(), 'each row retains its explicit type after reload');
$same(5, count($typedSaved->blocks), 'collection count includes rows of every type');

$typedDefs = $type->getDefinitions($typedField, $typedFreshPage);
$textProperty = $typedDefs->property('blocks.text.value');
$numberProperty = $typedDefs->property('blocks.number.value');
$same('text', $textProperty['kind'], 'property path resolves the text type schema');
$same('integer', $numberProperty['kind'], 'same property path resolves the integer type schema');
$same(null, $typedDefs->property('blocks.missing.value'), 'unknown type property path returns nullable metadata');
$assert($typedDefs->getPropertyInputfield('blocks.text.value') instanceof InputfieldText, 'property inputfield path resolves the text type');
$assert($typedDefs->getPropertyInputfield('blocks.number.value') instanceof InputfieldInteger, 'property inputfield path resolves the integer type');
$throws(fn() => $typedDefs->rowType('blocks', 'missing'), 'strict row type resolver rejects an unknown type');

$freshDefs = $type->getDefinitions($typedField, $typedFreshPage);
$foreignDefinitionRows = new FlexRows($typedFreshPage, $typedField, 'blocks', $freshDefs->property('blocks'));
$definitionBoundRow = new FlexRow(
	$typedFreshPage,
	$typedField,
	$typedDefs->rowType('blocks', 'text')['schema'],
	'blocks',
	bin2hex(random_bytes(16)),
	'text',
	['value' => 'Different definition object']
);
$throws(fn() => $foreignDefinitionRows->add($definitionBoundRow), 'row collection rejects a row created from another definitions object');

$typedBlankPage = $wire->pages->getFresh($second->id);
$typedBlankPage->of(false);
$typedBlank = $typedBlankPage->getUnformatted($typedName);
$assert($type->isEmptyValue($typedField, $typedBlank), 'an absent typed row set remains logically empty');
$same(0, count($typedBlank->blocks), 'a page without a stored set has no typed rows');
$typedIds = "$id|$second->id|$typedBlankMediaId";
$findTyped = static function(string $selector, ?int $pageId = null) use($wire, $typedIds): array {
	$scope = $pageId ?? $typedIds;
	return $wire->pages->findIDs("id=$scope, $selector");
};
$same([$id], $findTyped("$typedName.blocks.text.value=Seventeen"), 'text leaf query is scoped to its type');
$same([$id], $findTyped("$typedName.blocks.number.value=17"), 'integer leaf query is scoped to its type');
$same([], $findTyped("$typedName.blocks.text.value=17"), 'same-named numeric data does not satisfy the text type query');
$same([$id], $findTyped("$typedName.blocks.number.value>10"), 'numeric selectors use the integer type schema');
$same([$id], $findTyped("$typedName.blocks._type=text"), 'set type selector finds a present type');
$same([$id], $findTyped("$typedName.blocks._type=text|number"), 'native type value lists match known types');
$same([$second->id], $findTyped("$typedName.blocks._type=", $second->id), 'blank set type selector finds an empty collection');
$same([$id], $findTyped("$typedName.blocks._type!=", $id), 'negative blank set type selector finds a populated collection');
$same([$typedBlankMediaId], $findTyped("$typedName.blocks._type!=text|number", $typedBlankMediaId), 'negative type value lists exclude every named type');
$same([$second->id], $findTyped("$typedName.blocks._type!=text", $second->id), 'negative set type selector includes a page without typed rows');
$same([$typedBlankMediaId], $findTyped("$typedName.blocks._type!=text", $typedBlankMediaId), 'negative set type selector includes a page with only a different type');
$same([$id], $findTyped("$typedName.blocks.count=5"), 'total row count includes all defined types');
$same([$id], $findTyped("$typedName.blocks.number.count=2"), 'type count includes only rows of that type');
$same([$id], $findTyped("$typedName.blocks.image.count=2"), 'image type count excludes other types');
$same([$id], $findTyped("$typedName.blocks.image.photo.count>0"), 'typed media count finds populated image rows');
$same([$id], $findTyped("$typedName.blocks.image.photo.count=0", $id), 'numeric media count zero matches an existing empty media row');
$same([$typedBlankMediaId], $findTyped("$typedName.blocks.image.photo.count=0", $typedBlankMediaId), 'numeric media count zero matches an empty media row');
$same([], $findTyped("$typedName.blocks.image.photo.count=0", $second->id), 'numeric media count zero needs a row of the requested type');
$same([$id], $findTyped("$typedName.blocks.image.caption=With photo, $typedName.blocks.image.photo.count=0"), 'independent typed selectors may match different image rows');
$same([], $findTyped("$typedName.blocks.image=\"caption=With photo, photo.count=0\""), 'same-row typed query rejects conditions met by different rows');
$same([$id], $findTyped("$typedName.blocks.image=\"caption=No photo, photo.count=0\""), 'same-row typed query matches one blank media row');
$same([$id], $findTyped("$typedName.blocks.image!=\"caption=With photo, photo.count=0\"", $id), 'negative same-row query is true when no row satisfies both conditions');
$same([$id], $findTyped("$typedName.blocks.image!=\"caption=Missing, photo.count>0\"", $id), 'negative media quantifier is true when no typed row satisfies its predicate');
$same([], $findTyped("$typedName.blocks.image!=\"caption=No photo, photo.count=0\"", $id), 'negative same-row query is false when a row satisfies both conditions');
$same([$second->id], $findTyped("$typedName.blocks.image!=\"photo.count>0\"", $second->id), 'negative media quantifier includes a page without typed rows');
$same([$typedBlankMediaId], $findTyped("$typedName.blocks.image!=\"photo.count>0\"", $typedBlankMediaId), 'negative media quantifier includes a blank media row');
$same([$second->id], $findTyped("$typedName.blocks.image.photo=", $second->id), 'blank typed media selector includes a page without typed rows');
$same([$typedBlankMediaId], $findTyped("$typedName.blocks.image.photo=", $typedBlankMediaId), 'blank typed media selector finds a type with no populated media');
$same([], $findTyped("$typedName.blocks.image.photo=", $id), 'blank typed media selector excludes a populated media row');
$same([$second->id], $findTyped("$typedName.blocks.image.count=0", $second->id), 'per-type row count zero includes an absent set projection');
$same([$second->id], $findTyped("$typedName.blocks.text.value!=Seventeen", $second->id), 'negative typed leaf includes a page without any set row');
$same([$typedBlankMediaId], $findTyped("$typedName.blocks.text.value=", $typedBlankMediaId), 'blank typed leaf ignores other row types');
$same([], $findTyped("$typedName.blocks.text=\"value!=Seventeen\"", $id), 'inner negative predicate tests an existing row of the specified type');
$same([$id], $findTyped("$typedName.blocks.number=\"value!=18\"", $id), 'inner negative predicate uses the numeric type schema');
$throws(fn() => $findTyped("$typedName.blocks._type=missing"), 'type selector rejects unknown type names');
$throws(fn() => $findTyped("$typedName.blocks._type*=text"), 'type selector rejects unsupported operators');
$throws(fn() => $findTyped("$typedName.blocks=\"value=Seventeen\""), 'nonblank same-row selector requires an explicit type');
$throws(fn() => $findTyped("$typedName.blocks.text.value.count=1"), 'scalar typed leaf rejects a media count suffix');
$typedInfo = $type->getSelectorInfo($typedField);
$same('text', $typedInfo['subfields']['blocks.text.value']['input'], 'selector discovery keeps the text type leaf');
$same('number', $typedInfo['subfields']['blocks.number.value']['input'], 'selector discovery keeps the numeric type leaf');
$same('number', $typedInfo['subfields']['blocks.image.photo.count']['input'], 'selector discovery exposes typed media count');
$same('text', $typedInfo['subfields']['blocks.text.type']['input'], 'selector discovery exposes a user leaf named type');
$same('number', $typedInfo['subfields']['blocks.image.count']['input'], 'selector discovery exposes per-type row count');
$same('number', $typedInfo['subfields']['blocks.count']['input'], 'selector discovery retains total row count');
$assert(isset($typedInfo['subfields']['blocks._type']), 'selector discovery exposes the type selector');
$same(['""' => 'No rows', 'text' => 'Text', 'number' => 'Number', 'image' => 'Image'], $typedInfo['subfields']['blocks._type']['options'], 'type selector options include only blank and declared row types');
$same('text', $typedInfo['subfields']['blocks.text']['input'], 'type group accepts blank and same-row selector input');
$same('select', $typedInfo['subfields']['blocks']['input'], 'row set exposes a blank collection control');
$same(['""' => 'No rows'], $typedInfo['subfields']['blocks']['options'], 'row set blank control has no unsupported value choices');
$same('select', $typedInfo['subfields']['blocks.image.photo']['input'], 'typed media exposes only its blank selector');
$same(['""' => 'No media'], $typedInfo['subfields']['blocks.image.photo']['options'], 'typed media blank control has no unsupported value choices');
$same('text', $typedInfo['subfields']['summary']['input'], 'selector discovery retains root scalar leaves');
$same([], $typedInfo['subfields']['blocks.text.value']['subfields'], 'native selector leaf descriptors do not expose unsupported subselector aliases');
$assert(count(array_filter($typedInfo['subfields'], static fn(array $descriptor): bool => !empty($descriptor['subfields']))) === 0, 'native selector metadata is a flat suffix map');
$same('text', $typedInfo['flexTree']['blocks']['subfields']['text']['subfields']['value']['input'], 'hierarchical selector metadata remains available separately');

$renderNativeTypedSelector = static function(string $selectorValue) use($wire, $typedName): \DOMXPath {
	$input = $wire->modules->getModule('InputfieldSelector', ['noCache' => true]);
	$input->name = 'typed_selector';
	$input->value = $selectorValue;
	$document = new \DOMDocument('1.0', 'UTF-8');
	$previousErrors = libxml_use_internal_errors(true);
	try {
		$document->loadHTML('<!doctype html><html><body>' . $input->render() . '</body></html>', LIBXML_NONET);
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors($previousErrors);
	}
	return new \DOMXPath($document);
};
$selectedSubfield = static function(\DOMXPath $xpath): string {
	$options = $xpath->query("//select[contains(concat(' ', normalize-space(@class), ' '), ' select-subfield ')]/option[@selected]");
	return $options && $options->length ? $options->item(0)->getAttribute('value') : '';
};
$selectorWithNumber = $renderNativeTypedSelector("$typedName.blocks.number.value=17");
$same("$typedName.blocks.number.value", $selectedSubfield($selectorWithNumber), 'native selector retains the selected full typed-leaf path');
$numericValue = $selectorWithNumber->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' selector-row ')][not(contains(concat(' ', normalize-space(@class), ' '), ' selector-template-row '))]//input[contains(concat(' ', normalize-space(@class), ' '), ' input-value-number ')]");
$assert($numericValue && $numericValue->length === 1, 'native selector renders typed integer leaves with numeric value controls');
$selectorWithTypes = $renderNativeTypedSelector("$typedName.blocks._type=image");
$same("$typedName.blocks._type", $selectedSubfield($selectorWithTypes), 'native selector retains the selected row-type path');
$typeOptions = $selectorWithTypes->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' selector-row ')][not(contains(concat(' ', normalize-space(@class), ' '), ' selector-template-row '))]//select[contains(concat(' ', normalize-space(@class), ' '), ' select-value ')]/option");
$renderedTypeOptions = [];
if($typeOptions) {
	foreach($typeOptions as $option) {
		$renderedTypeOptions[$option->getAttribute('value')] = trim($option->textContent);
	}
}
$assert(isset($renderedTypeOptions['""'], $renderedTypeOptions['text'], $renderedTypeOptions['number'], $renderedTypeOptions['image']) && !isset($renderedTypeOptions['0']), 'native selector renders only blank and declared row-type choices');
$selectorWithEmptySet = $renderNativeTypedSelector("$typedName.blocks=");
$same("$typedName.blocks", $selectedSubfield($selectorWithEmptySet), 'native selector retains the blank row-set path');
$setBlankOptions = $selectorWithEmptySet->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' selector-row ')][not(contains(concat(' ', normalize-space(@class), ' '), ' selector-template-row '))]//select[contains(concat(' ', normalize-space(@class), ' '), ' select-value ')]/option");
$renderedSetBlankOptions = [];
if($setBlankOptions) {
	foreach($setBlankOptions as $option) {
		$renderedSetBlankOptions[$option->getAttribute('value')] = trim($option->textContent);
	}
}
$same('No rows', $renderedSetBlankOptions['""'] ?? '', 'native selector renders only the blank row-set choice');
$selectorWithMediaCount = $renderNativeTypedSelector("$typedName.blocks.image.photo.count>0");
$same("$typedName.blocks.image.photo.count", $selectedSubfield($selectorWithMediaCount), 'native selector retains the complete typed-media count path');
$mediaCountValue = $selectorWithMediaCount->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' selector-row ')][not(contains(concat(' ', normalize-space(@class), ' '), ' selector-template-row '))]//input[contains(concat(' ', normalize-space(@class), ' '), ' input-value-number ')]");
$assert($mediaCountValue && $mediaCountValue->length === 1, 'native selector renders typed media counts with numeric value controls');
$selectorWithBlankMedia = $renderNativeTypedSelector("$typedName.blocks.image.photo=");
$same("$typedName.blocks.image.photo", $selectedSubfield($selectorWithBlankMedia), 'native selector retains the blank typed-media path');
$blankMediaOptions = $selectorWithBlankMedia->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' selector-row ')][not(contains(concat(' ', normalize-space(@class), ' '), ' selector-template-row '))]//select[contains(concat(' ', normalize-space(@class), ' '), ' select-value ')]/option");
$renderedBlankMediaOptions = [];
if($blankMediaOptions) {
	foreach($blankMediaOptions as $option) {
		$renderedBlankMediaOptions[$option->getAttribute('value')] = trim($option->textContent);
	}
}
$same('No media', $renderedBlankMediaOptions['""'] ?? '', 'native selector renders only the blank media choice');
$selectorWithTypeGroup = $renderNativeTypedSelector("$typedName.blocks.text=\"value=Seventeen\"");
$same("$typedName.blocks.text", $selectedSubfield($selectorWithTypeGroup), 'native selector exposes the type-group blank and same-row control');
$typeGroupValue = $selectorWithTypeGroup->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' selector-row ')][not(contains(concat(' ', normalize-space(@class), ' '), ' selector-template-row '))]//input[contains(concat(' ', normalize-space(@class), ' '), ' input-value-text ')]");
$assert($typeGroupValue && $typeGroupValue->length === 1, 'native selector uses text input for same-row type expressions');
$nativeSubfieldOptions = $selectorWithTypeGroup->query("//select[contains(concat(' ', normalize-space(@class), ' '), ' select-subfield ')]/option");
$hasGeneratedIdAlias = false;
$typedLeafLabels = [];
if($nativeSubfieldOptions) {
	foreach($nativeSubfieldOptions as $option) {
		$path = $option->getAttribute('value');
		if($path === "$typedName.blocks.text.value" || $path === "$typedName.blocks.number.value") {
			$typedLeafLabels[$path] = trim($option->textContent);
		}
		if(str_ends_with($path, '.id')) {
			$hasGeneratedIdAlias = true;
		}
	}
}
$same('Blocks / Text / Value', $typedLeafLabels["$typedName.blocks.text.value"] ?? '', 'native selector labels the text leaf with its set and type');
$same('Blocks / Number / Value', $typedLeafLabels["$typedName.blocks.number.value"] ?? '', 'native selector distinguishes the same-named numeric leaf');
$assert(!$hasGeneratedIdAlias, 'native selector does not synthesize unsupported row .id aliases');

$typedOriginalDefinition = $typedField->flexDefinition;
$typedLabelSchema = json_decode($typedOriginalDefinition, true, 512, JSON_THROW_ON_ERROR);
$typedMarkupLabel = '<b>Text & image</b><img src=x onerror=alert(1)>';
$typedLabelSchema['blocks']['types']['text']['label'] = $typedMarkupLabel;
$typedField->flexDefinition = json_encode($typedLabelSchema, JSON_THROW_ON_ERROR);
try {
	$selectorWithMarkupLabel = $renderNativeTypedSelector("$typedName.blocks._type=text");
	$labelOptions = $selectorWithMarkupLabel->query("//select[contains(concat(' ', normalize-space(@class), ' '), ' select-value ')]/option[@value='text']");
	$same($typedMarkupLabel, $labelOptions->item(0)->textContent, 'native type selector displays HTML-looking labels as literal text');
	$same(0, $labelOptions->item(0)->getElementsByTagName('*')->length, 'native type selector labels cannot inject markup');
} finally {
	$typedField->flexDefinition = $typedOriginalDefinition;
}

$nativeTypeChildren = $wire->wire(new InputfieldWrapper());
$nativeTypeValue = $wire->wire(new InputfieldText());
$nativeTypeValue->name = 'value';
$nativeTypeValue->label = 'Native wrapper value';
$nativeTypeChildren->add($nativeTypeValue);
$nativeWrapperDefs = new FlexFieldDefs($typedField, $typedFreshPage, [
	'blocks' => [
		'type'       => 'rows',
		'types'      => [
			'native' => [
				'label'    => 'Native',
				'children' => $nativeTypeChildren,
			],
		],
	],
]);
$same('text', $nativeWrapperDefs->property('blocks.native.value')['kind'], 'repeatable type children accept a native InputfieldWrapper');
$invalidNativeWrapper = $wire->wire(new InputfieldWrapper());
$invalidNativeLeaf = $wire->wire(new InputfieldText());
$invalidNativeLeaf->name = 'value';
$invalidNativeLeaf->types = ['text' => ['children' => []]];
$invalidNativeWrapper->add($invalidNativeLeaf);
$throws(fn() => new FlexFieldDefs($typedField, $typedFreshPage, $invalidNativeWrapper), 'native scalar input rejects row type configuration');

$typedEditor = $typedField->getInputfield($typedFreshPage);
$typedEditor->value = $typedSaved;
$typedRowsBeforeReady = $typedSaved->rawArray();
$assetCountForPageField = static function() use($wire, $typedFreshPage, $typedField): int {
	$q = $wire->database->pdo('writer')->prepare('SELECT COUNT(*) FROM fieldtype_flex_assets WHERE pages_id=:page AND fields_id=:field');
	$q->execute(['page' => $typedFreshPage->id, 'field' => $typedField->id]);
	return (int) $q->fetchColumn();
};
$typedAssetsBeforeReady = $assetCountForPageField();
$typedEditor->renderReady();
$same($typedRowsBeforeReady, $typedSaved->rawArray(), 'renderReady prepares every declared row type without allocating rows');
$same($typedAssetsBeforeReady, $assetCountForPageField(), 'renderReady does not allocate media ownership records');

$throws(fn() => $typedBlank->blocks->addRow('missing'), 'unknown row type cannot be created');
$typedId = bin2hex(random_bytes(16));
$throwsExact = static function(string $class, callable $fn, string $message) use($assert): void {
	try {
		$fn();
	} catch(\Throwable $error) {
		$assert($error instanceof $class, $message . ' (received ' . get_class($error) . ')');
		return;
	}
	$assert(false, $message);
};
$throws(fn() => $type->sanitizeValue($typedBlankPage, $typedField, [
	'blocks' => [
		'_flex' => 'rows:1',
		'items' => [[
			'id'     => $typedId,
			'type'   => 'missing',
			'values' => [],
		]],
	],
]), 'unknown stored row type is rejected');
$throws(fn() => $type->sanitizeValue($typedBlankPage, $typedField, [
	'blocks' => [
		'_flex' => 'rows:999',
		'items' => [[
			'id'     => $typedId,
			'values' => ['value' => 'Legacy'],
		]],
	],
]), 'unknown row format is explicitly rejected');

$replacementRaw = $typedEnvelope;
$replacementRaw['items'][0]['type'] = 'text';
$typeReplacementPage = $wire->pages->getFresh($id);
$typeReplacementPage->of(false);
$typeReplacementPage->{$typedName} = ['blocks' => $replacementRaw];
$throwsExact(WireException::class, fn() => $typeReplacementPage->save($typedName), 'saving raw replacement of a persisted row type is rejected');
$replacementCheck = $wire->pages->getFresh($id)->getUnformatted($typedName)->rawArray()['blocks'];
$same($typedEnvelope, $replacementCheck, 'rejected row type replacement leaves canonical storage unchanged');

$removedTypeSchema = json_decode($typedField->flexDefinition, true, 512, JSON_THROW_ON_ERROR);
unset($removedTypeSchema['blocks']['types']['image']);
$removedTypeField = clone $typedField;
$removedTypeField->flexPrune = 0;
$removedTypeField->flexDefinition = json_encode($removedTypeSchema, JSON_THROW_ON_ERROR);
$throwsExact(FlexDefinitionException::class, fn() => $type->sanitizeValue($typedFreshPage, $removedTypeField, $typedSaved->rawArray()), 'stored rows with a removed type remain invalid when pruning is disabled');

$admin = $type->admin();
$draftToken = $admin->open($typedSaved, $typedName);
try {
	$draftRows = new FlexRows($typedFreshPage, $typedField, 'blocks', $typedDefs->property('blocks'));
	$draftTextRow = $draftRows->addRow('text', ['value' => 'Draft text', 'type' => 'User data']);
	$draftImageRow = $draftRows->addRow('image', ['caption' => 'Draft image']);
	$admin->registerRow($draftToken, 'blocks', $draftTextRow);
	$admin->registerRow($draftToken, 'blocks', $draftImageRow);
	$loadedDraftRow = $admin->row($draftToken, 'blocks', $draftTextRow->rowId(), $typedSaved);
	$same('text', $loadedDraftRow->rowType(), 'registered draft rows retain their type when loaded');
	$same('User data', $loadedDraftRow->type, 'registered draft rows retain a user leaf named type');
	$stageImagePath = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-typed-staged-fixture.png');
	$stageFixture = imagecreatetruecolor(16, 16);
	imagepng($stageFixture, $stageImagePath);
	imagedestroy($stageFixture);
	$sourceImageRow = $typedSaved->blocks->getById($typedImageRow->rowId());
	$canonicalPhoto = $sourceImageRow->photo->rawArray();
	$photoDefinition = $sourceImageRow->definitions()->property('photo');
	$photoAddress = 'blocks.' . $sourceImageRow->rowId() . '.photo';
	$stagedPhoto = new FlexImages($typedFreshPage, $typedField, $photoAddress, $photoDefinition, $canonicalPhoto);
	$stagedPhoto->draft($draftToken);
	$stagedPhoto->add($stageImagePath);
	$admin->stageMedia($draftToken, $photoAddress, $stagedPhoto);
	$stagedPhotoRaw = $stagedPhoto->rawArray();
	$canonicalAssetIds = array_column($canonicalPhoto['items'], 'id');
	$stagedAssetIds = array_column($stagedPhotoRaw['items'], 'id');
	$duplicateIds = [];
	try {
		$canonicalSource = $admin->row($draftToken, 'blocks', $sourceImageRow->rowId(), $typedSaved, false);
		$stagedSource = $admin->row($draftToken, 'blocks', $sourceImageRow->rowId(), $typedSaved, true);
		$same(1, count($canonicalSource->photo), 'row lookup without media keeps the persisted canonical image collection');
		$same(2, count($stagedSource->photo), 'row lookup overlays an existing row’s staged image collection');
		$same($canonicalPhoto, $canonicalSource->photo->rawArray(), 'canonical row lookup preserves the original media envelope');

		$duplicatePrefix = 'TypedDuplicate__blocks__' . $sourceImageRow->rowId() . '__';
		$form = $wire->modules->get('InputfieldForm');
		$form->protectCSRF = false;
		$children = $stagedSource->definitions()->getInputfields($stagedSource, $duplicatePrefix, $draftToken);
		foreach($children->children() as $child) {
			$form->add($child);
		}
		$photoInput = $children->getChildByName($duplicatePrefix . 'photo');
		$duplicatePost = [
			$duplicatePrefix . 'caption'          => 'Copied through native input',
			FlexPath::controlName('TypedDuplicate', $stagedSource->address() . '.photo', 'present')       => '1',
		];
		foreach($photoInput->value as $position => $file) {
			$duplicatePost['sort_' . $photoInput->name . '_' . $file->hash] = (string) $position;
		}
		$form->processInput($wire->wire(new WireInputData($duplicatePost)));
		$same([], $form->getErrors(), 'native duplicate inputs accept the source row media draft');
		InputfieldFlex::applyInputs($stagedSource, $form, $duplicatePrefix);
		$same('Copied through native input', $stagedSource->caption, 'native duplicate inputs process current source text');
		$same(2, count($stagedSource->photo), 'native duplicate inputs retain staged and persisted source images');

		$copyRows = new FlexRows($typedFreshPage, $typedField, 'blocks', $typedDefs->property('blocks'));
		$copyRow = $copyRows->addRow($stagedSource->rowType());
		foreach($stagedSource->definitions()->properties() as $name => $_) {
			$copyRow->set($name, $stagedSource->getUnformatted($name));
		}
		$admin->registerRow($draftToken, 'blocks', $copyRow);
		$duplicateIds = array_column($copyRow->photo->rawArray()['items'], 'id');
		$assert(count($duplicateIds) === 2 && count(array_intersect($stagedAssetIds, $duplicateIds)) === 0, 'registered duplicate row copies staged media to independent asset identities');
		$loadedCopy = $admin->row($draftToken, 'blocks', $copyRow->rowId(), $typedSaved);
		$same('image', $loadedCopy->rowType(), 'registered duplicate keeps the source row type');
		$same('Copied through native input', $loadedCopy->caption, 'registered duplicate keeps native text edits');
		$same($duplicateIds, array_column($loadedCopy->photo->rawArray()['items'], 'id'), 'registered duplicate reloads its independent staged asset identities');
		$same($canonicalPhoto, $typedSaved->blocks->getById($sourceImageRow->rowId())->photo->rawArray(), 'staged duplicate work does not change canonical source media');

		$emptyPhoto = new FlexImages($typedFreshPage, $typedField, $photoAddress, $photoDefinition, $stagedPhotoRaw);
		$emptyPhoto->draft($draftToken);
		$emptyPhoto->removeAll();
		$admin->stageMedia($draftToken, $photoAddress, $emptyPhoto);
		$same(0, count($admin->row($draftToken, 'blocks', $sourceImageRow->rowId(), $typedSaved, true)->photo), 'staged removal overlays an empty media collection');
		$same(1, count($admin->row($draftToken, 'blocks', $sourceImageRow->rowId(), $typedSaved, false)->photo), 'staged removal leaves canonical media available until save');
	} finally {
		foreach(array_unique(array_merge(array_diff($stagedAssetIds, $canonicalAssetIds), $duplicateIds)) as $assetId) {
			$type->media()->abandon($assetId, $typedFreshPage, $typedField, $draftToken);
		}
		$type->media()->collect(1000, $typedFreshPage, $typedField);
		@unlink($stageImagePath);
	}
	$draftDiscovery = $admin->discovery($draftToken, $typedSaved);
	$same('image', $draftDiscovery->blocks->getById($draftImageRow->rowId())->rowType(), 'draft discovery includes the registered row with its type');
	$throws(fn() => $admin->target($draftToken, 'blocks.' . $draftTextRow->rowId() . '.photo', $typedSaved), 'media target cannot use a property defined only in another row type');
	$removedTypeValue = new FlexValue($typedFreshPage, $removedTypeField, $type->getDefinitions($removedTypeField, $typedFreshPage));
	$throwsExact(FlexDefinitionException::class, fn() => $admin->row($draftToken, 'blocks', $draftImageRow->rowId(), $removedTypeValue), 'a draft row whose type was removed between render and POST is rejected');
} finally {
	$admin->forget($draftToken);
}

$oldDraftToken = bin2hex(random_bytes(32));
$admin->put($oldDraftToken, [
	'format'  => 999,
	'user'    => (int) $wire->user->id,
	'expires' => time() + 3600,
]);
try {
	$throwsExact(WirePermissionException::class, fn() => $admin->draft($oldDraftToken), 'unknown editor draft format is rejected');
} finally {
	$admin->forget($oldDraftToken);
}

$invalidDefinitions = [
	[
		'type'       => 'rows',
		'types'      => ['text' => ['children' => null]],
	],
	[
		'type'       => 'rows',
		'children'   => ['value' => ['type' => 'text']],
	],
	[
		'type'       => 'rows',
		'types'      => [],
	],
	[
		'type'       => 'rows',
		'types'      => [
			'Invalid' => [
				'label'    => 'Invalid',
				'children' => ['value' => ['type' => 'text']],
			],
		],
	],
	[
		'type'       => 'rows',
		'types'      => [
			'count' => [
				'label'    => 'Reserved',
				'children' => ['value' => ['type' => 'text']],
			],
		],
	],
	[
		'type'     => 'rows',
		'rowTitle' => '{value}',
		'types'    => [
			'text' => [
				'label'    => 'Text',
				'children' => ['value' => ['type' => 'text']],
			],
		],
	],
];
foreach($invalidDefinitions as $invalidDefinition) {
	$invalidField = clone $typedField;
	$invalidField->flexDefinition = json_encode(['blocks' => $invalidDefinition], JSON_THROW_ON_ERROR);
	$throws(fn() => $type->getDefinitions($invalidField, $typedFreshPage), 'invalid typed repeatable definition is rejected');
}
unlink($typedImagePath);
