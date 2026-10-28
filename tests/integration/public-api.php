<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$publicSchema = [
	'heading' => [
		'type' => 'text',
		'label' => 'Heading & <title>',
	],
	'choice' => [
		'type' => 'select',
		'options' => ['a&b' => 'A & B'],
	],
	'blocks' => [
		'type' => 'rows',
		'types' => [
			'item' => [
				'children' => [
					'heading' => ['type' => 'text'],
					'attachment' => [
						'type' => 'file',
						'extensions' => 'txt',
					],
				],
			],
		],
	],
];
$publicField = $createField('flex_public_api', $publicSchema);
$publicName = $publicField->name;
$definitionDirectory = $wire->config->paths->templates . 'flex-fields/';
$publicPHP = $trackFile($definitionDirectory . $publicName . '.php');
$publicJSON = $trackFile($definitionDirectory . $publicName . '.json');
$publicSource = '<?php namespace ProcessWire; return ' . var_export($publicSchema, true) . ';';
file_put_contents($publicPHP, $publicSource);
$publicField->flexDefinition = '';
$wire->fields->save($publicField);

$page->of(false);
$page->$publicName = ['heading' => 'Retained', 'choice' => 'a&b'];
$publicValue = $page->getUnformatted($publicName);
$publicRow = $publicValue->blocks->addRow('item', ['heading' => 'Row retained']);
$publicFile = $trackFile(dirname(__DIR__, 2) . '/.dev/public-api-' . bin2hex(random_bytes(5)) . '.txt');
file_put_contents($publicFile, 'Retained original bytes');
$publicRow->attachment->add($publicFile);
$page->save($publicName);
$same('rows:1', $publicValue->rawArray()['blocks']['_flex'], 'first public row format');
$same('Heading & <title>', $publicValue->label('heading'), 'raw property label');
$same('A & B', $publicValue->optionLabel('choice', 'a&b'), 'option labels use their own API');
$formattedPublic = clone $publicValue;
$formattedPublic->of(true);
$same('Heading &amp; &lt;title&gt;', $formattedPublic->label('heading'), 'formatted property label encodes once');
$same('A &amp; B', $formattedPublic->optionLabel('choice', 'a&b'), 'formatted option label encodes once');
$publicField->flexEntityEncode = '0';
$same('Heading & <title>', $formattedPublic->label('heading'), 'property label respects disabled entity encoding');
$publicField->flexEntityEncode = null;
$richLabelField = clone $publicField;
$richLabelField->flexDefinition = json_encode(['body' => ['type' => 'TinyMCE', 'label' => 'Body <HTML>']]);
$richLabelValue = $type->getBlankValue($page, $richLabelField);
$richLabelValue->of(true);
$same('Body &lt;HTML&gt;', $richLabelValue->label('body'), 'rich text property labels remain plain text');
$same('', $publicValue->optionLabel('choice', 'unknown'), 'unknown option has no label');
$same(1, (new \ReflectionMethod(FlexValue::class, 'label'))->getNumberOfParameters(), 'label has one public purpose');
$assert(!method_exists($publicValue, 'toArray'), 'no duplicate array export alias');
$same('JSON NOT NULL', $type->getDatabaseSchema($publicField)['data'], 'canonical storage is JSON');
$assert(!method_exists($type, 'migrateStorage'), 'no pre-release storage mode migration API');
$assert(!method_exists($type, 'upgradeRowStorage'), 'no pre-release projection migration API');

$publicField->flexDefinition = '{}';
$publicField->flexDefinitionFile = 'flex-fields/' . $publicName . '.php';
$throws(fn() => $type->getDefinitions($publicField, $page), 'explicit definition sources conflict', FlexDefinitionException::class, 'not both');
$publicField->flexDefinition = '';
$publicField->flexDefinitionFile = '';
file_put_contents($publicJSON, json_encode($publicSchema, JSON_THROW_ON_ERROR));
$throws(fn() => $type->getDefinitions($publicField, $page), 'two automatic files conflict', FlexDefinitionException::class, 'Multiple automatic');
$publicField->flexDefinitionFile = 'flex-fields/' . $publicName . '.php';
$assert($type->getDefinitions($publicField, $page)->getPropertyInputfield('heading') instanceof InputfieldText, 'explicit file resolves automatic ambiguity');
$publicField->flexDefinitionFile = '';
unlink($publicJSON);

