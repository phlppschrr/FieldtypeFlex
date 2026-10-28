# FieldtypeFlex

**Status: 0.3.0 pre-release.**

FieldtypeFlex is a structured fieldtype for ProcessWire. Define related properties with native ProcessWire Inputfields, edit them together in Page Edit, and read or query them through the ProcessWire API.

A Flex field can contain scalar properties, native File and Image properties, and typed repeatable fieldsets. Repeatable fieldsets can be nested and can also be used inside native ProcessWire Repeater items.

[Quick start](#quick-start) · [Examples](#examples) · [API reference](API.md) · [Inputfield settings](API.md#inputfield-settings-and-references) · [Development](docs/development.md)

## Features

- Define fields in PHP or JSON with native Inputfield settings.
- Use native ProcessWire validation, dependencies and output-formatting state.
- Store related properties in one JSON field while exposing them as named values.
- Add typed, sortable repeatable rows and nest repeatable fieldsets.
- Use Flex-managed files and images through ProcessWire-compatible Pagefile and Pageimage objects.
- Query scalar properties, row values and collection counts with ProcessWire selectors.
- Use native text, choice, date, Page and media inputs, with optional TinyMCE and multilingual support.
- Add no Composer runtime dependencies.

## Requirements

- ProcessWire 3.0.259 or newer
- PHP 8.1 or newer
- MySQL 8.0 or MariaDB 10.11 or newer
- InnoDB for Flex field tables

SQLite and PostgreSQL are not supported. An optional ProcessWire module is needed only when a definition uses it. For example, required multilingual properties need LanguageSupport and LanguageSupportFields, while a TinyMCE input requires InputfieldTinyMCE. ProcessWire also includes [CKEditor](https://processwire.com/docs/fields/ckeditor/) as `InputfieldCKEditor`; Flex has no CKEditor-specific integration or browser-save coverage yet. Inputfields that depend on their own Fieldtype storage need an explicit Flex integration and are not supported generically.

## Installation

1. Copy or clone the FieldtypeFlex directory into /site/modules/FieldtypeFlex/.
2. In ProcessWire Admin, choose **Modules → Refresh**, then install **FieldtypeFlex**. Its companion Inputfield modules are installed with it.
3. Create a field using the **Flex** fieldtype and add it to the required template.
4. Create a definition in site/templates/flex-fields/.

For a field named `contact`, automatic lookup uses exactly one of these files:

- `site/templates/flex-fields/contact.php`
- `site/templates/flex-fields/contact.json`

If both exist, Flex reports a configuration error. There is no automatic fallback directory. You can instead set an explicit PHP or JSON file path relative to site/templates, or define the schema with inline JSON. An explicit file and inline JSON cannot be active together.

PHP definitions are trusted developer code. JSON definitions are declarative. When no automatic definition exists, a new field can remain unconfigured. If a field already contains values and its automatic definition disappears, Flex blocks saves until the definition is restored, protecting the stored data from being replaced by an empty schema.

See [API.md](API.md#definitions) for all definition options.

## Admin translations

German (`de`), French (`fr`) and Spanish (`es`) translations are bundled in
`languages/`, including editor messages, field settings and the example catalog.
Install ProcessWire’s **LanguageSupport** module and add the desired site languages
under **Setup → Languages**. Then open **Modules → FieldtypeFlex → Module
Information → Languages → install translations**, match each CSV to a site
language and submit. The current user’s language controls the translated UI.

When a module update changes translations, repeat **install translations** to
import the updated text. Translation updates are listed in [CHANGELOG.md](CHANGELOG.md).
The CSV files use ProcessWire’s [native module translation format](https://processwire.com/docs/modules/development/multi-language-translations/).

These files translate the module UI and the bundled PHP examples at their original
paths. Labels in definitions copied into `site/templates/` belong to those files’
textdomains; translate them under **Setup → Languages → Site files**. Translating
stored content is configured separately with [`useLanguages`](API.md#optional-and-required-language-support).

## Quick start

Create a Flex field named `contact`, add it to a template, and save this as
`site/templates/flex-fields/contact.php`:

```php
<?php namespace ProcessWire;

return [
	'name' => [
		'type'     => 'text',
		'label'    => __('Name'),
		'required' => true,
	],
	'email' => [
		'type'  => 'email',
		'label' => __('Email'),
	],
];
```

Edit and save a page using that template. Read its values in a site template:

```php
<?php namespace ProcessWire;

$page->of(true);
echo '<p>' . $page->contact->name . '</p>';
echo '<p>' . $page->contact->email . '</p>';
```

To assign values through the API, turn output formatting off and save the field:

```php
<?php namespace ProcessWire;

$page->of(false);
$page->contact->name = 'Ada';
$page->contact->email = 'ada@example.org';
$page->save('contact');
```

Definitions configure the editor and value types; saving a definition does not
create regular ProcessWire fields for its properties. Text, number and choice
values live together in the Flex field. See [reading and exporting values](API.md#array-views-and-json)
for the difference between runtime objects and plain persistence data.

## Examples

Start with the small contact definition above, then choose an example for the
capability you need. The module's field configuration also provides a read-only
example catalog with the files to copy and their requirements.

| Example | Demonstrates |
| --- | --- |
| [Contact](examples/contact.php), [JSON](examples/contact.json), [native objects](examples/objects.php) | Text, email, choices and an ordinary fieldset; PHP versions also need [options.php](examples/options.php) |
| [Choices](examples/choices.php) and [dependencies](examples/dependencies.php) | Selection keys, checkboxes, `showIf` and `requiredIf` |
| [Dates](examples/dates.php) | Datepicker, input formats and formatted date output |
| [Rich text](examples/rich-text.php), [inherited settings](examples/inherit-field.php), [native settingsField](examples/settings-field.php) | Direct TinyMCE settings and two ways to reuse existing configuration |
| [Required languages](examples/languages.php), [optional languages](examples/languages-auto.php) and [Page references](examples/pages.php) | Native language values, portable definitions, single Pages and PageArrays |
| [Combined inputs](examples/advanced.php) | A compact combination of choices, TinyMCE, languages, references and dependencies |
| [Rows and media](examples/structured.php), [nested rows](examples/nested.php) | Typed repeatable fieldsets, files/images and recursive child rows |
| [Native Repeater](examples/repeater.php) | A Flex field inside a ProcessWire Repeater item |

The following example combines common content properties with typed rows.
Install InputfieldTinyMCE, create a Flex field named `content`, add it to a
template, and save the definition below as `site/templates/flex-fields/content.php`.

<details>
<summary>Combined example: text, dates, choices, images and repeatable sections</summary>

```php
<?php namespace ProcessWire;

return [
	'heading' => [
		'type'     => 'text',
		'label'    => __('Heading'),
		'required' => true,
	],
	'body' => [
		'type'    => 'TinyMCE',
		'label'   => __('Body'),
		'toolbar' => 'bold italic | bullist numlist | link',
	],
	'starts_on' => [
		'type'             => 'datetime',
		'label'            => __('Start date'),
		'datepicker'       => InputfieldDatetime::datepickerFocus,
		'dateInputFormat'  => 'd.m.Y',
		'dateOutputFormat' => 'd.m.Y',
	],
	'appearance' => [
		'type'    => 'select',
		'label'   => __('Appearance'),
		'options' => ['standard' => __('Standard'), 'featured' => __('Featured')],
	],
	'hero' => [
		'type'     => 'image',
		'label'    => __('Hero image'),
		'maxFiles' => 1,
	],
	'sections' => [
		'type'             => 'fieldset',
		'label'            => __('Sections'),
		'repeatable'       => true,
		'maxItems'         => 20,
		'repeaterAddLabel' => __('Add section'),
		'types'            => [
			'text' => [
				'label'         => __('Text section'),
				'repeaterTitle' => '{heading}',
				'children'      => [
					'heading' => ['type' => 'text', 'label' => __('Heading')],
					'body' => ['type' => 'TinyMCE', 'label' => __('Body')],
				],
			],
		],
	],
];
```

After editing and saving values in Page Edit, render them in the site template:

```php
<?php namespace ProcessWire;

$page->of(true);
$content = $page->content;
echo '<h1>' . $content->heading . '</h1>';
echo $content->body;
echo '<p>' . $content->optionLabel('appearance') . '</p>';
if($content->hasValue('starts_on')) {
	echo '<p>' . $content->starts_on . '</p>';
}
if($image = $content->hero) {
	echo '<img src="' . $image->size(1200, 700)->url . '" alt="">';
}
foreach($content->sections as $section) {
	echo '<section><h2>' . $section->heading . '</h2>' . $section->body . '</section>';
}
```

Ordinary text and labels are entity encoded when output formatting is enabled;
TinyMCE HTML stays markup. The image uses the native Pageimage API, and each
section is a FlexRow with its own values. A repeatable fieldset always has a
`types` map, even with just the single `text` type used here.

For labels, `$content->label('appearance')` returns the property label,
`$content->optionLabel('appearance')` returns the current selection's label,
and `$content->getOptions('appearance')` returns all available keys and labels.
See [label helpers](API.md#property-and-option-labels) for explicit option keys
and multiple selections.

</details>

## Configure native inputs

A definition's `type` selects a native Inputfield; `text` is shorthand for
`InputfieldText`. Settings such as `label`, `required`, `columnWidth`, `options`
and `toolbar` belong to those Inputfields. Flex adds settings for structured
values, such as `repeatable`, `types` and `renamedFrom`.

Use the [Inputfield settings guide](API.md#inputfield-settings-and-references)
for common settings, links to native documentation and direct date/TinyMCE
examples. The [tested Inputfields](API.md#tested-inputfields) section states the
verification available for each group.

### Portable multilingual text

Set `useLanguages => 'auto'` on a text or textarea property to enable
multilingual values when LanguageSupport and LanguageSupportFields are
available, and use ordinary strings otherwise. This also works with TinyMCE.
Use `true` when multilingual support is mandatory, or `false` to keep a
property single-language in every project. Existing language maps then expose
the default-language text. Reads preserve translations in storage; saving the
Flex field replaces them with plain strings. Flex remembers the default-language
ID centrally during field saves, so save once before removing LanguageSupport.

See the [portable definition](examples/languages-auto.php) and [language modes](API.md#optional-and-required-language-support)
for the resolved value types in single-language and multilingual projects.

### Reuse native field configuration

Set `inheritField` to an existing ProcessWire field name when a property should
reuse that field's configured native Inputfield. The source and Flex property
must use the same Inputfield type. Settings declared on the Flex property are
applied after the inherited settings, so local values can override them. The
source field's stored value, Fieldtype storage and output formatting are not
inherited. This is useful for richer Datetime setup and TinyMCE toolbar/editor
configuration; see the complete [inheritField example](examples/inherit-field.php).

```php
<?php namespace ProcessWire;

return [
	'starts_at' => [
		'type'         => 'datetime',
		'inheritField' => 'date_input_source',
		'label'        => __('Start date and time'),
	],
	'body' => [
		'type'         => 'TinyMCE',
		'inheritField' => 'rich_text_source',
		'label'        => __('Body'),
		'rows'         => 12,
	],
];
```

The example expects a Datetime field named `date_input_source` and a Textarea
field named `rich_text_source` configured to use TinyMCE. Install InputfieldTinyMCE
before using the rich-text property.

## Repeatable fieldsets

Every repeatable fieldset has a types map, even if all rows use one type. Each
type has its own child schema, and a row's type stays fixed. The combined example
above uses one row type; [examples/nested.php](examples/nested.php) shows nested rows
and multiple typed children. Typed properties use paths such as
`sections.section.blocks.text.heading`. The same FlexRows API works at every
nesting level. Nesting is limited by database JSON depth, schema size and
configured resource budgets; see [API.md](API.md#nested-repeatables-and-resource-limits).

## File and Image properties

Flex manages File and Image storage and exposes compatible ProcessWire objects.
For a single image on a Flex field named `content`:

```php
<?php namespace ProcessWire;

return [
	'hero' => [
		'type'     => 'image',
		'label'    => __('Hero image'),
		'maxFiles' => 1,
	],
];
```

With output formatting enabled and the default Auto media format, a single-image property returns a FlexPageimage (Pageimage subclass) or null:

```php
<?php namespace ProcessWire;

$image = $page->content->hero;
if($image) {
	echo $image->size(1200, 700)->url;
}
```

The ProcessWire image API remains available. See [API.md](API.md#file-and-image-properties) for value formats, upload lifecycle and limits.

Without an explicit `extensions` setting, Flex follows ProcessWire's default
image upload formats: JPG/JPEG, GIF and PNG. WebP is not a permitted ProcessWire
image upload extension.

## Selectors

Scalar and typed repeatable properties can be queried through ProcessWire:

```php
<?php namespace ProcessWire;

$pages->find('content.appearance=featured');
$pages->find('content.sections.text.heading*=Introduction');
$pages->find('content.sections.count>=3');
```

See [API.md](API.md#selectors) for operators, same-row expressions, blank-value behavior and nested selector examples.

## Current boundaries

- Flex field data uses MySQL/MariaDB JSON and InnoDB tables.
- Flex values are not included in native ProcessWire page-version save/restore.
- Page-reference traversal, arbitrary JSON paths and sorting repeated values are not supported.
- The native image-variation management dialog is unavailable for Flex media; use the Pageimage API.
- TinyMCE drag-and-drop uploads need a regular ProcessWire Image field as the upload destination.
- Native media overwrite mode, custom metadata templates and string output formats are not supported.

See [API.md](API.md) for the complete contract and [docs/architecture.md](docs/architecture.md) for persistence and integration decisions.

## Development

The repository includes a disposable DDEV ProcessWire fixture:

```sh
bash scripts/setup.sh
ddev flex-test
ddev composer lint
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/development.md](docs/development.md) for the contributor workflow.

## License

FieldtypeFlex is released under the [MIT License](LICENSE).
