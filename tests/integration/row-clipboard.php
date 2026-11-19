<?php namespace ProcessWire;
if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$referencePage = static function(Page $parent, Template $referenceTemplate, bool $unpublished = false) use ($wire, &$pages): Page {
	$name = 'clipboard-reference-' . bin2hex(random_bytes(4));
	$reference = $wire->pages->newPage(['template' => $referenceTemplate, 'parent' => $parent, 'name' => $name, 'title' => $name]);
	if($unpublished) $reference->addStatus(Page::statusUnpublished);
	$reference->save();
	$pages[] = $reference;
	return $reference;
};
$firstReference = $referencePage($page, $template);
$secondReference = $referencePage($page, $template);
$excludedReference = $referencePage($page, $template);
$wrongParent = $referencePage($second, $template);
$wrongTemplate = $referencePage($page, $wire->templates->get('basic-page'));
$unpublished = $referencePage($page, $template, true);

$notes = [
	'note' => ['children' => ['text' => ['type' => 'text']]],
	'other' => ['children' => ['text' => ['type' => 'text']]],
];
$sourceChildren = [
	'heading' => ['type' => 'text'],
	'amount' => ['type' => 'integer'],
	'layout' => ['type' => 'select', 'options' => ['a' => 'A', 'b' => 'B']],
	'body' => ['type' => 'TinyMCE'],
	'tags' => ['type' => 'TextTags', 'tagsList' => [
		'first' => 'First', 'second' => 'Second',
	]],
	'translation' => ['type' => 'text', 'useLanguages' => true],
	'link' => ['type' => 'page', 'derefAsPage' => 1, 'parent_id' => $page->id],
	'links' => ['type' => 'page', 'inputfield' => 'InputfieldAsmSelect', 'parent_id' => $page->id],
	'extra' => ['type' => 'text'],
	'object' => ['type' => 'object', 'children' => ['caption' => ['type' => 'text']]],
	'notes' => ['type' => 'rows', 'types' => $notes],
	'images' => ['type' => 'image', 'maxFiles' => 3, 'customFields' => ['credit' => ['type' => 'text']]],
];
$sourceTypes = [
	'card' => ['children' => $sourceChildren],
	'banner' => ['children' => ['heading' => ['type' => 'text']]],
];
$source = $createField('flex_clip_source', [
	'items' => ['type' => 'rows', 'types' => $sourceTypes],
	'empty' => ['type' => 'rows', 'types' => ['card' => $sourceTypes['card']]],
]);
$targetChildren = $sourceChildren;
unset($targetChildren['extra']);
$targetChildren['amount'] = ['type' => 'text', 'value' => 'Target amount'];
$targetChildren['layout'] = ['type' => 'select', 'options' => ['a' => 'A'], 'value' => 'a'];
$targetChildren['tags']['tagsList'] = ['first' => 'First'];
$targetChildren['new_value'] = ['type' => 'text', 'value' => 'Target default'];
$targetChildren['notes']['types'] = ['note' => $notes['note']];
$targetChildren['link']['template_id'] = $template->id;
$targetChildren['link']['findPagesSelector'] = 'name!=' . $excludedReference->name;
$targetChildren['links']['template_id'] = $template->id;
$targetCollection = ['type' => 'rows', 'types' => ['card' => ['children' => $targetChildren]], 'maxItems' => 1];
$target = $createField('flex_clip_target', [
	'parents' => ['type' => 'rows', 'types' => ['group' => ['children' => ['caption' => ['type' => 'text'], 'children' => $targetCollection]]]],
	'invalid' => ['type' => 'rows', 'types' => ['banner' => $sourceTypes['banner']]],
]);

