<?php namespace ProcessWire;

if(PHP_SAPI !== 'cli' || !isset($same, $createField)) {
	http_response_code(404);
	exit;
}

$media = $createField('flex_variations', [
	'heading' => ['type' => 'text'],
	'hero' => ['type' => 'image'],
	'blocks' => [
		'type' => 'rows',
		'types' => ['image' => ['children' => ['photo' => ['type' => 'image']]]],
	],
]);
$page = $wire->pages->getFresh($id);
$page->of(false);
$value = $page->getUnformatted($media->name);
$fixture = $trackFile(dirname(__DIR__, 2) . '/.dev/flex-variation-fixture.png');
$image = imagecreatetruecolor(80, 60);
imagepng($image, $fixture);
imagedestroy($image);
$value->hero->add($fixture);
$row = $value->blocks->addRow('image');
$row->photo->add($fixture);
$page->save($media->name);
$baseline = $type->storage()->persisted($page, $media);
$value->heading = 'Unsaved heading';
$token = $type->admin()->open($value, $media->name);
$draftFiles = $value->blocks->addRow('image')->photo;
$type->admin()->registerRow($token, 'blocks', $value->blocks->last());
$draftFiles->draft($token);
$draftFiles->add($fixture);
$type->admin()->stageMedia($token, $draftFiles->address(), $draftFiles);
$get = $wire->input->get;
$post = $wire->input->post;
$savedGet = $get->getArray();
$savedPost = $post->getArray();
$redirectHook = null;
try {
	foreach([$value->hero, $row->photo, $draftFiles] as $files) {
		$file = $files->first();
		$variation = $file->size(31, 23);
		$variationPath = $variation->filename();
		$get->removeAll()->setArray([
			'id' => $page->id,
			'file' => $file->basename(),
			'flex_draft' => $token,
			'varcnt' => 'varcnt_fixture',
			'modal' => 1,
		]);
		$post->removeAll();
		$wire->modules->get('ProcessPageEditImageSelect');
		$process = $wire->wire(new ProcessPageEditImageSelect());
		$process->init();
		$html = $process->executeVariations();
		$assert(str_contains($html, $variation->basename()), 'native variations dialog lists root, nested and draft-only derivatives');
		$assert(str_contains($html, 'flex_draft=' . $token), 'native variations form preserves the authenticated Flex draft');
		$post->setArray(['delete' => [$variation->basename(), $file->basename(), '../unowned.png']]);
		$post->{$wire->session->CSRF->getTokenName()} = $wire->session->CSRF->getTokenValue();
		$redirectUrl = '';
		$redirectHook = $wire->session->addHookBefore('redirect', function(HookEvent $event) use (&$redirectUrl) {
			$redirectUrl = $event->arguments(0);
			$event->replace = true;
		}, ['priority' => 200]);
		$process->executeVariations();
		$wire->session->removeHook($redirectHook);
		$redirectHook = null;
		$assert(!is_file($variationPath), 'native dialog immediately deletes a selected variation without a field save');
		$assert(is_file($file->filename()), 'native variation deletion cannot delete the original');
		parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);
		$same($token, $query['flex_draft'] ?? null, 'native deletion redirect preserves draft-only image discovery');
		$same('varcnt_fixture', $query['varcnt'] ?? null, 'native deletion redirect preserves the parent variation counter');
		$assert(is_file($file->size(31, 23)->filename()), 'deleted variation can be regenerated through the native Pageimage API');
		$same($baseline, $type->storage()->persisted($page, $media), 'variation management leaves canonical Flex data unchanged');
	}
	$same('Unsaved heading', $value->heading, 'variation management preserves unrelated pending scalar edits');
	$post->removeAll();
	$get->file = $value->hero->first()->basename();
	$get->flex_draft = $type->admin()->open($second->getUnformatted($media->name), $media->name);
	$process = $wire->wire(new ProcessPageEditImageSelect());
	$process->init();
	$throws(fn() => $process->executeVariations(), 'variation dialog rejects a draft belonging to another page', WirePermissionException::class);
	$get->flex_draft = $token;
	$post->removeAll()->setArray(['delete' => ['anything.png']]);
	$process = $wire->wire(new ProcessPageEditImageSelect());
	$process->init();
	$throws(fn() => $process->executeVariations(), 'variation deletion requires a valid CSRF token', WireCSRFException::class);
} finally {
	if($redirectHook) $wire->session->removeHook($redirectHook);
	$get->removeAll()->setArray($savedGet);
	$post->removeAll()->setArray($savedPost);
}
