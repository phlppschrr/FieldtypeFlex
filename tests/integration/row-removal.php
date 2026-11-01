<?php namespace ProcessWire;
if(PHP_SAPI !== 'cli' || !isset($same, $createField)) { http_response_code(404); exit; }

$collection = ['type' => 'rows', 'rowLazyLoad' => true, 'types' => ['item' => ['rowTitle' => '{heading}', 'children' => ['heading' => ['type' => 'text', 'required' => true, 'minlength' => 3]]]]];
$removalField = $createField('flex_removal', [
 'heading' => ['type' => 'text', 'required' => true, 'minlength' => 3],
 'items' => ['minItems' => 1, 'maxItems' => 2] + $collection,
 'sections' => ['type' => 'rows', 'types' => ['section' => ['children' => ['children' => $collection]]]],
]);
$name = $removalField->name;
$page->of(false); $v = $page->getUnformatted($name); $v->heading = 'Root valid';
$a = $v->items->addRow('item',['heading'=>'First']);
$b = $v->items->addRow('item',['heading'=>'Second', 'legacy'=>['keep'=>true]]); $b->setHidden(true);
$parent = $v->sections->addRow('section'); $child = $parent->children->addRow('item',['heading'=>'Child']);
$page->save($name);
$baseline = $wire->pages->getFresh($id)->getUnformatted($name)->rawArray();
$storedBaseline = json_encode($type->storage()->document($page, $removalField), JSON_THROW_ON_ERROR);
$hiddenPost = static function(string $html): array {
 $dom = new \DOMDocument(); $previous = libxml_use_internal_errors(true); $dom->loadHTML('<html><body>'.$html.'</body></html>'); libxml_clear_errors(); libxml_use_internal_errors($previous);
 $post = []; foreach((new \DOMXPath($dom))->query('//input[@type="hidden"]') as $input) $post[$input->getAttribute('name')] = $input->getAttribute('value');
 return $post;
};
$makeEditor = static function() use ($wire,$id,$name,$removalField): InputfieldFlex {
 $owner = $wire->pages->getFresh($id); $owner->of(false); $input = $removalField->getInputfield($owner); $input->value = $owner->getUnformatted($name); return $input;
};
$editor = $makeEditor(); $html = $editor->render(); $post = $hiddenPost($html);
$post[$name.'__heading'] = 'x';
$post[FlexPath::controlName($name,$a->address(),'remove')] = '1';
$editor->processInput($wire->wire(new WireInputData($post)));
$assert(count($editor->getErrors())>0,'retained root validation still blocks a removal save');
$same($baseline,$editor->value->rawArray(),'failed save retains complete canonical collection');
$same($storedBaseline,json_encode($type->storage()->document($page,$removalField), JSON_THROW_ON_ERROR),'marking alone changes no stored rows');
$recovery = $editor->render();
$assert(str_contains($recovery,'flex-row-remove-pending'),'failed validation keeps removal mark in recovery UI');
$assert(str_contains($recovery,'Undo removal') && str_contains($recovery,'aria-pressed="true"'),'recovery exposes accessible undo action');
$editor->getErrors(true); $editor->inputs()->getErrors(true);
$post = $hiddenPost($recovery); $post[$name.'__heading'] = 'Root corrected';
$valid = $makeEditor(); $valid->processInput($wire->wire(new WireInputData($post)));
$same([],$valid->getErrors(),'unopened removed row needs no lazy body or leaf validation');
$same([$b->rowId()],array_keys($valid->value->items->getArray()),'processed value omits marked row only');
$same($storedBaseline,json_encode($type->storage()->document($page,$removalField), JSON_THROW_ON_ERROR),'processing removal does not persist before Page save');
$page->set($name,$valid->value); $page->save($name);
$saved = $wire->pages->getFresh($id)->getUnformatted($name);
$same([$b->rowId()],array_keys($saved->items->getArray()),'ordinary Page save commits removal');
$same(true,$saved->items->first()->isHidden(),'unremoved hidden row retains visibility');
$same(['keep'=>true],$saved->items->first()->rawArray()['legacy'],'removal retains unknown sibling content');
$same([], $wire->pages->findIDs("id=$id, $name.items.item.heading=First"),'removal updates selector projection');

