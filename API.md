# FieldtypeFlex API

FieldtypeFlex exposes structured page values, native Inputfield definitions, typed repeatable rows, media properties and ProcessWire selectors. This document describes the supported runtime contract. See [README.md](README.md) for installation and a short example, and the [ProcessWire API reference](https://processwire.com/api/ref/) for the native classes.

- [Definitions and Inputfield settings](#definitions)
- [Tested Inputfields](#tested-inputfields)
- [Reading and writing page values](#page-values)
- [Array views and JSON](#array-views-and-json)
- [Property and option labels](#property-and-option-labels)
- [Property value types](#property-value-types)
- [Repeatable rows](#repeatable-rows)
- [File and Image properties](#file-and-image-properties)
- [Selectors](#selectors)
- [Field settings](#field-settings)
- [Maintenance APIs](#maintenance-apis)

## Definitions

A Flex field definition may come from one source:

- An automatic file at `site/templates/flex-fields/FIELD_NAME.php` or `.json`.
- An explicit PHP or JSON file path relative to `site/templates`.
- Inline JSON in the field's `flexDefinition` setting.
- A native `InputfieldWrapper` supplied by a trusted PHP definition.

Automatic lookup requires exactly one matching file. If both the PHP and JSON files exist, Flex reports an error. Setting both `flexDefinitionFile` and non-empty `flexDefinition` is an error. Explicit paths must resolve inside `site/templates` and use a `.php` or `.json` extension. PHP files are trusted code; do not allow untrusted users to change them.

If no source exists, a new field may be left unconfigured with an empty schema. If an automatic source disappears after the field has stored values, the field cannot be saved until that definition is restored. Flex preserves the stored values and reports the source error instead of treating the missing file as an empty schema.

PHP definitions execute for the owning Page context. The `$page`, `$field` and `$wire` variables identify the owning context; `$languages` is available as `null` when LanguageSupport is not installed. The same definition can be evaluated without an individual Page for query discovery, so definitions that use per-page logic must still expose queryable properties in that context.

PHP arrays, JSON and native Inputfield trees follow the same definition shape. An associative key is the stored property name:

```php
'heading' => [
	'type'     => 'text',
	'label'    => __('Heading'),
	'required' => true,
],
```

Native Inputfield settings such as `showIf`, `requiredIf` and `options` configure the corresponding installed Inputfield modules. Flex uses settings such as `useLanguages`, `derefAsPage` and `dateOutputFormat` for the runtime value behavior described below. Flex-specific definition settings include `inheritField`, `repeatable`, `types`, `maxItems`, `minItems`, `repeaterTitle`, `repeaterCollapse`, `repeaterAddLabel` and `renamedFrom`.

A regular fieldset uses `children` for visual organization; its leaf properties remain flat in the current data scope. Markup inputs have no stored value. Property names start with a letter or underscore and contain only ASCII letters, digits and underscores. `data`, `pages_id` and `count` are reserved.

### Inputfield settings and references

`type` selects a ProcessWire Inputfield module: `text` and `InputfieldText`
select the same input. Use the settings of that Inputfield in the definition.
Flex owns storage and output formatting; selecting an Inputfield does not
inherit a corresponding Fieldtype's database storage or textformatters.

| Setting | Example | Purpose |
| --- | --- | --- |
| `label`, `description`, `notes` | `'label' => __('Email')` | Editor-facing text |
| `required` | `true` | Require a value during native form processing |
| `columnWidth` | `50` | Percentage width in the editor |
| `value` | `'DE'` | Initial value when a stored key is absent; an explicit blank remains blank |
| `options` | `['DE' => __('Germany')]` | Available keys and labels for choice inputs |
| `showIf` | `'enabled=1'` | Show this input when a condition matches |
| `requiredIf` | `'enabled=1'` | Conditional validation; also set `required => true` |
| `useLanguages` | `true`, `false` or `'auto'` | Required, disabled or optional multilingual text; see [language modes](#optional-and-required-language-support) |

Dependency expressions use names in the current Flex scope. For example,
`showIf => 'enabled=1'` refers to the sibling property `enabled`; Flex generates
the scoped native input names, including inside repeatable rows.

Consult the [native Inputfield reference](https://processwire.com/api/ref/inputfield/)
for shared settings and the installed module's `API.md` or PHPDoc header for
type-specific settings. Core modules live under `wire/modules/Inputfield/`;
the [public core source](https://github.com/processwire/processwire/tree/master/wire/modules/Inputfield)
is also available. Check documentation against your installed core version.

| Input | Settings to look up | Reference |
| --- | --- | --- |
| Text and Textarea | `maxlength`, `placeholder`, `rows`, `contentType` | `InputfieldText` / `InputfieldTextarea` module headers |
| Select and related choices | `options`, option groups and language labels | [InputfieldSelect](https://processwire.com/api/ref/inputfield-select/) |
| Datetime | `inputType`, `datepicker`, `dateInputFormat`, `timeInputFormat` | `InputfieldDatetime/API.md` and its module header |
| TinyMCE | `toolbar`, `plugins`, `inlineMode`, `settingsField`, `settingsJSON` | [TinyMCE documentation](https://processwire.com/api/ref/inputfield-tiny-m-c-e/) and `InputfieldTinyMCE/API.md` |
| Page | `inputfield`, `findPagesSelector`, `labelFieldName`, `derefAsPage` | [FieldtypePage](https://processwire.com/api/ref/fieldtype-page/) and `InputfieldPage` module header |
| File and Image | Extensions, size limits, dimensions, descriptions | [Flex media contract](#file-and-image-properties); Flex implements a specific supported subset |

`inheritField` is a Flex setting that copies a same-type field's configured
Inputfield, then applies local overrides. It does not copy that field's stored
values. See [inheritField](examples/inherit-field.php).

#### Dates and a datepicker

```php
'starts_on' => [
	'type'             => 'datetime',
	'label'            => __('Start date'),
	'inputType'        => 'text',
	'datepicker'       => InputfieldDatetime::datepickerFocus,
	'dateInputFormat'  => 'd.m.Y',
	'dateOutputFormat' => 'd.m.Y',
],
```

The native text input shows a datepicker on focus. A date entered as
`18.10.2026` is read as `2026-10-18` with output formatting off and as
`18.10.2026` with it on. `dateInputFormat` controls editor parsing;
`dateOutputFormat` controls the Flex formatted read. For date and time, use
`timeInputFormat` as well. For direct API assignment of date/time, an integer
Unix timestamp such as `strtotime('2026-10-18 14:30:00')` avoids ambiguity with
the configured editor format. See [examples/dates.php](examples/dates.php).

#### Direct and reused TinyMCE settings

```php
'body' => [
	'type'    => 'TinyMCE',
	'label'   => __('Body'),
	'rows'    => 8,
	'toolbar' => 'bold italic | bullist numlist | link',
],
```

Install InputfieldTinyMCE before loading this definition. Configure settings
directly as above, copy an existing TinyMCE field's Inputfield with
`inheritField => 'rich_text_source'`, or use TinyMCE's own
`settingsField => 'rich_text_source'`. The latter is applied by the native
editor lifecycle; it is distinct from Flex's same-type inheritance.
See [direct settings](examples/rich-text.php), [inheritField](examples/inherit-field.php)
and [settingsField](examples/settings-field.php) for complete definitions and
their required source fields.

### Optional and required language support

Use `'auto'` to share a text definition between projects with and without
multilingual field support:

```php
'heading' => [
	'type'         => 'text',
	'label'        => __('Heading'),
	'useLanguages' => 'auto',
],
```

| Mode | LanguageSupport and LanguageSupportFields available | Either capability missing |
| --- | --- | --- |
| `false` (default) | Plain value | Plain value |
| `true` | Multilingual value | Definition error |
| `'auto'` | Multilingual value | Plain value |

Auto is supported on Text/Textarea inputs and their subclasses, including
TinyMCE. It requires an active Languages API and an installed
LanguageSupportFields module to enable multilingual values. Flex does not
install modules for this option. Array, JSON and native Inputfield-object
definitions use the same resolution, including ordinary fieldsets and nested
typed rows. Native inputs and schema discovery receive the resolved boolean.

With multilingual support, the runtime value is a LanguagesPageFieldValue and
canonical data is a map keyed by language ID. Without it, the value and stored
data are ordinary strings. String output works in either project; methods such
as `getLanguageValue()` require multilingual support. A previously stored
single-language string becomes the default-language value when support is
enabled.

When multilingual text switches to plain mode (explicit `false` or auto without
language support), existing language maps expose the default-language text,
including nested rows. A blank or missing default stays blank; the current
user's language does not affect this fallback. Reading leaves the database
unchanged. Saving that Flex field replaces its language maps with plain strings
and discards the other translations. No bulk conversion runs. Selectors continue
to query the stored JSON representation until the field is saved.

Flex remembers the installation's default-language ID centrally in its module
configuration on Flex field saves while the Languages API is available. The live
API takes precedence; the remembered ID allows reads after LanguageSupport is
removed. Save a Flex field with this version before removing LanguageSupport.
If neither the API nor a remembered ID is available, old language maps cannot
be resolved as scalar text. This metadata needs no manual configuration. See the
[portable example](examples/languages-auto.php) and the
[required-language example](examples/languages.php).

### Tested Inputfields

The integration fixture verifies construction and native rendering for these
core inputs. This is not a claim that every setting of every input is covered.

| Group | Verified Inputfields | Further behavior covered |
| --- | --- | --- |
| Text and numbers | Text, Textarea, Email, URL, Integer, Float, Hidden, Icon | Scalar hydration, plain-text encoding and numeric types; HTML textarea output |
| Choices | Select, SelectMultiple, AsmSelect, Radios, Checkbox, Checkboxes, Toggle, TextTags | Stored keys/arrays, checkbox values and TextTags strings; select label maps, translations and encoding |
| Date and layout | Datetime, Fieldset, Markup | Canonical/formatted dates; ordinary fieldsets flatten; Markup has no stored value |
| References | Page with Select, PageListSelect, PageListSelectMultiple or PageAutocomplete | Page/PageArray output and unpublished-reference filtering |
| Rich text | TinyMCE | HTML output, direct and reused settings, native processing; real Page Edit saves |
| Media | File, Image through the Flex media integration | Output modes, uploads, metadata, ownership, duplication and cleanup; real image upload/save |

LanguageSupport and LanguageSupportFields are needed when `useLanguages` is true;
`'auto'` falls back to plain text if either capability is unavailable.
Language values, label translation and nested mutation are covered separately.
TinyMCE is a core module that may need installation. Native Repeater is a
supported **container for a Flex field**; it is not an arbitrary nested property
type. Flex's repeatable fieldset is its own structured value model.

CKEditor has not been verified in Flex. Its presence in ProcessWire does not
establish Flex integration coverage. Inputs from third-party modules or inputs
that require separate Fieldtype storage need their own integration assessment.

### Renaming properties

Use `renamedFrom` on the new property definition to read a previous key:

```php
'heading' => [
	'type'        => 'text',
	'renamedFrom' => 'title',
],
```

A rename is applied when the value is read and saved. If the old and new keys both exist with different values, Flex throws rather than discarding one. `renamedFrom` also accepts an array of previous keys; ambiguous mappings are rejected. With `flexPrune` enabled, keys omitted from the active schema are removed on save. Set `flexPrune` to `false` for definitions that intentionally vary by Page context; unknown stored keys are then preserved.

### Definition evaluation and errors

Definitions are evaluated on demand, not globally cached. `FlexField::defs(?Page $page = null)` returns the active `FlexFieldDefs`. The supplied Page controls PHP evaluation; omitting it uses a NullPage.

```php
$defs = $field->defs($page);
$input = $defs->getPropertyInputfield('blocks.text.heading');
$properties = $defs->properties();
```

`getPropertyInputfield()` returns a fresh configured Inputfield. `getInputfields()` returns a fresh native input tree. `property()` and `properties()` return Flex metadata arrays; use native Inputfield methods for stable user-facing inspection.

Invalid definition errors include the field and source. A reliable source line is included for errors raised directly in a PHP definition file. During ProcessPageEdit, invalid definitions block the page save and preserve submitted values so the definition can be corrected. Database, permission and media ownership errors keep their original error types.

## Page values

`$page->field_name` returns a `FlexValue` value object. It extends ProcessWire `WireData` and exposes known properties through property access or these methods:

```php
$value->get(string $name);
$value->set(string $name, $input): FlexValue;
$value->getUnformatted(string $name);
$value->hasValue(string $name): bool;
$value->getArray(): array;
$value->rawArray(): array;
$value->label(string $property): string;
$value->optionLabel(string $property, string|int|null $option = null): string;
$value->getOptions(string $property): array;
$value->definitions(): FlexFieldDefs;
$value->of(?bool $formatted = null): bool;
$value->isChanged(string $name = ''): bool;
```

Use `$page->of(false)` before writing values, then save the field:

```php
$page->of(false);
$page->content->heading = 'Introduction';
$page->save('content');
```

`getArray()` and iteration follow the value's output-formatting state. `getUnformatted()` returns one property without output formatting. `rawArray()` and JSON serialization return canonical unformatted values. `of()` returns the previous formatting state. Unknown property reads return `null`; writes to unknown properties throw.

`hasValue()` checks logical content for a known property, independent of output formatting. Null, empty strings, false, empty collections and NullPage are empty; numeric zero and string "0" are populated. A multilingual value is populated if any language has content. A repeatable set is populated when a row leaf has content; row ID and type metadata alone do not count. Media counts as populated by stored entries, without checking whether files exist on disk.

### Array views and JSON

Use `getArray()` to work with the current runtime values by property name.
Use `rawArray()` when you need their canonical, unformatted persistence shape.
Neither method accepts a property parameter: read one value with `get()` or
`getUnformatted()`; read available choices with `getOptions()`.

| Operation | Formatting | Nested values | Intended use |
| --- | --- | --- | --- |
| `$value->getArray()` | Follows `$value->of()` | Keeps runtime objects | Iterate or inspect current property values |
| `foreach($value as $name => $item)` | Same as `getArray()` | Keeps runtime objects | Iterate known properties |
| `$value->rawArray()` | Always unformatted | Converts Flex rows/media, languages and Page references to persistence data | Inspect or serialize canonical values |
| `json_encode($value)` or `(string) $value` | Same canonical data as `rawArray()` | JSON objects/arrays and scalar values | Serialize data; not an HTML-safe rendering |

For text `A & B` and a date `2026-10-18` configured with
`dateOutputFormat => 'd.m.Y'`:

```php
$page->of(true);
$value = $page->content;
$display = $value->getArray();
// $display['heading'] === 'A &amp; B'
// $display['starts_on'] === '18.10.2026'

$raw = $value->rawArray();
// $raw['heading'] === 'A & B'
// $raw['starts_on'] === '2026-10-18'
```

With formatting off, `getArray()` contains unformatted runtime values, but
still retains their object types. In particular:

- Ordinary fieldsets add no array nesting: their leaves are entries in the
  current scope.
- A repeatable property remains a `FlexRows` collection of `FlexRow` objects.
  Each row's `getArray()` returns its own leaf values. The collection's
  `getArray()` is inherited from WireArray and is keyed by stable row IDs.
- Page references remain Page, NullPage, false or PageArray according to
  `derefAsPage`. Language values remain LanguagesPageFieldValue objects.
- File/Image values keep the normal formatted or unformatted media return
  types; `getArray()` does not convert Pagefile objects into metadata arrays.

`rawArray()` recursively creates `rows:1` envelopes containing each row's ID,
type and values. Media becomes `files:1` / `images:1` metadata envelopes;
file bytes are not embedded. Languages become maps keyed by language ID.
Page references become IDs or ID arrays; a blank `derefAsPage=1` value remains
false. These persistence shapes are not a public interchange format for
copying owned media between pages. Use the normal assignment and duplication
APIs so ownership is preserved.

`getArray()` contains known properties only. With `flexPrune=false`,
`rawArray()` also retains unknown stored keys. Both methods read the current
in-memory state, including unsaved edits; neither saves the field or queries a
fresh database snapshot. The returned runtime objects are not independent deep
copies. Reading arrays leaves canonical data unchanged, even with formatting on.

### Property and option labels

`label($property)` returns the translated property label. `optionLabel($property)` returns the translated label for the current single selection; pass a second argument to resolve a specific option key. `getOptions($property)` returns **all available choices** as keys mapped to translated labels, independent of the current selection. Optgroup headings and separators are omitted.

```php
$value->label('appearance');                    // Appearance
$value->optionLabel('appearance');              // Current selection: Standard
$value->optionLabel('appearance', 'featured');  // Featured
$value->getOptions('appearance');               // ['standard' => 'Standard', ...]
```

The same methods work on the root FlexValue and on a FlexRow at any nesting level. Current-value lookup reads that object's unformatted selection, so encoded option keys still resolve correctly. If a section type defines an `appearance` choice, a schema path such as `$content->optionLabel('sections.text.appearance', 'featured')` needs an explicit key: the path identifies a definition, not a particular row.

The singular helper returns `''` for an unknown option/property or an array selection. Zero and empty-string keys are valid. Omitting the second argument reads the current selection; passing `null` explicitly looks up the empty-string key, just like passing `''`. For multiple selections, resolve each selected key explicitly:

```php
foreach($value->getUnformatted('tags') as $key) {
	echo $value->optionLabel('tags', $key);
}
```

Unknown properties return `''` from `label()` and `[]` from `getOptions()`. These helpers follow the current user language and output-formatting state. When entity encoding is enabled, formatted labels are encoded once; raw labels are unchanged.

## Property value types

These are runtime property values. The raw array/JSON persistence shape is shown separately.

| Inputfield definition | Unformatted property value | Formatted property value | `rawArray()` / JSON |
| --- | --- | --- | --- |
| Text, plain textarea, email, URL, single select | `string` | `string`, entity encoded by default | string |
| HTML textarea or TinyMCE | `string` | HTML markup preserved | string |
| Integer | `int` or `''` | same | number or `''` |
| Float | `float` or `''` | same | number or `''` |
| Checkbox | Inputfield `checkedValue` or `uncheckedValue` | same | scalar |
| Checkboxes or multiple select | `string[]` | encoded `string[]` when enabled | array |
| TextTags | space-separated string | encoded string when enabled | string |
| Datetime | canonical string or `''` | formatted string or `''` | `Y-m-d` or `Y-m-d H:i:s` |
| Page, `derefAsPage=0` | `PageArray` | `PageArray`, unpublished pages omitted | ID array |
| Page, `derefAsPage=1` | `Page` or `false` | same; unpublished becomes false | ID or false |
| Page, `derefAsPage=2` | `Page` or `NullPage` | same; unpublished becomes NullPage | ID, with `0` for empty |
| `useLanguages` | `LanguagesPageFieldValue` | same class, optionally encoded | map keyed by language ID |
| Repeatable fieldset | `FlexRows` of `FlexRow` | same classes with formatted leaves | `rows:1` envelope |
| File or Image | `FlexFiles` or `FlexImages` | collection, `Pagefile`/`Pageimage`, or `null` | `files:1`/`images:1` envelope |

String conversion of a language value follows the current user's language. The [language mode](#optional-and-required-language-support) determines whether a text property exposes a language value or a plain string. Formatted Page references omit unpublished pages; unformatted storage retains their IDs.

### Dates, numbers, selections and text

Datetime values are stored as `Y-m-d` or `Y-m-d H:i:s`, without a UTC offset. Formatted reads use `dateOutputFormat`, then `dateInputFormat`, then `Y-m-d`. The configured PHP/site timezone is used when a selector normalizes a date string. Use an explicit ISO value for reproducible date selectors.

Canonical ISO date/date-time strings are recognized before applying the editor's
`dateInputFormat`, so saving and reloading cannot reinterpret them as localized
input. Other date strings are parsed with that input format, with PHP date
parsing as a fallback; an integer Unix timestamp can also be assigned.

Integer and Float properties remain numeric when read. Flex does not add locale formatting or round Float values on output. An Inputfield's `precision` setting applies to values processed by that native input; direct API assignment performs Flex type normalization but does not run all native input validation or input-specific rounding. Floating-point persistence has the normal PHP/JSON precision limits.

Selection properties store option keys, not labels. Single selects return a string key; multi-selects return arrays of string keys. Changing a label does not change stored values. Use `optionLabel()` for one label or `getOptions()` for the full option map. Property selectors compare option keys.

Ordinary text is entity encoded on formatted reads when `flexEntityEncode` is enabled. TinyMCE and textareas explicitly configured for HTML preserve markup. Native editor processing handles its configured input validation and purification; assigning HTML through the API does not run that editor pipeline. Flex does not run a configurable chain of Fieldtype Textformatters on property reads. `inheritField` reuses the native Inputfield class and its configured settings from an existing field of the same Inputfield type, then applies settings from the Flex definition. Values still use Flex storage; the source field's storage and output pipeline are not inherited. This is useful for copying the more involved configuration of Datetime and TinyMCE inputs. See `examples/inherit-field.php`.

ProcessWire also includes the native `InputfieldCKEditor` rich-text editor, which can be selected with `'type' => 'CKEditor'` when available. Flex currently has no CKEditor-specific integration or browser-save coverage; the module is documented here as an available native input, but its behavior in Flex has not been verified. See the [ProcessWire CKEditor documentation](https://processwire.com/docs/fields/ckeditor/).

## Repeatable rows

Every repeatable fieldset declares a non-empty `types` map. Each type has its own label and `children` schema. Type keys start with a lowercase letter and contain lowercase letters, digits or underscores, up to 64 characters. `count` is reserved.

```php
'blocks' => [
	'type'             => 'fieldset',
	'repeatable'       => true,
	'label'            => __('Content blocks'),
	'minItems'         => 0,
	'maxItems'         => 30,
	'repeaterCollapse' => 0,
	'repeaterAddLabel' => __('Add block'),
	'types'            => [
		'text' => [
			'label'         => __('Text'),
			'repeaterTitle' => '#n: {heading}',
			'children'      => [
				'heading' => ['type' => 'text'],
				'body'    => ['type' => 'TinyMCE'],
			],
		],
	],
],
```

The API uses type-qualified schema paths and explicit type selection:

```php
$rows = $page->content->blocks;
$row = $rows->addRow('text', ['heading' => 'Introduction']);
$row->rowType();              // immutable type name
$row->rowId();                // stable 32-character hex ID
$rows->getById($id);          // FlexRow|null
$rows->moveRow($id, $beforeId);
$copy = $rows->duplicateRow($id);
$rows->removeRow($id);
$rows->rawArray();            // canonical rows:1 envelope
```

Type keys start with a lowercase ASCII letter and contain lowercase letters, digits or underscores, up to 64 characters; `count` is reserved. Rows retain stable IDs and immutable types. `addRow()` requires a known type; there is no implicit default. Duplicating a row preserves its type, assigns fresh IDs to the copied subtree and creates independent media copies. Sorting and removal apply to siblings within one collection.

A type-specific `repeaterTitle` can use a bare leaf name such as `heading` or text with `{heading}` and `#n`. Tokens resolve only known flat leaves in that row type; unknown or empty tokens are blank. Resulting text is stripped of HTML and escaped. `repeaterCollapse` supports `0` (existing rows closed, new rows open), `1` (all closed) and `3` (all open). Rows remain individually toggleable. `repeaterAddLabel` sets the add button text. `minItems` and `maxItems` apply to the whole collection across types; `maxItems=0` means unlimited within the field resource budgets. Numeric strings are accepted for `repeaterCollapse`; invalid values use the default `0`. Invalid non-string add labels, row type labels and row titles use their normal defaults. These presentation fallbacks do not relax schema validation or row limits.

### Ordinary fieldsets and single-row collections

An ordinary fieldset groups inputs visually and flattens its leaves into the
current value. A repeatable fieldset always introduces a collection, even with
one declared type and `maxItems => 1`.

| Definition | Runtime access | Selector path |
| --- | --- | --- |
| Ordinary `address` fieldset with `city` | `$page->contact->city` | `contact.city=Berlin` |
| Repeatable `address`, type `item`, `maxItems=1` | `$page->contact->address->first()->city` when a row exists | `contact.address.item.city=Berlin` |

`maxItems` limits row count; it does not flatten the scope or create an implicit
row. For a blank collection, `first()` returns false; check it before reading.
Canonical storage for the repeatable case remains a `rows:1` envelope with row
identity and type; ordinary fieldset leaves remain keys in the containing value.
Native form inputs likewise use the current scope for ordinary leaves and a
row-specific scope for repeatable leaves. Flex generates those input names.

### Nested repeatables and resource limits

A row type may contain another repeatable fieldset in its `children`. The same FlexRows API, native form processing and selector rules apply at every depth.

```php
$section = $page->content->sections->addRow('section', ['heading' => 'Intro']);
$block = $section->blocks->addRow('text', ['heading' => 'Hello']);
$block->notes->addRow('note', ['text' => 'Third level']);
```

Schema paths include row types, for example `sections.section.blocks.text.heading`. Runtime addresses include stable row IDs, for example `sections.<row-id>.blocks.<row-id>.heading`. Input names are generated by Flex; construct values with the API instead of creating POST field names.

Nesting has no fixed two-level cap. The effective maximum depends on the JSON structure, database parser, other property types and field resource budgets. MariaDB's JSON parser accepts 32 nesting levels and MySQL's accepts 100; a repeatable set uses more than one JSON level, while languages and media may add more. Five repeatable levels are covered by the integration suite; a minimal local MariaDB chain of seven levels passed, while eight was rejected before mutation. These examples are evidence, not a general level guarantee for every schema.

By default, a field allows up to 2,000 total rows across all descendants and 8 MiB of canonical JSON. `flexMaxRows` and `flexMaxBytes` can lower or raise those additional budgets; zero disables the corresponding Flex budget but never the database limit. `flexMaxDepth` adds an optional field-specific depth budget; zero adds no extra cap.

The editor checks the actual PHP `max_input_vars` budget before submit. Completion markers reject truncated whole-form, field and AJAX-row requests. Flex does not change `max_input_vars` or `post_max_size`; increase PHP limits for large forms. An incomplete request blocks the owning page save and preserves the processed input for correction.

## File and Image properties

Flex manages File and Image values while exposing ProcessWire-compatible `Pagefile` and `Pageimage` objects. Unformatted reads return `FlexFiles` or `FlexImages` collections for every file limit. A file or image's real containing Page and Flex Field remain its owner, including inside a native Repeater item.

The formatted `outputFormat` setting applies only to formatted reads:

| `outputFormat` | Formatted result |
| --- | --- |
| `0` Auto (default) | A `Pagefile`/`Pageimage` or `null` when `maxFiles=1`; otherwise a collection |
| `1` Collection | A collection, including when empty |
| `2` Single | First file/image or `null`, for every file limit |

For example, with `maxFiles=1` and default Auto:

```php
$page->of(true);
$image = $page->content->hero;
if($image) {
	echo $image->size(640, 360)->url;
}

$images = $page->content->getUnformatted('hero'); // always a FlexImages collection
```

The ProcessWire `Pageimage` resize, crop and focus APIs remain available. New uploads and destructive image edits are staged until the page saves. Cross-property/page assignment and row duplication create independent copies. Physical removal is deferred until canonical data has committed. `maxFiles` is enforced during hydration and writes; lowering a limit below stored content can reject reads or saves and does not prune data.

To import a file through the value API, disable output formatting and add a readable local path:

```php
$page->of(false);
$page->content->downloads->add('/absolute/path/to/document.pdf');
$page->save('content');
```

Remote URLs are rejected. New or replaced files require saving the containing Flex
field. For an already committed file, `$file->save()` saves only that
file's metadata and preserves unrelated pending scalar or row edits. Concurrent
metadata changes cause a conflict instead of being overwritten. To save selected
metadata, use `$type->saveFile($page, $field, $file, ['description'])`;
allowed columns are description, tags, filedata, modified and modified_users_id.

Media supports native extension, size, dimension and count limits, plus descriptions and free tags. File extensions are required. Image properties default to ProcessWire's native image upload formats: JPG/JPEG, GIF and PNG; WebP is not a permitted ProcessWire image upload extension. Overwrite, predefined tags, custom metadata templates and string output mode are not supported. The native variation-management dialog is disabled; use the `Pageimage` API. TinyMCE drag-and-drop uploads require a regular ProcessWire Image field as their destination.

## Selectors

Scalar properties use normal ProcessWire selectors:

```php
$pages->find('content.starts_on>=2026-10-18');
$pages->find('contact.email*=example.org');
```

Supported numeric/date operators are `=`, `!=`, `>`, `>=`, `<` and `<=`. Text operators include `=`, `!=`, `*=`, `%=`, `^=`, `$=`, `%^=`, `%$=`, `~=` and `~|=`. Generic arrays and PageArrays support `=` and `!=` membership. Blank JSON properties and absent rows match as empty; explicit numeric zero remains populated.

Typed repeatable properties include the type in the path:

```php
$pages->find('content.blocks.text.heading=Introduction');
$pages->find('content.blocks.count>=3');
$pages->find('content.blocks.text.count>0');
$pages->find('content.blocks._type=image');
```

A leaf predicate uses any-row semantics; separate paths may match different rows. Use a quoted same-row selector when all conditions must match one concrete row:

```php
$pages->find('content.blocks.text="heading=Introduction, body*=ProcessWire"');
```

Nested same-row predicates can be nested inside their parent expression. A global negative or blank predicate matches when no row along that schema path has a matching/populated leaf; missing ancestors count as no matching row. A same-row expression requires an existing row. A count is zero when the field or parent rows are absent.

`getSelectorInfo()` exposes queryable properties to native `InputfieldSelector`. Definitions must reveal queryable properties without a specific Page. Page option discovery follows native selectable-page rules and is capped at 500. Page-reference traversal, arbitrary nested JSON paths, advanced fuzzy/full-text operators and sorting repeated values or type-specific counts are not supported.

## Field settings

| Setting | Default | Meaning |
| --- | --- | --- |
| `flexEntityEncode` | `true` | Entity encode eligible values and labels on formatted reads |
| `flexPrune` | `true` | Remove keys omitted from the active schema on save |
| `flexHideWrap` | `false` | Hide the outer editor heading |
| `flexDefinition` | empty | Inline JSON source |
| `flexDefinitionFile` | empty | Explicit path relative to `site/templates` |
| `flexMaxDepth` | `0` | Additional schema/repeatable depth limit; zero adds no field-specific cap |
| `flexMaxRows` | `2000` | Total row budget, including descendants; zero disables this additional budget |
| `flexMaxBytes` | `8388608` | Canonical JSON byte budget; zero disables this additional budget |

A definition may also use `renamedFrom`, `inheritField`, `repeatable`, `types`, row limits and native Inputfield settings. `inheritField` copies the configured Inputfield class/settings from an existing field of the same type, then applies local overrides. TinyMCE `settingsField` is a distinct native TinyMCE setting, resolved during the editor lifecycle; it is not an alias for `inheritField`.

## Maintenance APIs

The Fieldtype exposes maintenance helpers for rebuilding derived indexes and collecting registered media:

```php
$type = $modules->get('FieldtypeFlex');
$type->rebuildRowIndex($field, $afterPageId = 0, $limit = 100);
$type->collectFiles($limit = 100);
$type->repairClonedFiles($copy);
```

`rebuildRowIndex()` rebuilds the query projection from canonical field JSON in bounded batches. It returns `count` (processed Pages) and `lastPageId` for the next batch. Run it from the site's ProcessWire context. `collectFiles()` removes registered Flex assets that are no longer referenced; call it periodically and outside a transaction. It retains unknown files and references used by other fields or known page versions. `repairClonedFiles()` retries registered media copies for a Page clone whose native directory copy was incomplete.

The canonical field JSON is authoritative. Do not edit derived projection rows directly.

## Compatibility

Flex targets ProcessWire's public Field, Inputfield, Page, selector and file APIs. It does not import a field's existing SQL table or automatically convert values from another fieldtype. Native Page-version save/restore is outside the supported contract. Database support is MySQL and MariaDB with InnoDB.
