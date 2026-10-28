# FieldtypeFlex

FieldtypeFlex stores structured properties in one ProcessWire field. Retrieve the module with `$modules->get('FieldtypeFlex')`; read field values through `$page->content` as shown below. This reference covers definitions, runtime values, selectors and maintenance.

Start with [README.md](README.md) for installation and a complete example; see the [configuration catalog](examples/README.md) for reusable definitions. The editor uses InputfieldFlex and InputfieldFlexRows; shared input APIs are documented in [[Inputfield]] and [[InputfieldForm]].

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
| Toggle | `0`, `1`, `2` or `''`; custom integer keys supported | Per `formatType`: key, boolean or label | Number or `''` |
| Multiple choices | `string[]` | Same raw keys | Array of keys |
| TextTags | Space-separated string | Same raw keys | String |
| Datetime | Unix timestamp `int`, or `''` | Formatted string; timestamp if no output format (or `U`); `''` if empty | `Y-m-d` or `Y-m-d H:i:s` |
| Page reference (`derefAsPage=0`) | `PageArray` | `PageArray`, native filtering rules below | Page ID array |
| Page reference (`derefAsPage=1`) | `Page` or `false` | Same, with unpublished pages omitted | Page ID or `false` |
| Page reference (`derefAsPage=2`) | `Page` or `NullPage` | Same, with unpublished pages omitted | Page ID or `0` |
| Multilingual text | `LanguagesPageFieldValue` | Current-language `string`, formatted by configured Textformatters | Map keyed by language ID |
| `rows` | `FlexRows` containing `FlexRow` | Visible rows, formatted leaf values | `rows:1` envelope |
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

`getArray()` and iteration expose known runtime properties in the current formatting state, retaining objects such as Rows, Page references and media collections. A row exposes its own properties; `FlexRows::getArray()` is keyed by stable row IDs.

`rawArray()`, `(string) $value` and `json_encode($value)` export canonical unformatted data, including unknown keys and hidden rows. A `FlexRow` exports its content properties; its ID, type and visibility are included in the containing collection's envelope. These exports are **not HTML-safe**. Media metadata contains no file bytes: it is not a portable media archive, though assigning registered media to another owner in the same installation creates independent copies on save.

Both array methods describe current memory, including unsaved edits; neither saves, refreshes nor returns a deep copy. Neither accepts a property argument. JSON exports describe logical data rather than exact database bytes: empty PHP maps encode as `[]`; storage keeps the root and Row/media `values` maps as `{}`, while ordinary empty lists remain `[]`. Do not edit storage envelopes or selector projections directly.

## Reading and writing values

### Properties and saving

Access known properties directly or with `get()`; turn output formatting off before assigning values:

```php
$page->of(false);
$page->content->heading = 'Introduction';
$page->content->set('summary', 'An example');
$page->save('content');
```

Unknown ordinary property reads return `null`; unknown writes throw `WireException`. Inherited [[WireData]] read lookups remain available; use a row's `hasProperty()` or inspect definitions for schema membership. API assignments normalize values; native editor validation, purification and dependencies run during form processing. Validate application input before assigning it.

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

`hasValue()` checks canonical content regardless of formatting or row visibility. Empty strings, `null`, `false`, empty collections, `NullPage` and unchecked checkboxes are empty. Numeric `0` and string `'0'` otherwise count as content, as does whitespace. Any populated language or row leaf counts, including retained unknown row properties; row identity alone does not. Unknown property names themselves return false. Media entries count without checking files on disk. Native required validation still applies to individual editor inputs.

`isChanged()` includes nested Rows, PageArrays, language values and media; a direct property name limits the check. Read helpers do not mark changes.

Page-formatted values are cloned views. Reacquire `$page->content` after `$page->of(false)` before editing: retained formatted values stay formatted. `getUnformatted()` bypasses one property's output conversion, but does not make the value or its nested objects writable. Setters require both the value and owning Page to be unformatted.

On a formatted value, `getUnformatted('blocks')` returns an independent Rows collection containing
all backing rows, including hidden parents and children, with raw native leaf
values. Row IDs and types are retained. Formatting state still protects writes;
reading raw values does not disable that protection. Native language and PageArray
containers are detached from the source. In contrast, `find()` on an ordinary
formatted collection continues to count only visible children and shares the
selected source row objects. On an unformatted original, `getUnformatted()` keeps
returning the live owned collection.

### Property and option labels

```php
$value = $page->content;
$value->label('appearance');                    // Property label
$value->optionLabel('appearance');              // Current single selection label
$value->optionLabel('appearance', 'featured');  // Explicit option label
$value->getOptions('appearance');               // Key => translated label map
```

`optionLabel()` without a second argument resolves the current single selection. Explicit `null` selects the empty-string key; blank and zero keys are valid. Unknown choices and array selections return `''`; resolve multiple selections one key at a time.

These helpers also work on `FlexRow`. `label()` returns `''` for unknown properties; `getOptions()` returns `[]` for unknown/non-choice properties and omits optgroups/separators. Labels follow the current language and are entity-encoded when formatted; option keys remain raw.

Type-qualified schema paths such as `blocks.text.appearance` support label/option lookup. `optionLabel()` needs an explicit key on such a path: only a concrete row has a current selection.

### Dates, numbers, and text

Datetime returns Unix timestamps unformatted and uses current-language/default `dateOutputFormat` when formatted; an empty format or `U` returns a timestamp. JSON uses `Y-m-d` or `Y-m-d H:i:s` without an offset, in PHP/site timezone. Assign canonical ISO strings, timestamps, numeric timestamp strings or `DateTime` objects. ISO input takes precedence over localized editor formats. Blank/null/false/zero becomes `''`; time is preserved even without a time editor. Use ISO dates in SQL selectors.

Integer/Float normalize numeric strings and reject non-numeric input with `WireException`; blank/null becomes `''`. Float `precision` rounds on assignment and hydration (`2` by default; null, blank or negative disables rounding). Normal PHP/JSON floating-point precision applies.

