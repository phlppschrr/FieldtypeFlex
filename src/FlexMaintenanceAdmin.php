<?php namespace ProcessWire;

/** Superuser-only field configuration and bounded POST endpoint. @internal */
final class FlexMaintenanceAdmin extends Wire {
	private FieldtypeFlex $type;

	public function __construct(FieldtypeFlex $type) {
		parent::__construct();
		$type->wire($this);
		$this->type = $type;
	}

	/** The ordinary config render adds controls only, never a data scan. */
	public function addInput(InputfieldWrapper $wrapper, Field $field): void {
		if(!$field->id || !$this->wire()->user->isSuperuser()) return;
		$modules = $this->wire()->modules;
		$group = $modules->get('InputfieldFieldset');
		$group->name = 'flexMaintenance';
		$group->label = $this->_('Data maintenance');
		$group->collapsed = Inputfield::collapsedYes;
		$input = $modules->get('InputfieldMarkup');
		$input->label = $this->_('Preview and explicitly apply data changes');
		$escape = fn($text) => $this->wire()->sanitizer->entities($text);
		$messages = [
			'checked' => $this->_('Checked pages'),
			'changed' => $this->_('Changed pages'),
			'unchanged' => $this->_('Unchanged pages'),
			'wouldChange' => $this->_('Pages with proposed changes'),
			'renames' => $this->_('Renames'),
			'orphans' => $this->_('Potentially orphaned properties'),
			'issues' => $this->_('Conflicts and errors'),
			'path' => $this->_('Property path'),
			'from' => $this->_('Old path'),
			'to' => $this->_('New path'),
			'pageIds' => $this->_('Page IDs'),
			'pageCount' => $this->_('Affected pages'),
			'media' => $this->_('Media references'),
			'address' => $this->_('Stored address'),
			'message' => $this->_('Message'),
			'retry' => $this->_('Inspect errors and retry page IDs, then run a new preview.'),
			'renamed' => $this->_('Checked old keys migrated. Run a new full preview before removing renamedFrom.'),
			'cleaned' => $this->_('Selected cleanup completed.'),
			'select' => $this->_('Select explicit property paths.'),
			'csrf' => $this->_('Missing CSRF token.'),
			'previewed' => $this->_('Preview completed.'),
			'noRenames' => $this->_('No pending renames remain in this complete scan. The checked renamedFrom attributes can now be removed.'),
		];
		$input->value = '<div class="FlexMaintenance" data-field="' . (int) $field->id . '" data-messages="' . $escape(json_encode($messages, JSON_THROW_ON_ERROR)) . '">'
			. '<p>' . $escape($this->_('Potentially orphaned does not mean safe to delete. Dynamic definitions may intentionally omit properties.')) . '</p>'
			. '<p><button type="button" class="ui-button" data-flex-maintenance="rename">' . $escape($this->_('Preview renames')) . '</button> '
			. '<button type="button" class="ui-button" data-flex-maintenance="orphans">' . $escape($this->_('List potentially orphaned properties')) . '</button></p>'
			. '<div class="FlexMaintenancePaths"></div><p>'
			. '<button type="button" class="ui-button" data-flex-maintenance="cleanup" disabled>' . $escape($this->_('Preview selected cleanup')) . '</button> '
			. '<button type="button" class="ui-button" data-flex-maintenance="apply" disabled>' . $escape($this->_('Apply checked changes')) . '</button></p>'
			. '<p class="FlexMaintenanceProgress" role="status"></p><div class="FlexMaintenanceReport"></div></div>';
		$group->add($input);
		$wrapper->add($group);
		$this->wire()->config->scripts->add($this->wire()->config->urls->get('FieldtypeFlex') . 'FlexMaintenance.js');
		$this->wire()->config->styles->add($this->wire()->config->urls->get('FieldtypeFlex') . 'FieldtypeFlex.css');
	}

	/** Handle only explicit POSTs, before ProcessField can process a configuration save. */
	public function dispatch(): void {
		$input = $this->wire()->input;
		if(!$input->post('flexMaintenanceAction')) return;
		header('Content-Type: application/json; charset=utf-8');
		try {
			if(!$this->wire()->user->isSuperuser() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new WireException('Superuser POST required');
			$this->wire()->session->CSRF->validate();
			$field = $this->wire()->fields->get((int) $input->post('flexMaintenanceField'));
			if(!$field || !$field->type instanceof FieldtypeFlex || (int) $input->get('id') !== (int) $field->id) throw new WireException('Invalid maintenance field');
			$action = (string) $input->post('flexMaintenanceAction');
			$payload = json_decode((string) $input->post('flexMaintenancePayload'), true, FlexPath::JSON_DEPTH, JSON_THROW_ON_ERROR);
			if(!is_array($payload)) throw new WireException('Invalid maintenance payload');
			$result = match($action) {
				'rename' => $this->type->checkRenames($field, (int) ($payload['after'] ?? 0), 50),
				'orphans' => $this->type->listOrphans($field, (int) ($payload['after'] ?? 0), 50),
				'cleanup' => $this->type->cleanupProperties($field, $payload['paths'] ?? [], $payload['preview'] ?? []),
				'applyRename' => $this->type->applyRenames($field, $payload['preview'] ?? []),
				'applyCleanup' => $this->type->cleanupProperties($field, $payload['paths'] ?? [], $payload['preview'] ?? [], true),
				default => throw new WireException('Unknown maintenance action'),
			};
			echo json_encode($result, JSON_THROW_ON_ERROR);
		} catch(\Throwable $error) {
			http_response_code(400);
			echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
		}
		exit;
	}
}
