<?php namespace ProcessWire;

/** A trusted PHP/JSON definition could not be loaded or validated. */
class FlexDefinitionException extends WireException {
	private array $source;
	private ?int $sourceLine;

	/**
	 * Retain field, source and cause details for a definition failure.
	 *
	 * @param string $fieldName
	 * @param string $reason
	 * @param array $source
	 * @param int|null $sourceLine
	 * @param \Throwable|null $previous
	 */
	public function __construct(string $fieldName, string $reason, array $source = [], ?int $sourceLine = null, ?\Throwable $previous = null) {
		$this->source = $source;
		$this->sourceLine = $sourceLine;
		$message = sprintf(__('Invalid Flex definition for field "%1$s" from %2$s: %3$s'), $fieldName, self::formatSourceLabel($source), $reason);
		if($sourceLine !== null) {
			$message .= ' ' . sprintf(__('(line %d)'), $sourceLine);
		}
		parent::__construct($message, 0, $previous);
	}

	/**
	 * Return the resolved definition source and provenance metadata.
	 *
	 * @return array
	 */
	public function sourceInfo(): array {
		return $this->source;
	}

	/**
	 * Get the definition’s failing line when it is known.
	 *
	 * @return int|null
	 */
	public function sourceLine(): ?int {
		return $this->sourceLine;
	}

	private static function formatSourceLabel(array $source): string {
		if(($source['kind'] ?? '') === 'inline-json') {
			return __('inline JSON');
		}
		if(($source['kind'] ?? '') === 'missing') {
			return __('automatic lookup');
		}
		$path = (string) ($source['relativePath'] ?? __('configured file'));
		if(($source['origin'] ?? '') === 'automatic') {
			return sprintf(__('automatic file %s'), $path);
		}
		return sprintf(__('file %s'), $path);
	}
}

/** Loads trusted developer definitions and keeps UI hierarchy separate from the flat data schema. */
class FlexFieldDefs extends Wire {
	private Field $flexField;
	private Page $flexPage;
	private InputfieldWrapper $tree;
	private array $properties = [];
	private array $names = [];
	private int $depth;
	private ?array $mediaSettings;
	private array $sourceInfo = [];
	// Shared by rows of this type; labels do not require an owning collection link.
	private string $rowTypeLabel = '';

	/**
	 * Load and validate trusted definitions in the owning page’s API context.
	 *
	 * @param Field $field Owning Flex field.
	 * @param Page $page Owning page.
	 * @param array|InputfieldWrapper|null $source
	 * @param int $depth
	 * @param array|null $mediaSettings Containing media settings for metadata schemas.
	 */
	public function __construct(Field $field, Page $page, $source = null, int $depth = 0, ?array $mediaSettings = null) {
		parent::__construct();
		$field->wire($this);
		$this->flexField = $field;
		$this->flexPage = $page;
		$this->depth = $depth;
		$this->mediaSettings = $mediaSettings;
		if($depth > 64 || ((int) $field->flexMaxDepth > 0 && $depth > (int) $field->flexMaxDepth)) {
			throw new FlexDefinitionException($field->name, __('Flex definition exceeds its depth budget'), self::inspectSource($field, $this->wire()));
		}
		$this->tree = $this->wire(new InputfieldWrapper());
		$this->sourceInfo = self::inspectSource($field, $this->wire());
		try {
			if($source === null) {
				$source = $this->load();
			}
			if($source instanceof InputfieldWrapper) {
				$this->importWrapper($source, $this->tree);
			} else if(is_array($source)) {
				$this->importDefinitions($source, $this->tree);
			} else {
				throw new WireException($this->_('Definition must return an array or InputfieldWrapper'));
			}
		} catch(FlexDefinitionException $e) {
			throw $e;
		} catch(\PDOException | WireDatabaseException | WireDatabaseQueryException | WirePermissionException $e) {
			// A definition may call APIs while it is evaluated. Keep operational
			// and authorization failures visible as such instead of relabeling
			// them as an invalid schema.
			throw $e;
		} catch(\Throwable $e) {
			$line = null;
			if(
				($this->sourceInfo['kind'] ?? '') === 'file'
				&& isset($this->sourceInfo['absolutePath'])
				&& $e->getFile() === $this->sourceInfo['absolutePath']
			) {
				$line = $e->getLine();
			}
			throw new FlexDefinitionException($field->name, $e->getMessage(), $this->sourceInfo, $line, $e);
		}
	}