Email/URL assignments use native sanitizers and their IDN/relative-URL settings. Toggle uses `0`/`1`/`2` for no/yes/other (`useOther => true` shows the third option), or custom `options` with integer keys from -128 to 127; `''` means no selection. Text and rich HTML use the configured [Textformatters](#textformatters); absent formatters leave text unescaped.

Toggle `formatType` accepts `0` (default: integer key), `1` (only `0`/`1` become `false`/`true`), `2` (label), or `3` (HTML-encoded label without icons), also as numeric strings. Invalid modes raise a definition error. `inheritField` supplies the mode unless locally overridden. No selection remains `''`; other keys stay integers in boolean mode. Unformatted reads, `rawArray()`, JSON and selectors retain raw keys. Read-only editors always use native safe label rendering.

### Page references

`derefAsPage=0` returns a `PageArray`; `1` returns `Page` or `false`; `2` returns `Page` or `NullPage`—**in either output-formatting state**. Check `NullPage->id`, not object truthiness.

Flex uses native `FieldtypePage` for hydration, output filtering and save-time validation. `allowUnpub=false` excludes unpublished references on load; `true` retains them unformatted. Formatted output filters unpublished references according to native single/multiple rules; hidden references remain available. **Access permissions are not read filters**: call `$target->viewable()` when required.

Single references accept Page/PageArray, ID, path/name or native selector input. Multi-reference assignment of a Page/ID/ID array/string **appends**; a `PageArray` **replaces**. Null/blank multi assignment does not clear the selection; use `removeAll()` or an empty `PageArray`. Editor submissions replace selections. Saving enforces native selectable-page rules (`parent_id`, `template_id`, `findPagesSelector`); referenced Pages must be saved separately. See [selector traversal](#root-page-reference-traversal).

To remove one selected multi-reference by Page ID, assign a negative ID **string**,
for example `$value->references = '-' . $target->id`, then save the owning field.
An ID absent from the selection is ignored; other references retain their order.

### Limits of native compatibility

Flex reuses native Inputfields and selected Fieldtype behavior, with these boundaries:

| Area | Difference |
| --- | --- |
| Text / HTML | Native output options apply, but storage-time Textarea markup QA and link abstraction do not. Configure Textformatters explicitly for HTML text escaping. |
| Integer / Float / Datetime | Integer/Float preserve explicit zero. Invalid numbers/dates raise `WireException` rather than using permissive native conversion or blank fallback. |
| Checkbox / Toggle | Custom checkbox scalars are supported. Toggle supports native `formatType` output modes; custom integer option keys remain supported. |
| Choices | Values are raw keys, not native FieldtypeOptions objects or option IDs. |
| File / Image | Compatible objects use Flex-owned storage; see [media restrictions](#adding-saving-and-ownership). |
| Rows / layout | Rows are not Repeater Pages. Ordinary fieldsets and Markup have no stored value. |

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

PHP files return a property array or `InputfieldWrapper`; JSON uses an object keyed by property name (empty `[]` is also accepted). `type` defaults to `text` and selects a native ProcessWire Inputfield (`text` means `InputfieldText`) or Flex's own structure type `rows`. The default label is derived from the property name. Property keys are stored names: they must begin with a letter or underscore and contain only ASCII letters, digits and underscores. `data`, `pages_id` and `count` are reserved.

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

Nested schema paths include each collection and type, such as `sections.section.blocks.text.heading`. Internal schema metadata arrays are not the stable inspection API; inspect fresh native Inputfields instead.

A field without a definition may initially be empty. If an automatic definition disappears after the field contains data, Flex refuses further saves rather than replacing stored values with an empty schema. Invalid definitions report the field and source and block Page Edit saves while preserving submitted values for correction.

### Renaming and preserving properties

```php
'heading' => [
    'type'        => 'text',
    'renamedFrom' => 'title',
],
```

`renamedFrom` accepts one old name or an array of old names. It resolves old keys on read and persists new keys on individual saves, including nested rows. Equal values merge; conflicting old/new values throw `WireException`. Field configuration saves never migrate data; migrate stored owners before relying on selectors for the new keys.

Unknown properties, nested data and owned media **always survive ordinary saves**. Use [maintenance](#maintenance-apis) to apply bulk renames or explicitly remove unknown keys. Prefer complete definitions for all Page/role contexts and native collapsed/Locked input states; dynamically omitted properties complicate orphan discovery.

<a id="repeatable-rows-and-native-media"></a>

## Rows

`type => 'rows'` creates a `FlexRows` collection of typed `FlexRow` objects. It is **not** a native ProcessWire Repeater fieldtype. A non-empty `types` map is required, even for a collection with only one type. Each type has its own label and `children` schema.

```php
'blocks' => [
    'type'        => 'rows',
    'label'       => __('Content blocks'),
    'minItems'    => 0,
    'maxItems'    => 30,
    'addRowLabel' => __('Add block'),
    'rowCollapse' => FieldtypeFlex::ROW_COLLAPSE_EXISTING,
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
                'photo'   => ['type' => 'image', 'maxFiles' => 3],
                'caption' => ['type' => 'text'],
            ],
        ],
    ],
],
```

Type keys start with a lowercase ASCII letter and contain lowercase letters, digits or underscores (up to 64 characters); `count` is reserved. Row type and stable row ID do not change after creation. `rowTitle` accepts a string; Closures are not supported. A bare leaf property name remains shorthand for `{property}`.

| Title syntax | Meaning |
| --- | --- |
| `{heading}` | Plain display value of a property in the current row |
| `{heading\|title}` | First nonempty display value; numeric `0` counts as a value |
| `{@type}` | Current row type's configured label (type key when no label is configured) |
| `{@path}` | Ancestor type labels, separated by `›`; excludes the current row and all ancestor field values/titles |
| `#n` | One-based position in the current collection, including the `#` |
| `_{heading}_` | Badge around a token; an empty value removes the entire badge |
| `[…]` | De-emphasized text or multiple tokens; the square brackets are not displayed |

```php
'rowTitle' => '[{@path} › {@type}] #n: _{heading|title}_',
```

Square-bracket groups do not nest; unmatched brackets and brackets in field values stay literal. `[_{heading}_]` combines a badge with de-emphasis. Empty groups disappear. Custom titles add no automatic type badge; use `_{@type}_` for one.

Missing and whitespace-only values are empty. Separators at empty edges disappear; separators around omitted middle tokens collapse to one. An empty custom title falls back to `#n`. An absent or blank `rowTitle` uses the default type badge, label and row number.

Values use native display labels in the editor's language, with markup stripped and text escaped. Input edits and reordering update titles; `{@path}` depends only on ancestor types.

Use these `FieldtypeFlex` constants for `rowCollapse`:

| Constant | Value | Initial row state |
| --- | ---: | --- |
| `FieldtypeFlex::ROW_COLLAPSE_EXISTING` | `0` | Existing rows closed; new rows open |
| `FieldtypeFlex::ROW_COLLAPSE_ALL` | `1` | All rows closed |
| `FieldtypeFlex::ROW_EXPAND_ALL` | `3` | All rows open |

The matching integers and numeric strings are also accepted; invalid values fall back to `FieldtypeFlex::ROW_COLLAPSE_EXISTING`. Rows with input errors remain open. Invalid non-string labels and row titles use their normal defaults. `minItems` and `maxItems` must be nonnegative integers and apply across all types; `minItems` cannot exceed a nonzero maximum. `maxItems=0` adds no per-collection maximum within field budgets.

### Optional lazy row bodies

Set `rowLazyLoad => true` on a Rows definition (or `$rows->rowLazyLoad = true` on a native InputfieldFlexRows). Only boolean `true` enables it; the default is `false`. Eligible closed rows load their bodies on opening. Expanded, new and validation-error rows render directly; `rowCollapse` still controls the initial state.

Eligible types contain only native Text, Textarea, Email, URL, Integer, Float, Checkbox, Select, SelectMultiple, Checkboxes or Radios, optionally in ordinary fieldsets. Nested collections, media, rich text, date/Page inputs, multilingual or custom inputs, native AJAX collapse modes and nonzero custom unchecked checkbox values render directly. Nested collections can opt in independently for their eligible child rows.

Unopened values still undergo native validation and dependencies on Save. Unknown properties survive. Loading does not save content; a failed load offers Retry and blocks saving until resolved. Changed schemas or stale content require reloading. Without JavaScript, use the full-editor link (`flex_eager=1`).

Lazy loading reduces initial input construction and DOM size, not value hydration or save-time validation. See [lazy-loading verification](docs/development.md#lazy-row-verification).

### `FlexRow`: inspect a row

```php
$row->rowId();                     // Stable 32-character hex ID
$row->rowType();                   // 'text'
$row->rowTypeLabel();              // Resolved type label
$row->hasProperty('heading');      // Property is defined, even when empty
$row->hasValue('heading');         // Property contains logical content
```

`rowTypeLabel(): string` uses the evaluated type label or a title-case fallback. It does **not** automatically HTML-encode the label for output; escape it when rendering. `hasProperty(string $name): bool` checks the current row type's schema, not its stored content. Normal fieldset leaves are flat; nested row properties must be inspected on their own `FlexRow` objects.

### Row visibility

Every row has `isHidden(): bool` and `setHidden(bool $hidden): self`:

```php
$page->of(false);
$row = $page->content->blocks->first();
$row->setHidden(true);
$page->save('content');
```

Rows are visible by default. `isHidden()` reports the row's own state, independent of ancestors. `setHidden()` requires an unformatted value and Page and saves with the owning field. Duplicating copies visibility.

With output formatting enabled, iteration, `getArray()`, `getById()`, `first()`,
`count()` and type helpers expose only visible rows, recursively. Hiding a parent
hides its entire subtree without changing child states. Reacquire the unformatted
field to access **all rows**, including hidden ones:

```php
$allRows = $page->getUnformatted('content')->blocks;
```

Collection `rawArray()` and JSON serialization retain all backing rows even on a formatted view. `hasValue()`, maintenance, media ownership, collection limits and resource budgets include hidden content. Visibility is an editorial state, not an access-control boundary.

The editor includes every row. The eye button changes visibility on ordinary Save; hidden inputs remain editable and undergo native validation. Visibility survives validation errors.

SQL row/type predicates, same-row groups, counts and count sorting include only rows visible through **every ancestor**. Hidden-only collections are query-empty for blank/negative tests. Whole-field text search excludes hidden subtrees but retains root properties. Native `include=all` does not include hidden Flex rows; no all-row SQL option exists. Select owner Pages by other criteria and inspect unformatted PHP values when needed.

### Removing rows in Page Edit

Remove marks a row in the current form and changes its trash button to **Undo removal**. Undo restores its position and unsaved inputs. Leaving without saving discards the mark; only a successful owning-field Save deletes the row, subtree and associated media references. Validation errors preserve marks and undo.

Double-click Remove to mark siblings in the current collection after confirmation, respecting `minItems`. Double-click Undo removal to undo sibling marks without confirmation. Other collections and nested child marks are unchanged.

Marked rows contribute no blocking leaf-validation errors and do not count toward the saved collection's limits, so they free space for replacements. Loaded inputs are still processed for undo; unopened lazy bodies are skipped. Undo may require correcting `maxItems` before Save. Hidden unmarked rows still count. PHP `removeRow()` changes the in-memory collection directly and also requires a field save to persist.

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
| `addRow(string $type, array $values = []): FlexRow` | Append a typed row with a generated ID |
| `getById(string $id): ?FlexRow` | Find by stable ID; null when absent |
| `find($selector): FlexRows` | New selection of existing rows using in-memory selectors; preserves order and object identity |
| `rowsOfType($type): array` | Numeric `FlexRow[]` in current order |
| `countOfType($type): int` | Number of direct matching rows |
| `duplicateRow(string $id): FlexRow` | Appends an independent typed copy with new descendant IDs and media copies |
| `moveRow(string $id, ?string $before = null): self` | Move before another sibling ID, or to the end; same ID is a no-op |
| `removeRow(string $id): self` | Remove a row by ID; unknown ID is a no-op |
| `rawArray(): array` | Canonical rows envelope, including hidden rows |

Native [[WireArray]] operations such as `add()`, `set()`, `prepend()` and removal methods retain Flex's formatting, ownership and identity checks; create new rows with `addRow()`. `of(?bool $formatted = null): bool` reads/changes collection formatting and returns the previous state; the owning Page must also be unformatted for writes.

```php
$images = $page->content->blocks->rowsOfType('image');
$count = $page->content->blocks->countOfType('text');
```

Type helpers inspect direct rows only, including unsaved edits. They return `[]`/`0` for unknown type strings and throw `TypeError` for non-string arguments. `rowsOfType()` returns existing objects in a numeric array; edits affect the owning rows under normal formatting guards.

`addRow()` rejects unknown types with `FlexDefinitionException`. `duplicateRow()` and `moveRow()` reject unknown IDs with `WireException`; `removeRow()` ignores them. Formatting guards and collection-limit violations raise `WireException`. Maxima apply when adding/hydrating; minima apply on field save. A single type or `maxItems=1` still returns a collection without an implicit row. `first()` returns false when empty.

#### Searching a loaded collection

```php
$blocks = $page->content->blocks;
$images = $blocks->find('_type=image');
$galleries = $blocks->find('_type=image, photo.count>=3');
$intro = $blocks->find('_type=text, heading=Introduction, body*=ProcessWire');

// Search a nested collection directly, within this concrete parent row.
$notes = $block->notes->find('_type=note, text*=Sources');
```

`find()` accepts a selector string, native selector array or `Selectors`. It searches loaded direct rows, including unsaved values, and returns a new `FlexRows` selection with the same keys, order, objects and formatting state. Chaining searches the previous selection; an empty selector selects the current view. The source membership and change tracking remain unchanged. Row objects are shared: edit selected unformatted rows normally, but change collection membership on the original owned collection.

`_type` selects Row metadata; `type` selects an ordinary content property. Within selectors, `_type` takes precedence over any same-named content property; read that content directly on the Row. `_type=unknown` matches no rows; `_type!=unknown` retains all rows in the current view.

Every condition applies to the same direct row. Supported paths are `_type`, direct properties and media/child collection `.count`. Search a child's collection directly for child properties. OR values and prefix negation work. Arbitrary traversal, type-qualified paths, `@`, OR field names, groups, alternate operators and sorting/pagination directives are unsupported. Media/Rows without `.count` allow only blank checks. Type conditions use `=`/`!=`; counts use numeric operators; scalar and membership operators follow the [operator lists](#operators-and-matching-semantics), with numeric IDs for Page references. An explicit `_type` condition narrows schema validation. Invalid paths/operators raise `WireException`, even on an empty collection.

Comparisons use **unformatted leaf values** and native in-memory `Selector::matches()`. Textformatters and single-image output do not affect matches/counts. Dates require numeric Unix timestamps. Native in-memory zero/blank equality differs from SQL: `number=0` can match a blank numeric property.

The source formatting state controls row visibility and nested counts. Formatted results omit hidden rows; subsequently unformatting a result does not restore excluded members. Returned rows retain their original formatting state. Use native selector escaping or selector arrays for dynamic values.

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

Native [[InputfieldWrapper]] definitions use InputfieldFlexRows instead of the array/JSON type keyword. Each type's `children` accepts an array or native wrapper, including nested InputfieldFlexRows:

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

See the [basic InputfieldWrapper example](examples/02-basic-objects.php) to build a definition with native Inputfield objects. Flex fields may also be assigned to native ProcessWire Repeater item templates; that is distinct from defining a `rows` property.

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

Native collection iteration, `first()`, filename lookup with `get()`, `add()`, `delete()`/`deleteAll()`, `rename()` and `clone($file)` are available. File objects expose native URLs, filenames, size, descriptions, tags and filedata; images provide `size()`, `width()`, `height()`, `crop()` and `focus()`. See [[Pagefiles]], [Pagefile](https://processwire.com/api/ref/pagefile/) and [[Pageimage]] for these native APIs. Copies retain metadata and receive independent asset ownership.

`$file->rename('test.png')` and `$files->rename($file, 'test.png')` return the effective basename after native sanitization and collision handling. Rename preserves asset identity, order and metadata, and copies original bytes and variations to staged names. Save the owning field to commit; a failed save retains the previously committed names/files.

For an already committed file, `$file->save()` saves its metadata independently of other pending Flex edits; concurrent metadata changes are checked for conflicts. Use `$type->saveFile($page, $field, $file, ['description'])` on the module instance for selected metadata columns (`description`, `tags`, `filedata`, `values`, `modified`, `modified_users_id`).

File/Image settings include `extensions`, `maxFiles` (default `0`, unlimited), `maxFilesize` (bytes; `0` means no extra limit), native image dimensions, descriptions and free tags (`useTags`). Local imports enforce readable paths, extensions, size and count; native editor processing adds input-specific validation.

File properties require extensions. Image defaults are JPG/JPEG, GIF and PNG; configure WebP explicitly for original uploads. WebP derivatives depend on the installed image engine. Unsupported settings: overwrite, archive extraction (`unzip`), property `useLanguages`, predefined tags, custom metadata templates and string media output. Native language-aware descriptions use `noLang`. TinyMCE drag/drop uploads require a regular ProcessWire Image field as destination.

File/Image `textformatters` format descriptions after native language selection/fallback. Configure them explicitly or through `inheritField`; an absent list or `[]` leaves descriptions unescaped. For example, `'textformatters' => ['TextformatterEntities']` makes a formatted description suitable for an HTML attribute.

Formatting applies to `description` and `description($language)`, including variations. Repeated reads start from raw metadata. `description(true)` and `description(true, true)` retain native raw JSON/all-language exports. Tags, filedata, canonical metadata and change tracking are unaffected.

### Per-file custom fields

Set `customFields` on a File/Image property to define metadata without creating database Fields or metadata Templates. PHP arrays, JSON and native wrappers use the same definition rules; for a native Image/File Inputfield, assign an array or InputfieldWrapper to its `customFields` setting.

```php
// Per-file metadata definition.
'gallery' => [
    'type' => 'image',
    'customFields' => [
        'description' => ['type' => 'textarea', 'label' => 'Image description'],
        'tags' => ['type' => 'checkboxes', 'options' => ['web' => 'Website', 'print' => 'Print']],
        'copyright' => ['type' => 'text', 'textformatters' => ['TextformatterEntities']],
        'year' => ['type' => 'integer'],
        'body' => ['type' => 'TinyMCE'],
        'author' => ['type' => 'page', 'inputfield' => 'InputfieldSelect', 'derefAsPage' => 2, 'parent_id' => 1],
        'token' => ['type' => 'hidden'],
        'help' => ['type' => 'markup', 'value' => '<p>Enter the image credits.</p>'],
    ],
],
```

```php
// Existing image in the gallery.
$page->of(false);
$image = $page->content->gallery->first();
$image->copyright = 'Example Photographer';
$image->year = 2026;
$page->save('content');
$page->of(true);
echo $page->content->gallery->first()->copyright;
```

Supported inputs are Text, Textarea (plain or HTML), TinyMCE, Hidden, Page, Email, URL, Integer, Float, Select, Radios, SelectMultiple, Checkboxes, AsmSelect, Checkbox, Toggle, Datetime and TextTags, grouped with ordinary fieldsets if needed. Markup displays editor content without storing a value. Leaves share one metadata scope per file. Their values follow the [Flex value types](#value-types): raw keys/lists, numeric zero, timestamps and multilingual text. Labels, defaults, native settings, compatible `inheritField`, `required`, `showIf` and `requiredIf` apply. Rows, nested media and unsupported third-party inputs are rejected in `customFields`.

TinyMCE uses the existing native rich-text and language pipeline, including inline mode. Pending editor content survives image refreshes and the existing single-file replacement. Its upload destination remains a regular ProcessWire Image field. Page metadata uses native `InputfieldSelect` (default) or `InputfieldAsmSelect`; other Page selection widgets are rejected. The [Page-reference value rules](#value-types) apply: `derefAsPage` selects Page/PageArray, selections store IDs, and mutations on an unformatted original's PageArray are saved with the file or owning field. Reference containers are independent in copies and formatted views. Page metadata has no SQL selectors or Page-reference traversal.

`$image->getFieldValue('copyright', false)` explicitly returns an unformatted value; `true` formats it and `null` follows the file view. `$image->setFieldValue('copyright', 'Name')` returns bool. Unknown bridge names return `null`/`false`; retained unknown data does not become a declared API property. Native bookkeeping remains native. Formatted views are read-only. Native property/method names, language-description aliases and internal names are reserved, with `description` and `tags` as explicit exceptions.

A custom `description` input edits the existing native description. Use Text, Textarea or TinyMCE. Its language mode inherits media `noLang`; an explicit contradictory mode is rejected. Its formatter list overrides media description formatters, including `[]`; otherwise the media list applies once. Other custom text fields have independent language modes.

A custom `tags` input edits the existing native tags string. Text, TextTags and choice inputs are supported; choice keys must be unambiguous native tags (letters, digits, `_` or `-`, unique ignoring case). The public value remains a string and native `tags()`, `hasTag()`, `addTag()` and `removeTag()` remain available. Each replaced standard input is suppressed independently. Removing either override retains its native value.

Bare dependency names refer to the same file's metadata. `parent.` refers to the Flex root or Row containing the media property; `root.` refers to the owning Flex root. `page.` and `forpage.` retain their [native owner/editor contexts](#dependency-contexts). Uploads may remain staged while required metadata is incomplete; the containing editor save validates it. Removing a file does not require filling its metadata.

`$page->save('content')` and `$image->save()` persist ordinary custom values. Selective `saveFile(..., ['values'])` saves the ordinary metadata map together; per-property partial saves are unsupported. A selective native save such as `['description']` leaves pending ordinary metadata intact. Metadata-only edits retain image variations; variations read the original's metadata, including subsequent edits, and reject metadata writes.

Removing custom fields or the whole `customFields` setting preserves stored values through normal saves and copies. Reintroducing a definition recovers them. `renamedFrom` uses the existing strict conflict rules; renames to/from native description/tags are rejected. Raw maintenance addresses metadata with schema paths such as `gallery.copyright`, or `blocks.image.gallery.copyright` inside Rows; concrete reported addresses also include the asset ID. Cleanup only removes explicitly selected unknown values.

Custom metadata is not available to SQL selectors, media sorting or loaded collection filters. Whole-field search retains basename, native description and tags; it does not include arbitrary custom values.

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

Use **type-qualified paths** for row values: `FIELD.COLLECTION.TYPE.PROPERTY`. Deeper collections include each collection name and type. SQL `COLLECTION._type` accepts `=`/`!=` with a declared type or blank; unknown types raise `PageFinderSyntaxException`.

### Operators and matching semantics

- Numbers, dates and single Page references (numeric IDs): `=`, `!=`, `>`, `>=`, `<`, `<=`.
- Other scalar properties (text, single-choice keys, Checkbox, Toggle, TextTags): `=`, `!=`, `*=`, `%=`, `^=`, `$=`, `%^=`, `%$=`, `~=`, `~|=`.
- Generic arrays and multiple Page references: `=` and `!=` membership checks.

A row leaf predicate means **any matching row**. Separate row predicates may match different rows. To require multiple conditions on one concrete row, use ProcessWire's `@` prefix:

```php
$pages->find('@content.blocks.text.heading=Introduction, @content.blocks.text.body*=ProcessWire');
```

`$pages->find()` returns **owner Pages** using the saved database projection.
`FlexRows::find()` above returns Rows from one loaded collection. Their path
syntax, result types, visibility context and matching engines are different.

Flex groups `@` clauses by field and group name, requiring one concrete row at every shared collection/ancestor scope. Named groups such as `a@content.blocks.text.heading=Introduction, b@content.blocks.text.body*=ProcessWire` match independently; different groups can still select the same Row. Unprefixed conditions remain independent.

```php
// One parent and one child must satisfy all their respective conditions.
$pages->find('@content.sections.section.heading=Introduction, '
    . '@content.sections.section.blocks.text.heading=Overview, '
    . '@content.sections.section.blocks.text.body*=ProcessWire');

// Counts here belong to that concrete parent; zero children is valid.
$pages->find('@content.sections.section.heading=Introduction, '
    . '@content.sections.section.blocks.count=0');

// Media count belongs to the same image Row as its caption.
$pages->find('@content.blocks.image.caption=Gallery, '
    . '@content.blocks.image.photo.count>=3');
```

Within one `@` group, use explicit type-qualified property paths, media counts,
or child collection blank/count predicates. Each collection has one required Row
type per group. Conflicting types, collection `_type` predicates, counts of the grouped Row
itself, OR field names, sub-selectors and alternate operators fail explicitly
with `PageFinderSyntaxException`. Ordinary collection/type count selectors remain
available without `@`. Prefix negation, negative operators and OR values are
evaluated within the concrete Row. To require that **no Row** satisfies an entire
condition group, use the Core's native ID exclusion:

```php
$excluded = $pages->findIDs('@content.blocks.text.heading=Introduction, '
    . '@content.blocks.text.body*=ProcessWire');
$excluded = implode('|', $excluded) ?: '0';
$matches = $pages->find("id!=$excluded");
```

This takes two queries and materializes owner IDs; `0` keeps empty exclusions valid. On the tested ProcessWire 3.0.274 core, `id!=[sub-selector]` fails for empty results. Native Repeater `@` matching also has correlation gaps; Flex enforces same-row correlation at every shared scope.

Collection/type scopes accept only blank checks. The former Flex-specific quoted condition syntax is unsupported; replace it with type-qualified `@` conditions, or ID exclusion for a negated group.

Global `leaf!=value` means no row at that path matches `value`, rather than “some row differs”. `leaf=""` means no row has a populated leaf; `leaf!=""` means at least one does. Missing fields/ancestors therefore match global blank and negative conditions. Within an `@` group, leaf tests apply to that concrete row; positive same-row matching requires an actual row. Native ID exclusion negates the whole group.

Nested `@` conditions correlate child rows with their concrete parent. Nested path counts outside a group total matching descendants across all qualifying parents; inside a group they count only that parent’s descendants. Missing collections/parents count as zero. Numeric zero remains populated rather than blank.

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

Definitions reuse settings from the selected **native Inputfield**. The `textformatters` setting additionally uses the native Fieldtype output pipeline; Fieldtype database storage is never copied. Refer to the installed Inputfield's PHPDoc and [[Inputfield]] for shared options.

| Setting | Example or values | Meaning |
| --- | --- | --- |
| `label`, `description`, `notes` | `__('Heading')` | Editor labels and help text |
| `required` | `true` | Required during native form processing |
| `columnWidth` | `50` | Input width in Page Edit |
| `value` | `'DE'` | Default when the stored key is absent |
| `options` | `['DE' => __('Germany')]` | Keys/labels for choice inputs; Toggle requires integer keys (-128..127). Page references use native selection settings instead. |
| `showIf`, `requiredIf` | `'enabled=1'`, `'parent.enabled=1'` | Native conditions on current, ancestor or Page editor inputs |
| `useLanguages` | `false`, `true`, `'auto'` | Multilingual mode for supported text inputs |
| `derefAsPage` | `0`, `1`, `2` | Page-reference return type |
| `dateInputFormat`, `dateOutputFormat` | `'d.m.Y'` | Input parsing and formatted date output |
| `inheritField` | `'rich_text_source'` | Copy Inputfield settings and compatible text formatters from an existing same-type Field |
| `textformatters` | `['TextformatterEntities']` or `[]` | Ordered native output formatter modules for text or File/Image descriptions |

`requiredIf` also needs `required => true`. Flex scopes native dependencies inside rows.

### Dependency contexts

`showIf` and `requiredIf` use ProcessWire's native selector parsing, browser
dependencies and `InputfieldForm` processing. Flex resolves these field references:

| Reference | Input used by the condition |
| --- | --- |
| `enabled=1` | Property in the current Flex scope |
| `parent.enabled=1` | Property in the directly containing Flex scope |
| `root.enabled=1` | Property in the root value of this Flex field |
| `page.feature_enabled=1` | Native field on the Page owning the Flex field |
| `forpage.feature_enabled=1` | Native field on the main Page being edited |

For a nested row, `parent` is its containing row; for a direct root row, the root Flex value. `parent` at the root is invalid. Ordinary fieldsets add no scope. `root` always identifies this Flex field.

Inside native Repeaters, `page` is the item Page and `forpage` the main editor Page; elsewhere they identify the same Page. These are editor inputs, not PHP variables or PageFinder paths.

```php
// Inside a nested Row; both conditions must match.
'details' => [
    'type' => 'textarea',
    'showIf' => 'parent.show_details=1, forpage.feature_enabled=1',
    'required' => true,
    'requiredIf' => 'root.details_required=1',
],
```

The example needs `show_details` in the containing scope, `details_required` at the Flex root and a native `feature_enabled` Checkbox on the main Page. Conditions use submitted values, including unchecked checkboxes, on Save and AJAX duplication. Array/JSON and wrapper definitions behave alike.

References accept one name plus native `.count` where supported. Native operators, comma-separated AND and field/value OR expressions remain available. Bare properties named `parent`, `root`, `page` or `forpage` remain local properties.

External sources must be rendered native value inputs. Rows, fieldsets, File/Image and compound Flex fields cannot supply values; missing/unsupported sources cause a definition error. Arbitrary traversal (`page.parent.template.name`, `parent.parent`) is unsupported; use PHP definitions for stored Page metadata. Conditions do not replace permissions or validation.

### Reusing native input configuration

```php
'body' => [
    'type'         => 'TinyMCE',
    'inheritField' => 'rich_text_source',
    'label'        => __('Body'),
],
```

The source Field must use the same Inputfield type (here, Textarea configured for TinyMCE). Flex copies Inputfield settings and compatible FieldtypeText/FieldtypeFile `textformatters`, never source values or storage. Local settings override inherited ones; an explicit formatter list replaces the inherited list, including `[]`.

TinyMCE's native `settingsField` is separate from `inheritField`. See [text inputs](examples/03-text.php), [numbers/dates](examples/04-numbers-dates.php) and [advanced settings](examples/07-advanced.php) for prerequisites and examples.

### Textformatters

Text-valued inputs, including custom scalar inputs, TinyMCE, Email/URL, Hidden and Icon support native Textformatters. File/Image applies them to descriptions. Settings on numbers, Toggle, choices, Page references, Rows and fieldsets are ignored. Effective lists must name installed Textformatter modules or a definition error is raised.

PHP definitions use `'textformatters' => ['TextformatterMarkdownExtra']`. JSON uses the same ordered list:

```json
{
  "body": {
    "type": "textarea",
    "textformatters": ["TextformatterMarkdownExtra"]
  }
}
```

Formatters run in configured order on formatted reads, after native language resolution/fallback (`langBlankInherit`, template `noLang`). An absent list or `[]` leaves text/descriptions unescaped. Use `TextformatterEntities` for HTML text/attributes; Markdown formatter output retains its HTML. Repeated reads start from raw values and do not alter canonical data or change tracking.

The formatter receives the owning Page and a transient Field named `<flex_field>_<property>`, with no database ID, including inside Rows. Formatters requiring a stored Field or `$page->{$field->name}` need Flex-aware integration.

Native URL `addRoot` and Textarea output options apply even without formatters. Storage-time markup QA/link abstraction remains unsupported. Choice keys stay raw except for Toggle `formatType`; [label helpers](#property-and-option-labels) encode display labels separately.

### Language modes

| `useLanguages` | LanguageSupport **and** LanguageSupportFields available | Otherwise |
| --- | --- | --- |
| `false` (default) | Plain text | Plain text |
| `true` | Multilingual value | Definition error |
| `'auto'` | Multilingual value | Plain text |

`'auto'` supports Text/Textarea subclasses, including TinyMCE. Unformatted values are `LanguagesPageFieldValue`; formatted reads use the current language. JSON stores language-ID maps. Scalar assignment updates the current user's language and retains others; template `noLang` uses the default. A stored scalar becomes the default-language value when multilingual mode is enabled.

Switching to plain mode reads the default-language text, even when blank/missing. **Saving then replaces the language map and discards other translations**; reads alone preserve them. Before uninstalling LanguageSupport, save a Flex field so the module remembers the default-language ID. The live Languages API takes precedence; without it or a remembered ID, old maps cannot be read as plain text. See [language examples](examples/03-text.php).

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

Native Repeater can contain a Flex field but cannot be a Flex property. Flex, Repeater/RepeaterMatrix, PageTable and Password inputs are rejected as properties. Inputfields requiring their own Fieldtype persistence are not automatically supported; CKEditor has no Flex-specific browser-save verification.

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

**Authorize PHP maintenance callers yourself.** Operations scan stored owner IDs in bounded batches (including hidden Pages and Repeater items). Apply locks and revalidates data/schema. Missing definitions, unknown row types and rename conflicts are reported, not silently pruned. Rename conflict checks distinguish JSON objects from lists, including unknown values inside Rows. Cleanup only removes explicitly selected unknown paths; ownership and selector projections update atomically.

Results contain `fieldId`, `operation`, `paths`, `checked`, `changed`, `unchanged`, `wouldChange`, `pages` (opaque preview tokens), `properties` (counts/locations), `renames`, `conflicts`, `errors`, `findings`, `skippedChecks`, `retryPageIds`, `resumeAfterPageId`, `afterPageId`, `lastPageId`, `complete`, `success` and `finished`. `findings`/`skippedChecks` are empty for these PHP operations; [CLI check](#consistency-checks) populates them. Errors/conflicts identify `pageId`, `path` and `message`; renames identify `pageId`, `from`, `to` and a concrete `address`. Type-qualified paths select schema properties; concrete row addresses use row IDs.

Continue successful incomplete batches with `lastPageId`; after errors repair and resume from `resumeAfterPageId`. A scan is not a global snapshot. Before removing `renamedFrom`, complete every batch and repeat a full preview from zero to confirm no pending renames. Apply rejects stale previews and respects existing transactions.

### Native CLI

Run from the ProcessWire root on a core with native CLI dispatch. Refresh module metadata after updating. The development fixture's 3.0.274 supports it; the pinned 3.0.259 does not. PHP and Admin maintenance remain available without native CLI.

```sh
php index.php flex --help
php index.php flex schema content --page=1234 --json
php index.php flex status --json
php index.php flex check content --all --limit=100 --json
php index.php flex rename content --dry-run
php index.php flex rename content --all --apply
php index.php flex orphans content --details
php index.php flex cleanup content --path=old_summary --dry-run
php index.php flex cleanup content --path=old_summary --apply
```

| Command | Accepted options |
| --- | --- |
| `schema FIELD` | `--page=ID`, `--json` |
| `status` | `--json` |
| `check FIELD` | `--page=ID`, `--all`, `--limit=N`, `--after=ID`, `--json` |
| `rename FIELD` | Same selection/output options as check; `--dry-run`, `--apply` |
| `orphans FIELD` | Same selection/output options as check; `--details` |
| `cleanup FIELD` | Same selection/output options as rename; one or more `--path=PATH` |

`rename` and `cleanup` preview by default; only `--apply` writes content. Apply creates and revalidates a fresh preview, not a prior terminal dry-run. `--dry-run` cannot accompany `--apply`. Cleanup paths must be unique type-qualified schema paths, never row IDs. `--details` adds owner IDs and concrete addresses to orphan batches.

Unknown, repeated, inapplicable or contradictory options fail; distinct `--path` options may repeat. Numeric options require decimal integers without leading zeros or signs: `--limit=1..1000` (default 100), `--after>=0` (default 0), `--page>0`.

#### Exact owners and batches

`--page` selects one exact owner, including hidden/unpublished Pages and Repeater items. A missing Page or field assignment is an error (exit 2). An assigned field without stored data returns `stored=false`, `checked=0`; stored empty JSON is checked normally. Schema inspection evaluates the Page even without storage. `--page` excludes `--all`, `--after` and `--limit`.

Without `--all`, check/maintenance scans one bounded batch. Continue with `lastPageId` while `complete=false`. `--all` advances automatically; `--limit` bounds each batch, not the run. `--after` starts a suffix. Each owner commits independently; no global snapshot/transaction spans the run.

An all-run stops after its first batch with errors or skipped checks; successful owners in that batch remain applied. Repair failures and resume with `--all --after=RESUME_ID`, using `resumeAfterPageId` and the same operation/selection. `retryPageIds` identifies failed owners. `lastPageId` records examination progress, not success; `finished` covers only the selected range.

Before removing `renamedFrom`, complete migration from zero, then repeat a full preview from zero until no pending renames remain. Concurrent edits can require another scan.

#### Schema inspection

`schema` reports source, native input types, language/multiplicity settings, recursive collections/types, renames and supported selector paths/operators. Without `--page`, it uses discovery/NullPage; with it, that exact Page's schema. Dynamic definitions must still expose queryable paths in discovery. Missing/invalid definitions fail. Inspection never saves or migrates data.

#### Consistency checks

`check` reads stored data without hydration, defaults, normalization or repair. It checks owner/field assignment, canonical JSON/envelopes, resource budgets, per-Page definitions, unknown row types, rename conflicts, row/search projections and media ownership/metadata. Pending renames are warnings; unknown properties alone are allowed. Media originals are checked for existence; optional variations are not required. Unreferenced live ownership is a warning.

Checks cover selected stored owners, not a global filesystem, cross-field, version or staging inventory. They do not verify file bytes or complete index definitions. Custom file-path hooks/unavailable roots can skip original-path checks; query failures or invalid data can prevent further checks. Reports expose these boundaries, and concurrent writes may require rechecking.

`findings` entries have stable `category`, `severity` (`error`/`warning`), `field`,
`fieldId`, `pageId`, `path`, `address` and a value-free `message`. Empty paths indicate
owner/field-level findings. Categories are `owner`, `canonical_json`, `definition`,
`envelope`, `schema_data`, `row_schema`, `rename_conflict`, `pending_rename`,
`search_projection`, `row_projection`, `media_reference`, `media_unreferenced`,
`media_original`, and `derived_unavailable`. `skippedChecks` identifies uncompleted
checks per owner; `retryPageIds`/`resumeAfterPageId` allow explicit reinspection.
`complete` means the selected owner range was exhausted; `finished` additionally
requires no errors and no skipped checks. Warnings alone do not fail a complete check.

#### Operating status

`status` reports versions, native CLI availability, required shared-table columns/InnoDB engines, media journal counts, expired temporary records and the stored cleanup cursor when available. Missing storage or wrong engines fail; pending/expired counts alone are normal work. It does not run cleanup or audit index definitions. The cursor is not completion progress, and `lastCleanup=unknown` does not assert a last-run time or success.

#### Output and exit codes

`rename`, `orphans` and `cleanup` default to JSON; `schema`, `status` and `check` default to readable text. `--json` produces one JSON object. With `--all --json`, output is JSON-Lines: bounded `record="batch"` objects, then a `record="summary"` with totals, diagnostics, cursors and completion state. Without `--json`, all-runs use their command's default format for batches and summary.

Progress and adapter diagnostics go to stderr. Failures can terminate a stream before its summary; check the exit status and ignore blank lines.

| Exit | Meaning |
| --- | --- |
| 0 | Successful command/check; check warnings alone are allowed |
| 1 | Known consistency errors, maintenance errors/conflicts, invalid/missing definition in schema, or unhealthy required status storage |
| 2 | Invalid request, exact owner selection error, or adapter-level failure |
| 3 | Check incomplete without a known error (explicit skipped checks) |

Known errors take precedence over incompleteness. The `check`, `schema`, `status` and `orphans` handlers are read-only. The independent automatic media lifecycle may still collect eligible files at process completion.

### Field configuration

Superusers have **Field → Details → Data maintenance** for batched previews, bulk renames, orphan inventory and explicitly selected cleanup. Actions require CSRF validation; cleanup has separate selection, preview and apply steps. Ordinary field-configuration saves do not migrate data. Repair conflicts and rerun affected batches.

## Notes and limitations

- Requires ProcessWire 3.0.259+, PHP 8.1+, MySQL 8.0+ or MariaDB 10.11+ with InnoDB. SQLite/PostgreSQL are unsupported.
- Native Page-version save/restore does not include Flex values.
- Fieldtype conversion is limited to Flex; remove saved Flex fields before uninstalling the module.
- Use the documented Flex APIs and referenced native collection APIs. Schema metadata/transformers, view factories, services such as `storage()`/`media()`, owner/address helpers and editor hooks are internal even where PHP visibility is public.
- Retrieve this reference through native `php index.php docs get FieldtypeFlex` or WireApiDocs; runtime signatures are also available through PHPDoc.

Shared APIs: [[WireData]], [[WireArray]], [[Inputfield]], [[InputfieldForm]], [[Pagefiles]], [Pagefile](https://processwire.com/api/ref/pagefile/), [[Pageimage]].

Related: [README.md](README.md) · [Configuration examples](examples/README.md) · [Architecture](docs/architecture.md) · [ProcessWire API reference](https://processwire.com/api/ref/).

**Module source:** [FieldtypeFlex.module.php](FieldtypeFlex.module.php).
