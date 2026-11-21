<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$tableDefinition = ['prices' => ['type' => 'table', 'editableHeaders' => true, 'rowLabels' => true, 'columns' => [
	'service' => ['type' => 'text', 'label' => 'Service', 'width' => '22rem'],
	'price' => ['type' => 'float', 'label' => 'Price', 'width' => '90px'],
]]];
$tf = $createField('flex_tables', $tableDefinition); $tn = $tf->name;
$page = $wire->pages->getFresh($id); $page->of(false);
$table = $page->$tn->prices;
$assert($table instanceof FlexValue && $table->rows instanceof FlexRows, 'table reuses Object and Rows values');
$same('Service', $table->headers->service, 'missing header starts with its definition label');
$row = $table->rows->addRow('row', ['row_label' => 'Advice', 'service' => 'Consulting', 'price' => 80]);
$page->save($tn);
$saved = $wire->pages->getFresh($id)->getUnformatted($tn)->prices;
$same('Consulting', $saved->rows->first()->service, 'table row survives independent persistence readback');
$same('Advice', $saved->rows->first()->row_label, 'row label is ordinary row content');
$same('object:1', $page->$tn->rawArray()['prices']['_flex'], 'table uses the existing Object envelope');
$same('rows:1', $page->$tn->rawArray()['prices']['values']['rows']['_flex'], 'table rows use the existing Rows envelope');
$editor = $tf->getInputfield($page);
$editor->value = $page->$tn;
$html = $editor->render();
$assert(str_contains($html, '<table') && str_contains($html, '<thead>') && str_contains($html, '<tbody class="flex-rows-items">'), 'editor renders semantic table sections');
$assert(str_contains($html, '<thead><tr><th class="flex-table-sort" scope="col"><span class="flex-table-sr">'), 'the first column has an accessible reorder heading');
$assert(str_contains($html, '<th class="flex-table-actions" scope="col"><span class="flex-table-sr">'), 'the actions column keeps a screen-reader heading with its own styling hook');
$assert((bool) preg_match('~<tbody[^>]*><tr[^>]*><td class="flex-table-sort"><button[^>]*class="flex-row-sort"~', $html), 'the sort handle is in the first cell of each editable row');
$assert((bool) preg_match('~<details class="flex-table-more">.*?data-flex-row-action="duplicate".*?data-flex-row-action="visibility".*?</details><button[^>]*data-flex-row-action="remove"~s', $html), 'the disclosure contains secondary actions and keeps removal directly available');
$assert(str_contains($html, 'width:22rem') && str_contains($html, 'width:90px'), 'column widths are shared by table head and body');
$assert(str_contains($html, 'data-flex-row="' . $row->rowId() . '"'), 'table renders the existing row identity');
$assert(str_contains($html, 'InputfieldText') && str_contains($html, 'flex-table-input'), 'cells retain native Inputfield wrappers');
preg_match('~<table\b.*?</table>~s', $html, $tableMarkup);
$assert(isset($tableMarkup[0]) && !str_contains($tableMarkup[0], 'toggle-icon'), 'open table cells and editable headers have no collapse controls');
$readonly = $editor->renderValue();
$assert(str_contains($readonly, '<table') && !str_contains($readonly, 'flex-table-actions') && !str_contains($readonly, 'flex-table-sort'), 'native read-only rendering keeps only the data columns');