$value = $type->getBlankValue($page, $source);
$card = $value->items->addRow('card', ['heading' => 'Saved source', 'amount' => 7, 'layout' => 'b', 'extra' => 'Source-only', 'future' => 'Retained unknown']);
$card->link = $firstReference;
$card->object->caption = 'Saved object';
$note = $card->notes->addRow('note', ['text' => 'Saved note']);
$other = $card->notes->addRow('other', ['text' => 'Other note']);
$banner = $value->items->addRow('banner', ['heading' => 'Position anchor']);
$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/clipboard.png');
$image = imagecreatetruecolor(8, 8);
imagepng($image, $fixture);
$card->images->add($fixture);
$file = $card->images->first();
$file->description(null, 'Image description');
$file->tags = 'example';
$file->setFieldValue('credit', 'Photographer');
$page->set($source->name, $value);
$page->save($source->name);
$baseline = $wire->pages->getFresh($id)->getUnformatted($source->name)->rawArray();
$targetValue = $type->getBlankValue($second, $target);
$parent = $targetValue->parents->addRow('group', ['caption' => 'Saved target']);
$second->set($target->name, $targetValue);
$second->save($target->name);

$render = static function(Page $owner, Field $field): array {
	$editor = $field->getInputfield($owner);
	$editor->value = $owner->getUnformatted($field->name);
	$html = $editor->render();
	$document = new \DOMDocument();
	$previous = libxml_use_internal_errors(true);
	$document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
	libxml_clear_errors();
	libxml_use_internal_errors($previous);
	$post = [];
	foreach((new \DOMXPath($document))->query('//input[@name] | //textarea[@name] | //select[@name]') as $input) {
		$name = $input->getAttribute('name');
		if($input->hasAttribute('disabled') || in_array($input->getAttribute('type'), ['submit', 'file'])
			|| (in_array($input->getAttribute('type'), ['checkbox', 'radio']) && !$input->hasAttribute('checked'))) continue;
		if($input->tagName === 'textarea') $post[$name] = $input->textContent;
		else if($input->tagName === 'select') {
			foreach($input->getElementsByTagName('option') as $option) {
				if($option->hasAttribute('selected')) $post[$name] = $option->getAttribute('value');
			}
		} else $post[$name] = $input->getAttribute('value');
	}
	return [$editor, $html, $post, $post[$field->name . '__0draft']];
};
[, $html, $post, $draft] = $render($page, $source);
[$targetEditor, $targetHtml, $targetPost, $targetDraft] = $render($second, $target);
$assert(str_contains($html, 'fa-copy') && str_contains($html, 'fa-clipboard'), 'clipboard and Duplicate use separate action icons');
$assert(str_contains($html, '<select class="flex-row-type InputfieldSetWidth" aria-label="New item type"><option value="" selected disabled>Add item</option>'), 'multiple types show a standalone picker with a neutral option');
$assert(str_contains($html, '</select><details class="flex-collection-menu" hidden><summary>Actions') && str_contains($html, 'data-flex-selection-action="done" hidden>Done</button><span class="flex-selection-count"'), 'editable collections place the menu beside the type picker and the count after Done');
$assert(!str_contains($html, 'value="@clipboard"'), 'the add picker lists only row types');
$assert(str_contains($targetHtml, '</button><details class="flex-collection-menu" hidden><summary>Actions') && str_contains($targetHtml, 'data-flex-row-action="paste-end"'), 'empty nested collections retain a scoped paste action');
$assert(!str_contains($targetHtml, '<select class="flex-row-type'), 'single-type collections omit the type picker');

