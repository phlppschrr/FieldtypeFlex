<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$leaves = [
	'heading' => ['type' => 'text', 'textformatters' => ['TextformatterEntities']],
	'choice' => ['type' => 'select', 'label' => 'A & B', 'options' => ['a' => 'A & B']],
	'date' => ['type' => 'datetime', 'dateOutputFormat' => 'd.m.Y'],
	'translation' => ['type' => 'text', 'useLanguages' => true, 'textformatters' => ['TextformatterEntities']],
	'references' => ['type' => 'page', 'inputfield' => 'InputfieldAsmSelect', 'derefAsPage' => 0],
	'file' => ['type' => 'file', 'extensions' => 'txt', 'maxFiles' => 1, 'textformatters' => ['TextformatterEntities']],
];
$children = ['type' => 'rows', 'types' => ['note' => ['children' => $leaves]]];
$readField = $createField('flex_row_read_views', [
	'items' => ['type' => 'rows', 'types' => ['item' => ['children' => $leaves + ['notes' => $children]]]],
]);
$name = $readField->name;
$page->of(false);
$value = $page->getUnformatted($name);
$a = $value->items->addRow('item', [
	'heading' => 'A & <B>',
	'date' => '2026-10-23',
	'translation' => [$defaultLanguage->id => 'Default & text', $language->id => 'Deutsch & Text'],
	'references' => [1],
	'future' => ['keep' => true],
]);
$b = $value->items->addRow('item', ['heading' => 'Hidden & parent']);
$b->setHidden(true);
$child = $a->notes->addRow('note', ['heading' => 'Child & text']);
$secret = $a->notes->addRow('note', ['heading' => 'Hidden & child']);
$secret->setHidden(true);
$b->notes->addRow('note', ['heading' => 'Inside hidden parent']);
$path = $trackFile(dirname(__DIR__, 2) . '/.dev/row-read-views-' . bin2hex(random_bytes(4)) . '.txt');
file_put_contents($path, 'Read view file');
$a->file->add($path);
$a->file->first()->description = 'File & description';
$page->save($name);
$page = $wire->pages->getFresh($id);
$page->of(false);
$original = $page->getUnformatted($name);
$original->resetTrackChanges();
$page->resetTrackChanges();
$raw = $original->rawArray();
$revision = $original->expectedRevision();
$page->of(true);
$formatted = $page->get($name);
$formattedChanged = $formatted->isChanged();
$formattedChanges = $formatted->getChanges();
$rows = $formatted->getUnformatted('items');
$same([$a->rowId(), $b->rowId()], array_keys($rows->getArray()), 'explicit raw row read includes hidden parents in canonical order');
$same(1, count($formatted->items), 'raw row read preserves source visible membership');
$same('A &amp; &lt;B&gt;', $formatted->items->first()->heading, 'raw row read preserves source output formatting');
$row = $rows->getById($a->rowId());
$same('A & <B>', $row->heading, 'raw row read bypasses textformatters');
$same('A & B', $row->label('choice'), 'raw row read bypasses label encoding');
$same('A & B', $row->optionLabel('choice', 'a'), 'raw row read bypasses option label encoding');
$same(strtotime('2026-10-23'), $row->date, 'raw row read returns native date timestamp');
$assert($row->translation instanceof LanguagesPageFieldValue, 'raw row read returns native multilingual object');
$same('Deutsch & Text', $row->translation->getLanguageValue($language), 'raw row read retains all translations');
$assert($row->references instanceof PageArray, 'raw row read retains native reference container');
$same(2, count($row->notes), 'raw row read includes hidden descendants');
$same('Hidden & child', $row->notes->getById($secret->rowId())->heading, 'raw descendant read bypasses formatters');
$same('Inside hidden parent', $rows->getById($b->rowId())->notes->first()->heading, 'raw read traverses hidden parent');
$assert($row->file instanceof FlexFiles, 'raw row read bypasses single-file output mode');
$same('File & description', $row->file->first()->description, 'raw row read bypasses media description formatting');
$same($raw['items'], $rows->rawArray(), 'raw read retains unknown values, row identity and visibility');
$same([$a->rowId()], array_keys($formatted->items->find('notes.count=1')->getArray()), 'formatted find counts visible children');
$same([$a->rowId()], array_keys($rows->find('notes.count=2')->getArray()), 'raw read find counts all children');
$assert($formatted->items->find('heading=A & <B>')->first() === $formatted->items->first(), 'formatted find retains source row identity');
$assert($rows->find('heading=A & <B>')->first() === $row, 'raw read find retains view row identity');

// A result owns only its selected membership, even when read without formatting.
$selection = $formatted->items->find('heading=A & <B>');
$selected = $selection->unformattedCollection();
$same([$a->rowId()], array_keys($selected->getArray()), 'raw search result does not resurrect excluded rows');
$same([], $formatted->items->find('heading=Absent')->unformattedCollection()->getArray(), 'empty raw search result stays empty');
$same(2, count($selected->first()->notes), 'raw search result includes all children of its selected parent');

// Native mutable containers are detached; read guards survive a Page mode switch.
$row->translation->setLanguageValue($language, 'Detached translation');
$row->references->add($wire->pages->get(3));
$same('Deutsch & Text', $formatted->items->first()->getUnformatted('translation')->getLanguageValue($language), 'raw language mutations leave formatted source intact');
$same([1], $formatted->items->first()->getUnformatted('references')->explode('id'), 'raw reference mutations leave formatted source intact');
$page->of(false);
$throws(fn() => $row->set('heading', 'Bad'), 'raw row read retains formatted setter guard');
$throws(fn() => $row->setHidden(true), 'raw row read retains visibility guard');
$throws(fn() => $rows->addRow('item'), 'raw row read retains collection add guard');
$throws(fn() => $rows->removeRow($b->rowId()), 'raw row read retains collection remove guard');
$throws(fn() => $row->notes->first()->set('heading', 'Bad'), 'raw descendant retains setter guard');
$throws(fn() => $row->file->first()->set('description', 'Bad'), 'raw descendant media retains setter guard');
$throws(fn() => $row->file->removeAll(), 'raw descendant media retains collection guard');
$same($raw, $original->rawArray(), 'raw reads and detached mutations leave canonical source intact');
$same($raw, $formatted->rawArray(), 'raw reads leave formatted backing intact');
$same($revision, $original->expectedRevision(), 'raw reads preserve source revision');
$same(false, $original->isChanged(), 'raw reads preserve source tracking');
$same($formattedChanged, $formatted->isChanged(), 'raw reads preserve formatted source tracking');
$same($formattedChanges, $formatted->getChanges(), 'raw reads preserve formatted source change names');
$same(false, $page->isChanged($name), 'raw reads preserve owning field tracking');
$same($raw, $wire->pages->getFresh($id)->getUnformatted($name)->rawArray(), 'raw reads leave persisted data intact');
$assert($original->getUnformatted('items') === $original->items, 'unformatted original still exposes its live owned rows');
$toggle = clone $rows;
$toggle->of(true);
$same(1, count($toggle), 'explicit formatting restores visible membership on raw read clone');
$same('A &amp; &lt;B&gt;', $toggle->first()->heading, 'explicit formatting resets raw read override');
$toggle->of(false);
$same(2, count($toggle), 'explicit unformatting restores complete clone membership');
$same('A & <B>', $toggle->first()->heading, 'explicit unformatting returns native leaves');
$same(2, count($rows), 'changing cloned read mode preserves prior raw read membership');