// Definition changes retain data outside the visible schema, including headers.
$table->headers->service = '';
$table->headers->price = '0';
$page->save($tn);
$tableDefinition['prices']['columns']['service']['label'] = 'Changed label';
$tableDefinition['prices']['editableHeaders'] = false;
$tableDefinition['prices']['rowLabels'] = false;
unset($tableDefinition['prices']['columns']['price']);
$tf->flexDefinition = json_encode($tableDefinition); $tf->save();
$page = $wire->pages->getFresh($id); $page->of(false); $v = $page->$tn;
$same(['service'], array_keys($v->prices->rows->first()->getArray()), 'removed columns and disabled labels are absent from ordinary iteration');
$same(null, $v->prices->headers, 'disabled editable headers leave the declared property API');
$page->save($tn);
$raw = $wire->pages->getFresh($id)->getUnformatted($tn)->rawArray()['prices']['values'];
$same('', $raw['headers']['values']['service'], 'disabled headers retain an intentionally empty value');
$same('0', $raw['headers']['values']['price'], 'disabled headers retain the string zero');
$same(80, $raw['rows']['items'][0]['values']['price'], 'removed column remains in canonical data after ordinary save');
$same('Advice', $raw['rows']['items'][0]['values']['row_label'], 'disabled row label survives ordinary save');
$tableDefinition['prices']['editableHeaders'] = true;
$tableDefinition['prices']['rowLabels'] = true;
$tableDefinition['prices']['columns']['price'] = ['type' => 'float', 'label' => 'New price'];
$tf->flexDefinition = json_encode($tableDefinition); $tf->save();
$page = $wire->pages->getFresh($id); $page->of(false); $v = $page->$tn;
$same('', $v->prices->headers->service, 'reactivating headers preserves empty rather than applying a new label');
$same('0', $v->prices->headers->price, 'reactivating headers restores zero rather than applying a new label');
$same('Advice', $v->prices->rows->first()->row_label, 'reactivating labels restores stored text');
$same([$id], $wire->pages->findIDs("id=$id, $tn.prices.rows.row.service=Consulting"), 'table leaves reuse native Flex selectors');
$copy = $v->prices->rows->duplicateRow($row->rowId());
$copy->service = 'Independent copy';
$same('Consulting', $v->prices->rows->first()->service, 'duplicated table rows are independent');
$v->prices->rows->moveRow($copy->rowId(), $row->rowId());
$same('Independent copy', $v->prices->rows->first()->service, 'table rows use existing order operations');
$copy->setHidden(true);
$formatted = clone $v; $formatted->of(true);
$same(1, count($formatted->prices->rows), 'formatted table rows respect visibility');
$same(2, count($formatted->rawArray()['prices']['values']['rows']['items']), 'formatted exports retain hidden table rows');

// Validate effective native inputs, not just the public type string.
$valid = ['type' => 'table', 'columns' => ['value' => ['type' => 'text']]];
$badTables = [
 ['columns' => []], ['columns' => ['value']],
 ['minItems' => -1], ['minItems' => 3, 'maxItems' => 2], ['types' => []], ['rowLazyLoad' => true],
 ['columns' => ['row_label' => ['type' => 'text']]],
];
foreach(['100%', 'auto', '-2rem', '0px', '1em', '1rem;display:none', 12] as $width) $badTables[] = ['columns' => ['value' => ['width' => $width]]];
foreach(['fieldset', 'Markup', 'Hidden', 'Selector'] as $inputType) $badTables[] = ['columns' => ['value' => ['type' => $inputType]]];
foreach([10, 11, 20, 21, 22] as $collapsed) $badTables[] = ['columns' => ['value' => ['type' => 'text', 'collapsed' => $collapsed]]];
$badTables[] = ['columns' => ['value' => ['type' => 'object', 'children' => []]]];
$badTables[] = ['columns' => ['value' => ['type' => 'rows', 'types' => ['item' => ['children' => []]]]]];
$badTables[] = ['columns' => ['value' => ['type' => 'page', 'inputfield' => 'InputfieldIcon']]];
foreach($badTables as $index => $override) {
 $bad = clone $tf; $bad->flexDefinition = json_encode(['prices' => array_replace($valid, $override)]);
 $throws(fn() => $type->getDefinitions($bad, $page), "invalid table definition $index is rejected", FlexDefinitionException::class);
}
$coerced = clone $tf;
$coerced->flexDefinition = json_encode(['prices' => ['type' => 'table', 'editableHeaders' => 1, 'rowLabels' => 1, 'columns' => ['slug' => ['type' => 'Name']]]]);
$coercedDefs = $type->getDefinitions($coerced, $page);
$assert($coercedDefs->property('prices.headers') !== null, 'truthy editableHeaders coerces to boolean');
$assert($coercedDefs->property('prices.rows.row.row_label') !== null, 'truthy rowLabels coerces to boolean');
$assert($coercedDefs->getPropertyInputfield('prices.rows.row.slug') instanceof InputfieldName, 'table columns accept InputfieldText subclasses structurally');
$bad = clone $tf;
$bad->flexDefinition = json_encode(['prices' => ['type' => 'table', 'columns' => ['body' => ['type' => 'TinyMCE', 'inheritField' => $sourceField->name]]]]);
$same('InputfieldTinyMCE', $type->getDefinitions($bad, $page)->getPropertyInputfield('prices.rows.row.body')->className(), 'table supports inherited TinyMCE settings');

