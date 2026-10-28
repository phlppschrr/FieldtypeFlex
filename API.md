# FieldtypeFlex API

FieldtypeFlex stores structured properties in one ProcessWire field and exposes them through `FlexValue`, `FlexRows`, `FlexRow`, and ProcessWire-compatible media objects. This reference covers the supported definition and runtime APIs. For installation and a first example, see [README.md](README.md). For shared ProcessWire APIs, see the [ProcessWire API reference](https://processwire.com/api/ref/).

- [Value types](#value-types)
- [Reading and writing values](#reading-and-writing-values)
- [Page references](#page-references)
- [Native compatibility limits](#limits-of-native-compatibility)
- [Definitions](#definitions)
- [Rows](#rows)
- [File and Image properties](#file-and-image-properties)
- [Selectors](#selectors)
- [Inputfield settings](#inputfield-settings)
- [Field settings](#field-settings)
- [Maintenance APIs](#maintenance-apis)
- [Notes and limitations](#notes-and-limitations)

## Value types

A Flex field returns a `FlexValue` object extending `WireData`:

```php
$value = $page->content;           // FlexValue
$title = $value->heading;          // Property value
$blocks = $value->blocks;          // FlexRows
$row = $blocks->first();           // FlexRow, or false when empty
```

Examples run in the `ProcessWire` namespace after bootstrap. Unless stated otherwise,
`content` is a Flex field on `$page` containing the properties used by that example;
combine the definition fragments with your field definition. Install InputfieldTinyMCE
for examples using `TinyMCE`. The media import path must be replaced with a readable local file. Writing examples assume a saved, editable Page.

Individual properties retain types appropriate to their definitions. **The Page's output-formatting state affects how some properties are read; it does not change canonical stored data.**

| Definition | Unformatted value | Formatted value | Canonical JSON representation |
| --- | --- | --- | --- |
| Text, plain textarea, email, URL, single select | `string` | `string`; Textformatters apply only to text-valued inputs | String |
| HTML textarea, TinyMCE | `string` | HTML string | String |
| Integer / Float | `int` / `float`, or `''` | Same | Number or `''` |
| Checkbox | `1` / `0` by default; custom non-empty checked/unchecked values supported | Same | Scalar |
| Toggle | `0`, `1`, `2` or `''` | Same (native `formatNone` behavior) | Number or `''` |
| Multiple choices | `string[]` | Same raw keys | Array of keys |
| TextTags | Space-separated string | Same raw keys | String |
| Datetime | Unix timestamp `int`, or `''` | Formatted string; timestamp if no output format (or `U`); `''` if empty | `Y-m-d` or `Y-m-d H:i:s` |
| Page reference (`derefAsPage=0`) | `PageArray` | `PageArray`, native filtering rules below | Page ID array |
| Page reference (`derefAsPage=1`) | `Page` or `false` | Same, with unpublished pages omitted | Page ID or `false` |
| Page reference (`derefAsPage=2`) | `Page` or `NullPage` | Same, with unpublished pages omitted | Page ID or `0` |
| Multilingual text | `LanguagesPageFieldValue` | Current-language `string`, formatted by configured Textformatters | Map keyed by language ID |
| `rows` | `FlexRows` containing `FlexRow` | Same classes, formatted leaf values | `rows:1` envelope |
| File / Image | `FlexFiles` / `FlexImages` | Collection, compatible `Pagefile` / `Pageimage`, or `null` | Media metadata envelope |

Page-reference mutation follows native `FieldtypePage` rules; see [Page references](#page-references).

Selection properties store **raw option keys**, not their display labels; they are not HTML-encoded. The `label()`, `getOptions()` and `optionLabel()` display helpers encode labels when output formatting is enabled. Page-reference visibility follows the installed core’s `allowUnpub` and formatting rules, described below. For details on media output modes, see [File and Image properties](#file-and-image-properties).

### Output formatting and serialization

```php
$page->of(true);
echo $page->content->heading;           // Formatted text

$rawHeading = $page->content->getUnformatted('heading');
$display = $page->content->getArray(); // Runtime values, current formatting
$raw = $page->content->rawArray();     // Canonical unformatted data
$json = json_encode($page->content);  // Unformatted JSON view
```

`getArray()` and iteration retain runtime objects such as `FlexRows`, Page references and media objects (media may be a single object when formatted). Language objects are retained only with formatting off; formatted language reads are strings, and unformatted date reads are timestamps. Neither array method accepts a property argument. `rawArray()` recursively serializes their canonical data; `(string) $value` and `json_encode($value)` use that same unformatted representation. These are **not** HTML-safe rendering methods. They expose logical persistence data, not the exact database JSON bytes: empty PHP property arrays serialize as `[]`, while database storage preserves property maps as `{}`.

`getArray()` contains known properties only; `rawArray()` always retains unknown stored keys. Both describe current memory, including unsaved edits, and neither saves nor refreshes data. Returned runtime objects are not independent deep copies. A row’s `getArray()` contains its own properties; `FlexRows::getArray()` is inherited from WireArray and keyed by stable row IDs.

The JSON contains row IDs, types, values and media metadata—not uploaded file bytes. Flex owns its media storage separately and maintains derived data needed for selectors. Do not edit canonical envelopes or selector projections directly, or use serialized media metadata to copy files between pages.

## Reading and writing values

### Properties and saving

Access known properties directly or with `get()`; turn output formatting off before assigning values:

```php
$page->of(false);
$page->content->heading = 'Introduction';
$page->content->set('summary', 'An example');
$page->save('content');
```

Unknown ordinary property reads return `null`; writes to unknown properties throw `WireException`. Inherited WireData API lookups remain available; use `hasProperty()` on a row or inspect its definitions to test schema membership. Direct API assignments normalize Flex values but do not invoke every native editor validation or purification step. Native validation and dependencies run during editor processing.

| `FlexValue` method | Purpose |
| --- | --- |
| `get($name)` / `set($name, $value)` | Read a property (`mixed`); assign a known property and return `$this` |
| `getUnformatted($name)` | Read one property without output formatting |
| `getArray()` | Read known runtime properties in the current formatting state |
| `rawArray()` | Inspect canonical unformatted values, including nested structures |
| `hasValue(string $name): bool` | Check whether a property contains logical content |
| `label(string $name): string` | Get the translated property label |
| `optionLabel(string $name, $option = null): string` | Resolve a current or explicit single choice to its label |
| `getOptions(string $name): array` | Get available option keys and translated labels |
| `definitions(): FlexFieldDefs` | Get this value's evaluated `FlexFieldDefs` |
| `of(?bool $formatted = null): bool` | Read or change this value's formatting state; returns previous state |
| `isChanged($name = ''): bool` | Inspect change tracking |

`hasValue()` is independent of output formatting. Empty strings, `null`, `false`, empty collections and `NullPage` count as empty; numeric `0` and string `'0'` count as populated. A multilingual property is populated if any language has content. A rows collection is populated only when a row has meaningful leaf content, not merely an ID and type. Media is counted from its entries without checking file existence on disk. Whitespace is content; this is not an editor-required validation check.

`isChanged()` also checks nested rows, PageArrays, language values and media; pass a direct property name to inspect that property. Read helpers do not mark data changed. Page-formatted values are cloned views. Reacquire `$page->content` after `$page->of(false)` before editing; a previously retained formatted value stays formatted. `getUnformatted()` bypasses conversion for one property, but does not make a formatted value or its nested objects writable. Setters require both the value and owning Page to be unformatted. An unchecked checkbox is logically empty even though its default runtime value is integer `0`.

### Property and option labels

```php
$value = $page->content;
$value->label('appearance');                    // Property label
$value->optionLabel('appearance');              // Current single selection label
$value->optionLabel('appearance', 'featured');  // Explicit option label
$value->getOptions('appearance');               // Key => translated label map
```

`optionLabel()` returns `''` for unknown choices and array selections. Omitting its second argument resolves the current single selection; explicitly passing `null` selects the empty-string option key. To resolve a multiple selection, iterate its keys and call `optionLabel()` for each. These helpers are also available on `FlexRow` and follow the current user language and formatting state. `label()` returns `''` for an unknown property; `getOptions()` returns `[]` for unknown/non-choice properties and omits optgroup headings and separators. Zero and blank option keys are valid. Formatted property and option labels are always HTML entity-encoded; unformatted labels stay raw. Option *keys* are never automatically encoded. Reads do not change the stored option keys.

Schema paths such as `blocks.text.appearance` can inspect a nested definition with `label()`, `getOptions()` or an explicit-key `optionLabel()`. Omitting the key on such a path returns `''`: a schema path identifies no concrete row. Use the row object for its current selection.

### Dates, numbers, and text

Datetime properties return Unix timestamps with output formatting off, like native `FieldtypeDatetime`. `rawArray()` and stored JSON still use canonical `Y-m-d` or `Y-m-d H:i:s` strings without a UTC offset. PHP/site timezone applies; use ISO values for reproducible selectors. Formatted reads use the current-language `dateOutputFormat`, then the default `dateOutputFormat`; an empty format or `U` returns a timestamp, as in the core. Blank/null/false/zero inputs become `''`. Canonical ISO inputs are recognized before localized editor formats; Unix integers, numeric timestamp strings and `DateTime` objects are also accepted. A timestamp’s time component is preserved even without a time editor.

Integers and floats remain numeric on output. Direct assignments normalize numeric strings, reject non-numeric input with `WireException`, and apply configured Float `precision` on assignment and hydration (`2` by default; null, blank or negative disables rounding). Blank or null numeric input becomes `''`; normal PHP/JSON floating-point precision applies. Text without configured or inherited formatters remains unchanged on formatted reads. Configure `TextformatterEntities` for HTML text content or escape output in the template; HTML textarea and TinyMCE markup are preserved. Email and URL properties use the native sanitizers on API assignment, including the input’s IDN/relative-URL settings; Toggle normalizes native yes/no/other values to integers. Text-valued inputs can configure native ProcessWire Textformatters; see [Textformatters](#textformatters).

### Page references

`derefAsPage=0` returns a `PageArray`; `1` returns `Page` or `false`; `2` returns `Page` or `NullPage`—**in either output-formatting state**. Check `NullPage->id`, not object truthiness.

Flex uses native `FieldtypePage` for hydration, output filtering and save-time validation. `allowUnpub=false` excludes unpublished references on load; `true` retains them unformatted. Formatted output filters unpublished references according to native single/multiple rules; hidden references remain available. **Access permissions are not read filters**: call `$target->viewable()` when required.

Single references accept Page/PageArray, ID, path/name or native selector input. Multi-reference assignment of a Page/ID/ID array/string **appends**; a `PageArray` **replaces**. Null/blank multi assignment does not clear the selection; use `removeAll()` or an empty `PageArray`. Editor submissions replace selections. Saving enforces native selectable-page rules (`parent_id`, `template_id`, `findPagesSelector`); referenced Pages must be saved separately. See [selector traversal](#root-page-reference-traversal).

### Limits of native compatibility

The integration suite compares real native fields against Flex properties,
including assignment, blank values, both formatting states and save/reload.
It does not establish complete emulation of every Fieldtype setting:

| Area | Explicit difference |
| --- | --- |
| Plain Text/Textarea, Email/URL output | Flex delegates to native output formatting for text-valued properties even without a formatter list. As in native fields, `TextformatterEntities` must be configured for automatic HTML text escaping. Flex keeps its own JSON persistence rather than native Fieldtype storage. |
| HTML / TinyMCE | Common HTML values match; native Textarea markup-QA and link abstraction are not provided by Flex’s storage layer. Native output `htmlOptions` apply on formatted reads, including when no formatter list is configured. |
| Integer / Float | Flex always preserves explicit zero, Native Integer requires `zeroNotEmpty=1` to retain zero on save; native Float already retains zero. It rejects invalid/non-numeric API inputs rather than applying native permissive conversion or returning blank. |
| Datetime | Runtime types match; JSON keeps ISO strings. Invalid API dates raise `WireException` instead of the native blank fallback; localized input parsing remains supported. |
| Checkbox | Default `1`/`0` matches native Checkbox. Custom non-empty checked/unchecked scalars are a Flex/Inputfield extension; native FieldtypeCheckbox stores `1`/`0`. |
| Toggle | Default native integer/blank mode matches. FieldtypeToggle’s optional boolean/string/entity `formatType` modes are not applied. |
| Select, Radios, multiple choices, AsmSelect, TextTags | Flex exposes keys (string/string array); native FieldtypeOptions exposes a SelectableOptionArray in both formatting states. Native option-object methods/IDs are not a Flex API. |
| File / Image | Native-compatible objects and modes `0..2` match within configured limits; Flex owns copies, staged originals, metadata and cleanup. Native string output, remote imports and the other unsupported media settings remain excluded below. |
| Multilingual text | Raw language objects and formatted strings match. JSON stores language-ID maps rather than native language columns; plain-mode fallback remains Flex-specific. |
| Rows / fieldsets / Markup | Rows are Flex structures, not native Repeater Pages. Ordinary fieldsets and Markup organize the editor without their own stored value. |

## Definitions

A Flex field's properties are defined with a PHP array, JSON, or a native `InputfieldWrapper` tree. An automatic definition for a field named `content` is loaded from exactly one of:

```text
site/templates/flex-fields/content.php
site/templates/flex-fields/content.json
```

Alternatively set `flexDefinitionFile` to a PHP/JSON path relative to `site/templates`, or provide inline JSON via `flexDefinition`. Two automatic files, or an explicit path together with non-empty inline JSON, are configuration errors. Explicit paths must resolve inside `site/templates` and end in `.php` or `.json`. PHP files are trusted executable code; never make them editable by untrusted users.

```php
<?php namespace ProcessWire;

return [
    'heading' => [
        'type'           => 'text',
        'label'          => __('Heading'),
        'required'       => true,
        'textformatters' => ['TextformatterEntities'],
    ],
    'hero' => [
        'type'     => 'image',
        'maxFiles' => 1,
    ],
];
```

`type` selects a native ProcessWire Inputfield (`text` means `InputfieldText`) or Flex's own structure type `rows`. Property keys are stored names: they must begin with a letter or underscore and contain only ASCII letters, digits and underscores. `data`, `pages_id` and `count` are reserved.

An ordinary `fieldset` groups native inputs, but its `children` remain **flat properties in the containing value**. A `rows` property introduces a separate typed collection and data scope. See [Rows](#rows).

### Evaluation and schema inspection

PHP definitions execute in their owning Page context with `$page`, `$field` and `$wire`; `$languages` is `null` when LanguageSupport is absent. Definitions are evaluated on demand. Selector discovery also evaluates definitions without a specific Page, so queryable properties must be discoverable in that context.

```php
$field = $fields->get('content');
$defs = $field->defs($page); // FlexFieldDefs
$input = $defs->getPropertyInputfield('blocks.text.heading');
$tree = $defs->getInputfields();
```

`FlexField::defs(?Page $page = null): FlexFieldDefs` uses a NullPage context when no Page is supplied. `getPropertyInputfield(string $name): ?Inputfield` returns a fresh configured Inputfield, or null for an unknown path; `getInputfields()` returns a fresh input tree. Native Inputfield methods are preferred when inspecting native input settings.

Each type’s `children` may be an array or native wrapper, including nested `InputfieldFlexRows`. `getPropertyInputfield()` also accepts nested type-qualified schema paths. Internal schema metadata arrays are not the stable inspection API; prefer fresh native Inputfields for inspecting input settings.

A field without a definition may initially be empty. If an automatic definition disappears after the field contains data, Flex refuses further saves rather than replacing stored values with an empty schema. Invalid definitions report the field and source and block Page Edit saves while preserving submitted values for correction.

### Renaming and preserving properties

```php
'heading' => [
    'type'        => 'text',
    'renamedFrom' => 'title',
],
```

`renamedFrom` resolves old keys on read and persists new keys on individual saves, including nested rows. Equal values merge; conflicting old/new values throw `WireException`. Field configuration saves never migrate data, so selectors may see old keys until stored owners are migrated.

Unknown properties, nested data and owned media **always survive ordinary saves**; the old `flexPrune` switch is removed. Use [maintenance](#maintenance-apis) to apply bulk renames or explicitly remove unknown keys. Prefer complete definitions for all Page/role contexts and native collapsed/Locked input states; dynamically omitted properties complicate orphan discovery.

## Rows

`type => 'rows'` creates a `FlexRows` collection of typed `FlexRow` objects. It is **not** a native ProcessWire Repeater fieldtype. A non-empty `types` map is required, even for a collection with only one type. Each type has its own label and `children` schema.

```php
'blocks' => [
    'type'        => 'rows',
    'label'       => __('Content blocks'),
    'minItems'    => 0,
    'maxItems'    => 30,
    'addRowLabel' => __('Add block'),
    'rowCollapse' => 0,
    'types'       => [
        'text' => [
            'label'    => __('Text'),
            'rowTitle' => '#n: {heading}',
            'children' => [
                'heading' => ['type' => 'text'],
                'body'    => ['type' => 'TinyMCE'],
            ],
        ],
        'image' => [
            'label'    => __('Image'),
            'rowTitle' => '{caption}',
            'children' => [
                'photo'   => ['type' => 'image', 'maxFiles' => 1],
                'caption' => ['type' => 'text'],
            ],
        ],
    ],
],
```

Type keys start with a lowercase ASCII letter and contain lowercase letters, digits or underscores (up to 64 characters); `count` is reserved. Row type and stable row ID do not change after creation. `rowTitle` supports a leaf property name, `{property}` and `#n`; the rendered editor title strips markup and is escaped. `rowCollapse` supports `0` (existing closed/new open), `1` (all closed) and `3` (all open). Numeric-string collapse modes are accepted; invalid modes fall back to `0`. Invalid non-string labels and row titles use their normal defaults. `minItems` and `maxItems` apply across all types; `maxItems=0` means no per-collection maximum within the field's resource budgets.

### `FlexRow`: inspect a row

```php
$row->rowId();                     // Stable 32-character hex ID
$row->rowType();                   // 'text'
$row->rowTypeLabel();              // Resolved type label
$row->hasProperty('heading');      // Property is defined, even when empty
$row->hasValue('heading');         // Property contains logical content
```

`rowTypeLabel(): string` uses the evaluated type label or a title-case fallback. It does **not** automatically HTML-encode the label for output; escape it when rendering. `hasProperty(string $name): bool` checks the current row type's schema, not its stored content. Normal fieldset leaves are flat; nested row properties must be inspected on their own `FlexRow` objects.

### `FlexRows`: add, find, filter, and reorder

Turn Page output formatting off before changing rows:

```php
$page->of(false);
$rows = $page->content->blocks;
$row = $rows->addRow('text', ['heading' => 'Introduction']);
$copy = $rows->duplicateRow($row->rowId());
$rows->moveRow($copy->rowId(), $row->rowId());
$rows->removeRow($copy->rowId());
$page->save('content');
```

| Method | Result |
| --- | --- |
| `addRow(string $type, array $values = [])` | Appends a new typed `FlexRow` with a generated ID |
| `getById(string $id)` | `FlexRow|null` with the requested stable ID |
| `rowsOfType($type)` | Numeric `FlexRow[]` in current order |
| `countOfType($type)` | Number of direct matching rows |
| `duplicateRow(string $id): FlexRow` | Appends an independent typed copy with new descendant IDs and media copies |
| `moveRow(string $id, ?string $before = null): self` | Move before another sibling ID, or to the end; same ID is a no-op |
| `removeRow(string $id): self` | Remove a row by ID; unknown ID is a no-op |
| `rawArray()` | Canonical rows data; not a separate persistence API |

```php
$images = $page->content->blocks->rowsOfType('image');
$count = $page->content->blocks->countOfType('text');
```

`rowsOfType()` returns **the existing objects**, not clones or a second owned collection; the array is a snapshot of the current order. Both type helpers consider only direct rows, do not query the database, return `[]`/`0` for an unknown type string and throw `TypeError` for non-string arguments. Mutations to selected Row objects affect their owning collection, subject to normal output-formatting checks.

`addRow()` requires a declared type; unknown types raise `FlexDefinitionException`. Unknown IDs in `duplicateRow()` or either argument of `moveRow()` raise `WireException`; `getById()` returns null. Mutations with output formatting enabled and collection-limit violations also raise `WireException`. Maximum limits apply when adding/hydrating; minima are enforced on field save, so temporary underfilled collections can be edited. Sorting/removal affects siblings only. A single type or `maxItems=1` still returns a collection and does not create an implicit row. Guard `first()` against false on empty collections.

<a id="nested-repeatables-and-resource-limits"></a>

### Nested rows and native Inputfield definitions

A row type's `children` may contain another `type => 'rows'`. All row operations and type-qualified selectors work at each nesting level:

```php
$page->of(false);
$section = $page->content->sections->addRow('section', ['heading' => 'Intro']);
$block = $section->blocks->addRow('text', ['heading' => 'Hello']);
$block->notes->addRow('note', ['text' => 'A note']);
$page->save('content');
```

The example assumes the nested `sections → blocks → notes` schema in [examples/08-rows.php](examples/08-rows.php). Paths for schema lookup and selectors include each type, for example `sections.section.blocks.text.heading`.

Native `InputfieldWrapper` definitions use `InputfieldFlexRows` instead of the array/JSON type keyword:

```php
$rows = $modules->get('InputfieldFlexRows');
$rows->name = 'blocks';
$rows->addRowLabel = __('Add block');
$rows->types = [
    'text' => [
        'rowTitle' => '{heading}',
        'children' => ['heading' => ['type' => 'text']],
    ],
];
$inputs->add($rows); // $inputs is the returned InputfieldWrapper
```

See the [basic InputfieldWrapper example](examples/02-basic-objects.php); the object-based definition path remains supported. Flex fields may also be assigned to native ProcessWire Repeater item templates; that is distinct from defining a `rows` property.

Nesting is constrained by database JSON depth and field resources rather than a fixed number of repeatable levels. Defaults are **2,000 rows across all descendants** and **8 MiB canonical JSON** per field; see [Field settings](#field-settings). Very large editor forms may require larger PHP `max_input_vars` and `post_max_size` settings. Incomplete/truncated submissions are rejected rather than silently saved.

## File and Image properties

File and Image definitions use Flex-managed storage with ProcessWire-compatible `Pagefile` and `Pageimage` objects. The files themselves are not embedded in the JSON value.

```php
'hero' => [
    'type'     => 'image',
    'maxFiles' => 1,
],
'downloads' => [
    'type'       => 'file',
    'extensions' => 'pdf txt zip',
    'maxFiles'   => 5,
],
```

### Output formats

Unformatted reads always return a `FlexImages` or `FlexFiles` collection. With Page output formatting enabled, the property-level `outputFormat` setting determines its result:

| `outputFormat` | Formatted value |
| --- | --- |
| `0` Auto (default) | Single `Pageimage`/`Pagefile` or `null` when `maxFiles=1`; otherwise a collection |
| `1` Collection | Collection, even when empty |
| `2` Single | First media object or `null`, regardless of file limit |

```php
// With maxFiles=1, Auto mode, and normal front-end output formatting:
$image = $page->content->hero;
if($image) {
    echo $image->size(1200, 700)->url;
}

$images = $page->content->getUnformatted('hero'); // FlexImages collection
```

Formatted collection reads respect `maxFiles`; `maxFiles=0` means unlimited. Unformatted access retains the full collection. `maxFiles` is also enforced on hydration and writes: lowering it below existing content may reject reads/saves rather than prune entries. Numeric strings `'0'`, `'1'`, `'2'` are accepted for `outputFormat`; other modes raise a definition error.

Image resizing, cropping and focus APIs are available. The native image Variations dialog can list and immediately delete generated variations; removal of a derivative does not require saving the page or change the original image.

### Adding, saving, and ownership

```php
$page->of(false);
$page->content->downloads->add('/absolute/path/to/document.pdf');
$page->save('content');
```

File imports require readable local paths; remote URLs are rejected. New uploads and changes to original images are staged until the owning Flex field saves. The owning Page and Field remain responsible for the media, including inside native Repeater items. Copying across owners or duplicating rows creates independent media copies. Deletion of originals is deferred until canonical data commits.

Native collection iteration, `first()`, filename lookup with `get()`, `add()`, `delete()`/`deleteAll()`, `rename()` and `clone($file)` are available. File objects expose native URLs, filenames, size, descriptions, tags and filedata; images provide `size()`, `width()`, `height()`, `crop()` and `focus()`. Use the [Pagefiles](https://processwire.com/api/ref/pagefiles/), [Pagefile](https://processwire.com/api/ref/pagefile/) and [Pageimage](https://processwire.com/api/ref/pageimage/) references for these native APIs. Copies retain metadata and receive independent asset ownership.

`$file->rename('test.png')` and `$files->rename($file, 'test.png')` return the effective basename. Rename preserves the file object, asset ID, collection order and metadata. An available name is retained exactly after native filename sanitization; a suffix is added only for a conflict with page files, reserved Flex names or existing image variations. Original bytes and existing native image variations are copied to the new names while the previously committed files remain available. Save the owning Flex field to commit the rename; cleanup of the former names follows commit. A failed save leaves the previous canonical names and files intact. Uploads, replacements and duplicates retain their independent-copy naming and ownership behavior.

For an already committed file, `$file->save()` saves its metadata independently of other pending Flex edits; concurrent metadata changes are checked for conflicts. Use `$type->saveFile($page, $field, $file, ['description'])` on the module instance for selected metadata columns (`description`, `tags`, `filedata`, `modified`, `modified_users_id`).

File/Image definitions support `extensions`, `maxFiles` (default `0`), `maxFilesize` (bytes, `0` means no extra limit), native image dimension limits, descriptions and free tags (`useTags`). Native upload/editor processing applies input-specific validation; local imports enforce readable paths, allowed extensions, size and count, and must be validated appropriately for your application. File extensions must be configured. Default Image upload formats are: JPG/JPEG, GIF and PNG (not WebP). WebP derivatives may be generated when the installed ProcessWire image engine supports them; that does not enable WebP original uploads. Overwrite mode, archive extraction (`unzip`), property-level `useLanguages`, predefined tags, custom metadata templates and native string media output are not supported. Native language-aware descriptions use `noLang`; this is separate from multilingual text properties. TinyMCE drag-and-drop uploads require a regular ProcessWire Image field as their destination.

## Selectors

Flex properties participate in ProcessWire's selector API:

```php
$pages->find('content.heading*=Welcome');
$pages->find('content.starts_on>=2026-10-18');
$pages->find('content.blocks.text.heading=Introduction');
$pages->find('content.blocks._type=image');
$pages->find('content.blocks.count>=3');
$pages->find('content.blocks.text.count>0');
```

Use **type-qualified paths** for row values: `FIELD.COLLECTION.TYPE.PROPERTY`. More deeply nested collections include each collection name and type.

### Operators and matching semantics

- Numbers, dates and single Page references (numeric IDs): `=`, `!=`, `>`, `>=`, `<`, `<=`.
- Text: `=`, `!=`, `*=`, `%=`, `^=`, `$=`, `%^=`, `%$=`, `~=`, `~|=`.
- Generic arrays and multiple Page references: `=` and `!=` membership checks.

A row leaf predicate means **any matching row**. Separate row predicates may match different rows. To require multiple conditions on one concrete row, use a quoted same-row expression:

```php
$pages->find('content.blocks.text="heading=Introduction, body*=ProcessWire"');
```

Global `leaf!=value` means no row at that path matches `value`, rather than “some row differs”. `leaf=""` means no row has a populated leaf; `leaf!=""` means at least one does. Missing fields/ancestors therefore match global blank and negative conditions. Within a same-row expression, leaf tests apply to that concrete row; positive same-row matching requires an actual row. Negating the entire expression means no row satisfies it.

Nested same-row expressions correlate child rows with their concrete parent. Nested path counts outside such an expression total matching descendants across all qualifying parents; inside it they count only that parent’s descendants. Missing collections/parents count as zero. Numeric zero remains populated rather than blank.

Date range comparisons exclude blank or missing dates. For scalar properties,
nonblank `!=value` includes missing values; native Fieldtypes can differ in their
handling of blank values for inequality. Checkbox `=0` requires a stored zero;
it does not match a missing JSON property or absent Flex storage, unlike native
Checkbox's unchecked default. Use blank checks to query missing values.

Root and row media support blank checks and `.count` numeric comparisons, not metadata/filename selectors. Whole-field text search (`content*=Welcome`) searches flattened values, excluding schema names and row identity/type metadata. Text matching follows database collation; `~=` means all words and `~|=` any word. Use ProcessWire selector escaping for dynamic values; SQL parameter binding does not escape selector syntax.

Selector metadata is exposed to native `InputfieldSelector` by `getSelectorInfo()`. Definitions must expose queryable properties without a specific Page. Page option discovery follows native selectable-page rules and is capped at 500. Sorting supports scalar properties and root collection/media counts, for example `sort=content.blocks.count`. Arbitrary JSON paths, advanced fuzzy/full-text queries on Flex's own JSON text, sorting multi-selections, and sorting by repeated values or type-specific counts are not supported. Invalid paths/operators raise `PageFinderSyntaxException`.

### Root Page-reference traversal

Query one **native target Field** through a root Flex Page-reference property:

```php
$pages->find('recipe.ingredient.title=Flour');
$pages->find('recipe.ingredients.title~=Flour'); // multiple Page references
$pages->find('recipe.ingredient.price>=2.5');    // native numeric target field
```

Path: `FLEX_FIELD.PAGE_PROPERTY.TARGET_FIELD`. Supported targets: native Text/Textarea/PageTitle (including language variants), Email, URL, Integer, Float, Checkbox, Toggle, Datetime, Options and Page. Native PageFinder handles target operators, options, language behavior, blank values and indexes; third-party subclasses are not automatically supported.

- Positive matching requires a matching referenced Page. `ingredient.title!=Flour` means **no referenced Page matches** `title=Flour`; separate conditions may match different Pages.
- `ingredient.title=""` requires a reference with a blank target title. `ingredient.title!=""` includes empty reference collections; use `ingredient=""`/`ingredient!=""` to test the reference itself.
- Native `parent_id`, `template_id(s)` and `findPagesSelector` restrict targets; default target matching uses `include=all`. Hidden, unpublished and access-restricted targets can contribute matches—this is **not an authorization filter**.
- Unsupported: native Page properties (`id`, `name`, `path`), File/Image and compound/third-party targets, additional Page hops, traversal from rows, target sorting, same-target `@` grouping, selector negation and native multi-dot OR-field traversal. Ordinary `!=` and OR values work.

Target IDs are materialized without the editor's 500-option cap and matched against Flex JSON. Native target indexes help, but Flex root references have no dedicated property index; large target sets and wide owner scans can be expensive. Target edits affect subsequent queries without resaving owners. Discovery exposes supported dotted field suffixes.

## Inputfield settings

Definitions reuse settings from the selected **native Inputfield**. The `textformatters` setting additionally uses the native Fieldtype output pipeline; Fieldtype database storage is never copied. Refer to the installed Inputfield's PHPDoc and the [ProcessWire Inputfield API](https://processwire.com/api/ref/inputfield/) for shared options.

| Setting | Example or values | Meaning |
| --- | --- | --- |
| `label`, `description`, `notes` | `__('Heading')` | Editor labels and help text |
| `required` | `true` | Required during native form processing |
| `columnWidth` | `50` | Input width in Page Edit |
| `value` | `'DE'` | Default when the stored key is absent |
| `options` | `['DE' => __('Germany')]` | Choice keys and labels |
| `showIf`, `requiredIf` | `'enabled=1'` | Conditions on sibling properties in the current scope |
| `useLanguages` | `false`, `true`, `'auto'` | Multilingual mode for supported text inputs |
| `derefAsPage` | `0`, `1`, `2` | Page-reference return type |
| `dateInputFormat`, `dateOutputFormat` | `'d.m.Y'` | Input parsing and formatted date output |
| `inheritField` | `'rich_text_source'` | Copy Inputfield settings and compatible text formatters from an existing same-type Field |
| `textformatters` | `['TextformatterEntities']` or `[]` | Ordered native output formatter modules for text-valued inputs |

`requiredIf` also needs `required => true`. Flex scopes native dependencies inside rows.

### Reusing native input configuration

```php
'body' => [
    'type'         => 'TinyMCE',
    'inheritField' => 'rich_text_source',
    'label'        => __('Body'),
],
```

For native datepicker, TinyMCE and `inheritField` definitions, see [numbers/dates](examples/04-numbers-dates.php), [text inputs](examples/03-text.php) and [advanced settings](examples/07-advanced.php), including their prerequisites.

The source Field must use the same Inputfield type (for this example, a Textarea field configured for TinyMCE). Flex copies its Inputfield settings and, for a source using `FieldtypeText` or a subclass (including Textarea, language variants, Email and URL), its actual Fieldtype `textformatters` list. Local settings override inherited settings; an explicit `textformatters` list replaces the inherited list completely, including `[]`. A source without formatters supplies an empty list. Source values and database storage are never copied. TinyMCE's own `settingsField` is a separate native setting, not an alias of `inheritField`. See [examples/07-advanced.php](examples/07-advanced.php).

### Textformatters

Text, Textarea (including HTML Textarea, TinyMCE and compatible subclasses), Email, URL, Hidden and Icon support native `Textformatter` modules. Numbers, choices, Page references, rows and media do not accept this setting. Each module must be installed and extend ProcessWire's `Textformatter`; invalid lists/modules raise a definition error with field, source and property context.

PHP definitions use `'textformatters' => ['TextformatterMarkdownExtra']`. JSON uses the same ordered list:

```json
{
  "body": {
    "type": "textarea",
    "textformatters": ["TextformatterMarkdownExtra"]
  }
}
```

Formatter order is significant. Flex delegates to native Text/Textarea/Email/URL `formatValue()` with the actual owning Page and a transient Field containing property settings. This Field has no database ID; its name is `<flex_field>_<property>`, including inside rows. It is not the inherited source Field. Formatters that require a real stored Field or fetch the property via `$page->{$field->name}` need their own Flex-aware integration. No source values or Fieldtype persistence hooks are invoked.

Formatters run only on formatted reads. Multilingual values first resolve to the current language using native fallback/`langBlankInherit` and template `noLang` behavior. Every read starts from the unformatted value, including nested rows; `rawArray()`, canonical JSON and change tracking remain unchanged. Saving requires output formatting off as usual.

An absent formatter list and an explicit `[]` both mean **no Textformatter transformation**. Explicit or inherited formatters control formatted text output in their configured order. Flex never adds its own entity-encoding step: `TextformatterEntities` must be selected for HTML text escaping; Markdown-generated HTML remains HTML when using a Markdown formatter.

This concerns text-valued properties only. Selection keys remain raw; `label()`, `optionLabel()` and `getOptions()` entity-encode display labels when formatted. Native URL `addRoot` and Textarea output options (such as lazy image loading) apply even without a formatter list. Flex does not implement native Textarea storage-time markup QA or link abstraction. See [rich text](examples/03-text.php) and [inheritField](examples/07-advanced.php).

### Language modes

| `useLanguages` | LanguageSupport **and** LanguageSupportFields available | Otherwise |
| --- | --- | --- |
| `false` (default) | Plain text | Plain text |
| `true` | Multilingual value | Definition error |
| `'auto'` | Multilingual value | Plain text |

`'auto'` is available on Text/Textarea subclasses, including TinyMCE. An unformatted multilingual value uses `LanguagesPageFieldValue`; formatted reads return the current-language string. JSON retains a language-ID map. Direct scalar property assignment updates the current user language while preserving other translations (a template with `noLang` uses the default). When a multilingual property switches to plain mode, Flex reads its default-language text; **saving the field replaces the language map with that plain value and discards other translations**. Reads alone do not discard stored translations. A previously stored scalar becomes the default-language value when multilingual mode is enabled. String conversion uses the current user language; `hasValue()` checks all languages. Flex remembers the default-language ID during field saves while the Languages API exists; save a Flex field before uninstalling LanguageSupport so this ID is available afterward. The live Languages API takes precedence over the remembered ID. A blank or missing default remains blank regardless of the current user. Without the API and a remembered ID, old language maps cannot be read as plain text. No bulk conversion or module installation runs. See [examples/03-text.php](examples/03-text.php).

### Tested Inputfields

The integration fixture covers construction and native rendering for the following groups, but does not establish support for every setting or every third-party Inputfield:

| Group | Covered inputs |
| --- | --- |
| Text / numbers | Text, Textarea, Email, URL, Integer, Float, Hidden, Icon |
| Choices | Select, SelectMultiple, AsmSelect, Radios, Checkbox, Checkboxes, Toggle, TextTags |
| Layout / date | Fieldset, Markup, Datetime |
| Page references | Page with Select, PageListSelect, PageListSelectMultiple and PageAutocomplete |
| Rich text | TinyMCE |
| Media | Flex File and Image integration |

Native ProcessWire Repeater is supported as a **container for a Flex field**, not as a property type. CKEditor has no Flex-specific browser-save verification. Inputfields relying on their own Fieldtype persistence are not automatically supported.

## Field settings

These settings apply to the **Flex Field**, rather than individual properties:

| Setting | Default | Effect |
| --- | --- | --- |
| `flexDefinition` | Empty | Inline JSON definition |
| `flexDefinitionFile` | Empty | Explicit definition file relative to `site/templates` |
| `flexHideWrap` | `false` | Hide the outer editor heading |
| `flexMaxDepth` | `0` | Additional nesting limit; zero means no extra Flex depth cap |
| `flexMaxRows` | `2000` | Total row budget across descendants; zero removes this extra budget |
| `flexMaxBytes` | `8388608` | Canonical JSON byte budget; zero removes this extra budget |

Database and PHP resource limits still apply when Flex-specific budgets are disabled.

## Maintenance APIs

Get the Fieldtype module through ProcessWire:

```php
$type = $modules->get('FieldtypeFlex');

$type->repairClonedFiles($copy);
```

- `repairClonedFiles(Page $copy): int` retries registered media copies for a cloned Page when its native directory copy was incomplete.
- `saveFile($page, $field, $file, $columns)` selectively saves committed media metadata; see [Adding, saving, and ownership](#adding-saving-and-ownership).

Clone repair returns the number repaired. `$copy` must be the saved cloned Page, not a Flex row. `saveFile()` returns bool, defaults to all supported metadata columns when `$columns=[]`, and requires an unformatted owned file and live Page. New/replaced files must be saved through the containing field; stale metadata raises `WireException`.

### Automatic projections and file cleanup

Field saves, metadata updates, maintenance, cloning and deletion keep canonical JSON, row/search projections and media ownership consistent. No regular projection rebuild or manual collector call is needed.

File removal is conservative and **eventual**. Bounded cleanup runs after relevant writes and at `ProcessWire::finished` (at most 100 candidates per pass). Later PHP executions retry pending work; without further execution cleanup cannot progress. Staging expires after six hours. Referenced and unknown files remain protected. Open outer transactions postpone cleanup without implicit commits.

These guarantees do not make whole-Page permanent deletion or native image-variation deletion rollback-safe. See [architecture](docs/architecture.md#automatic-media-work) for failure, cursor and transaction behavior.

### Rename and potentially orphaned property maintenance

```php
$type = $modules->get('FieldtypeFlex');
$field = $fields->get('content');

$preview = $type->checkRenames($field, 0, 100);
$result = $type->applyRenames($field, $preview);

$inventory = $type->listOrphans($field, 0, 100); // Read-only, independent operation
$paths = ['old_summary', 'blocks.text.old_caption']; // Type-qualified schema paths
$cleanup = $type->cleanupProperties($field, $paths, $inventory); // Read-only preview
$result = $type->cleanupProperties($field, $paths, $cleanup, true); // Explicit write
```

| Operation | Method |
| --- | --- |
| Preview renames | `checkRenames(Field $field, int $afterPageId = 0, int $limit = 100): array` |
| Apply checked batch | `applyRenames(Field $field, array $preview): array` |
| Inventory | `listOrphans(Field $field, int $afterPageId = 0, int $limit = 100): array` |
| Preview/apply cleanup | `cleanupProperties(Field $field, array $paths, array $preview, bool $apply = false): array` |

**Authorize PHP maintenance callers yourself.** Operations scan stored owner IDs in bounded batches (including hidden Pages and Repeater items). Apply locks and revalidates data/schema. Missing definitions, unknown row types and rename conflicts are reported, not silently pruned. Cleanup only removes explicitly selected unknown paths; ownership and selector projections update atomically.

Results contain `fieldId`, `operation`, `paths`, `checked`, `changed`, `unchanged`, `wouldChange`, `pages` (opaque preview tokens), `properties` (counts/locations), `renames`, `conflicts`, `errors`, `retryPageIds`, `resumeAfterPageId`, `afterPageId`, `lastPageId`, `complete`, `success` and `finished`. Type-qualified paths select schema properties; concrete row addresses use row IDs.

Continue successful incomplete batches with `lastPageId`; after errors repair and resume from `resumeAfterPageId`. A scan is not a global snapshot. Before removing `renamedFrom`, complete every batch and repeat a full preview from zero to confirm no pending renames. Apply rejects stale previews and respects existing transactions.

### Native CLI

On cores with native CLI dispatch, run from the ProcessWire root:

```sh
php index.php flex --help
php index.php flex rename content --dry-run --limit=100 --after=0
php index.php flex rename content --apply --limit=100 --after=0
php index.php flex orphans content
php index.php flex orphans content --details --limit=50 --after=0
php index.php flex cleanup content --path=old_summary --path=blocks.text.old_caption --dry-run
php index.php flex cleanup content --path=old_summary --path=blocks.text.old_caption --apply
```

Each invocation handles one batch (default 100 owners, `--limit=1..1000`). `--after` is the page-ID cursor; `--details` adds affected owner locations to orphan reports. Commands without `--apply` are read-only; apply takes and validates a fresh preview. Exit codes: `0` success, `1` conflicts/errors, `2` invalid request.

CLI is optional and does not raise minimum requirements. Pinned ProcessWire 3.0.274 supports it, while pinned 3.0.259 lacks the dispatcher. On older cores use [PHP maintenance](#maintenance-apis) or Admin; a silent zero-exit CLI invocation is not proof of success. Refresh module metadata after upgrades.

### Field configuration

Superusers have **Field → Details → Data maintenance** for batched previews, bulk renames, orphan inventory and explicitly selected cleanup. Actions require CSRF validation; cleanup has separate selection, preview and apply steps. Ordinary field-configuration saves do not migrate data. Repair conflicts and rerun affected batches.

## Notes and limitations

- Flex uses MySQL 8.0+ or MariaDB 10.11+ with InnoDB. SQLite and PostgreSQL are not supported.
- Native ProcessWire Page-version save/restore does not currently include Flex values.
- The `rows` structure is independent of native FieldtypeRepeater/RepeaterMatrix. Rows are not pages, and mutations are saved with their owning Flex field.
- File and Image values use Flex-managed storage exposed through compatible ProcessWire classes, not native FieldtypeFile/FieldtypeImage persistence.
- PHP definitions are trusted code. Use direct API assignments only with appropriate validation and authorization for your application.
- API methods and examples here document the supported public contract, not all inherited ProcessWire methods or implementation internals.

The supported entry points are this module reference and `FlexField::defs()`. Services such as `storage()`, `media()`, owner/address helpers and editor hooks are implementation APIs, even where PHP visibility is public. Native WireApiDocs can retrieve this reference as `FieldtypeFlex`; runtime methods are also discoverable through class PHPDoc. Shared classes: [[WireData]], [[WireArray]], [[Inputfield]], [[Pagefile]], [[Pageimage]].

Related: [README.md](README.md) · [Examples](examples/) · [Architecture](docs/architecture.md) · [ProcessWire API reference](https://processwire.com/api/ref/).

**Source files:** `FieldtypeFlex.module.php`, `src/FlexField.php`, `src/FlexValue.php`, `src/FlexRows.php`, `src/FlexFieldDefs.php`, `src/FlexMedia.php`, `src/FlexQuery.php`, `src/FlexStorage.php`, `InputfieldFlexRows.module.php`.