$ajax = static function(Page $owner, Field $field, string $token, array $data, bool $csrf = true) use ($wire, $type): array {
	$server = $_SERVER;
	$post = $wire->input->post->getArray();
	$_SERVER['HTTP_X_FLEX_DRAFT'] = $token;
	$_SERVER['HTTP_X_FIELDNAME'] = $field->name;
	$data['flex_complete'] = $token;
	if($csrf) $data[$wire->session->CSRF->getTokenName()] = $wire->session->CSRF->getTokenValue();
	$wire->input->post->removeAll()->setArray($data);
	ob_start();
	try {
		$type->admin()->ajax(new HookEvent(['arguments' => [$owner]]));
		return json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
	} finally {
		ob_end_clean();
		$_SERVER = $server;
		$wire->input->post->removeAll()->setArray($post);
	}
};
$prefix = FlexPath::inputPrefix($source->name, $card->address());
$post[$prefix . 'heading'] = 'Unsaved heading';
$post[$prefix . 'amount'] = '7';
$post[$prefix . 'layout'] = 'b';
$post[$prefix . 'tags'] = 'first second';
$post[$prefix . 'body'] = '<p><a href="/original-link/">Source</a></p>';
$post[$prefix . 'translation'] = 'Original language';
$post[$prefix . 'translation__' . $language->id] = 'Übersetzung';
$post[$prefix . 'link'] = (string) $firstReference->id;
$post[$prefix . 'links'] = [(string) $secondReference->id, (string) $firstReference->id];
$post[FlexPath::inputPrefix($source->name, $card->address() . '.object') . 'caption'] = 'Unsaved object';
$post[FlexPath::inputPrefix($source->name, $note->address()) . 'text'] = 'Unsaved note';
$post[FlexPath::controlName($source->name, $card->address() . '.notes', 'order')] = $other->rowId() . ',' . $note->rowId();
$post[FlexPath::controlName($source->name, $card->address(), 'hidden')] = '1';
$copy = ['flex_action' => 'clipboard-copy', 'flex_set' => 'items', 'flex_source' => $card->rowId()];
$beforeCopy = $type->admin()->draft($draft);
$same(true, $ajax($page, $source, $draft, $copy + $post, false)['error'], 'copy requires a valid CSRF token');
$result = $ajax($page, $source, $draft, $copy + $post);
$same(false, $result['error'], 'copy uses the native AJAX input pipeline ' . ($result['message'] ?? ''));
$text = $result['clipboard'];
$payload = json_decode($text, true, 100, JSON_THROW_ON_ERROR);
$values = $payload['row']['values'];
$same('Unsaved heading', $values['heading']['value'], 'clipboard includes unsaved native controls');
$same('Unsaved object', $values['object']['value']['caption']['value'], 'clipboard includes unsaved Object values');
$same(['other', 'note'], array_column($values['notes']['value'], 'type'), 'clipboard follows the submitted nested order');
$same('Unsaved note', $values['notes']['value'][1]['values']['text']['value'], 'clipboard includes unsaved nested input');
$same('Übersetzung', $values['translation']['value'][$language->name], 'clipboard uses language names');
$same(true, $payload['row']['hidden'], 'clipboard includes the submitted visibility');
$portableReference = static fn(Page $reference) => [
	'path' => $reference->path(), 'parent' => $reference->parent()->path(), 'template' => $reference->template->name,
];
$same($portableReference($firstReference), $values['link']['references'][$firstReference->id], 'single Page reference includes its path, parent path and template name');
$same([$secondReference->id, $firstReference->id], $values['links']['value'], 'multiple Page references include the submitted order');
$same($portableReference($secondReference), $values['links']['references'][$secondReference->id], 'multiple Page references include portable descriptors');
$assert(!str_contains($text, $card->rowId()) && !str_contains($text, $file->assetId()), 'clipboard omits row identities and standalone asset IDs');
$assert(!isset($values['future']), 'clipboard only exports declared properties');
$same($beforeCopy, $type->admin()->draft($draft), 'copy leaves the source draft unchanged');