$imageTable = $createField('flex_table_images', ['grid' => ['type' => 'table', 'columns' => [
	'photo' => ['type' => 'image', 'maxFiles' => 3],
	'attachment' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 3],
]]]);
$imageTableValue = $type->getBlankValue($page, $imageTable);
$imageTableValue->grid->rows->addRow('row');
$imageTableEditor = $imageTable->getInputfield($page);
$imageTableEditor->value = $imageTableValue;
$imageTableHtml = $imageTableEditor->render();
foreach(['image', 'file'] as $preview) {
	$assert(str_contains($imageTableHtml, 'data-flex-preview="' . $preview . '"') && str_contains($imageTableHtml, 'data-flex-upload'), "table $preview preview owns the existing native upload field");
}

// Every released cell widget renders under the table's native wrappers.
$cellTypes = ['Text', 'Email', 'URL', 'Integer', 'Float', 'Textarea', 'Checkbox', 'Toggle', 'Select', 'Radios', 'Checkboxes', 'SelectMultiple', 'AsmSelect', 'TextTags', 'Datetime', 'TinyMCE'];
$columns = [];
foreach($cellTypes as $i => $inputType) {
 $columns['cell_' . $i] = ['type' => $inputType];
 if($inputType === 'TextTags') $columns['cell_' . $i]['allowUserTags'] = true;
 if($inputType === 'Datetime') $columns['cell_' . $i]['dateInputFormat'] = 'Y-m-d';
 if(in_array($inputType, ['Select', 'Radios', 'Checkboxes', 'SelectMultiple', 'AsmSelect'])) $columns['cell_' . $i]['options'] = ['a' => 'Alpha', 'b' => 'Beta'];
}
foreach(['InputfieldSelect', 'InputfieldSelectMultiple', 'InputfieldAsmSelect', 'InputfieldPageAutocomplete', 'InputfieldCheckboxes', 'InputfieldRadios', 'InputfieldTextTags', 'InputfieldTextTags', 'InputfieldPageListSelect', 'InputfieldPageListSelectMultiple'] as $i => $widget) {
 $columns['page_' . $i] = ['type' => 'page', 'inputfield' => $widget, 'parent_id' => 1, 'derefAsPage' => in_array($i, [0, 5, 7, 8], true) ? 2 : 0];
}
$columns['inline'] = ['type' => 'TinyMCE', 'inlineMode' => 1, 'useLanguages' => true];
$widgets = $createField('flex_table_widgets', ['grid' => ['type' => 'table', 'columns' => $columns]]);
$same('title', $widgets->defs($page)->getPropertyInputfield('grid.rows.row.page_3')->labelFieldName, 'PageAutocomplete table columns default to visible native Page titles');
$wv = $type->getBlankValue($page, $widgets); $wr = $wv->grid->rows->addRow('row');
$wi = $widgets->getInputfield($page); $wi->value = $wv; $wh = $wi->render();
$same(2, substr_count($wh, 'data-flex-preview="richtext"'), 'both TinyMCE modes use a table detail preview');
foreach(array_keys($columns) as $column) $assert(str_contains($wh, FlexPath::inputPrefix($widgets->name, $wr->address()) . $column), "$column renders as a native table input");


