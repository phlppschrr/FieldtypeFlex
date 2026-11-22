# FieldtypeFlex

FieldtypeFlex stores structured content in one ProcessWire field. Get the module with `$modules->get('FieldtypeFlex')` and field values with `$page->content`. Start with the [README](README.md#quick-start) for setup and the [examples](examples/README.md) for complete definitions.

- [Value types](#value-types)
- [Reading and writing values](#reading-and-writing-values)
- [Definitions](#definitions)
- [Objects](#objects)
- [Tables](#tables)
- [Rows](#rows)
- [File and Image properties](#file-and-image-properties)
- [Selectors](#selectors)
- [Inputfield settings](#inputfield-settings)
- [Field settings](#field-settings)
- [Hooks](#hooks)
- [Maintenance APIs](#maintenance-apis)
- [Notes and limitations](#notes-and-limitations)

## Value types

A Flex field returns a `FlexValue` (extends [[WireData]]):

```php
$value = $page->content;  // FlexValue
$title = $value->heading; // Property value
$blocks = $value->blocks; // FlexRows
$row = $blocks->first();  // FlexRow, or false when empty
```

Examples assume the `ProcessWire` namespace, a saved editable Page, and a Flex field named `content`. TinyMCE examples require `InputfieldTinyMCE`.

| Definition | Unformatted | Formatted | Stored as |
| --- | --- | --- | --- |
| Text, Textarea, Email, URL | `string` | String with configured textformatters | String |
| Integer / Float | `int` / `float`, or `''` | Same | Number or `''` |
| Checkbox | `1` / `0`, or configured scalars | Same | Scalar |
| Toggle | Integer key or `''` | Key, `bool`, or label per `formatType` | Integer or `''` |
| Single / multiple choices | `string` / `string[]` keys | Same raw keys | String / array |
| TextTags | Space-separated `string` | Same | String |
| Datetime | Unix timestamp or `''` | Formatted string, timestamp, or `''` | ISO date/datetime |
| Page (`derefAsPage=0`) | `PageArray` | Filtered `PageArray` | ID array |
| Page (`derefAsPage=1`) | `Page` or `false` | Filtered `Page` or `false` | ID or `false` |
| Page (`derefAsPage=2`) | `Page` or `NullPage` | Filtered `Page` or `NullPage` | ID or `0` |
| Multilingual text | `LanguagesPageFieldValue` | Current-language `string` | Language-ID map |
| `object` | `FlexValue` | `FlexValue` with formatted children | Nested map |
| `table` | `FlexValue` (`rows`, `headers`) | Same with visible formatted rows | Nested map |
| `rows` | `FlexRows` of `FlexRow` | Visible rows with formatted values | Row list |
| File / Image | `FlexFiles` / `FlexImages` | Collection, `Pagefile`/`Pageimage`, or `null` | File metadata list |

### Output formatting and serialization

```php
$page->of(true);
echo $page->content->heading;            // Formatted text

$rawHeading = $page->content->getUnformatted('heading');
$runtime = $page->content->getArray();   // Known runtime values (current formatting)
$raw = $page->content->rawArray();       // Raw stored data (unformatted)
$json = json_encode($page->content);     // Raw JSON representation
```

- Output formatting affects reads only and propagates through Objects, Tables, Rows, and media.
- Formatted values are read-only cloned views. **After `$page->of(false)`, read `$page->content` again before editing.** Writes require both the value and owning Page to be unformatted.
- `getUnformatted($name)` returns raw values without granting write access on a formatted view. To edit all Rows (including hidden ones), use `$page->getUnformatted('content')`.
- `getArray()` and iteration expose known runtime properties (Row collections key items by stable row ID).
- `rawArray()`, string cast, and `json_encode()` export raw unformatted data, including hidden Rows and unknown properties. Raw exports are **not HTML-safe** and do not embed file bytes.

## Reading and writing values

```php
$page->of(false);
$page->content->heading = 'Introduction';
$page->content->set('summary', 'An example');
$page->save('content');
```

- Reading an unknown property returns `null`. Writing an unknown property throws `WireException`.
- Assignments normalize types, but native Inputfield validation and purification run during form processing. Validate API input yourself.

| `FlexValue` method | Purpose |
| --- | --- |
| `get($name)` / `set($name, $value)` | Read a property (`mixed`); set a known property and return `$this` |
| `getUnformatted(string $name)` | Read raw typed property value |
| `getArray()` / `rawArray(): array` | Runtime / raw property array (takes no arguments) |
| `hasValue(string $name): bool` | True when the property has logical content (ignores formatting and row visibility) |
| `label(string $name): string` | Translated property label |
| `optionLabel(string $name, $option = null): string` | Label for the current or explicit single-choice key |
| `getOptions(string $name): array` | Option `key => translated label` map |
| `definitions(): FlexFieldDefs` | Evaluated schema definitions for this value |
| `of(?bool $formatted = null): bool` | Read or set formatting; returns previous state |
| `isChanged($name = ''): bool` | True if this value or any nested value changed; optionally check one property |

`hasValue()` treats blank, `null`, `false`, unchecked checkboxes, empty collections, and `NullPage` as empty. Numeric/string `0` and whitespace count as content. Hidden Rows, translations, and retained unknown properties count; an empty Row ID does not. Read helpers do not mark changes.

### Property and option labels

```php
$value = $page->content;
$value->label('appearance');                   // Property label
$value->optionLabel('appearance');             // Current selection label
$value->optionLabel('appearance', 'featured'); // Explicit option label
$value->getOptions('appearance');              // Key => translated label map
```

- Choice properties always store and return raw keys, never native Options objects.
- Label helpers follow the current language and HTML-encode labels when formatted. Unknown names/options return `''` or `[]`.
- `optionLabel()` returns `''` for multi-select arrays; pass one key at a time. Explicit `null` selects the blank key; `0` is a valid key.
- Helpers also work on Rows and accept type-qualified schema paths such as `blocks.text.appearance` (pass an explicit key to `optionLabel()` for schema paths).

### Dates, numbers, and text

- **Datetime:** Accepts ISO strings, Unix timestamps, numeric timestamp strings, or `DateTimeInterface`. ISO strings take precedence over localized input formats. Blank, `null`, `false`, invalid values, or epoch `0` sanitize to `''`. Stored as `Y-m-d` or `Y-m-d H:i:s` in site timezone. Formatted reads use `dateOutputFormat` (blank or `U` returns a timestamp).
- **Integer / Float:** Normalize numeric strings, keep `0`, and sanitize blank, `null`, or non-numeric input to `''`. Float `precision` rounds on write/load (default `2`; `null`, `''`, or negative disables rounding).
- **Email / URL:** Use ProcessWire's native sanitizers and configured IDN / relative-URL settings.
- **Toggle:** Default keys are `0`/`1`/`2` (no/yes/other; set `useOther` for `2`), or custom integer keys from `-128` to `127`. `formatType`: `0` key, `1` boolean (for `0`/`1` only), `2` label, `3` encoded label without icons.

### Page references

`derefAsPage` controls the return type in both formatting states (see [Value types](#value-types)). Check `$ref->id` on a `NullPage`, not object truthiness. Native `FieldtypePage` handles loading, filtering, and save-time validation (`parent_id`, `template_id`, `findPagesSelector`). Save referenced Pages separately.

| Operation | Behavior |
| --- | --- |
| Assign single reference | Accepts `Page`, `PageArray`, ID, path/name, or selector |
| Assign `Page`, ID, ID array, or string to multi-reference | **Appends** |
| Assign `PageArray` to multi-reference | **Replaces** |
| Clear multi-reference | Call `removeAll()` or assign an empty `PageArray` (`null`/`''` does not clear) |
| Remove one ID | Assign a negative ID string, e.g. `$value->refs = '-' . $target->id` |
| Editor form submission | Replaces the selection |

`allowUnpub=false` drops unpublished Pages on load; `true` keeps them unformatted. Formatted reads filter unpublished Pages using native rules; hidden Pages remain included. **Access permissions are not read filters:** call `$target->viewable()` where needed.

## Definitions

Define properties with a PHP array, JSON object, or native `InputfieldWrapper`. By default, a field named `content` loads one of `site/templates/flex-fields/content.php` or `content.json`:

```php
<?php namespace ProcessWire;

return [
    'heading' => [
        'type' => 'text',
        'label' => __('Heading'),
        'required' => true,
        'textformatters' => ['TextformatterEntities'],
    ],
    'hero' => ['type' => 'image', 'maxFiles' => 1],
];
```

- `type` defaults to `text` and selects a native Inputfield or Flex's `object`, `table`, or `rows` structures. Labels default from property names.
- Property names must start with an ASCII letter or underscore and contain only ASCII letters, digits, or underscores. `data`, `pages_id`, and `count` are reserved.
- Change the auto-discovery directory with module setting `definitionsPath` (relative to `site/templates/`, default `flex-fields/`). Or configure per field with `flexDefinitionFile` (templates-relative PHP/JSON path) or `flexDefinition` (inline JSON). Having two auto-discovered files, or both a file and non-empty inline JSON, is an error.
- Paths must stay inside `site/templates/`. PHP definitions execute as trusted code.

### Evaluation and schema inspection

PHP definitions run with `$page`, `$field`, `$wire`, and `$languages` (`null` without `LanguageSupport`). Selector discovery runs without a concrete Page (`NullPage`), so queryable properties must be declared in that context too.

```php
$field = $fields->get('content');
$defs = $field->defs($page); // FlexFieldDefs
$input = $defs->getPropertyInputfield('blocks.text.heading');
$tree = $defs->getInputfields();
```

| Method | Result |
| --- | --- |
| `FlexField::defs(?Page $page = null): FlexFieldDefs` | Evaluated schema (`NullPage` context when omitted) |
| `getPropertyInputfield(string $name): ?Inputfield` | Fresh configured Inputfield, or `null` |
| `getInputfields(): InputfieldWrapper` | Fresh native input tree |

Schema paths include each collection and type, e.g. `sections.section.blocks.text.heading`. Losing an auto-discovered definition when data exists blocks writes; invalid definitions block Page Edit saves while keeping submitted inputs for correction.

### Renaming and preserving properties

```php
'heading' => ['type' => 'text', 'renamedFrom' => 'title'],
```

- `renamedFrom` accepts one old name or an array of old names. Reads resolve old keys; saving persists the new key. Equal old/new values merge; conflicts throw `WireException`.
- **Unknown properties, nested data, and owned media survive ordinary saves and copies.** Saving field settings never migrates stored data.
- Use [Maintenance APIs](#maintenance-apis) for bulk renames or explicit cleanup. Complete migration before querying renamed fields or removing `renamedFrom`.

## Objects

`type => 'object'` declares a fixed nested `FlexValue`, rendered as a native fieldset without add/remove controls. Objects can contain Objects, Tables, Rows, and media. Regular `fieldset` wrappers stay flat in the parent scope.

```php
'link' => [
    'type' => 'object',
    'children' => [
        'label' => ['type' => 'text'],
        'url' => ['type' => 'url'],
    ],
],
```

- Read or edit `$page->content->link->url`, or assign `$page->content->link = ['url' => '/about/']` when unformatted.
- **Assigning an array replaces the Object** and applies defaults to omitted known children. Editing a single child keeps sibling values.
- The Object always exists: check `$page->content->hasValue('link')` instead of truthiness.
- Selectors use dotted paths (`content.link.label=Contact`) and support sorting on scalar children. Objects have no `.count` (their child collections do). Objects are not supported in per-file `customFields`.

## Tables

`type => 'table'` defines fixed `columns` in display order with repeatable rows. Editors can add, duplicate, reorder, hide, remove, copy, and paste rows.

```php
'prices' => [
    'type' => 'table',
    'sortOnSave' => 'price', // optional: 'price' (asc) or '-price' (desc)
    'columns' => [
        'service' => ['type' => 'text', 'label' => 'Service'],
        'price' => ['type' => 'float', 'label' => 'Price', 'width' => '7rem'],
    ],
],
```

- **API access:** Read rows from `$page->content->prices->rows` (`FlexRows`) and add rows with `$table->rows->addRow('row', ['service' => 'Advice'])`.
- **Settings:** `minItems` and `maxItems` limit row count. Optional column `width` accepts a positive `rem` or `px` value; editors can drag column dividers to resize until reload.
- **Headers & row labels:** `editableHeaders => true` exposes `$table->headers->service` (initialized from the column label). `rowLabels => true` adds `$row->row_label`. Table-level `useLanguages` applies to headers and row labels; set `useLanguages` on each data column separately.
- **Inline cells:** `Text`, `Email`, `URL`, `Integer`, `Float`, plain `Textarea`, `Checkbox`, `Toggle`, `Select`, `Radios`, `Checkboxes`, `SelectMultiple`, `AsmSelect`, `TextTags`, `Datetime`, and compatible Inputfield subclasses edit inline. Page references support `Select`, `SelectMultiple`, `AsmSelect`, `PageAutocomplete`, `Checkboxes`, `Radios`, and `TextTags` inline.
- **Detail rows:** `File`, `Image`, `PageListSelect`, `PageListSelectMultiple`, and `TinyMCE` (iframe or inline) open in a full-width detail row below the selected row. See [table examples](examples/08-tables.php).
- **Sorting:**
  - `Text`, `Integer`, and `Float` columns offer manual header sort buttons (click again to reverse; page save persists the order).
  - Set `sortOnSave => 'price'` (ascending) or `'-price'` (descending) to sort automatically on every write, including API saves. The editor marks the sorted column and hides manual sort buttons and drag handles.
  - Both modes place blank values last and preserve relative order for ties. Multilingual text sorts by the default-language value using its collation locale (falling back to English, or case-insensitive byte comparison when PHP `Intl` is unavailable).

## Rows

`type => 'rows'` returns a `FlexRows` collection of typed `FlexRow` objects (**not Repeater Pages**). A non-empty `types` map is required, even for one type:

```php
'blocks' => [
    'type' => 'rows',
    'minItems' => 0,
    'maxItems' => 30,
    'types' => [
        'text' => [
            'rowTitle' => '#{@index} {heading|"No heading"}',
            'children' => [
                'heading' => ['type' => 'text'],
                'body' => ['type' => 'TinyMCE'],
            ],
        ],
        'image' => [
            'children' => [
                'photo' => ['type' => 'image', 'maxFiles' => 3],
                'caption' => ['type' => 'text'],
            ],
        ],
    ],
],
```

- Type keys must start with a lowercase ASCII letter, contain only lowercase letters, digits, or underscores, and be at most 64 characters. `count` is reserved. Custom developer metadata keys on row types are allowed.
- Row ID and type are immutable. Single-type collections and `maxItems => 1` still return a `FlexRows` collection.

### Presentation and visibility

**Dynamic `rowTitle` templates:**

| Token | Meaning |
| --- | --- |
| `{heading}` | Property in the current Row (supports Object paths like `{details.caption}`) |
| `{heading\|title\|"No heading"}` | First non-blank value (whitespace is blank; `0` is a value) |
| `{image.description}`, `.tags`, `.basename` | First item of a media property |
| `{parent.heading}` / `{root.heading}` | Property on the containing scope or root Flex value |
| `{@type}` / `{@index}` / `{@path}` | Row type label / 1-based index / ancestor type labels joined by ` › ` |
| `[ · {category}]{.flex-muted}` | Optional group (omitted if any token inside is blank) with CSS classes |

- Built-in title classes: `flex-badge`, `flex-muted`, `flex-strong`, `flex-small`, `flex-mono` (custom admin CSS classes also work).
- Groups cannot nest; escape special characters (`{}[]|'"\`) with `\`. Blank/omitted `rowTitle` defaults to `[{@type}]{.flex-badge} #{@index}`. An empty custom result falls back to `#@index`.
- Standard tokens update live in the browser as you type or reorder. For custom PHP tokens or full overrides, see [Hooks](#hooks).

**Collapse, limits, and lazy loading:**

- `rowCollapse`: `FieldtypeFlex::ROW_COLLAPSE_EXISTING` (`0`, default: existing closed, new open), `ROW_COLLAPSE_ALL` (`1`), or `ROW_EXPAND_ALL` (`3`). Set `addRowLabel` for the add control label.
- `minItems` / `maxItems`: `0` or higher across all types (`maxItems => 0` means unlimited). `maxItems` blocks adding new items (existing excess rows still load so they can be removed); `minItems` is enforced on editor submissions, not API saves. Hidden rows count.
- `rowLazyLoad => true`: optionally lazy-loads closed scalar-only rows on open (unsupported types render eagerly; unopened values still validate on save; without JS, pass `flex_eager=1`). See [lazy-loading eligibility](docs/development.md#lazy-row-eligibility).

**Row identity and visibility:**

```php
$row->rowId();                // Stable 32-char hex ID
$row->rowType();              // 'text'
$row->rowTypeLabel();         // Unescaped type label
$row->hasProperty('heading'); // Defined in this row type's schema

$page->of(false);
$row->setHidden(true);        // Toggle visibility (isHidden() reads it)
$page->save('content');
```

- Hiding a Row hides its nested children without changing their own hidden flags. Duplication preserves visibility.
- Formatted reads, counts, and SQL selectors exclude hidden Rows (including with `include=all`). Use `$page->getUnformatted('content')->blocks` to access all Rows. Visibility is editorial state, **not access control**.
- Marking a Row for removal in the editor can be undone until Save. Marked rows skip validation and do not count toward limits.

### Copy, paste, and bulk actions

- **Row actions:** Copy any Row or paste above/below it from the row menu; **Actions → Paste from Clipboard** appends to the collection (even when empty).
- **Bulk actions:** Choose **Actions → Select rows** to select multiple rows (Shift-click selects a range), then copy, hide, or mark them for removal together.
- **Cross-site paste:** Requires HTTPS and browser clipboard permission. Pasted values map to the target collection's types and defaults; invalid leaf values are kept for native editor validation on Save.
  - Languages match by language name.
  - Page references resolve by matching full path, parent path, and template name (subject to the target field's selection and access rules).
  - Same-site paste copies owned local files directly; cross-site paste embeds original media inline as base64 in clipboard JSON (up to 16 MB raw data / 24 MB payload, bounded by PHP `post_max_size`). Skipped items are reported in the editor.

### Collection operations

```php
$page->of(false);
$rows = $page->content->blocks;
$row = $rows->addRow('text', ['heading' => 'Introduction']);
$copy = $rows->duplicateRow($row->rowId());
$rows->moveRow($copy->rowId(), $row->rowId());
$rows->removeRow($copy->rowId());
$page->save('content');
```

| `FlexRows` method | Purpose |
| --- | --- |
| `addRow(string $type, array $values = []): FlexRow` | Append a new Row with a generated ID |
| `getById(string $id): ?FlexRow` | Look up a Row by ID, or `null` |
| `find($selector): FlexRows` | Filter loaded Rows (`sort=`, `limit=`, `start=`, OR fields/values) |
| `rowsOfType(string $type): array` | Direct Rows of `$type` (`[]` for unknown types) |
| `countOfType(string $type): int` | Count of direct Rows of `$type` (`0` for unknown types) |
| `duplicateRow(string $id): FlexRow` | Append independent copy with new IDs and media copies |
| `moveRow(string $id, ?string $before = null): self` | Move before sibling ID, or to end |
| `removeRow(string $id): self` | Remove Row (unknown ID is a no-op) |
| `rawArray(): array` | Raw data including hidden Rows |
| `of(?bool $formatted = null): bool` | Read or set formatting; returns previous state |

- Unknown add types throw `FlexDefinitionException`. Unknown duplicate/move IDs, write-guard violations, and `maxItems` violations throw `WireException`.
- Standard [[WireArray]] methods (`first()`, `count()`, `slice()`, etc.) work with Flex ownership checks; create new Rows with `addRow()`.

**In-memory filtering (`FlexRows::find()`):**

```php
$images = $blocks->find('_type=image, photo.count>=3');
$intro = $blocks->find('_type=text, heading=Introduction, body*=ProcessWire');
$notes = $block->notes->find('_type=note, text*=Sources');
```

- Delegates to [[WireArray]] filtering and accepts a selector string, array, or `Selectors` (including unsaved changes).
- Supports `_type` (row type metadata; `type` is an ordinary property), direct properties, Object paths, media/child `.count`, Page dot paths, OR fields (`heading|body*=foo`), OR values, prefix negation, `sort=`, `limit=`, and `start=`.
- Missing or unknown property paths evaluate to `''` (matching `PageArray::find()`). Comparisons run on unformatted values (dates are timestamps).

**Nested Rows & native wrappers:**

```php
$page->of(false);
$section = $page->content->sections->addRow('section', ['heading' => 'Intro']);
$block = $section->blocks->addRow('text', ['heading' => 'Hello']);
$block->notes->addRow('note', ['text' => 'A note']);
$page->save('content');
```

In native `InputfieldWrapper` definitions, use `InputfieldFlexRows`:

```php
$rows = $modules->get('InputfieldFlexRows');
$rows->name = 'blocks';
$rows->types = ['text' => ['children' => ['heading' => ['type' => 'text']]]];
$inputs->add($rows);
```

Flex fields also work inside native ProcessWire Repeater items. Nesting depth is bounded by database JSON depth, [field budgets](#field-settings), and PHP input limits. Truncated editor submissions are rejected.

## File and Image properties

Flex manages file storage using ProcessWire-compatible `Pagefile` and `Pageimage` objects. File bytes are stored on disk, not in JSON.

```php
'hero' => ['type' => 'image', 'maxFiles' => 1],
'downloads' => ['type' => 'file', 'extensions' => 'pdf txt zip', 'maxFiles' => 5],
```

Unformatted reads always return `FlexFiles` or `FlexImages`. Formatted reads follow `outputFormat`:

| `outputFormat` | Formatted result |
| --- | --- |
| `0` Auto (default) | Single `Pagefile`/`Pageimage` or `null` when `maxFiles=1`; otherwise collection |
| `1` Collection | Always a collection (even when empty) |
| `2` Single | First `Pagefile`/`Pageimage` or `null`, regardless of `maxFiles` |

```php
$image = $page->content->hero; // Pageimage|null (Auto mode, maxFiles=1)
if($image) echo $image->size(1200, 700)->url;

$images = $page->content->getUnformatted('hero'); // FlexImages
```

- `maxFiles => 0` means unlimited. Lowering `maxFiles` below existing files still loads the collection and caps formatted output to `maxFiles`.
- Native image `size()`, `width()`, `height()`, `crop()`, `focus()`, and Variations work as usual. Deleting a variation is immediate; originals are committed when the field saves.

### Adding, saving, and ownership

```php
$page->of(false);
$page->content->downloads->add('/absolute/path/to/document.pdf');
$page->save('content');
```

- `add()` accepts readable local paths or `http://`/`https://` URLs (for example `$page->content->hero->add('https://example.com/images/hero.jpg')`, downloaded via `WireHttp::download()` and validated for extension, `maxFilesize`, image integrity, and `Sanitizer::validateFile()`). Other URL schemes are rejected.
- New, replaced, renamed, and deleted originals stay staged until the owning field saves. Cross-owner assignment and Row duplication create independent copies.

| Operation | Behavior |
| --- | --- |
| `first()`, `get()`, `add()`, `delete()`, `deleteAll()`, `clone($file, $options)` | Native-style collection operations; `clone()` also supports `'pagefiles' => $targetCollection` |
| `$file->rename($name)` / `$files->rename($file, $name)` | Collision-safe rename keeping order and metadata; save field to commit |
| `$file->save()` | Save metadata on an already-committed file without publishing other pending edits |
| `$type->saveFile($page, $field, $file, $columns = []): bool` | Save selected metadata columns (`description`, `tags`, `filedata`, `values`, `modified`, `modified_users_id`); empty saves all, unknown columns are ignored |

- Default `extensions`: ProcessWire's standard file list (`pdf doc docx xls xlsx gif jpg jpeg png`) for `file`; `jpg jpeg gif png` for `image` (add `webp` explicitly if needed). `maxFilesize` is in bytes (`0` = no extra limit).
- Unsupported: overwrite mode, `unzip`, property `useLanguages` (use `noLang` for descriptions), predefined tags, native custom-field templates, string output, and TinyMCE drag-and-drop uploads into Flex images.
- See [[Pagefiles]], [Pagefile](https://processwire.com/api/ref/pagefile/), and [Pageimage](https://processwire.com/api/ref/pageimage/) for standard methods.

### Per-file custom fields

Define custom metadata per file or image with `customFields` (no separate ProcessWire fields or templates needed):

```php
'gallery' => [
    'type' => 'image',
    'customFields' => [
        'description' => ['type' => 'textarea', 'label' => 'Image description'],
        'copyright' => ['type' => 'text', 'textformatters' => ['TextformatterEntities']],
        'year' => ['type' => 'integer'],
    ],
],
```

```php
$page->of(false);
$image = $page->content->gallery->first();
$image->copyright = 'Example Photographer';
$image->year = 2026;
$page->save('content');
```

- **Supported types:** Text/HTML/TinyMCE, Hidden, Email/URL, numbers, choices, Checkbox/Toggle, Datetime, TextTags, Page (`Select`/`AsmSelect`), fieldsets/Markup, and compatible subclasses. Objects, Rows, and nested media are rejected.
- **API:** Access directly (`$image->copyright`) or via `getFieldValue($name, false|true|null)` / `setFieldValue($name, $value): bool`.
- **Native overrides:** Declaring `description` or `tags` overrides the native file description/tags input (native methods keep working; `description` follows `noLang` and overrides media textformatters; `tags` choice keys must be case-insensitively unique). Other native `Pagefile`/`Pageimage` names are reserved.
- **Scope & limits:** Bare dependencies (`showIf`/`requiredIf`) target file metadata; `parent.` targets the owning Object/Row/root; `root.` targets the Flex root. Custom metadata has no SQL selectors or sorting.

## Selectors

`$pages->find()` queries saved data and returns **owning Pages** (unlike `FlexRows::find()`, which filters loaded Rows in memory).

```php
$pages->find('content.heading*=Welcome');
$pages->find('content.starts_on>=2026-10-18');
$pages->find('content.blocks.text.heading=Introduction');
$pages->find('content.blocks._type=image');
$pages->find('content.blocks.count>=3');
$pages->find('content.blocks.text.count>0');
```

- Path format: `FIELD.COLLECTION.TYPE.PROPERTY` (include each type for nested collections; Objects add their property name).
- Collection, type, and media paths support blank checks (`=""`, `!=""`) and `.count`.
- `COLLECTION._type` accepts `=` and `!=`. Unknown `_type` values, non-numeric comparisons on numbers/counts, and invalid dates return empty matches (or all pages for `!=`) instead of throwing. Unknown property paths and unsupported operators throw `PageFinderSyntaxException`.

### Operators and matching semantics

| Value kind | Supported operators |
| --- | --- |
| Numbers, dates, single Page IDs, counts | `=`, `!=`, `>`, `>=`, `<`, `<=` |
| Text, single choices, Checkbox, Toggle, TextTags | `=`, `!=`, `*=`, `%=`, `^=`, `$=`, `%^=`, `%$=`, `~=`, `~|=` |
| Multi-select arrays, multiple Page IDs | `=`, `!=` (membership) |

**Matching multiple conditions on the same Row (`@`):**

By default, separate Row conditions may match different Rows. Prefix conditions with `@` (or named groups `a@`, `b@`) to require the same Row at every shared scope:

```php
$pages->find('@content.blocks.text.heading=Introduction, @content.blocks.text.body*=ProcessWire');
```

- Each `@` group targets one Row type per collection. Supported inside `@`: scalar leaves, media counts, and child collection blank/count tests. Unsupported: conflicting types, `_type`, counts of the grouped Row itself, OR field names, and sub-selectors.
- To exclude Pages matching a group, run `$excluded = $pages->findIDs(...)` and filter with `id!=...`.

| Selector pattern | Meaning |
| --- | --- |
| `leaf!=value` (ungrouped) | No Row at that path equals `value` (missing paths match) |
| `leaf=""` / `leaf!=""` (ungrouped) | No populated value / at least one populated value |
| `@...` grouped leaf test | Applies to the same concrete Row; positive match requires a Row |
| Nested `.count` | Total descendants outside `@`; descendants of the matched parent inside `@` |

- `0` is populated, not blank. Date ranges exclude blank/missing dates. Checkbox `=0` requires a stored `0`.
- Whole-field search (`content*=Welcome`) searches flattened text values (excluding hidden Rows and schema metadata). Do not rely on cross-property phrase order.
- Sorting works on scalar leaves, fixed Object leaves, and root collection/media counts (`sort=content.blocks.count`). Sorting on repeated leaves, multi-selects, or type-specific counts is unsupported.

### Root Page-reference traversal

Query one native target field through a root Page reference (`FLEX_FIELD.PAGE_PROPERTY.TARGET_FIELD`):

```php
$pages->find('recipe.ingredient.title=Flour');
$pages->find('recipe.ingredients.title~=Flour');
$pages->find('recipe.ingredient.price>=2.5');
```

- **Supported target fields:** Native Text, Textarea, PageTitle (including multilingual), Email, URL, Integer, Float, Checkbox, Toggle, Datetime, Options, and Page.
- **Semantics:** `ingredient.title!=Flour` means no referenced Page matches `title=Flour`. `ingredient.title=""` requires a reference with a blank title; test the reference itself with `ingredient=""`. Target matching defaults to `include=all` (**not access control**).
- **Unsupported:** System properties (`id`, `name`, `path`), File/Image/compound targets, multi-hop traversal, traversal from Rows, target sorting, same-target `@`, prefix negation, and multi-dot OR fields.

## Inputfield settings

Definitions accept native [[Inputfield]] settings. See each Inputfield's docs and the [examples](examples/README.md).

| Setting | Purpose / values |
| --- | --- |
| `label`, `description`, `notes` | Editor text |
| `required`, `columnWidth`, `collapsed` | Native validation and layout |
| `value`, `options` | Default value for missing keys; choice `key => label` map |
| `showIf`, `requiredIf` | Conditional visibility/requirement (`requiredIf` also needs `required => true`) |
| `useLanguages` | `false` (default), `true`, or `'auto'` |
| `derefAsPage` | `0` (`PageArray`), `1` (`Page\|false`), `2` (`Page\|NullPage`) |
| `dateInputFormat`, `dateOutputFormat` | Native date parsing / formatted output |
| `inheritField` | Copy settings from an existing ProcessWire Field (`type` optional or compatible base type) |
| `textformatters` | Ordered formatter module names (`[]` disables) |

**Inheriting field settings (`inheritField`):**

```php
'body' => ['inheritField' => 'rich_text_source', 'label' => __('Body')],
```

Copies compatible Inputfield settings, textformatters, and Textarea `contentType`/`htmlOptions` from an existing Field. Local settings override inherited ones (including `'textformatters' => []`). TinyMCE `settingsField` works separately.

### Dependency contexts

`showIf` and `requiredIf` support scoped prefixes:

| Prefix | Target input |
| --- | --- |
| `enabled=1` | Current Flex scope (fieldsets add no scope) |
| `parent.enabled=1` | Direct containing Flex scope (invalid at root) |
| `root.enabled=1` | Root of this Flex field |
| `page.enabled=1` | Owning Page (Repeater item when inside a Repeater) |
| `forpage.enabled=1` | Main Page being edited |

```php
'details' => [
    'type' => 'textarea',
    'showIf' => 'parent.show_details=1, forpage.feature_enabled=1',
    'required' => true,
    'requiredIf' => 'root.details_required=1',
],
```

Supports one property name and native `.count`. External sources must be rendered native value inputs (not Rows, media, or other Flex fields). Missing or unsupported sources are definition errors.

### Textformatters

- Text inputs (including TinyMCE, Email, URL, and custom scalar inputs) and media descriptions run configured `textformatters` after language resolution.
- Omitting `textformatters` or setting `[]` leaves text **unescaped**; add `TextformatterEntities` for HTML-safe text.
- Formatters receive the real Page and a transient Field named `<flex_field>_<property>` (no database ID).
- Setting `contentType >= FieldtypeTextarea::contentTypeHTML` enables HTML URL rewriting on save/load; adding `htmlOptions => [FieldtypeTextarea::htmlLinkAbstract]` tracks outgoing page links across moves/renames (TinyMCE alone does not enable this).

### Language modes

- `useLanguages => true` requires `LanguageSupport` and `LanguageSupportFields`. `'auto'` enables multilingual mode on Text/Textarea/TinyMCE inputs when both modules are installed, otherwise falls back to plain strings. Ignored on non-text inputs.
- Assigning a string to a multilingual property updates the current user's language and keeps other languages.
- Switching a multilingual property back to plain mode reads the default language; **saving then discards the other translations**. Save once before uninstalling `LanguageSupport` to record the default language ID.

### Language Alternate Fields

Supports ProcessWire Language Alternate siblings such as `image` and `image_de` (works for media, Page references, scalars, etc.). When output formatting is enabled, `$page->content->image` returns the current language's sibling and falls back to `image` when empty.

## Field settings

Configure on the Flex Field itself:

| Setting | Default | Purpose |
| --- | --- | --- |
| `flexDefinition` | `''` | Inline JSON schema |
| `flexDefinitionFile` | `''` | Templates-relative PHP/JSON schema path |
| `flexHideWrap` | `false` | Hide outer field wrapper heading in the editor |
| `flexMaxDepth` | `0` | Maximum nesting depth (`0` = no extra limit) |
| `flexMaxRows` | `2000` | Maximum total descendant Rows (`0` = unlimited) |
| `flexMaxBytes` | `8388608` | Maximum JSON payload size in bytes (`0` = unlimited) |

### PHPDoc class stubs

The field's **Details** tab generates PHPDoc class stubs (evaluated with `NullPage`) for IDE autocompletion. Customize output via the `FieldtypeFlex::getPhpDoc` hook.

## Hooks

| Hook | When | Arguments |
| --- | --- | --- |
| `InputfieldFlexRows::rowTitleValues` | Computing custom `@name` tokens for a Row title | `$row`, `$root`, `$parent` |
| `InputfieldFlexRows::rowTitleParts` | Overriding computed Row title parts | `$row`, `$parts`, `$root`, `$parent` |
| `FieldtypeFlex::getPhpDoc` | Generating PHPDoc stubs for a Flex field | `$field` |

`rowTitleValues` and `rowTitleParts` run on initial render, server-rendered row insertions (add, duplicate, paste), and after Save. Keep them read-only (do not save pages or modify files).

```php
// Template: {heading}[ · {@details}]{.flex-muted}
$wire->addHookAfter('InputfieldFlexRows::rowTitleValues', function(HookEvent $event) {
    $row = $event->arguments(0);
    if($row->rowType() !== 'gallery') return;
    $count = count($row->getUnformatted('images'));
    $event->return = array_merge($event->return, ['@details' => $count ? "$count images" : '']);
});
```

```php
// Replace title parts directly (text is escaped; return null to use template)
$wire->addHookAfter('InputfieldFlexRows::rowTitleParts', function(HookEvent $event) {
    $row = $event->arguments(0);
    if($row->rowType() !== 'gallery') return;
    $event->return = [
        ['text' => $row->rowTypeLabel(), 'classes' => ['flex-badge']],
        ['text' => ' ' . $row->getUnformatted('heading'), 'classes' => ['flex-strong']],
    ];
});
```

## Maintenance APIs

Normal saves, cloning, and deletions maintain data, selector indexes, and media ownership automatically. Use maintenance APIs for bulk schema migrations, cleanup, or cloned-media recovery:

```php
$type = $modules->get('FieldtypeFlex');
$field = $fields->get('content');

$preview = $type->checkRenames($field, 0, 100);
$result = $type->applyRenames($field, $preview);

$inventory = $type->listOrphans($field, 0, 100);
$paths = ['old_summary', 'blocks.text.old_caption'];
$cleanup = $type->cleanupProperties($field, $paths, $inventory);
$result = $type->cleanupProperties($field, $paths, $cleanup, true);

$repaired = $type->repairClonedFiles($clonedPage);
```

| Method on `FieldtypeFlex` | Purpose |
| --- | --- |
| `checkRenames(Field $field, int $afterPageId = 0, int $limit = 100): array` | Preview a batch of pending `renamedFrom` migrations |
| `applyRenames(Field $field, array $preview): array` | Apply a checked rename batch |
| `listOrphans(Field $field, int $afterPageId = 0, int $limit = 100): array` | List unknown stored property paths |
| `cleanupProperties(Field $field, array $paths, array $preview, bool $apply = false): array` | Preview or delete explicit unknown schema paths |
| `repairClonedFiles(Page $copy): int` | Retry missing media copies on a saved cloned Page |

- **Authorize PHP callers yourself.** Batch limits are `1`–`1000` and include hidden/unpublished Pages and Repeater items.
- Writes are atomic per Page and reject stale previews. Continue batches with `lastPageId`, or retry after fixing errors from `resumeAfterPageId` (`retryPageIds` lists failed Pages).
- Superusers can run renames and orphan cleanup in **Field → Actions** (**Apply property renames** / **Remove unknown properties**).

### Native CLI and consistency checks

Run from the ProcessWire root (`php index.php flex --help` lists all options):

```sh
php index.php flex schema content --page=1234 --json
php index.php flex lint content --json
php index.php flex status --json
php index.php flex check content --all --limit=100 --json
php index.php flex rename content --dry-run
php index.php flex rename content --all --apply
php index.php flex orphans content --details
php index.php flex cleanup content --path=old_summary --dry-run
php index.php flex cleanup content --path=old_summary --apply
```

- `schema` inspects definitions and query paths; `lint` reports non-blocking diagnostics; `status` reports storage and media journal counts; `check` verifies stored data, schemas, selector indexes, and media files without modifying data.
- Pass `--page=ID` for one Page, or `--all` to stream batches (resume with `--after=resumeAfterPageId`). Rename and cleanup preview unless `--apply` is given.
- `--json` outputs JSON (JSON-Lines with `--all`). `check` exit codes: `0` ok/warnings, `1` errors, `2` invalid request, `3` incomplete. See [CLI report contract](docs/development.md#cli-operation-and-report-contract).

## Notes and limitations

- **Requirements:** ProcessWire 3.0.259+, PHP 8.1+, MySQL 8.0+ or MariaDB 10.11+ with InnoDB. SQLite and PostgreSQL are not supported.
- **Core features not supported:** Native Page Versions save/restore, the **Pages Export/Import** module, direct Fieldtype conversion (remove Flex fields before uninstalling), and automatic backlink discovery (`$page->links()`).
- **Nested fields & editors:** Native Repeaters may contain Flex fields, but `Flex`, `Repeater`, `RepeaterMatrix`, `PageTable`, and `Password` cannot be Flex properties. `TinyMCE` is supported; `CKEditor` is not.
- **Media cleanup:** Staged and orphaned files are cleaned up automatically in the background during normal requests (expired staging retries after 6 hours). Referenced and unknown files stay protected. See [automatic media work](docs/architecture.md#automatic-media-work).
- **Public API boundary:** Only use the APIs documented here and standard ProcessWire collections. Internal helpers (`storage()`, `media()`, transformers, schema internals, undocumented hooks) are not part of the public API.

Retrieve this reference with `php index.php docs get FieldtypeFlex` or WireApiDocs; `[[ClassName]]` links reference native ProcessWire API docs.

Related: [Examples](examples/README.md) · [Architecture](docs/architecture.md) · [Development](docs/development.md) · [[WireData]] · [[WireArray]] · [[Inputfield]] · [[InputfieldForm]].

**Module source:** [FieldtypeFlex.module.php](FieldtypeFlex.module.php).
