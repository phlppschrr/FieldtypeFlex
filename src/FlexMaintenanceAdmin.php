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
		$escape = fn($text) => $this->wire()->sanitizer->entities($text);
		$messages = [
			'checked' => $this->_('Checked pages'),
			'changed' => $this->_('Changed pages'),
			'wouldChange' => $this->_('Pages with proposed changes'),
			'issues' => $this->_('Conflicts and errors'),
			'path' => $this->_('Property path'),
			'from' => $this->_('Old path'),
			'to' => $this->_('New path'),
			'pageIds' => $this->_('Page IDs'),
			'pageCount' => $this->_('Affected pages'),
			'media' => $this->_('Media references'),
			'address' => $this->_('Stored addresses'),
			'message' => $this->_('Message'),
			'retry' => $this->_('Resolve reported errors and run a new check. Previously applied changes are retained.'),
			'renamed' => $this->_('Checked old keys migrated. Run a new full preview before removing renamedFrom.'),
			'cleaned' => $this->_('Selected cleanup completed.'),
			'select' => $this->_('Select explicit property paths.'),
			'csrf' => $this->_('Missing CSRF token.'),
			'noChanges' => $this->_('No changes found.'),
			'noOrphans' => $this->_('No unknown properties found.'),
			'noRenames' => $this->_('No pending renames remain in this complete scan. The checked renamedFrom attributes can now be removed.'),
		];
		$button = static function(string $action, string $label, bool $hidden = false) use ($modules): string {
			$input = $modules->get('InputfieldButton');
			$input->name = 'flexMaintenance_' . $action;
			$input->value = $label;
			$input->attr('data-flex-maintenance', $action);
			if($hidden) $input->attr(['hidden' => 'hidden', 'disabled' => 'disabled']);
			return $input->render() . ' ';
		};
		$report = '<p class="FlexMaintenanceProgress" role="status" aria-live="polite"></p><div class="FlexMaintenanceReport"></div>';
		$tasks = [
			'flexMaintenanceRenames' => [
				'label' => $this->_('Apply property renames'),
				'icon' => 'exchange',
				'description' => $this->_('Check stored values against renamedFrom before applying renames.'),
				'value' => '<p>' . $button('rename', $this->_('Check renames'))
					. $button('applyRename', $this->_('Apply checked renames'), true) . '</p>' . $report,
			],
			'flexMaintenanceCleanup' => [
				'label' => $this->_('Remove unknown properties'),
				'icon' => 'eraser',
				'description' => $this->_('Potentially orphaned does not mean safe to delete. Dynamic definitions may intentionally omit properties.'),
				'value' => '<p>' . $button('orphans', $this->_('Find unknown properties')) . '</p><div class="FlexMaintenancePaths"></div><p>'
					. $button('cleanup', $this->_('Check selection'), true)
					. $button('applyCleanup', $this->_('Remove selected properties'), true) . '</p>' . $report,
			],
		];
		foreach($tasks as $name => $task) {
			$input = $modules->get('InputfieldMarkup');
			$input->name = $name;
			$input->label = $task['label'];
			$input->icon = $task['icon'];
			$input->description = $task['description'];
			$input->collapsed = Inputfield::collapsedYes;
			$input->value = '<div class="FlexMaintenance" data-field="' . (int) $field->id . '" data-messages="' . $escape(json_encode($messages, JSON_THROW_ON_ERROR)) . '">'
				. '<section class="FlexMaintenanceTask">' . $task['value'] . '</section></div>';
			$delete = $wrapper->getChildByName('delete');
			if($delete) $wrapper->insertBefore($input, $delete);
			else $wrapper->add($input);
		}
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
			// Native admin translations may contain entities. The JSON client uses
			// textContent, so send human-readable messages rather than HTML text.
			foreach(['errors', 'conflicts'] as $kind) {
				foreach($result[$kind] as &$issue) {
					$issue['message'] = $this->wire()->sanitizer->unentities($issue['message']);
				}
				unset($issue);
			}
			echo json_encode($result, JSON_THROW_ON_ERROR);
		} catch(\Throwable $error) {
			http_response_code(400);
			echo json_encode(['error' => $this->wire()->sanitizer->unentities($error->getMessage())], JSON_THROW_ON_ERROR);
		}
		exit;
	}
}