$ids = [$card->rowId(), $banner->rowId()];
$paste = static fn(string $set, string $action, array $ids, string $text, ?string $anchor = null) => [
	'flex_action' => $action, 'flex_set' => $set, 'flex_order' => implode(',', $ids),
	'flex_clipboard' => $text, 'flex_source' => $anchor,
];
$above = $ajax($page, $source, $draft, $paste('items', 'paste-above', $ids, $text, $banner->rowId()));
$same(false, $above['error'], 'paste above accepts a different type as the position anchor ' . ($above['message'] ?? ''));
$ids = [$card->rowId(), $above['id'], $banner->rowId()];
$same($ids, $type->admin()->draft($draft)['orders']['items'], 'server records paste above order');
$below = $ajax($page, $source, $draft, $paste('items', 'paste-below', $ids, $text, $banner->rowId()));
$same(false, $below['error'], 'paste below uses the anchor collection');
$ids[] = $below['id'];
$same($ids, $type->admin()->draft($draft)['orders']['items'], 'server records paste below order');
$empty = $ajax($page, $source, $draft, $paste('empty', 'paste-end', [], $text));
$same(false, $empty['error'], 'paste appends to an empty single-type collection');
$assert(count(array_unique([...$ids, $empty['id']])) === 5, 'every pasted row has a fresh identity');
$new = $type->admin()->row($draft, 'items', $above['id'], $page->getUnformatted($source->name));
$same($firstReference->id, $new->link instanceof Page ? $new->link->id : $new->link, 'a valid Page reference survives within the installation');
$same(['other', 'note'], array_map(static fn(FlexRow $row) => $row->rowType(), array_values($new->notes->getArray())), 'supported nested rows retain order');
$assert($new->notes->last()->rowId() !== $note->rowId() && $new->notes->first()->rowId() !== $other->rowId(), 'nested rows receive fresh identities');
$newFile = $new->images->first();
$assert($newFile && $newFile->assetId() !== $file->assetId(), 'an owned original is imported under a fresh asset identity');
$same(file_get_contents($file->filename()), file_get_contents($newFile->filename()), 'imported media contains the original bytes');
$same('Image description', $newFile->rawDescription(), 'standard native media metadata survives');
$same('Photographer', $newFile->getFieldValue('credit', false), 'compatible custom media metadata survives');
$same($draft, $type->media()->asset($newFile->assetId())['draft'], 'asset ownership is tied to the receiving draft');
$same($baseline, $wire->pages->getFresh($id)->getUnformatted($source->name)->rawArray(), 'copy and paste do not persist or overwrite existing source values');