	/**
	 * Normalize the automatic definition directory and retain the templates boundary.
	 * @param ProcessWire $wire Owning instance.
	 * @param mixed $value Optional directory override; null reads module configuration.
	 * @return string Normalized templates-relative directory with trailing slash.
	 * #pw-internal
	 * @internal
	 */
	public static function definitionDirectory(ProcessWire $wire, $value = null): string {
		$value = $value ?? $wire->modules->getConfig('FieldtypeFlex', 'definitionsPath') ?? '';
		if(!is_string($value)) {
			throw new WireException(__('Use a relative definitions directory inside site/templates.'));
		}
		$value = str_replace('\\', '/', trim($value, " \t\r\n"));
		if($value === '') {
			$value = 'flex-fields/';
		}
		if(str_starts_with($value, '/') || str_contains($value, ':') || str_contains($value, "\0") || in_array('..', explode('/', $value), true)) {
			throw new WireException(__('Use a relative definitions directory inside site/templates.'));
		}
		$segments = array_values(array_filter(explode('/', $value), static fn($part) => $part !== '' && $part !== '.'));
		if(!$segments) {
			throw new WireException(__('Use a relative definitions directory inside site/templates.'));
		}
		$relative = implode('/', $segments) . '/';
		$base = realpath($wire->config->paths->templates);
		if(!$base) {
			throw new WireException(__('The site/templates directory could not be resolved.'));
		}
		$path = $base . '/' . rtrim($relative, '/');
		// Validate the nearest existing ancestor too: a missing child must not hide
		// an outside-pointing or broken symlink in its parent directory.
		while(!file_exists($path) && !is_link($path)) {
			$path = dirname($path);
		}
		$resolved = realpath($path);
		if(!$resolved || !is_dir($resolved) || ($resolved !== $base && !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR))) {
			throw new WireException(__('The definitions directory must resolve inside site/templates.'));
		}
		return $relative;
	}

	/**
	 * Resolve the active source without evaluating PHP. Paths remain relative in admin output.
	 *
	 * @param Field $field Owning Flex field.
	 * @param ProcessWire|null $wire
	 * @return array
	 * #pw-internal
	 * @internal
	 */
	public static function inspectSource(Field $field, ?ProcessWire $wire = null): array {
		$wire = $wire ?: $field->wire();
		$base = realpath($wire->config->paths->templates);
		$searchPaths = [];
		$configured = trim((string) $field->get('flexDefinitionFile'));
		$inline = trim((string) $field->get('flexDefinition'));
		if($inline !== '') {
			return [
				'kind'           => 'inline-json',
				'origin'         => 'inline',
				'relativePath'   => null,
				'configuredPath' => $configured,
				'absolutePath'   => null,
				'exists'         => true,
				'error'          => $configured !== '' ? __('Configure either an inline JSON definition or a definition file, not both.') : null,
				'searchPaths'    => $searchPaths,
			];
		}
		$relative = $configured;
		$origin = 'explicit';
		if($relative === '') {
			$origin = 'automatic';
			try {
				$directory = self::definitionDirectory($wire);
			} catch(WireException $error) {
				return [
					'kind' => 'missing',
					'origin' => 'automatic',
					'relativePath' => null,
					'configuredPath' => '',
					'absolutePath' => null,
					'exists' => false,
					'error' => $error->getMessage(),
					'searchPaths' => [],
				];
			}
			foreach(['php', 'json'] as $extension) {
				$searchPaths[] = "$directory{$field->name}.$extension";
			}
			$matches = [];
			foreach($searchPaths as $candidate) {
				// Select the first existing candidate before applying the boundary
				// check below. Otherwise an outside-pointing symlink is silently
				// skipped and can turn a broken definition into an empty schema.
				$candidatePath = $base ? $base . DIRECTORY_SEPARATOR . $candidate : '';
				if($candidatePath && is_file($candidatePath)) {
					$matches[] = $candidate;
				}
			}
			$relative = $matches[0] ?? '';
			if(count($matches) > 1) {
				return [
					'kind'           => 'file',
					'origin'         => 'automatic',
					'relativePath'   => null,
					'configuredPath' => '',
					'absolutePath'   => null,
					'exists'         => false,
					'error'          => __('Multiple automatic definition files exist. Keep one PHP or JSON file, or configure an explicit file.'),
					'searchPaths'    => $searchPaths,
				];
			}
			if($relative === '') {
				return [
					'kind'           => 'missing',
					'origin'         => 'automatic',
					'relativePath'   => null,
					'configuredPath' => '',
					'absolutePath'   => null,
					'exists'         => false,
					'error'          => null,
					'searchPaths'    => $searchPaths,
				];
			}
		}
		$path = $base ? realpath($base . DIRECTORY_SEPARATOR . $relative) : false;
		$error = null;
		if(!$base) {
			$error = __('The site/templates directory could not be resolved.');
		} else if(!$path || !is_file($path)) {
			$error = $origin === 'automatic'
				? __('The automatic definition file is no longer available.')
				: __('The configured definition file does not exist.');
		} else if(!str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
			$error = __('The definition file must resolve inside site/templates.');
		} else if(!is_readable($path)) {
			$error = __('The definition file is not readable.');
		} else if(!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'json'], true)) {
			$error = __('Definition files must use the PHP or JSON extension.');
		}
		return [
			'kind'           => 'file',
			'origin'         => $origin,
			'relativePath'   => $relative,
			'configuredPath' => $configured,
			'absolutePath'   => $error ? null : $path,
			'exists'         => !$error,
			'error'          => $error,
			'searchPaths'    => $searchPaths,
		];
	}

	/**
	 * Return the resolved definition source and provenance metadata.
	 *
	 * @return array
	 * #pw-internal
	 * @internal
	 */
	public function sourceInfo(): array {
		return $this->sourceInfo;
	}

	/**
	 * Reject a lost definition before existing values can be edited.
	 *
	 * @param array $raw Existing or assigned unformatted values.
	 * @return void
	 * #pw-internal
	 * @internal
	 */
	public function assertSourceForValues(array $raw): void {
		if(($this->sourceInfo['kind'] ?? '') === 'missing' && $raw !== []) {
			throw new FlexDefinitionException(
				$this->flexField->name,
				$this->_('The definition file is missing. Restore a definition before reading or saving existing Flex values.'),
				$this->sourceInfo
			);
		}
	}

	private function load() {
		$source = $this->sourceInfo;
		if($source['error'] ?? null) {
			throw new WireException($source['error']);
		}
		if(($source['kind'] ?? '') === 'missing') {
			return [];
		}
		if(($source['kind'] ?? '') === 'inline-json') {
			return $this->decode((string) $this->flexField->get('flexDefinition'));
		}
		$path = $source['absolutePath'];
		if(strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
			$json = file_get_contents($path);
			if($json === false) {
				throw new WireException($this->_('Unable to read the definition file.'));
			}
			return $this->decode($json);
		}
		// ProcessWire API variables are scoped to this instance. The explicit context
		// wins over same-named API entries, and prefixed locals protect the loader.
		return (static function($__flexPath, $__flexPage, $__flexField, $__flexWire) {
			$__flexApi = $__flexWire->fuel()->getArray();
			$__flexApi['languages'] = $__flexWire->wire('languages');
			extract($__flexApi, EXTR_SKIP);
			$page = $__flexPage;
			$field = $__flexField;
			$wire = $__flexWire;
			return include $__flexPath;
		})($path, $this->flexPage, $this->flexField, $this->wire());
	}

	private function decode(string $json): array {
		try {
			$value = json_decode($json, true, FlexPath::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch(\JsonException $e) {
			throw new WireException(sprintf($this->_('Invalid Flex definition JSON: %s'), $e->getMessage()), 0, $e);
		}
		if(!is_array($value) || (array_is_list($value) && $value !== [])) {
			throw new WireException($this->_('Flex definition JSON must be an object keyed by property name'));
		}
		return $value;
	}

	private function checkName(string $name): void {
		if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) || in_array($name, ['data', 'pages_id', 'count'], true)) {
			throw new WireException(sprintf($this->_('Invalid or reserved Flex property name: %s'), $name));
		}
		if(isset($this->names[$name])) {
			throw new WireException(sprintf($this->_('Duplicate Flex property name: %s'), $name));
		}
		if($this->mediaSettings !== null && $name !== 'description' && $name !== 'tags' && FlexMedia::reservedName($name)) {
			throw new WireException(sprintf($this->_('Reserved Flex media metadata name: %s'), $name));
		}
		$this->names[$name] = true;
	}

	private function resolveType(string $type): string {
		$wanted = str_starts_with(strtolower($type), 'inputfield') ? $type : 'Inputfield' . $type;
		foreach($this->wire()->modules->findByPrefix('Inputfield') as $class) {
			if(strcasecmp($wanted, $class) === 0) {
				return $class;
			}
		}
		// Also allow available but not yet installed standalone core inputs (e.g. TinyMCE).
		foreach($this->wire()->modules->getInstallable() as $class => $file) {
			if(strcasecmp($wanted, $class) === 0) {
				return $class;
			}
		}
		throw new WireException(sprintf($this->_('Unknown Flex input type: %s'), $type));
	}

	private function assertSupported(Inputfield $input): void {
		if($this->mediaSettings !== null && $input instanceof InputfieldWrapper && !in_array($input->className(), ['InputfieldWrapper', 'InputfieldFieldset', 'InputfieldMarkup'], true)) {
			throw new WireException(sprintf($this->_('Unsupported customFields input: %s'), $input->className()));
		}
		if(
			$input instanceof InputfieldFlex
			|| preg_match('/(Repeater|PageTable|Password|FlexFile|FlexImage)/i', $input->className())
		) {
			throw new WireException(sprintf($this->_('%s is not a supported Flex definition input'), $input->className()));
		}
	}

	private function resolveLanguages(Inputfield $input): void {
		$mode = $input->useLanguages;
		$auto = $mode === 'auto';
		if(!$auto && !in_array($mode, [null, '', false, true, 0, 1, '0', '1'], true)) {
			throw new WireException($this->_('useLanguages must be true, false or "auto"'));
		}
		if($auto && !$input instanceof InputfieldText && !$input instanceof InputfieldTextarea) {
			throw new WireException($this->_('Automatic language support requires a Text or Textarea input'));
		}
		$available = ($auto || $mode) && $this->wire()->languages && $this->wire()->modules->isInstalled('LanguageSupportFields');
		if(!$auto && $mode && !$available) {
			throw new WireException(sprintf($this->_("useLanguages on '%s' requires LanguageSupport and LanguageSupportFields"), $input->name));
		}
		$input->useLanguages = $auto ? (bool) $available : (bool) $mode;
	}

	private function importDefinitions(array $definitions, InputfieldWrapper $parent, int $nesting = 0): void {
		if($nesting > 64) {
			throw new WireException($this->_('Flex fieldset tree is cyclic or too deep'));
		}
		foreach($definitions as $name => $settings) {
			$name = (string) $name;
			$this->checkName($name);
			if(!is_array($settings)) {
				throw new WireException(sprintf($this->_("Flex property '%s' must be an array"), $name));
			}
			if(($settings['type'] ?? '') === 'table') $settings = $this->tableDefinition($settings);
			$isRows = ($settings['type'] ?? '') === 'rows';
			$isObject = ($settings['type'] ?? '') === 'object';
			$this->validateStructureSettings($settings, $isRows);
			$class = $isRows ? 'InputfieldFlexRows' : ($isObject ? 'InputfieldFieldset' : $this->resolveType((string) ($settings['type'] ?? 'text')));
			if(!$isRows && $class === 'InputfieldFlexRows') {
				throw new WireException($this->_('Declare a Rows collection with type rows'));
			}
			$input = $this->wire()->modules->get($class);
			if(!$input instanceof Inputfield) {
				throw new WireException(sprintf($this->_('Unable to load %s'), $class));
			}
			if(!empty($settings['inheritField'])) {
				$inherit = $this->wire()->fields->get($settings['inheritField']);
				if(!$inherit) {
					throw new WireException(sprintf($this->_('Unknown inheritField: %s'), $settings['inheritField']));
				}
				$input = $inherit->getInputfield($this->flexPage);
				if($input->className() !== $class) {
					throw new WireException(sprintf($this->_('inheritField must use %s'), $class));
				}
				if($input instanceof InputfieldToggle && !array_key_exists('formatType', $settings)) {
					$settings['formatType'] = $inherit->get('formatType') ?? 0;
				}
				if($inherit->type instanceof FieldtypeTextarea) {
					foreach(['contentType', 'htmlOptions'] as $key) {
						if(!array_key_exists($key, $settings)) $settings[$key] = $inherit->get($key);
					}
				}
				// Textformatters belong to the Fieldtype, not the source Inputfield.
				if(($inherit->type instanceof FieldtypeText || $inherit->type instanceof FieldtypeFile) && !array_key_exists('textformatters', $settings)) {
					$settings['textformatters'] = $inherit->get('textformatters') ?? [];
				}
			}
			$this->assertSupported($input);
			if(array_key_exists('customFields', $settings) && !$input instanceof InputfieldFile) throw new WireException($this->_('customFields requires a File or Image property'));
			if($input instanceof InputfieldToggle && array_key_exists('options', $settings)) {
				// Custom Toggle options are an Inputfield API; configure before value sanitization.
				FlexDependencies::setToggleOptions($input, $settings['options']);
			}
			foreach($settings as $key => $value) {
				if(in_array($key, ['type', 'children', 'types', 'renamedFrom', 'inheritField', 'name', 'id', 'customFields'], true)) {
					continue;
				}
				if($key === 'options' && $input instanceof InputfieldToggle) {
					continue;
				} else if($key === 'options' && method_exists($input, 'addOptions')) {
					$input->addOptions($value);
				} else {
					$input->set($key, $value);
				}
			}
			$input->name = $name;
			$input->label = $settings['label'] ?? ucwords(str_replace('_', ' ', $name));
			$input->hasPage = $this->flexPage;
			$input->hasField = null;
			$input->hasFieldtype = false;
			$input->_flex_property = $name;
			$this->resolveMetadataLanguages($name, $input, $settings);
			$this->resolveLanguages($input);
			if($isObject) {
				if($this->mediaSettings !== null) throw new WireException(__('Objects are not supported in customFields'));
				if(!empty($settings['inheritField'])) throw new WireException($this->_('inheritField requires a native Inputfield type'));
				$children = $settings['children'] ?? null;
				if(!is_array($children) && !$children instanceof InputfieldWrapper) throw new WireException(__('Object children must be definitions or a wrapper'));
				$this->register($name, $input, $settings);
				$this->properties[$name]['kind'] = 'object';
				$this->properties[$name]['schema'] = new self($this->flexField, $this->flexPage, $children, $this->depth + 1);
			} else if($isRows) {
				$this->registerRows($name, $input, $settings);
			} else if($input instanceof InputfieldWrapper) {
				$children = $settings['children'] ?? [];
				if(!is_array($children)) {
					throw new WireException(sprintf($this->_("children of '%s' must be an array"), $name));
				}
				$this->importDefinitions($children, $input, $nesting + 1);
			} else {
				$this->register($name, $input, $settings);
			}
			$parent->add($input);
		}
	}

	/** Tables are syntax and presentation over existing Object/Rows schemas. */
	private function tableDefinition(array $settings): array {
		foreach(['editableHeaders', 'rowLabels'] as $key) {
			if(array_key_exists($key, $settings) && !is_bool($settings[$key])) throw new WireException(sprintf($this->_('%s must be a boolean'), $key));
		}
		foreach(['children', 'types', 'rowTitle', 'rowCollapse', 'rowLazyLoad', 'inheritField'] as $key) {
			if(array_key_exists($key, $settings)) throw new WireException(sprintf($this->_('%s is not supported on a table'), $key));
		}
		$columns = $settings['columns'] ?? null;
		if(!is_array($columns) || !$columns || array_is_list($columns)) throw new WireException($this->_('Table columns must be a non-empty property map'));
		if(array_key_exists('row_label', $columns)) throw new WireException($this->_('row_label is reserved for table row labels'));
		$headers = [];
		$widths = [];
		$label = ['type' => 'text', 'label' => $this->_('Label'), 'useLanguages' => $settings['useLanguages'] ?? false, 'langBlankInherit' => 1];
		if($settings['rowLabels'] ?? false) $columns = ['row_label' => $label] + $columns;
		foreach($columns as $name => &$column) {
			if(!is_array($column)) throw new WireException($this->_('Each table column must be an Inputfield definition'));
			if(array_key_exists('width', $column)) {
				$width = $column['width'];
				if(!is_string($width) || !preg_match('/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:rem|px)$/D', $width) || (float) $width <= 0) throw new WireException($this->_('Table column width must be a positive rem or px length'));
				$widths[$name] = $width;
				unset($column['width']);
			}
			// The native wrapper fills its cell; its percentage layout is unrelated.
			$column['columnWidth'] = 100;
			$headers[$name] = $label + ['value' => ''];
			$headers[$name]['size'] = 1;
			$headers[$name]['collapsed'] = Inputfield::collapsedNever;
			$headers[$name]['label'] = $column['label'] ?? ucwords(str_replace('_', ' ', $name));
			$headers[$name]['value'] = $headers[$name]['label'];
			foreach($column as $key => $value) {
				if($key === 'renamedFrom' || preg_match('/^label[0-9]+$/D', $key)) $headers[$name][$key] = $value;
			}
			if(($settings['useLanguages'] ?? false) && $this->wire()->languages && $this->wire()->modules->isInstalled('LanguageSupportFields')) {
				$defaults = [];
				foreach($this->wire()->languages as $language) $defaults[$language->id] = $column['label' . $language->id] ?? $headers[$name]['label'];
				$headers[$name]['value'] = $defaults;
			}
		}
		unset($column);
		$table = ['columns' => array_keys($columns), 'widths' => $widths, 'rowLabels' => $settings['rowLabels'] ?? false];
		$rows = ['type' => 'rows', '_flexTable' => $table, 'rowCollapse' => FieldtypeFlex::ROW_EXPAND_ALL, 'types' => ['row' => ['children' => $columns]]];
		foreach(['minItems', 'maxItems', 'addRowLabel'] as $key) {
			if(array_key_exists($key, $settings)) { $rows[$key] = $settings[$key]; unset($settings[$key]); }
		}
		$children = ($settings['editableHeaders'] ?? false) ? ['headers' => ['type' => 'object', 'children' => $headers]] : [];
		$children['rows'] = $rows;
		unset($settings['columns'], $settings['editableHeaders'], $settings['rowLabels'], $settings['useLanguages']);
		return array_replace($settings, ['type' => 'object', 'children' => $children, '_flexTable' => $table]);
	}

	private function importWrapper(InputfieldWrapper $source, InputfieldWrapper $parent, int $nesting = 0): void {
		if($nesting > 64) {
			throw new WireException($this->_('Flex fieldset tree is cyclic or too deep'));
		}
		foreach($source->children() as $original) {
			$name = (string) $original->name;
			$this->checkName($name);
			$input = $this->copyInput($original);
			$this->assertSupported($input);
			if($input->customFields !== null && !$input instanceof InputfieldFile) throw new WireException($this->_('customFields requires a File or Image property'));
			$input->hasPage = $this->flexPage;
			$input->_flex_property = $name;
			$this->resolveMetadataLanguages($name, $input, $input->getArray());
			$this->resolveLanguages($input);
			$this->validateStructureSettings($input->getArray(), $input instanceof InputfieldFlexRows);
			if($input instanceof InputfieldFlexRows) {
				$this->registerRows($name, $input, $input->getArray());
			} else if($input instanceof InputfieldWrapper) {
				$this->importWrapper($original, $input, $nesting + 1);
			} else {
				$this->register($name, $input, ['value' => $input->value] + $input->getArray());
			}
			$parent->add($input);
		}
	}

	private function validateStructureSettings(array $settings, bool $isRows): void {
		foreach(['repeatable', 'repeaterAddLabel', 'repeaterCollapse', 'repeaterTitle'] as $key) {
			if(array_key_exists($key, $settings)) {
				throw new WireException(sprintf($this->_('Unsupported Flex definition setting: %s'), $key));
			}
		}
		foreach(['types', 'minItems', 'maxItems', 'addRowLabel', 'rowCollapse', 'rowLazyLoad'] as $key) {
			if(!$isRows && array_key_exists($key, $settings)) {
				throw new WireException(sprintf($this->_('%s requires type rows'), $key));
			}
		}
		if(!$isRows && array_key_exists('rowTitle', $settings)) {
			throw new WireException($this->_('rowTitle belongs to a row type'));
		}
		if($isRows && !empty($settings['inheritField'])) {
			throw new WireException($this->_('inheritField requires a native Inputfield type'));
		}
	}

	private function registerRows(string $name, Inputfield $input, array $settings): void {
		if($this->mediaSettings !== null) throw new WireException($this->_('Rows are not supported in customFields'));
		foreach(['minItems', 'maxItems'] as $limit) {
			if(isset($settings[$limit]) && (!is_int($settings[$limit]) || $settings[$limit] < 0)) {
				throw new WireException(sprintf($this->_('%s must be a non-negative integer'), $limit));
			}
		}
		$min = $settings['minItems'] ?? 0;
		$max = $settings['maxItems'] ?? 0;
		if($max && $min > $max) {
			throw new WireException($this->_('minItems must not exceed maxItems'));
		}
		foreach(['children', 'rowTitle'] as $setting) {
			if(array_key_exists($setting, $settings)) {
				throw new WireException(sprintf($this->_('%s belongs to a row type, not the collection'), $setting));
			}
		}
		// Presentation settings can fall back without changing stored values or row limits.
		if(isset($settings['addRowLabel']) && !is_string($settings['addRowLabel'])) {
			unset($settings['addRowLabel']);
		}
		$collapseModes = [
			FieldtypeFlex::ROW_COLLAPSE_EXISTING,
			FieldtypeFlex::ROW_COLLAPSE_ALL,
			FieldtypeFlex::ROW_EXPAND_ALL,
		];
		$collapse = $settings['rowCollapse'] ?? FieldtypeFlex::ROW_COLLAPSE_EXISTING;
		$settings['rowCollapse'] = in_array($collapse, array_merge($collapseModes, array_map('strval', $collapseModes)), true)
			? (int) $collapse
			: FieldtypeFlex::ROW_COLLAPSE_EXISTING;
		$settings['rowLazyLoad'] = ($settings['rowLazyLoad'] ?? false) === true;
		$types = $settings['types'] ?? null;
		if(!is_array($types) || !$types || array_is_list($types)) {
			throw new WireException($this->_('Rows types must be a non-empty map'));
		}
		$this->register($name, $input, $settings);
		$this->properties[$name]['kind'] = 'rows';
		$this->properties[$name]['multiple'] = true;
		$this->properties[$name]['types'] = [];
		foreach($types as $type => $typeSettings) {
			if(!is_string($type) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type) || $type === 'count') {
				throw new WireException(sprintf($this->_('Invalid or reserved Flex row type: %s'), $type));
			}
			if(!is_array($typeSettings)) {
				throw new WireException(sprintf($this->_('Settings for row type %s must be an array'), $type));
			}
			foreach($typeSettings as $key => $_) {
				if(!in_array($key, ['label', 'rowTitle', 'children'], true)) {
					throw new WireException(sprintf($this->_('Unsupported row type setting: %s'), $key));
				}
			}
			foreach(['label', 'rowTitle'] as $key) {
				if(array_key_exists($key, $typeSettings) && !is_string($typeSettings[$key])) {
					unset($typeSettings[$key]);
				}
			}
			$children = $typeSettings['children'] ?? null;
			if(!is_array($children) && !$children instanceof InputfieldWrapper) {
				throw new WireException($this->_('Row type children must be definitions or a wrapper'));
			}
			$schema = new self($this->flexField, $this->flexPage, $children, $this->depth + 1);
			if(isset($settings['_flexTable'])) {
				$allowed = ['InputfieldText', 'InputfieldEmail', 'InputfieldURL', 'InputfieldInteger', 'InputfieldFloat', 'InputfieldTextarea', 'InputfieldCheckbox', 'InputfieldToggle', 'InputfieldSelect', 'InputfieldRadios', 'InputfieldCheckboxes', 'InputfieldSelectMultiple', 'InputfieldAsmSelect', 'InputfieldTextTags', 'InputfieldDatetime', 'InputfieldPage'];
				foreach($schema->properties() as $column => $definition) {
					$cell = $definition['input'];
					if(!in_array($cell->className(), $allowed, true) || in_array($definition['kind'], ['object', 'rows', 'file', 'image'], true)) throw new WireException(sprintf($this->_('Unsupported table column input: %s'), $cell->className()));
					if($cell instanceof InputfieldPage && !$cell->labelFieldName) $cell->labelFieldName = 'title';
					if($cell instanceof InputfieldPage && !in_array(ltrim((string) $cell->inputfield, '_'), ['InputfieldSelect', 'InputfieldSelectMultiple', 'InputfieldAsmSelect', 'InputfieldPageAutocomplete'], true)) throw new WireException($this->_('Table Page columns require Select, SelectMultiple, AsmSelect or PageAutocomplete'));
					if(in_array((int) $cell->collapsed, [Inputfield::collapsedYesAjax, Inputfield::collapsedBlankAjax, Inputfield::collapsedTab, Inputfield::collapsedTabAjax, Inputfield::collapsedTabLocked], true)) throw new WireException($this->_('Table cells cannot use AJAX collapse or tabs'));
					if(in_array((int) $cell->collapsed, [Inputfield::collapsedNo, Inputfield::collapsedYes, Inputfield::collapsedBlank, Inputfield::collapsedPopulated], true)) $cell->collapsed = Inputfield::collapsedNever;
					if(in_array((int) $cell->collapsed, [Inputfield::collapsedBlankLocked, Inputfield::collapsedYesLocked], true)) $cell->collapsed = Inputfield::collapsedNoLocked;
				}
				if(array_keys($schema->properties()) !== $settings['_flexTable']['columns']) throw new WireException($this->_('Table columns must be value inputs, not wrappers or markup'));
			}
			$schema->rowTypeLabel = $typeSettings['label'] ?? ucwords(str_replace('_', ' ', $type));
			$this->properties[$name]['types'][$type] = [
				'label'    => $schema->rowTypeLabel(),
				'settings' => $typeSettings,
				'schema'   => $schema,
			];
		}
	}

	/** Validate effective formatter lists; irrelevant settings never reach this method. */
	private function normalizeTextformatters(Inputfield $input, array &$settings): void {
		if(!array_key_exists('textformatters', $settings)) return;
		$names = $settings['textformatters'];
		if(!is_array($names) || !array_is_list($names)) {
			throw new WireException(sprintf($this->_('textformatters on "%s" must be a list of module names'), $input->name));
		}
		foreach($names as $name) {
			if(!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)
				|| !$this->wire()->modules->isInstalled($name)
				|| !$this->wire()->modules->get($name) instanceof Textformatter) {
				throw new WireException(sprintf($this->_('Invalid Textformatter module on "%s": %s'), $input->name, is_scalar($name) ? (string) $name : get_debug_type($name)));
			}
		}
		$settings['textformatters'] = $names;
		$input->textformatters = $names;
	}

	private function resolveMetadataLanguages(string $name, Inputfield $input, array $settings): void {
		if($this->mediaSettings === null) return;
		if(in_array($name, ['description', 'tags'], true) && $input instanceof InputfieldWrapper) throw new WireException(sprintf($this->_('Unsupported customFields input: %s'), $input->className()));
		if($name !== 'description') return;
		$mode = !$this->mediaSettings['noLang'] && $this->wire()->languages && $this->wire()->modules->isInstalled('LanguageSupportFields');
		if(array_key_exists('useLanguages', $settings) && $settings['useLanguages'] !== null) {
			$explicit = $settings['useLanguages'] === 'auto' ? (bool) ($this->wire()->languages && $this->wire()->modules->isInstalled('LanguageSupportFields')) : (bool) $settings['useLanguages'];
			if($explicit !== (bool) $mode) throw new WireException($this->_('customFields.description useLanguages contradicts the media noLang setting'));
		}
		$input->useLanguages = (bool) $mode;
	}

	private function validateMetadataInput(string $name, Inputfield $input, array &$settings): void {
		$allowed = ['InputfieldText', 'InputfieldTextarea', 'InputfieldTinyMCE', 'InputfieldHidden', 'InputfieldPage', 'InputfieldEmail', 'InputfieldURL', 'InputfieldInteger', 'InputfieldFloat', 'InputfieldSelect', 'InputfieldRadios', 'InputfieldSelectMultiple', 'InputfieldCheckboxes', 'InputfieldAsmSelect', 'InputfieldCheckbox', 'InputfieldToggle', 'InputfieldDatetime', 'InputfieldTextTags'];
		if(!in_array($input->className(), $allowed, true)) {
			throw new WireException(sprintf($this->_('Unsupported customFields input: %s'), $input->className()));
		}
		if($input instanceof InputfieldPage && !in_array(ltrim((string) $input->inputfield, '_') ?: 'InputfieldSelect', ['InputfieldSelect', 'InputfieldAsmSelect'], true)) {
			throw new WireException($this->_('customFields Page inputs require InputfieldSelect or InputfieldAsmSelect'));
		}
		foreach((array) ($settings['renamedFrom'] ?? []) as $old) {
			if(in_array($name, ['description', 'tags'], true) || in_array($old, ['description', 'tags'], true)) throw new WireException($this->_('Renames between native description/tags and custom values are unsupported'));
		}
		if($name === 'description') {
			if(!in_array($input->className(), ['InputfieldText', 'InputfieldTextarea', 'InputfieldTinyMCE'], true)) throw new WireException($this->_('customFields.description requires Text, Textarea or TinyMCE'));
			if(!array_key_exists('textformatters', $settings)) $settings['textformatters'] = $this->mediaSettings['textformatters'] ?? [];
		} else if($name === 'tags') {
			if(!in_array($input->className(), ['InputfieldText', 'InputfieldTextTags', 'InputfieldSelect', 'InputfieldRadios', 'InputfieldSelectMultiple', 'InputfieldCheckboxes', 'InputfieldAsmSelect'], true) || $input->useLanguages) throw new WireException($this->_('customFields.tags requires a plain text, tag or choice input'));
			$tagKeys = [];
			foreach(method_exists($input, 'getOptions') ? $input->getOptions() : [] as $key => $label) {
				if(!preg_match('/^[a-zA-Z0-9_-]+$/D', (string) $key) || isset($tagKeys[strtolower((string) $key)])) throw new WireException($this->_('customFields.tags option keys must be unambiguous native tags'));
				$tagKeys[strtolower((string) $key)] = true;
			}
		}
	}

	private function register(string $name, Inputfield $input, array $settings): void {
		if($input instanceof InputfieldMarkup) {
			return;
		}
		if($this->mediaSettings !== null) $this->validateMetadataInput($name, $input, $settings);
		$settings['useLanguages'] = (bool) $input->useLanguages;
		if($input instanceof InputfieldPage && !$input->inputfield) {
			$input->inputfield = 'InputfieldSelect';
		}
		$renames = (array) ($settings['renamedFrom'] ?? []);
		foreach($renames as $old) {
			if(!is_string($old) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $old) || $old === $name) {
				throw new WireException(sprintf($this->_("Invalid renamedFrom for '%s'"), $name));
			}
		}
		if($input instanceof InputfieldToggle) {
			$format = array_key_exists('formatType', $settings) ? $settings['formatType'] : 0;
			if(!in_array($format, [0, 1, 2, 3, '0', '1', '2', '3'], true)) {
				throw new WireException('Toggle formatType must be 0 (integer), 1 (boolean), 2 (label) or 3 (HTML label)');
			}
			$settings['formatType'] = $input->formatType = (int) $format;
		}
		$kind = 'text';
		if($input instanceof InputfieldImage) {
			$kind = 'image';
		} else if($input instanceof InputfieldFile) {
			$kind = 'file';
		} else if($input instanceof InputfieldPage) {
			$kind = 'page';
		} else if($input instanceof InputfieldDatetime) {
			$kind = 'date';
		} else if($input instanceof InputfieldCheckbox) {
			$kind = 'checkbox';
		} else if($input instanceof InputfieldFloat) {
			$kind = 'float';
		} else if($input instanceof InputfieldInteger) {
			$kind = 'integer';
		} else if($input instanceof InputfieldTextTags) {
			$kind = 'tags';
		} else if($input instanceof InputfieldSelectMultiple || $input instanceof InputfieldCheckboxes) {
			$kind = 'array';
		} else if($input instanceof InputfieldSelect) {
			$kind = 'select';
		}
		if(in_array($kind, ['text', 'file', 'image'], true) && !$input instanceof InputfieldToggle) {
			$this->normalizeTextformatters($input, $settings);
		}
		$customFields = null;
		if(array_key_exists('customFields', $settings) && !in_array($kind, ['file', 'image'], true)) {
			throw new WireException($this->_('customFields requires a File or Image property'));
		}
		$multiple = $kind === 'array' || ($kind === 'page' && !(int) $input->derefAsPage);
		if(in_array($kind, ['file', 'image'], true)) {
			$outputFormat = $settings['outputFormat'] ?? 0;
			if(!in_array($outputFormat, [0, 1, 2, '0', '1', '2'], true)) {
				throw new WireException($this->_('Flex media outputFormat must be 0 (auto), 1 (collection) or 2 (single)'));
			}
			$settings['outputFormat'] = (int) $outputFormat;
			if(
				!empty($settings['overwrite'])
				|| !empty($settings['useLanguages'])
				|| !empty($settings['fieldsTemplate'])
				|| $input->unzip
			) {
				throw new WireException($this->_('Flex media uses owned collections and staged replacement; overwrite/custom metadata templates/archive extraction are unsupported'));
			}
			if(!isset($settings['extensions'])) {
				$settings['extensions'] = $input->extensions ?: ($kind === 'image' ? 'jpg jpeg gif png' : '');
			}
			if(trim((string) ($settings['extensions'] ?? '')) === '') {
				throw new WireException($this->_('File properties require allowed extensions'));
			}
			$input->extensions = $settings['extensions'];
			$settings['maxFilesize'] = $input->maxFilesize;
			$settings['maxFiles'] = (int) $input->maxFiles;
			$settings['noLang'] = (bool) $input->noLang;
			if($settings['maxFiles'] < 0 || (int) $input->useTags >= FieldtypeFile::useTagsPredefined) {
				throw new WireException($this->_('Flex media requires a non-negative file limit and free-form tags'));
			}
			$source = array_key_exists('customFields', $settings) ? $settings['customFields'] : [];
			if(!is_array($source) && !$source instanceof InputfieldWrapper) throw new WireException($this->_('customFields must be an array or InputfieldWrapper'));
			$customFields = new self($this->flexField, $this->flexPage, $source, $this->depth, $settings);
			unset($settings['customFields']);
			$multiple = true;
		}
		$this->properties[$name] = [
			'customFields'  => $customFields,
			'input'         => $input,
			'settings'      => $settings,
			'kind'          => $kind,
			'multiple'      => $multiple,
			'renamedFrom'   => $renames,
			'languages'     => (bool) $input->useLanguages,
		];
	}

	/**
	 * Get the flat property metadata, keyed by stored property name.
	 *
	 * @return array
	 * #pw-internal
	 * @internal
	 */
	public function properties(): array {
		return $this->properties;
	}

	/**
	 * Get the resolved label of this row-type schema, or blank for a root schema.
	 *
	 * @return string
	 * #pw-internal
	 * @internal
	 */
	public function rowTypeLabel(): string {
		return $this->rowTypeLabel;
	}

	/**
	 * Read-only structural summary for field configuration; it never opens editor state.
	 *
	 * @return array
	 * #pw-internal
	 * @internal
	 */
	public function structure(): array {
		return $this->structureFor($this->tree);
	}

	private function structureFor(InputfieldWrapper $wrapper): array {
		$nodes = [];
		foreach($wrapper->children() as $input) {
			$name = (string) $input->_flex_property;
			$definition = $this->properties[$name] ?? null;
			$isRows = $definition && $definition['kind'] === 'rows';
			$node = [
				'name'         => $name,
				'label'        => (string) $input->label,
				'type'         => $input->className(),
				'kind'         => ($definition['kind'] ?? '') === 'object' ? 'object' : ($isRows ? 'rows' : ($input instanceof InputfieldWrapper ? 'fieldset' : ($definition['kind'] ?? 'markup'))),
				'required'     => (bool) $input->required,
				'showIf'       => (string) $input->showIf,
				'requiredIf'   => (string) $input->requiredIf,
				'useLanguages' => (bool) ($definition['languages'] ?? false),
				'children'     => [],
			];
			if($isRows) {
				$node['minItems'] = (int) ($definition['settings']['minItems'] ?? 0);
				$node['maxItems'] = (int) ($definition['settings']['maxItems'] ?? 0);
				foreach($definition['types'] as $type => $typeDefinition) {
					$node['children'][] = [
						'name'         => $type,
						'label'        => $typeDefinition['label'],
						'type'         => 'FlexRow',
						'kind'         => 'row-type',
						'required'     => false,
						'showIf'       => '',
						'requiredIf'   => '',
						'useLanguages' => false,
						'children'     => $typeDefinition['schema']->structure(),
					];
				}
			} else if(($definition['kind'] ?? '') === 'object') {
				$node['children'] = $definition['schema']->structure();
			} else if($definition['customFields'] ?? null) {
				$node['customFields'] = $definition['customFields']->structure();
			} else if($input instanceof InputfieldWrapper) {
				$node['children'] = $this->structureFor($input);
			}
			$nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * Find metadata for a property, including a repeatable leaf path.
	 *
	 * @param string $name Property name.
	 * @return array|null
	 * #pw-internal
	 * @internal
	 */
	public function property(string $name): ?array {
		$parts = explode('.', $name, 3);
		$def = $this->properties[$parts[0]] ?? null;
		if(count($parts) === 1) {
			return $def;
		}
		if($def && $def['kind'] === 'object') return $def['schema']->property(substr($name, strlen($parts[0]) + 1));
		if($def && ($def['customFields'] ?? null)) return $def['customFields']->property(substr($name, strlen($parts[0]) + 1));
		return $def && $def['kind'] === 'rows' && count($parts) === 3 && isset($def['types'][$parts[1]])
			? $def['types'][$parts[1]]['schema']->property($parts[2]) : null;
	}

	/**
	 * Resolve one named row type, retaining definition provenance on failure.
	 *
	 * @param string $set Repeatable property name.
	 * @param string $type Stable row type name.
	 * @return array
	 * @throws FlexDefinitionException
	 * #pw-internal
	 * @internal
	 */
	public function rowType(string $set, string $type): array {
		$definition = $this->properties[$set] ?? null;
		if(!$definition || $definition['kind'] !== 'rows' || !isset($definition['types'][$type])) {
			throw new FlexDefinitionException($this->flexField->name, sprintf($this->_('Unknown Flex row type: %1$s.%2$s'), $set, $type), $this->sourceInfo);
		}
		return $definition['types'][$type];
	}

	/**
	 * Get native input metadata for a property.
	 *
	 * @param string $name Property name.
	 * @return Inputfield|null
	 */
	public function getPropertyInputfield(string $name): ?Inputfield {
		$definition = $this->property($name);
		return $definition ? $this->copyInput($definition['input']) : null;
	}

	private function copyInput(Inputfield $source): Inputfield {
		return FlexDependencies::copyInput($source);
	}

	/**
	 * Fresh inputs; prefix isolates two Flex fields or two Repeater items on the same form.
	 *
	 * @param FlexValue|null $value
	 * @param string $prefix Scope prefix for property names.
	 * @param string $draft Editor draft state or token.
	 * @param FlexValue|null $root Detached root overlay used to resolve descendants.
	 * @param FlexValue|null $base Canonical root, before draft media overlays, for POST processing.
	 * @param string $inputName Outer rendered name including native Repeater context.
	 * @param array $ancestors Native Flex scopes, nearest first (internal).
	 * @param string|null $parentAddress Containing Flex scope for per-file dependencies.
	 * @return InputfieldWrapper
	 */
	public function getInputfields(?FlexValue $value = null, string $prefix = '', string $draft = '', ?FlexValue $root = null, ?FlexValue $base = null, string $inputName = '', array $ancestors = [], ?string $parentAddress = null): InputfieldWrapper {
		if($inputName === '') {
			$inputName = $draft !== '' ? $this->flexField->type->admin()->draft($draft)['input'] : substr($prefix, 0, -2);
		}
		if(!$ancestors && $value instanceof FlexRow && $root) {
			$ancestors = FlexDependencies::ancestors($value, $root, $inputName, $draft);
		}
		$copy = function(InputfieldWrapper $source) use (&$copy, $value, $prefix, $draft, $root, $base, $inputName, $parentAddress, $ancestors): InputfieldWrapper {
			$target = $this->wire(new InputfieldWrapper());
			foreach($source->children() as $original) {
				$input = $this->copyInput($original);
				$name = (string) $original->_flex_property;
				$definition = $this->properties[$name] ?? null;
				if($definition && $definition['kind'] === 'object') {
					$input->value = '';
					$object = $value ? $value->getUnformatted($name) : null;
					$address = ($value && $value->address() !== '' ? $value->address() . '.' : '') . $name;
					$input->flexObjectPrefix = FlexPath::inputPrefix($inputName, $address);
					$children = $definition['schema']->getInputfields($object, $input->flexObjectPrefix, $draft, $root ?: $value, $base, $inputName, $ancestors, $value ? $value->address() : '');
					foreach($children->children() as $child) $input->add($child);
					if(isset($definition['settings']['_flexTable'])) {
						$rows = $children->getChildByName($input->flexObjectPrefix . 'rows');
						$rows->label = $input->label;
						$rows->flexTableHeaders = $children->getChildByName($input->flexObjectPrefix . 'headers');
						foreach(['render', 'renderValue'] as $method) {
							$input->addHookBefore($method, function(HookEvent $event) use ($rows, $method) {
								$event->replace = true;
								$event->return = $rows->$method();
							});
						}
					}
				} else if($definition && $definition['kind'] === 'rows') {
					$input->definition = $definition;
					$input->setName = $value && $value->address() !== '' ? $value->address() . '.' . $name : $name;
					$input->flexRoot = $root ?: $value;
					$input->flexBaseRoot = $base ?: $root ?: $value;
					$input->flexDraft = $draft;
					$input->flexInputName = $inputName;
				} else if($definition && in_array($definition['kind'], ['file', 'image'], true)) {
					$this->wire()->modules->get('InputfieldImage');
					require_once __DIR__ . '/InputfieldFlexMedia.php';
					$class = $definition['kind'] === 'image' ? InputfieldFlexImage::class : InputfieldFlexFile::class;
					$input = $this->wire(new $class());
					$input->init();
					foreach($original->getArray() as $key => $setting) {
						$input->set($key, $setting);
					}
					foreach($original->getAttributes() as $key => $attribute) {
						$input->attr($key, $attribute);
					}
					$input->label = $original->label;
					$input->editable($original->editable());
					$input->noAjax = 1;
					$input->overwrite = 0;
					$input->noRename = 1;
					$input->flexDraft = $draft;
					$input->flexInputName = $inputName;
					$input->flexRoot = $root ?: $value;
					$input->flexParentAddress = $value ? $value->address() : '';
					$input->editFieldName = $this->flexField->name;
					$input->hasPage = $this->flexPage;
					$input->hasField = $this->flexField;
					$input->hasFieldtype = true;
					$input->addClass('InputfieldFile', 'wrapClass');
					if($definition['kind'] === 'image') {
						$input->addClass('InputfieldImage', 'wrapClass');
					}
				} else if($original instanceof InputfieldWrapper) {
					foreach($copy($original)->children() as $child) {
						$input->add($child);
					}
				}
				$input->name = $prefix . $name;
				$input->id = 'Inputfield_' . $prefix . $name;
				foreach(['showIf', 'requiredIf'] as $key) {
					$expression = (string) $input->get($key);
					if($prefix && $expression) {
						$selectors = $this->wire(new Selectors($expression));
						foreach($selectors as $selector) {
							$fields = [];
							$not = $selector->not;
							foreach($selector->fields() as $fieldName) {
								$fields[] = FlexDependencies::fieldName($fieldName, $value, $inputName, $prefix, $this->names, $this->flexPage, $this->flexField, $parentAddress);
							}
							$selector->setField($fields);
							$selector->not = $not;
						}
						$input->set($key, (string) $selectors);
					}
				}
				if($value && isset($this->properties[$name]) && $definition['kind'] !== 'object') {
					$v = $value->getUnformatted($name);
					if($v instanceof LanguagesPageFieldValue) {
						$v->setToInputfield($input);
					} else {
						$input->value = $v;
					}
					if($v instanceof FlexFiles || $v instanceof FlexImages) {
						$v->draft($draft);
						$input->value = clone $v;
					}
					if($input instanceof InputfieldCheckbox) {
						$input->checked($v !== '' && $v !== null && $v !== 0);
					}
				}
				$input->resetTrackChanges();
				$target->add($input);
			}
			return $target;
		};
		$inputs = $copy($this->tree);
		$inputs->flexDependencyAncestors = $ancestors;
		$inputs->flexDependencyField = $this->flexField;
		$inputs->flexDependencyPrefix = $prefix;
		foreach($inputs->getAll() as $child) {
			if($child instanceof InputfieldFlexRows || $child instanceof InputfieldFile) $child->flexDependencyAncestors = array_merge([$inputs], $ancestors);
		}
		return $inputs;
	}

	/**
	 * Apply loss-aware property renames while retaining conflicting values as an error.
	 *
	 * @param array $raw Unformatted persisted values.
	 * @return array
	 * #pw-internal
	 * @internal
	 */
	public function migrate(array $raw): array {
		$claimed = [];
		foreach($this->properties as $name => $def) {
			foreach($def['renamedFrom'] as $old) {
				if(isset($this->properties[$old]) || isset($claimed[$old])) {
					throw new WireException(sprintf($this->_('Ambiguous renamedFrom: %s'), $old));
				}
				$claimed[$old] = true;
				if(!array_key_exists($old, $raw)) {
					continue;
				}
				if(array_key_exists($name, $raw) && $raw[$name] !== $raw[$old]) {
					throw new WireException(sprintf($this->_("Flex rename collision: '%1\$s' and '%2\$s' both contain different values"), $old, $name));
				}
				$raw[$name] = $raw[$old];
				unset($raw[$old]);
			}
		}
		return $raw;
	}
}