// Revert a mark in a later submission; no canonical removal metadata is introduced.
$editor = $makeEditor(); $post = $hiddenPost($editor->render());
$post[$name.'__heading'] = 'x'; $post[FlexPath::controlName($name,$b->address(),'remove')] = '1';
$editor->processInput($wire->wire(new WireInputData($post))); $recovery = $editor->render();
$editor->getErrors(true); $editor->inputs()->getErrors(true);
$post = $hiddenPost($recovery); $post[$name.'__heading'] = 'Root corrected';
$post[FlexPath::controlName($name,$b->address(),'remove')] = '0';
$undo = $makeEditor(); $undo->processInput($wire->wire(new WireInputData($post)));
$same([],$undo->getErrors(),'undo of a mark restores normal validation');
$same(1,count($undo->value->items),'undo keeps row in candidate value');
$assert(!array_key_exists('remove',$undo->value->rawArray()['items']['items'][0]),'removal mark never enters canonical row metadata');

// Removing last row must fail minimum and keep undo available, not discard its UI.
$post[FlexPath::controlName($name,$b->address(),'remove')] = '1';
$minimum = $makeEditor(); $minimum->processInput($wire->wire(new WireInputData($post)));
$assert(count($minimum->getErrors())>0,'minimum counts rows remaining after marked removal');
$assert(str_contains($minimum->render(),'Undo removal'),'all-marked minimum failure keeps recoverable row shell');
$minimum->getErrors(true); $minimum->inputs()->getErrors(true);

// Forged transport remains strict and cannot remove an unknown row.
$bad = $post; $bad[FlexPath::controlName($name,$b->address(),'remove')] = 'yes';
$invalid = $makeEditor(); $invalid->processInput($wire->wire(new WireInputData($bad)));
$assert(count($invalid->getErrors())>0,'nonboolean removal transport is rejected');
$invalid->getErrors(true); $invalid->inputs()->getErrors(true);
$bad = $post; $bad[FlexPath::controlName($name,'items','order')] = str_repeat('f',32);
$invalid = $makeEditor(); $invalid->processInput($wire->wire(new WireInputData($bad)));
$assert(count($invalid->getErrors())>0,'marking cannot authorize an unknown row identity');
$invalid->getErrors(true); $invalid->inputs()->getErrors(true);

// Nested removals omit descendants without requiring unopened child bodies.
$editor = $makeEditor(); $post = $hiddenPost($editor->render()); $post[$name.'__heading'] = 'Root valid';
$post[FlexPath::controlName($name,$parent->address(),'remove')] = '1';
$editor->processInput($wire->wire(new WireInputData($post)));
$same([],$editor->getErrors(),'removing a parent skips descendant body requirements');
$same(0,count($editor->value->sections),'marked parent omits the whole subtree');
$same(1,count($wire->pages->getFresh($id)->getUnformatted($name)->sections),'unsaved parent removal leaves persisted subtree intact');

// A nested child can be removed independently while retaining its parent identity.
$editor=$makeEditor(); $post=$hiddenPost($editor->render()); $post[$name.'__heading']='Root valid';
$post[FlexPath::controlName($name,$child->address(),'remove')]='1';
$editor->processInput($wire->wire(new WireInputData($post)));
$same([],$editor->getErrors(),'independent nested child removal validates normally');
$same($parent->rowId(),$editor->value->sections->first()->rowId(),'child removal preserves parent identity');
$same(0,count($editor->value->sections->first()->children),'only the marked nested child is omitted');