$crossSite = $payload;
$crossSite['site'] = 'a-different-installation';
$crossSite['source'] = 'https://example.test/source-page/';
$foreignID = $excludedReference->id; // In the destination this ID belongs to another page.
$crossSite['row']['values']['link'] = [
	'kind' => 'page', 'value' => $foreignID, 'references' => [$foreignID => $portableReference($firstReference)],
];
$crossSite['row']['values']['links'] = [
	'kind' => 'page', 'value' => [700001, 700002, 700003],
	'references' => [
		700001 => $portableReference($secondReference),
		700002 => $portableReference($wrongParent),
		700003 => $portableReference($firstReference),
	],
];
$crossSite['row']['values']['translation']['value']['absent_language'] = 'Missing';
$crossSite['row']['values']['images']['value'][0]['url'] = 'https://127.0.0.1/' . rawurlencode($file->basename());
$crossSite['row']['values']['object.caption'] = ['kind' => 'text', 'value' => 'Reject dotted key'];
$crossSite['row']['values']['body']['value'] = '<p><a href="../original-link/" onclick="bad()">Source</a><script>bad()</script></p>';
$crossText = json_encode($crossSite, JSON_THROW_ON_ERROR);
$targetSet = $parent->address() . '.children';
$beforePaste = $type->admin()->draft($targetDraft);
$bad = $ajax($second, $target, $targetDraft, $paste('invalid', 'paste-end', [], $text));
$same(true, $bad['error'], 'a missing root row type is rejected');
$assert(str_contains($bad['message'], 'card'), 'missing type error identifies the type');
foreach(['non-JSON', '{"format":"fieldtype-flex-row","version":55}', str_repeat('[', 110) . str_repeat(']', 110)] as $badText) {
	$same(true, $ajax($second, $target, $targetDraft, $paste($targetSet, 'paste-end', [], $badText))['error'], 'malformed or unsupported clipboard is rejected');
}
$same(true, $ajax($second, $target, $targetDraft, $paste($targetSet, 'paste-above', [], $text, $banner->rowId()))['error'], 'an unrelated row cannot anchor an insert');
$same($beforePaste, $type->admin()->draft($targetDraft), 'rejected pastes leave the target draft untouched');
$pasteTarget = $paste($targetSet, 'paste-end', [], $crossText);
$same(true, $ajax($page, $target, $targetDraft, $pasteTarget)['error'], 'paste requires the matching owner Page');
$same(true, $ajax($second, $target, $targetDraft, $pasteTarget, false)['error'], 'paste retains CSRF protection');
$cross = $ajax($second, $target, $targetDraft, $pasteTarget);
$same(false, $cross['error'], 'a different evaluated schema and installation can receive a partial nested import ' . ($cross['message'] ?? ''));
$actual = $type->admin()->row($targetDraft, $targetSet, $cross['id'], $second->getUnformatted($target->name));
$same('Unsaved heading', $actual->heading, 'the clipboard crosses field and page boundaries');
$same('Target amount', $actual->amount, 'an incompatible property leaves the target default intact');
$same('Target default', $actual->new_value, 'a target-only property keeps its configured default');
$same('a', $actual->layout, 'an invalid option leaves the target default intact');
$same('first', $actual->tags, 'predefined tags are checked against the target widget');
$same('Unsaved object', $actual->object->caption, 'compatible Object values still import');
$assert(!array_key_exists('extra', $actual->rawArray()) && !array_key_exists('object.caption', $actual->rawArray()), 'foreign properties cannot become retained unknown data');
$same('Übersetzung', $actual->translation->getLanguageValue($language), 'known language names are mapped to destination IDs');
$same(1, count($actual->notes), 'unsupported nested types are skipped');
$same($firstReference->id, $actual->link instanceof Page ? $actual->link->id : $actual->link, 'paste resolves the portable reference to the local Page despite a colliding source ID');
$same([$secondReference->id, $firstReference->id], array_map('intval', $actual->links->explode('id')), 'valid multiple references retain their order around a rejected parent');
$assert(str_contains($cross['warning'], 'links'), 'a partial Page selection reports the skipped reference');
$same(0, count($actual->images), 'an unavailable original does not leave a media reference');
$assert(str_contains($cross['warning'], 'amount') && str_contains($cross['warning'], $file->basename()), 'the result reports individual skipped properties and filenames: ' . $cross['warning']);
$assert(str_contains($actual->body, 'https://example.test/original-link/') && !str_contains($actual->body, 'bad()'), 'HTML is purified and relative links retain their source site');
$same(true, $ajax($second, $target, $targetDraft, $paste($targetSet, 'paste-end', [$cross['id']], $text))['error'], 'paste enforces the target collection maximum');

// Batch copy uses the same submitted values and preserves the selected order.
$batchPost = ['flex_sources' => $banner->rowId() . ',' . $card->rowId()] + $copy + $post;
$batch = $ajax($page, $source, $draft, $batchPost);
$same(false, $batch['error'], 'copying multiple rows succeeds through the existing endpoint');
$batchPayload = json_decode($batch['clipboard'], true, 100, JSON_THROW_ON_ERROR);
$same('fieldtype-flex-rows', $batchPayload['format'], 'multiple rows use a distinct clipboard format');
$same(['banner', 'card'], array_column($batchPayload['rows'], 'type'), 'a batch retains selection order');
$same('Unsaved heading', $batchPayload['rows'][1]['values']['heading']['value'], 'a batch includes pending source edits');
$same(true, $ajax($page, $source, $draft, ['flex_sources' => $card->rowId() . ',' . $card->rowId()] + $copy + $post)['error'], 'copy rejects duplicate selected row IDs');
$previousDraft = $type->admin()->draft($draft);
$batchRejected = $ajax($page, $source, $draft, $paste('empty', 'paste-end', [$empty['id']], $batch['clipboard']));
$same(true, $batchRejected['error'], 'an incompatible batch is rejected before it inserts any rows');
$same($previousDraft, $type->admin()->draft($draft), 'a rejected batch leaves the editor draft intact');
$batchLimit = $ajax($second, $target, $targetDraft, $paste($targetSet, 'paste-end', [], $batch['clipboard']));
$assert($batchLimit['error'] && str_contains($batchLimit['message'], 'Maximum'), 'the whole batch must fit the available row limit');
$batchPaste = $ajax($page, $source, $draft, $paste('items', 'paste-above', $ids, $batch['clipboard'], $banner->rowId()));
$same(false, $batchPaste['error'], 'a batch can be pasted at an existing row anchor');
$same(2, count($batchPaste['rows']), 'the editor receives both new rows');
$batchIds = array_column($batchPaste['rows'], 'id');
$same([...array_slice($ids, 0, 2), ...$batchIds, ...array_slice($ids, 2)], $type->admin()->draft($draft)['orders']['items'], 'anchored paste keeps the batch contiguous and ordered');
$assert(count(array_unique([...$ids, ...$batchIds])) === count($ids) + 2, 'batch row identities are newly assigned');