$dom = new \DOMDocument(); $old = libxml_use_internal_errors(true); $dom->loadHTML($wh); libxml_clear_errors(); libxml_use_internal_errors($old);
$post = [];
foreach((new \DOMXPath($dom))->query('//input[@type="hidden"]') as $hidden) $post[$hidden->getAttribute('name')] = $hidden->getAttribute('value');
$wp = FlexPath::inputPrefix($widgets->name, $wr->address());
$submitted = ['Typed', 'editor@example.com', 'https://example.com/', '42', '3.25', "Line one\nLine two", '1', '1', 'b', 'b', ['a', 'b'], ['a', 'b'], ['b'], 'one two', '2026-11-10', '<p>Rich <strong>text</strong></p>'];
foreach($submitted as $i => $cellValue) $post[$wp . 'cell_' . $i] = $cellValue;
$post['Inputfield_' . $wp . 'inline'] = '<p>Default inline</p>';
$post['Inputfield_' . $wp . 'inline__' . $language->id] = '<p>German inline</p>';
foreach(range(0, 9) as $i) $post[$wp . 'page_' . $i] = match($i) {
 0, 5, 8, 9 => (string) $second->id,
 6, 7 => '_' . $second->id, // Native TextTags prefixes numeric IDs.
 default => [$second->id],
};
$wi->processInput($wire->wire(new WireInputData($post)));
$same([], $wi->getErrors(), 'all released native cell widgets process a submitted table row');
$processed = $wi->value->grid->rows->first();
foreach([0 => 'Typed', 1 => 'editor@example.com', 2 => 'https://example.com/', 3 => 42, 4 => 3.25, 5 => "Line one\nLine two", 6 => 1, 7 => 1, 8 => 'b', 9 => 'b', 10 => ['a', 'b'], 11 => ['a', 'b'], 12 => ['b'], 13 => 'one two'] as $i => $expected) $same($expected, $processed->get('cell_' . $i), "native cell_$i uses its existing value normalization");
$same('<p>Rich <strong>text</strong></p>', $processed->cell_15, 'table TinyMCE submits native rich text');
$same(strtotime('2026-11-10'), $processed->cell_14, 'table Datetime uses native timestamp reads');
$same($second->id, $processed->page_0->id, 'single Page table cell resolves the selected page');
foreach([1, 2, 3, 4, 6, 9] as $i) $same([$second->id], array_values($processed->get('page_' . $i)->explode('id')), "Page widget $i replaces the selected PageArray");
foreach([5, 7, 8] as $i) $same($second->id, $processed->get('page_' . $i)->id, "Page widget $i resolves the single selected page");
$page->set($widgets->name, $wi->value); $page->save($widgets->name);
$reloaded = $wire->pages->getFresh($id)->getUnformatted($widgets->name)->grid->rows->first();
$same(42, $reloaded->cell_3, 'processed native widget row persists independently');
$inline = $reloaded->getUnformatted('inline');
$same('<p>Default inline</p>', $inline->getLanguageValue($wire->languages->getDefault()), 'native inline transport persists the default language');
$same('<p>German inline</p>', $inline->getLanguageValue($language), 'native inline transport persists the alternate language');

// Tables are valid descendants, with the normal language and dependency scopes.
$nested = $createField('flex_nested_tables', ['sections' => ['type' => 'rows', 'types' => ['section' => ['children' => [
 'box' => ['type' => 'object', 'children' => ['grid' => ['type' => 'table', 'editableHeaders' => true, 'rowLabels' => true, 'useLanguages' => true, 'columns' => [
  'text' => ['type' => 'text', 'useLanguages' => true],
 ]]]],
]]]]]);
$nv = $type->getBlankValue($page, $nested); $section = $nv->sections->addRow('section');
$nt = $section->box->grid; $nr = $nt->rows->addRow('row', ['text' => ['default' => 'Default', 'de' => 'Deutsch']]);
$nt->headers->text = ['default' => 'Heading', 'de' => ''];
$nt->headers->row_label = ['default' => 'Label', 'de' => 'Bezeichnung'];
$nr->row_label = ['default' => 'Row', 'de' => 'Zeile'];
$page->{$nested->name} = $nv; $page->save($nested->name);
$nv = $wire->pages->getFresh($id)->getUnformatted($nested->name);
$oldLanguage = $wire->user->language;
try {
 $wire->user->language = $language; $nv->of(true); $nt = $nv->sections->first()->box->grid;
 $same('Deutsch', $nt->rows->first()->text, 'nested table cells use native multilingual formatting');
 $same('', $nt->headers->text, 'intentionally blank translated header does not inherit default text');
 $same('Zeile', $nt->rows->first()->row_label, 'generated row labels use native multilingual values');
} finally { $wire->user->language = $oldLanguage; }
$ni = $nested->getInputfield($page); $ni->value = $page->{$nested->name};
$assert(str_contains($ni->render(), 'flex-table-grid'), 'table renderer works inside Object and Row ancestors');