// Registered unsaved rows use the same mark, without ever entering persistence.
$editor=$makeEditor(); $post=$hiddenPost($editor->render()); $post[$name.'__heading']='Root valid';
$draft=$post[$name.'__0draft']; $owner=$editor->value->ownerPage();
$standalone=new FlexRows($owner,$removalField,'items',$type->getDefinitions($removalField,$owner)->property('items'));
$new=$standalone->addRow('item'); $type->admin()->registerRow($draft,'items',$new);
$post[FlexPath::controlName($name,'items','order')].=','.$new->rowId();
$post[FlexPath::controlName($name,$new->address(),'remove')]='1';
$editor->processInput($wire->wire(new WireInputData($post)));
$same([],$editor->getErrors(),'marked registered new row needs no required blank leaf');
$same(1,count($editor->value->items),'marked new row is excluded from candidate collection');
$assert(str_contains($editor->render(),$new->rowId()),'unsaved removed row remains in recovery markup for undo');

// Visibility toggles also survive a failed save while the row is marked for removal.
$editor=$makeEditor(); $post=$hiddenPost($editor->render()); $post[$name.'__heading']='x';
$post[FlexPath::controlName($name,$b->address(),'remove')]='1';
$post[FlexPath::controlName($name,$b->address(),'hidden')]='0';
$editor->processInput($wire->wire(new WireInputData($post)));
$recovery=$hiddenPost($editor->render());
$same('0',$recovery[FlexPath::controlName($name,$b->address(),'hidden')],'pending removal recovery retains submitted visibility for undo');
$editor->getErrors(true); $editor->inputs()->getErrors(true);

// Eager pending bodies retain submitted input edits while suppressing their errors.
$eager = clone $removalField; $defs=json_decode($eager->flexDefinition,true); $defs['items']['rowLazyLoad']=false; $eager->flexDefinition=json_encode($defs);
$owner=$wire->pages->getFresh($id); $owner->of(false); $editor=$eager->getInputfield($owner); $editor->value=$owner->getUnformatted($name);
$post=$hiddenPost($editor->render()); $post[$name.'__heading']='x';
$post[FlexPath::controlName($name,$b->address(),'remove')]='1';
$post[FlexPath::inputPrefix($name,$b->address()).'heading']='Pending unsaved edit';
$editor->processInput($wire->wire(new WireInputData($post)));
$assert(str_contains($editor->render(),'Pending unsaved edit'),'pending eager edits survive unrelated failed validation for later undo');


// Media ownership is untouched by processing a mark; only the normal field save removes it.
$mediaField=$createField('flex_removal_media',['attachments'=>['type'=>'rows','types'=>['asset'=>['children'=>['photo'=>['type'=>'image','maxFiles'=>1]]]]]]);
$mediaName=$mediaField->name; $owner=$wire->pages->getFresh($id); $owner->of(false);
$assetRow=$owner->getUnformatted($mediaName)->attachments->addRow('asset');
$imagePath=$trackFile(dirname(__DIR__,2).'/.dev/removal-'.bin2hex(random_bytes(4)).'.png');
$image=imagecreatetruecolor(16,16); imagepng($image,$imagePath); imagedestroy($image);
$assetRow->photo->add($imagePath); $owner->save($mediaName);
$owner=$wire->pages->getFresh($id); $owner->of(false); $assetRow=$owner->getUnformatted($mediaName)->attachments->first();
$mediaBefore=json_encode($type->storage()->document($owner,$mediaField), JSON_THROW_ON_ERROR); $filename=$assetRow->photo->first()->filename(); $hash=hash_file('sha256',$filename);
$editor=$mediaField->getInputfield($owner); $editor->value=$owner->getUnformatted($mediaName);
$post=$hiddenPost($editor->render()); $post[FlexPath::controlName($mediaName,$assetRow->address(),'remove')]='1';
$editor->processInput($wire->wire(new WireInputData($post)));
$same([],$editor->getErrors(),'marked media row processes without saving');
$same($mediaBefore,json_encode($type->storage()->document($owner,$mediaField), JSON_THROW_ON_ERROR),'media canonical references survive mark processing');
$same($hash,hash_file('sha256',$filename),'marked media file remains intact before Save');
$owner->set($mediaName,$editor->value); $owner->save($mediaName);
$same(0,count($wire->pages->getFresh($id)->getUnformatted($mediaName)->attachments),'only actual Save removes the media row');