// Normal native PageEdit processing merges draft rows with pending target edits.
$targetPost[FlexPath::controlName($target->name, $targetSet, 'order')] = $cross['id'];
$targetPost[FlexPath::inputPrefix($target->name, $parent->address()) . 'caption'] = 'Unsaved target';
$actualPrefix = FlexPath::inputPrefix($target->name, $actual->address());
$targetPost[$actualPrefix . 'heading'] = 'Edited after paste';
$targetPost[$actualPrefix . 'link'] = (string) $firstReference->id;
$targetPost[$actualPrefix . 'links'] = [(string) $secondReference->id, (string) $firstReference->id];
$targetPost[FlexPath::controlName($target->name, $actual->address(), 'hidden')] = '1';
$targetEditor->processInput($wire->wire(new WireInputData($targetPost)));
$same([], $targetEditor->getErrors(), 'native input processing accepts the inserted nested row');
$second->set($target->name, $targetEditor->value);
$second->save($target->name);
$saved = $wire->pages->getFresh($second->id)->getUnformatted($target->name)->parents->first();
$same('Unsaved target', $saved->caption, 'page save retains the pre-existing pending target change');
$same('Edited after paste', $saved->children->first()->heading, 'page save persists the pasted row and its later edits');
$same($cross['id'], $saved->children->first()->rowId(), 'the saved row retains its new identity');
$same($firstReference->id, $saved->children->first()->link->id, 'normal save persists the resolved destination Page ID');
$same([$secondReference->id, $firstReference->id], array_map('intval', $saved->children->first()->links->explode('id')), 'normal save persists the ordered local Page selection');

$probeReference = static function(array $entry, string $site) use ($wire, $type, $second, $target, $targetDraft, $payload): array {
	$blank = $type->getBlankValue($second, $target);
	$probeParent = $blank->parents->addRow('group');
	$data = $payload;
	$data['site'] = $site;
	$data['row']['values'] = ['link' => $entry];
	$clipboard = $wire->wire(new FlexClipboard());
	[$row] = $clipboard->paste($probeParent->children, $probeParent->definitions()->property('children'), json_encode($data, JSON_THROW_ON_ERROR), $targetDraft);
	return [$row->link instanceof Page ? $row->link->id : $row->link, $clipboard->warningSummary()];
};
$legacyEntry = ['kind' => 'page', 'value' => $firstReference->id];
$same([$firstReference->id, ''], $probeReference($legacyEntry, $payload['site']), 'existing ID-only clipboard data still works within its installation');
[$rejected, $warning] = $probeReference($legacyEntry, $crossSite['site']);
$same(false, $rejected, 'ID-only clipboard data cannot resolve on another installation');
$assert(str_contains($warning, 'link'), 'an old unresolved reference is reported');
$locator = $portableReference($firstReference);
$rejectedLocators = [
	'partial path' => ['path' => '/missing-parent/' . $firstReference->name . '/'] + $locator,
	'noncanonical path' => ['path' => rtrim($firstReference->path(), '/')] + $locator,
	'mismatched exported parent' => ['parent' => '/another-parent/'] + $locator,
	'mismatched exported template' => ['template' => 'another-template'] + $locator,
	'parent excluded by the destination input' => $portableReference($wrongParent),
	'template excluded by the destination input' => $portableReference($wrongTemplate),
	'selector excluded by the destination input' => $portableReference($excludedReference),
	'unpublished destination page' => $portableReference($unpublished),
	'destination owner itself' => $portableReference($second),
];
foreach($rejectedLocators as $label => $reference) {
	$entry = ['kind' => 'page', 'value' => $foreignID, 'references' => [$foreignID => $reference]];
	[$rejected, $warning] = $probeReference($entry, $crossSite['site']);
	$same(false, $rejected, 'paste rejects ' . $label);
	$assert(str_contains($warning, 'link'), 'paste reports ' . $label);
}

