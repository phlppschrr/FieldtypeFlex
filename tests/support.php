<?php namespace ProcessWire;

/**
 * Run one integration suite with a fresh ProcessWire bootstrap and owned fixture.
 *
 * The included suite receives its fixture and assertion helpers as local variables.
 * Cleanup runs even when setup or an assertion fails, and every owned resource is
 * attempted so one cleanup failure does not strand the rest of the fixture.
 */
function runFlexTestSuite(string $suitePath, string $suiteName): int {
	require dirname(__DIR__) . '/scripts/bootstrap.php';

	$wire = wire();
	$type = $wire->modules->get('FieldtypeFlex');
	$passed = 0;
	$pages = [];
	$temporaryFields = [];
	$temporaryFiles = [];
	$field = null;
	$fieldgroup = null;
	$template = null;
	$originalError = null;
	$cleanupErrors = [];

	$assert = static function(bool $condition, string $message) use (&$passed): void {
		if(!$condition) {
			throw new \RuntimeException('FAIL: ' . $message);
		}
		++$passed;
		echo 'PASS: ' . $message . "\n";
	};
	$same = static function($expected, $actual, string $message) use ($assert): void {
		$assert(
			$expected === $actual,
			$message . ($expected === $actual ? '' : ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true))
		);
	};
	$throws = static function(
		callable $action,
		string $message,
		?string $expectedClass = WireException::class,
		?string $messageFragment = null
	) use ($assert): void {
		try {
			$action();
		} catch(\Throwable $error) {
			$classMatches = $expectedClass === null || $error instanceof $expectedClass;
			$messageMatches = $messageFragment === null || str_contains($error->getMessage(), $messageFragment);
			$details = ' (received ' . get_class($error) . ': ' . $error->getMessage() . ')';
			$assert($classMatches && $messageMatches, $message . ($classMatches && $messageMatches ? '' : $details));
			return;
		}
		$assert(false, $message . ' (no exception was thrown)');
	};
	$trackFile = static function(string $path) use (&$temporaryFiles): string {
		$temporaryFiles[] = $path;
		return $path;
	};

	$context = compact(
		'wire',
		'type',
		'passed',
		'pages',
		'temporaryFields',
		'temporaryFiles',
		'assert',
		'same',
		'throws',
		'trackFile'
	);
	$context['field'] = null;
	$context['fieldgroup'] = null;
	$context['template'] = null;

	try {
		if($wire->fields->get('flex_test')) {
			throw new \RuntimeException('The previous Flex test fixture was not cleaned up. Run the disposable fixture setup before retrying.');
		}

		$field = $wire->wire(new FlexField());
		$field->name = 'flex_test';
		$field->type = $type;
		$field->flexDefinition = json_encode(flexTestBaseDefinition(), JSON_THROW_ON_ERROR);
		$wire->fields->save($field);

		$fieldgroup = $wire->wire(new Fieldgroup());
		$fieldgroup->name = 'flex_suite_' . bin2hex(random_bytes(4));
		$fieldgroup->add($wire->fields->get('title'));
		$fieldgroup->add($field);
		$fieldgroup->save();

		$template = $wire->wire(new Template());
		$template->name = $fieldgroup->name;
		$template->fieldgroup = $fieldgroup;
		$wire->templates->save($template);

		$page = $wire->pages->newPage([
			'template' => $template,
			'parent'   => 1,
			'name'     => 'flex-suite-' . bin2hex(random_bytes(5)),
			'title'    => 'Flex integration',
		]);
		$pages[] = $page;
		$page->of(false);
		$page->flex_test = flexTestBaseValue();
		$page->save();
		$id = $page->id;

		$second = $wire->pages->newPage([
			'template' => $template,
			'parent'   => 1,
			'name'     => 'flex-suite-second-' . bin2hex(random_bytes(4)),
			'title'    => 'Second',
		]);
		$pages[] = $second;
		$second->of(false);
		$second->flex_test = [
			'name'       => 'Other',
			'age'        => 0,
			'price'      => 12.5,
			'country'    => '',
			'tags'       => [],
			'categories' => [],
		];
		$second->save();
		$ids = "$id|$second->id";

		$createField = static function(string $name, array $definitions) use (
			$wire,
			$type,
			$template,
			&$temporaryFields
		): Field {
			$created = $wire->wire(new FlexField());
			$created->name = $name . '_' . bin2hex(random_bytes(3));
			$created->type = $type;
			$created->flexDefinition = json_encode($definitions, JSON_THROW_ON_ERROR);
			$temporaryFields[] = $created;
			$wire->fields->save($created);
			$template->fieldgroup->add($created);
			$template->fieldgroup->save();
			return $created;
		};
		$context['page'] = $page;
		$context['id'] = $id;
		$context['second'] = $second;
		$context['ids'] = $ids;
		$context['createField'] = $createField;
		$context['field'] = $field;
		$context['fieldgroup'] = $fieldgroup;
		$context['template'] = $template;
		$context['trackFile'] = $trackFile;

		$sourceField = $wire->wire(new Field());
		$sourceField->name = 'flex_tiny_source_' . bin2hex(random_bytes(4));
		$sourceField->type = $wire->modules->get('FieldtypeTextarea');
		$sourceField->inputfieldClass = 'InputfieldTinyMCE';
		$sourceField->rows = 7;
		$sourceField->toolbar = 'bold italic';
		$temporaryFields[] = $sourceField;
		$wire->fields->save($sourceField);
		$wire->modules->get('LanguageSupport');
		$wire->modules->get('LanguageSupportFields');
		$language = $wire->languages->get('de');
		if(!$language || !$language->id) {
			$language = $wire->languages->add('de');
			$language->title = 'Deutsch';
			$wire->languages->save($language);
		}
		$defaultLanguage = $wire->languages->getDefault();
		$repeaterType = $wire->modules->get('FieldtypeRepeater');
		$repeaterType->getFieldClass();

		$context['sourceField'] = $sourceField;
		$context['language'] = $language;
		$context['defaultLanguage'] = $defaultLanguage;
		$context['repeaterType'] = $repeaterType;
		$context['page'] = $page;
		$context['pages'] = &$pages;
		$context['temporaryFields'] = &$temporaryFields;
		$context['temporaryFiles'] = &$temporaryFiles;
		$context['passed'] = &$passed;

		$run = static function(array &$context, string $suitePath): void {
			extract($context, EXTR_REFS);
			require $suitePath;
		};
		$run($context, $suitePath);
	} catch(\Throwable $error) {
		$originalError = $error;
	}

	foreach(array_reverse($pages) as $createdPage) {
		try {
			if($createdPage->id) {
				$wire->pages->delete($createdPage, true);
			}
		} catch(\Throwable $error) {
			$cleanupErrors[] = 'page ' . $createdPage->id . ': ' . $error->getMessage();
		}
	}
	if($template) {
		try {
			$wire->templates->delete($template);
		} catch(\Throwable $error) {
			$cleanupErrors[] = 'template ' . $template->name . ': ' . $error->getMessage();
		}
	}
	if($fieldgroup) {
		try {
			$wire->fieldgroups->delete($fieldgroup);
		} catch(\Throwable $error) {
			$cleanupErrors[] = 'fieldgroup ' . $fieldgroup->name . ': ' . $error->getMessage();
		}
	}
	foreach(array_reverse($temporaryFields) as $temporary) {
		try {
			if($wire->fields->get($temporary->id)) {
				$wire->fields->delete($temporary);
			}
		} catch(\Throwable $error) {
			$cleanupErrors[] = 'field ' . $temporary->name . ': ' . $error->getMessage();
		}
	}
	if($field) {
		try {
			if($wire->fields->get($field->id) && count($field->getFieldgroups()) === 0) {
				$wire->fields->delete($field);
			}
		} catch(\Throwable $error) {
			$cleanupErrors[] = 'field ' . $field->name . ': ' . $error->getMessage();
		}
	}
	foreach(array_unique($temporaryFiles) as $path) {
		try {
			if(is_file($path) && !unlink($path)) {
				throw new \RuntimeException('unlink returned false');
			}
		} catch(\Throwable $error) {
			$cleanupErrors[] = 'file ' . $path . ': ' . $error->getMessage();
		}
	}

	if($originalError) {
		if($cleanupErrors) {
			error_log('Flex test cleanup errors after failure: ' . implode('; ', $cleanupErrors));
		}
		throw $originalError;
	}
	if($cleanupErrors) {
		throw new \RuntimeException('Flex test cleanup failed: ' . implode('; ', $cleanupErrors));
	}

	echo "\n$passed assertions passed in suite $suiteName on ProcessWire {$wire->config->version}, PHP " . PHP_VERSION . ".\n";
	return $passed;
}