// Column sorting reuses canonical table rows and can also run for API writes.
$sortColumns = [
	'caption' => ['type' => 'text', 'useLanguages' => true],
	'amount' => ['type' => 'integer'],
	'price' => ['type' => 'float'],
	'date' => ['type' => 'datetime'],
];
$manualTable = ['type' => 'table', 'editableHeaders' => true, 'columns' => $sortColumns];
$sorting = $createField('flex_table_sorting', [
	'manual' => $manualTable,
	'prices' => $manualTable + ['sortOnSave' => '-price'],
	'sections' => ['type' => 'rows', 'types' => ['section' => ['children' => [
		'names' => $manualTable + ['sortOnSave' => 'caption'],
		'amounts' => $manualTable + ['sortOnSave' => 'amount'],
	]]]],
]);
$page = $wire->pages->getFresh($id);
$page->of(false);
$ordered = $page->{$sorting->name};
$manualRows = $ordered->manual->rows;
$manualRows->addRow('row', ['amount' => 10]);
$manualRows->addRow('row', ['amount' => -1]);
$prices = $ordered->prices->rows;
$blank = $prices->addRow('row');
$firstTie = $prices->addRow('row', ['price' => 2.25]);
$zero = $prices->addRow('row', ['price' => 0]);
$top = $prices->addRow('row', ['price' => 10.5]);
$secondTie = $prices->addRow('row', ['price' => 2.25]);
$firstTie->setHidden(true);
$section = $ordered->sections->addRow('section');
$section->names->rows->addRow('row', ['caption' => ['default' => 'Zulu', 'de' => 'Alpha'], 'amount' => 1]);
$section->names->rows->addRow('row', ['caption' => ['default' => 'alpha', 'de' => 'Zulu'], 'amount' => 2]);
foreach([10, '', 2, -1, 0] as $amount) $section->amounts->rows->addRow('row', ['amount' => $amount]);
$ids = static fn(FlexRows $rows) => array_keys($rows->getArray());
$same([$blank->rowId(), $firstTie->rowId(), $zero->rowId(), $top->rowId(), $secondTie->rowId()], $ids($prices), 'automatic sorting waits until save');
$page->save($sorting->name);
$expected = [$top->rowId(), $firstTie->rowId(), $secondTie->rowId(), $zero->rowId(), $blank->rowId()];
$readback = $wire->pages->getFresh($id)->getUnformatted($sorting->name);
$same($expected, $ids($readback->prices->rows), 'API save sorts decimals descending with stable ties and blanks last');
$same($expected, $ids($prices), 'successful save updates the order of the existing runtime collection');
$same($firstTie, $prices->getById($firstTie->rowId()), 'save keeps the original row objects');
$assert($readback->prices->rows->getById($firstTie->rowId())->isHidden(), 'automatic sorting preserves row visibility');
$same([10, -1], array_values($readback->manual->rows->explode('amount')), 'manual tables keep their submitted order');
$same([2, 1], array_values($readback->sections->first()->names->rows->explode('amount')), 'nested multilingual Text sorts by the default-language value');
$same([-1, 0, 2, 10, ''], array_values($readback->sections->first()->amounts->rows->explode('amount')), 'nested Integer sorts numerically ascending with zero distinct from blank');

$renderSort = static function(string $name) use ($page, $createField, $manualTable): string {
	$table = $name === 'auto' ? $manualTable + ['sortOnSave' => '-price'] : $manualTable;
	$field = $createField('flex_sort_' . $name, ['grid' => $table]);
	$editor = $field->getInputfield($page);
	$value = $field->type->getBlankValue($page, $field);
	$value->grid->rows->addRow('row', ['price' => 25]);
	$editor->value = $value;
	return $editor->render();
};
$manualHtml = $renderSort('manual');
$autoHtml = $renderSort('auto');
$same(3, substr_count($manualHtml, 'class="flex-table-column-sort"'), 'only Text, Integer and Float headings have a manual sort control');
$assert(str_contains($manualHtml, 'class="flex-row-sort"'), 'manual tables still offer row drag handles');
$assert(str_contains($autoHtml, 'Sorted by Price in descending order on save') && !str_contains($autoHtml, 'class="flex-table-column-sort"'), 'automatic table heading explains the fixed order and omits manual sorts');
$assert(!str_contains($autoHtml, 'class="flex-row-sort"') && str_contains($autoHtml, 'class="flex-row-select '), 'automatic tables omit drag handles while retaining bulk selection');
foreach(['date', '-date', 'missing', '--price', '+price', '-', '', ['column' => 'price', 'direction' => 'desc']] as $invalidSort) {
	$bad->flexDefinition = json_encode(['grid' => $manualTable + ['sortOnSave' => $invalidSort]]);
	$throws(fn() => $type->getDefinitions($bad, $page), 'unsupported automatic sort definition is rejected', FlexDefinitionException::class);
}

echo "\n$passed assertions passed on ProcessWire {$wire->config->version}, PHP " . PHP_VERSION . ".\n";