// Compare against the concrete definition when the owner context changes after hydration.
$dynamic = $createField('flex_clip_dynamic', []);
$dynamicFile = $trackFile($wire->config->paths->templates . 'flex-fields/row-clipboard.php');
file_put_contents($dynamicFile, <<<'DEF'
<?php namespace ProcessWire;
return [
	'items' => ['type' => 'rows', 'types' => $page->clipboard_variant
		? ['card' => ['children' => ['heading' => ['type' => 'text'], 'new' => ['type' => 'text', 'value' => 'Evaluated now']]]]
		: ['banner' => ['children' => ['heading' => ['type' => 'text']]]]],
];
DEF
);
$dynamic->flexDefinition = '';
$dynamic->flexDefinitionFile = 'flex-fields/row-clipboard.php';
$wire->fields->save($dynamic);
$second->setQuietly('clipboard_variant', false);
$cached = $second->getUnformatted($dynamic->name);
$assert(isset($cached->definitions()->property('items')['types']['banner']), 'initial context hydrates a different row type');
$second->setQuietly('clipboard_variant', true);
[, , , $dynamicDraft] = $render($second, $dynamic);
$dynamicPaste = $ajax($second, $dynamic, $dynamicDraft, $paste('items', 'paste-end', [], $text));
$same(false, $dynamicPaste['error'], 'paste reevaluates the dynamic target schema rather than using a hydrated snapshot');
$assert(str_contains($dynamicPaste['html'], 'Evaluated now'), 'the rendered inserted row uses live dynamic defaults');

// A rejected registration must abandon any staged copies along with the draft row.
$budget = $createField('flex_clip_budget', ['items' => ['type' => 'rows', 'types' => $sourceTypes]]);
$budget->flexMaxRows = 1;
$wire->fields->save($budget);
[, , , $budgetDraft] = $render($second, $budget);
$assetQuery = $wire->database->prepare('SELECT COUNT(*) FROM fieldtype_flex_assets WHERE fields_id=:field');
$assetCount = static function() use ($assetQuery, $budget): int {
	$assetQuery->execute(['field' => (int) $budget->id]);
	$count = (int) $assetQuery->fetchColumn();
	$assetQuery->closeCursor();
	return $count;
};
$beforeBudget = $type->admin()->draft($budgetDraft);
$beforeAssets = $assetCount();
$overBudget = $ajax($second, $budget, $budgetDraft, $paste('items', 'paste-end', [], $text));
$same(true, $overBudget['error'], 'nested rows count against the existing field tree budget');
$assert(str_contains($overBudget['message'], 'tree budget'), 'the budget failure explains why the row was rejected');
$same($beforeBudget, $type->admin()->draft($budgetDraft), 'budget failure restores the entire draft');
$same($beforeAssets, $assetCount(), 'budget failure abandons media staged for the rejected row');

[, , , $staleDraft] = $render($second, $target);
$latest = $second->getUnformatted($target->name);
$latest->parents->first()->caption = 'Updated independently';
$second->save($target->name);
$stale = $ajax($second, $target, $staleDraft, $paste('invalid', 'paste-end', [], $text));
$same(true, $stale['error'], 'stale field revisions cannot receive pasted rows');
$assert(str_contains($stale['message'], 'changed in another request'), 'stale draft reports the normal revision error');