/** @return array<string, array<string, mixed>> */
function flexTestBaseDefinition(): array {
	return [
		'name' => [
			'type'     => 'text',
			'label'    => 'Full name',
			'required' => true,
		],
		'email' => ['type' => 'email', 'label' => 'Email'],
		'age' => ['type' => 'integer'],
		'price' => ['type' => 'float'],
		'country' => [
			'type'    => 'select',
			'label'   => 'Country',
			'options' => ['DE' => 'Germany', 'US' => 'United States'],
		],
		'tags' => [
			'type'    => 'checkboxes',
			'options' => ['a' => 'Alpha', 'b' => 'Beta'],
		],
		'enabled' => ['type' => 'checkbox'],
		'custom_check' => ['type' => 'checkbox', 'checkedValue' => 'yes'],
		'conditional' => ['type' => 'text', 'showIf' => 'enabled=1'],
		'required_conditional' => [
			'type'       => 'text',
			'required'   => true,
			'requiredIf' => 'enabled=1',
		],
		'date' => [
			'type'             => 'datetime',
			'dateInputFormat'  => 'Y-m-d',
			'dateOutputFormat' => 'd.m.Y',
		],
		'group' => [
			'type'     => 'fieldset',
			'children' => ['city' => ['type' => 'text']],
		],
		'category' => [
			'type'           => 'page',
			'inputfield'     => 'InputfieldSelect',
			'derefAsPage'    => 2,
			'parent_id'      => 1,
			'labelFieldName' => 'title',
		],
		'categories' => [
			'type'           => 'page',
			'inputfield'     => 'InputfieldAsmSelect',
			'derefAsPage'    => 0,
			'parent_id'      => 1,
			'labelFieldName' => 'title',
		],
		'default_text' => ['type' => 'text', 'value' => 'Default'],
		'markup' => ['type' => 'markup', 'value' => '<p>Informational</p>'],
	];
}

/** @return array<string, mixed> */
function flexTestBaseValue(): array {
	return [
		'name'                 => 'Hanna <Flex>',
		'email'                => 'hanna@example.test',
		'age'                  => 12,
		'price'                => 2.75,
		'country'              => 'DE',
		'tags'                 => ['a', 'b'],
		'enabled'              => 1,
		'custom_check'         => 'yes',
		'city'                 => 'Berlin',
		'date'                 => '2026-10-14',
		'category'             => 1,
		'categories'           => [1, 2],
		'conditional'          => 'Keep',
		'required_conditional' => 'Present',
		'default_text'         => '',
	];
}