$emptyField = $wire->wire(new FlexField());
$emptyField->name = 'flex_unconfigured_' . bin2hex(random_bytes(4));
$emptyField->type = $type;
$same([], $type->getBlankValue($page, $emptyField)->rawArray(), 'unconfigured new field can be empty');
$throws(fn() => $type->sanitizeValue($page, $emptyField, ['heading' => 'Value']), 'unconfigured field cannot discard assigned data', FlexDefinitionException::class, 'missing');
$otherDirectory = $wire->config->paths->templates . 'other-definitions/';
$createdOtherDirectory = !is_dir($otherDirectory);
if($createdOtherDirectory) mkdir($otherDirectory);
$otherFile = $trackFile($otherDirectory . $emptyField->name . '.php');
try {
	file_put_contents($otherFile, $publicSource);
	$same([], $type->getDefinitions($emptyField)->properties(), 'only flex-fields is searched automatically');
} finally {
	unlink($otherFile);
	if($createdOtherDirectory) rmdir($otherDirectory);
}

$publicTable = $wire->database->escapeTable($publicField->table);
$readCanonical = static function() use ($wire, $publicTable, $id): string {
	return (string) $wire->database->query("SELECT data FROM `$publicTable` WHERE pages_id=" . (int) $id)->fetchColumn();
};
$readProjection = static function() use ($wire, $publicField, $id): array {
	return $wire->database->query('SELECT * FROM fieldtype_flex_rows WHERE fields_id=' . (int) $publicField->id . ' AND pages_id=' . (int) $id . ' ORDER BY row_id')->fetchAll(\PDO::FETCH_ASSOC);
};
$canonicalBefore = $readCanonical();
$projectionBefore = $readProjection();
$readAssets = static function() use ($wire, $publicField, $id): array {
	return $wire->database->query('SELECT * FROM fieldtype_flex_assets WHERE fields_id=' . (int) $publicField->id . ' AND pages_id=' . (int) $id . ' ORDER BY asset_id')->fetchAll(\PDO::FETCH_ASSOC);
};
$assetsBefore = $readAssets();
$originalFile = $publicRow->attachment->first()->filename();
$originalHash = hash_file('sha256', $originalFile);
unlink($publicPHP);
try {
	$throws(fn() => $type->getDefinitions($publicField, $page), 'lost source is a definition error', FlexDefinitionException::class, 'missing');
	$publicValue->heading = 'Unsaved change';
	$throws(fn() => $page->save($publicName), 'cached value cannot save after source loss', FlexDefinitionException::class, 'missing');
	$throws(fn() => $page->save(), 'whole-page save cannot hide source loss', FlexDefinitionException::class, 'missing');
	$throws(fn() => $type->sanitizeValue($page, $publicField, []), 'empty replacement cannot bypass lost-source guard', FlexDefinitionException::class, 'missing');
	$same($canonicalBefore, $readCanonical(), 'lost source retains canonical JSON');
	$same($projectionBefore, $readProjection(), 'lost source retains row projections');
	$same($assetsBefore, $readAssets(), 'lost source retains media ownership');
	$same($originalHash, hash_file('sha256', $originalFile), 'lost source retains original media bytes');
} finally {
	file_put_contents($publicPHP, $publicSource);
}
$page = $wire->pages->getFresh($id);
$page->of(false);
$page->$publicName->heading = 'Recovered';
$page->save($publicName);
$same('Recovered', $wire->pages->getFresh($id)->getUnformatted($publicName)->heading, 'restored source permits a normal save');
