# FieldtypeFlex

FieldtypeFlex stores structured content in one ProcessWire field. Get the module with `$modules->get('FieldtypeFlex')` and values through `$page->content`. Start with the [README](README.md#quick-start) for setup and the [configuration examples](examples/README.md) for complete definitions.

- [Value types](#value-types)
- [Reading and writing values](#reading-and-writing-values)
- [Definitions](#definitions)
- [Objects](#objects)
- [Rows](#rows)
- [File and Image properties](#file-and-image-properties)
- [Selectors](#selectors)
- [Inputfield settings](#inputfield-settings)
- [Field settings](#field-settings)
- [Maintenance APIs](#maintenance-apis)
- [Notes and limitations](#notes-and-limitations)

## Value types

A Flex field returns `FlexValue`, extending [[WireData]]:

```php
$value = $page->content;           // FlexValue
$title = $value->heading;          // Property value
$blocks = $value->blocks;          // FlexRows
$row = $blocks->first();           // FlexRow, or false when empty
```

Examples assume the `ProcessWire` namespace after bootstrap, a saved editable Page, and a Flex field named `content` with the illustrated properties. Definition fragments belong inside your field definition. TinyMCE examples require InputfieldTinyMCE; imports require your own readable local file.

| Definition | Unformatted value | Formatted value | Stored data |
| --- | --- | --- | --- |
| Text, Textarea, Email, URL | `string` | String with configured native output formatting | String |
| Integer / Float | `int` / `float`, or `''` | Same | Number or `''` |
| Checkbox | `1` / `0`, or configured checked/unchecked scalars | Same | Scalar |
| Toggle | Integer key or `''` | Key, boolean or label per `formatType` | Integer or `''` |
| Single / multiple choices | `string` / `string[]` keys | Same raw keys | String / array |
| TextTags | Space-separated string | Same | String |
| Datetime | Unix timestamp or `''` | Output-format string, timestamp or `''` | ISO date/datetime |
| Page (`derefAsPage=0`) | `PageArray` | Native filtered `PageArray` | ID array |
| Page (`derefAsPage=1`) | `Page` or `false` | Same types, native filtering | ID or `false` |
| Page (`derefAsPage=2`) | `Page` or `NullPage` | Same types, native filtering | ID or `0` |
| Multilingual text | `LanguagesPageFieldValue` | Current-language string | Language-ID map |
| `object` | `FlexValue` | Formatted child properties | `object:1` envelope |
| `rows` | `FlexRows` of `FlexRow` | Visible Rows with formatted leaves | `rows:1` envelope |
| File / Image | `FlexFiles` / `FlexImages` | Collection, `Pagefile` / `Pageimage`, or `null` | Media metadata envelope |

### Output formatting and serialization

```php
$page->of(true);
echo $page->content->heading;           // Formatted text

$rawHeading = $page->content->getUnformatted('heading');
$display = $page->content->getArray(); // Runtime values, current formatting
$raw = $page->content->rawArray();     // Canonical unformatted data
$json = json_encode($page->content);  // Unformatted JSON view
```

Output formatting changes reads, never canonical stored data. It propagates through Objects, Rows and media. Page-formatted values are cloned views: **reacquire `$page->content` after `$page->of(false)` before editing**. Retained formatted views stay formatted. Writes require both the value and owning Page to be unformatted.

`getUnformatted($name)` bypasses output conversion without granting write access. On formatted structured values it exposes detached raw views, including hidden descendant Rows; on an unformatted original it returns the live owned collection. To edit all Rows, use `$page->getUnformatted('content')`.

`rawArray()`, string conversion and JSON serialization export canonical unformatted data, including unknown properties and hidden Rows. A Row exports its properties; identity/type/visibility belong to its collection envelope. `getArray()`/iteration expose known runtime properties and retain objects; Row collections key them by stable IDs.

Array views reflect unsaved memory, do not save or refresh, and are not deep copies. Raw exports are **not HTML-safe** or portable media archives. Known HTML contains awakened URLs; database storage uses sleeping URLs. Logical JSON exports may encode empty PHP maps as `[]` while storage preserves property maps as `{}`. Do not edit storage envelopes or derived selector projections directly.

## Reading and writing values

```php
$page->of(false);
$page->content->heading = 'Introduction';
$page->content->set('summary', 'An example');
$page->save('content');
```

Read properties directly or with `get()`. Unknown ordinary reads return `null`; unknown writes throw `WireException`. Assignments normalize values, but native editor validation, purification and dependencies run during form processing: validate application input yourself.

| `FlexValue` method | Result / purpose |
| --- | --- |
| `get($name)` / `set($name, $value)` | Read `mixed`; assign a known property and return `$this` |
| `getUnformatted(string $name)` | Raw typed property value |
| `getArray()` / `rawArray(): array` | Runtime / canonical property array; neither takes a property argument |
| `hasValue(string $name): bool` | Logical content, independent of formatting/visibility |
| `label(string $name): string` | Translated property label |
| `optionLabel(string $name, $option = null): string` | Current or explicit single-choice label |
| `getOptions(string $name): array` | Option key => translated label |
| `definitions(): FlexFieldDefs` | Evaluated definitions for this value |
| `of(?bool $formatted = null): bool` | Read/change formatting; returns previous state |
| `isChanged($name = ''): bool` | Changes including nested values; optionally one direct property |

`hasValue()` treats blank/null/false, unchecked checkboxes and empty collections/NullPages as empty. Other numeric/string zero and whitespace count as content. Hidden content, translations and retained unknown descendants contribute; Row identity alone does not. Unknown property names return false; media existence on disk is not checked. Read helpers do not mark changes.

### Property and option labels

```php
$value = $page->content;
$value->label('appearance');                    // Property label
$value->optionLabel('appearance');              // Current single selection label
$value->optionLabel('appearance', 'featured');  // Explicit option label
$value->getOptions('appearance');               // Key => translated label map
```

Choice values are raw keys, never native Options objects or HTML-encoded labels. Display helpers follow the current language and entity-encode labels when formatted. Unknown labels/options return `''` or `[]`. `optionLabel()` returns `''` for array selections; resolve them one key at a time. Explicit `null` selects the blank key; zero keys are valid.

Helpers also work on Rows and accept type-qualified schema paths such as `blocks.text.appearance`. A schema-path `optionLabel()` needs an explicit key because it identifies no concrete current selection.

### Dates, numbers, and text

Datetime accepts canonical ISO strings, timestamps, numeric timestamp strings or `DateTime` objects. ISO input takes precedence over localized input formats. Blank/null/false/zero becomes `''`. Storage uses `Y-m-d` or `Y-m-d H:i:s` in PHP/site timezone; time survives even without a time editor. Formatted reads use `dateOutputFormat` (current language/default); blank format or `U` returns a timestamp. SQL selectors use ISO dates.

Integer/Float normalize numeric strings, preserve zero and reject non-numbers with `WireException`; blank/null becomes `''`. Float `precision` rounds on assignment/hydration (default `2`; null, blank or negative disables rounding). Invalid dates also throw. Email/URL use native sanitizers and configured IDN/relative-URL settings.

Toggle defaults to keys `0`/`1`/`2` (no/yes/other; enable `useOther` for the third choice), or accepts custom integer options from -128 to 127. `formatType`: `0` key, `1` boolean for keys 0/1 only, `2` label, `3` encoded label without icons. See [formatting and languages](#textformatters).

### Page references

`derefAsPage` determines the types in the [value table](#value-types) in both formatting states. Test `NullPage->id`, not object truthiness. Native `FieldtypePage` handles hydration, filtering and save-time selectable-page validation (`parent_id`, `template_id`, `findPagesSelector`). Referenced Pages must be saved separately.

| Operation | Behavior |
| --- | --- |
| Assign a single reference | Accepts Page/PageArray, ID, path/name or native selector input |
| Assign Page/ID/ID array/string to a multiple reference | **Appends** |
| Assign a `PageArray` to a multiple reference | **Replaces** |
| Clear a multiple reference | `removeAll()` or assign an empty `PageArray`; null/blank does not clear |
| Remove one selected ID | Assign a negative ID **string**, e.g. `$value->references = '-' . $target->id` |
| Submit editor selections | Replaces the selection |

`allowUnpub=false` excludes unpublished references on load; true retains them unformatted. Formatted output filters unpublished Pages by native single/multiple rules; hidden Pages remain available. **Access permissions are not read filters**: call `$target->viewable()` where required.

## Definitions

Define properties with a PHP array, JSON or a native `InputfieldWrapper`. Automatic lookup for `content` uses exactly one of `site/templates/flex-fields/content.php` or `content.json`:

```php
<?php namespace ProcessWire;

return [
    'heading' => [
        'type' => 'text', 'label' => __('Heading'), 'required' => true,
        'textformatters' => ['TextformatterEntities'],
    ],
    'hero' => ['type' => 'image', 'maxFiles' => 1],
];
```

PHP returns a property array or wrapper; JSON uses an object keyed by property name. `type` defaults to `text` and selects a native Inputfield or Flex's `object`/`rows` structures. Labels default from names. Property names begin with a letter/underscore and contain ASCII letters, digits or underscores; `data`, `pages_id` and `count` are reserved.

The module's `definitionsPath` changes the automatic directory relative to templates (default `flex-fields/`). Alternatively, set Field `flexDefinitionFile` to a templates-relative PHP/JSON file, or `flexDefinition` to inline JSON. Two automatic files, or explicit file plus nonempty inline JSON, are errors. Paths must stay inside templates. PHP definitions are trusted executable code: do not expose them to untrusted editors. Changing paths moves no files/data; provide definitions first.

### Evaluation and schema inspection

Definitions run in owning Page context with `$page`, `$field`, `$wire`; `$languages` is null without LanguageSupport. Query discovery runs without a concrete Page, so queryable properties must also be exposed there.

```php
$field = $fields->get('content');
$defs = $field->defs($page); // FlexFieldDefs
$input = $defs->getPropertyInputfield('blocks.text.heading');
$tree = $defs->getInputfields();
```

| Method | Result |
| --- | --- |
| `FlexField::defs(?Page $page = null): FlexFieldDefs` | Definitions; NullPage context when omitted |
| `getPropertyInputfield(string $name): ?Inputfield` | Fresh configured input, or null |
| `getInputfields(): InputfieldWrapper` | Fresh native input tree |

Schema paths include each collection and type, e.g. `sections.section.blocks.text.heading`. Inspect native Inputfields rather than internal metadata arrays. Missing definitions may initially yield an empty field; losing an automatic definition with stored data blocks writes. Invalid definitions block Page Edit saves and retain submitted values for correction.

### Renaming and preserving properties

```php
'heading' => ['type' => 'text', 'renamedFrom' => 'title'],
```

`renamedFrom` accepts one name or an array of old names. Reads resolve old keys; individual saves persist new keys recursively. Equal old/new values merge; conflicts throw `WireException`. Field configuration saves do not migrate data.

**Unknown properties, nested data and owned media survive ordinary saves and copies.** Use [maintenance](#maintenance-apis) for bulk renames or explicit cleanup. Prefer complete schemas and native hidden/locked presentation settings: context-dependent omissions make orphan classification unreliable. Finish migration before relying on renamed selectors or removing `renamedFrom`.

<a id="repeatable-rows-and-native-media"></a>

## Objects

`type => 'object'` declares a fixed child `FlexValue`, rendered as a native fieldset. It has no Row ID/type or add/remove controls. Objects can contain Objects, Rows and media; ordinary `fieldset` children remain flat in the containing scope.

```php
// Fixed object definition.
'link' => [
    'type' => 'object',
    'children' => ['label' => ['type' => 'text'], 'url' => ['type' => 'url']],
],
```

Read/edit `$page->content->link->url`, or assign `$page->content->link = ['url' => '/about/']` with formatting off. **Map assignment replaces the Object and applies defaults to omitted known children**; editing a leaf retains other values. Stored unknown children survive ordinary saves. The Object always exists: use `hasValue('link')` instead of truthiness. Assign plain maps, not raw envelopes.

Formatting, languages, change tracking and renames apply recursively. Names and bare dependencies are local; `parent.`/`root.` address containing/root scopes. Selectors use Object paths (`content.link.label=Contact`); scalar leaves can be sorted. Objects have no `.count`; their child collections do. `FlexRows::find()` accepts Object leaf paths. Objects are unsupported in per-file `customFields`.

## Rows

`rows` returns a `FlexRows` collection of typed `FlexRow` values, **not Repeater Pages**. A nonempty `types` map is required, even for one type:

```php
'blocks' => [
    'type' => 'rows', 'minItems' => 0, 'maxItems' => 30,
    'types' => [
        'text' => [
            'rowTitle' => '#n: {heading}',
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

Type keys start with a lowercase ASCII letter, contain lowercase letters/digits/underscores, and have at most 64 characters; `count` is reserved. Row ID/type are immutable. One type or `maxItems=1` still returns a collection without an implicit Row.

### Presentation and visibility

`rowTitle` is a string (no Closures); a bare property name means `{property}`. Values use escaped plain display labels in the editor's language.

Titles support `{heading}`, fallback `{heading|title}`, type/ancestor labels `{@type}`/`{@path}`, position `#n`, token badges `_{heading}_` and de-emphasized groups `[…]`. Zero counts as a value.

```php
'rowTitle' => '[{@path} › {@type}] #n: _{heading|title}_',
```

Absent/blank titles use the default badge/label/number; an empty custom title falls back to `#n`.

`rowCollapse` uses `FieldtypeFlex::ROW_COLLAPSE_EXISTING` (0, default: existing closed/new open), `ROW_COLLAPSE_ALL` (1), or `ROW_EXPAND_ALL` (3). Use `addRowLabel` for the add-button text. See [Rows examples](examples/07-rows.php).

`minItems`/`maxItems` are nonnegative integers across all types; zero maximum means no extra collection cap. Maxima apply on adding/hydration, minima on save. Hidden Rows count. Error Rows stay open. `rowLazyLoad => true` optionally loads eligible closed scalar-only bodies on opening; unsupported types render eagerly. Unopened values still validate on Save; failed loads block saving. Without JavaScript use `flex_eager=1`. See [lazy-loading eligibility](docs/development.md#lazy-row-eligibility).

```php
$row->rowId();                     // Stable 32-character hex ID
$row->rowType();                   // 'text'
$row->hasProperty('heading');      // Defined even when empty
```

`rowTypeLabel(): string` is **not HTML-encoded**. `hasProperty(string $name): bool` checks the current type's schema. Rows inherit the value/label methods above.

```php
$page->of(false);
$row = $page->content->blocks->first();
$row->setHidden(true);
$page->save('content');
```

`isHidden(): bool` reads the Row's own state; `setHidden(bool $hidden): self` changes it under normal write guards. Hiding a parent hides its subtree without altering child states. Duplication preserves visibility.

Formatted iteration, lookup, type helpers and counts expose only visible Rows recursively. Reacquire the raw field for all Rows:

```php
$allRows = $page->getUnformatted('content')->blocks;
```

Raw exports retain hidden backing Rows even from formatted views. `hasValue()`, limits, maintenance and media ownership include them. SQL predicates/counts and whole-field search exclude hidden subtrees; `include=all` does not change this and there is no all-Row SQL option. Visibility is editorial state, **not access control**.

Editor Remove marks a Row until a successful field save; Undo restores its position and unsaved inputs. Marked Rows do not block leaf validation or count toward saved limits. Hidden unmarked Rows remain editable and validated.

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

| `FlexRows` method | Result / purpose |
| --- | --- |
| `addRow(string $type, array $values = []): FlexRow` | Append with generated ID |
| `getById(string $id): ?FlexRow` | Lookup; null if absent |
| `find($selector): FlexRows` | Selection of existing Row objects in current order |
| `rowsOfType($type): array` / `countOfType($type): int` | Direct Rows / count for a string type |
| `duplicateRow(string $id): FlexRow` | Append independent copy, new descendant IDs/media copies |
| `moveRow(string $id, ?string $before = null): self` | Move before sibling, or to end |
| `removeRow(string $id): self` | Remove; unknown ID is a no-op |
| `rawArray(): array` | Canonical envelope including hidden Rows |
| `of(?bool $formatted = null): bool` | Read/change formatting; returns previous state |

```php
$images = $page->content->blocks->rowsOfType('image');
$count = $page->content->blocks->countOfType('text');
```

Type helpers return `[]`/`0` for unknown type strings. `first()` returns false when empty. Unknown add types raise `FlexDefinitionException`; unknown duplicate/move IDs, formatting violations and limit violations raise `WireException`. Native [[WireArray]] operations retain ownership/identity checks; create new Rows with `addRow()`.

```php
$blocks = $page->content->blocks;
$images = $blocks->find('_type=image');
$galleries = $blocks->find('_type=image, photo.count>=3');
$intro = $blocks->find('_type=text, heading=Introduction, body*=ProcessWire');

// Search a nested collection directly, within this concrete parent row.
$notes = $block->notes->find('_type=note, text*=Sources');
```

`find()` accepts a selector string, native array or `Selectors`, including unsaved values. Conditions match the same direct Row. Paths: `_type`, direct properties, Object leaves and media/child collection `.count`; search child collections directly for child leaves. `_type` addresses metadata, `type` an ordinary property. OR values and prefix negation work. Invalid paths/operators raise `WireException` even on empty collections.

Results share Row objects but have separate membership. Edit selected unformatted Rows normally; change membership on the original collection. Chaining narrows the selection. Formatted results exclude hidden Rows permanently from that selection. Leaf comparisons are unformatted native `Selector::matches()` comparisons: dates use timestamps, and numeric zero can match blank. Counts follow current Row visibility. No type-qualified paths, `@` groups, OR field names, arbitrary traversal, sorting or pagination directives; collections/media without `.count` support only blank checks. See [operator families](#operators-and-matching-semantics).

<a id="nested-repeatables-and-resource-limits"></a>

```php
$page->of(false);
$section = $page->content->sections->addRow('section', ['heading' => 'Intro']);
$block = $section->blocks->addRow('text', ['heading' => 'Hello']);
$block->notes->addRow('note', ['text' => 'A note']);
$page->save('content');
```

The [nested example](examples/07-rows.php) defines `sections → blocks → notes`. In native wrappers use `InputfieldFlexRows`; type `children` accepts arrays or wrappers:

```php
$rows = $modules->get('InputfieldFlexRows');
$rows->name = 'blocks';
$rows->types = [
    'text' => ['children' => ['heading' => ['type' => 'text']]],
];
$inputs->add($rows); // $inputs is the returned InputfieldWrapper
```

See [native Inputfields](examples/02-basic-objects.php) for the wrapper. A Flex field inside a native Repeater item is also supported, independently of its own Rows. Database JSON depth, field budgets and PHP input limits constrain nesting. Truncated editor submissions are rejected rather than silently saved.

## File and Image properties

Flex owns storage; values use ProcessWire-compatible `Pagefile`/`Pageimage` objects. File bytes are not embedded in JSON.

```php
'hero' => ['type' => 'image', 'maxFiles' => 1],
'downloads' => ['type' => 'file', 'extensions' => 'pdf txt zip', 'maxFiles' => 5],
```

Unformatted access always returns `FlexFiles`/`FlexImages`. Formatted access uses property `outputFormat`:

| Mode | Result |
| --- | --- |
| `0` Auto (default) | Single object or null when `maxFiles=1`; otherwise collection |
| `1` Collection | Collection even when empty |
| `2` Single | First object or null, regardless of limit |

```php
// With maxFiles=1, Auto mode, and normal front-end output formatting:
$image = $page->content->hero;
if($image) {
    echo $image->size(1200, 700)->url;
}

$images = $page->content->getUnformatted('hero'); // FlexImages collection
```

`maxFiles=0` means unlimited. Limits also apply on hydration/writes: lowering a maximum below existing content can reject reads/saves rather than prune files. Image resize/crop/focus and native Variations are supported. Deleting a generated variation is immediate and needs no field save; originals follow the staged lifecycle below.

### Adding, saving, and ownership

```php
$page->of(false);
$page->content->downloads->add('/absolute/path/to/document.pdf');
$page->save('content');
```

Imports require readable local paths; remote URLs are rejected. New/replaced originals and renames remain staged until the owning field saves. Ownership belongs to the actual Page/Field, including Repeater items. Cross-owner assignment and Row duplication create independent media copies. Original deletion waits for canonical commit.

| Public operations | Behavior |
| --- | --- |
| Collection iteration, `first()`, `get()`, `add()`, `delete()`/`deleteAll()`, `clone($file)` | Native-style access and mutation with Flex ownership checks |
| `$file->rename($name)` / `$files->rename($file, $name)` | Effective sanitized/collision-safe basename; preserves identity/order/metadata; save field to commit |
| `$file->save()` | Save committed metadata without publishing other pending Flex edits; stale changes fail |
| `$type->saveFile($page, $field, $file, $columns = []): bool` | Select metadata columns; empty list means all supported columns |
| Image `size()`, `width()`, `height()`, `crop()`, `focus()` | Native image operations |

`saveFile()` requires an unformatted owned original and live Page. Columns: `description`, `tags`, `filedata`, `values`, `modified`, `modified_users_id`. New/replaced files require a field save. See [[Pagefiles]], [Pagefile](https://processwire.com/api/ref/pagefile/) and [Pageimage](https://processwire.com/api/ref/pageimage/) for native URLs, filenames, descriptions, tags and image methods.

Settings include `extensions`, `maxFiles`, `maxFilesize` (bytes; zero adds no size limit), image dimensions, descriptions and free tags (`useTags`). File requires extensions; Image defaults to JPG/JPEG/GIF/PNG (configure WebP originals explicitly). Imports enforce path/extension/size/count; editor processing adds native validation.

Unsupported: overwrite, `unzip`, property `useLanguages`, predefined tags, native custom metadata templates and string output. Use `noLang` for descriptions. TinyMCE drag/drop uploads need a regular ProcessWire Image field. Description Textformatters apply after language selection, including variations; absent/empty lists leave text unescaped. Native `description(true)`/`description(true, true)` remain raw exports.

### Per-file custom fields

`customFields` defines metadata with arrays, JSON or native wrappers, without database Fields/Templates:

```php
// Per-file metadata definition.
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
// Existing image in the gallery.
$page->of(false);
$image = $page->content->gallery->first();
$image->copyright = 'Example Photographer';
$image->year = 2026;
$page->save('content');
$page->of(true);
echo $page->content->gallery->first()->copyright;
```

Supported: text/HTML/TinyMCE, Hidden, Email/URL, numbers, choices, Checkbox/Toggle, Datetime, TextTags and Page (Select/AsmSelect only). Ordinary fieldsets/Markup organize inputs. Objects, Rows, nested media and unsupported third-party inputs are rejected. Native names are reserved except `description` and `tags` overrides.

Values follow ordinary Flex typing, formatting, languages, defaults, validation and rename rules. Formatted views are read-only. `getFieldValue($name, false|true|null)` explicitly reads raw/formatted/current-view values; unknown names return null. `setFieldValue($name, $value): bool` returns false for unknown names.

A `description` override edits native description; its language mode follows `noLang`, and its formatter list overrides the media list. A `tags` override edits the native string; choice keys must be unique ignoring case and contain letters/digits/underscore/hyphen. Native tag methods remain available. Bare dependencies address file metadata; `parent.` addresses its containing Object/Row/root and `root.` the Flex root.

Field save or `$file->save()` persists custom values. Selective `saveFile(..., ['values'])` saves the whole custom map; `['description']` leaves pending custom values intact. Metadata edits retain variations; variations read original metadata and reject writes. Removing definitions retains unknown values through saves/copies; reintroducing definitions recovers them. Renames to/from native description/tags are rejected. Maintenance uses schema paths such as `gallery.copyright` or `blocks.image.gallery.copyright`.

Custom values have no SQL selectors, media sorting or loaded collection filters, and do not enter whole-field search (which includes basename, native description and tags).

## Selectors

`$pages->find()` queries saved data and returns **owner Pages**; `FlexRows::find()` filters loaded Rows and has different paths/comparison rules.

```php
$pages->find('content.heading*=Welcome');
$pages->find('content.starts_on>=2026-10-18');
$pages->find('content.blocks.text.heading=Introduction');
$pages->find('content.blocks._type=image');
$pages->find('content.blocks.count>=3');
$pages->find('content.blocks.text.count>0');
```

Use `FIELD.COLLECTION.TYPE.PROPERTY`, including each type for deeper collections. Objects add their property name. Collection/type/media scopes support blank checks and `.count`. SQL `COLLECTION._type` accepts `=`/`!=` with a declared type or blank; unknown types raise `PageFinderSyntaxException`.

### Operators and matching semantics

| Values | Operators |
| --- | --- |
| Numbers, dates, single Page IDs, counts | `=`, `!=`, `>`, `>=`, `<`, `<=` |
| Text, single choices, Checkbox, Toggle, TextTags | `=`, `!=`, `*=`, `%=`, `^=`, `$=`, `%^=`, `%$=`, `~=`, `~|=` |
| Arrays / multiple Page IDs | `=` / `!=` membership |

Separate Row predicates may match different Rows. Prefix conditions with native `@` to require one concrete Row at each shared ancestor scope:

```php
$pages->find('@content.blocks.text.heading=Introduction, @content.blocks.text.body*=ProcessWire');
```

Named groups (`a@content…`, `b@content…`) match independently and may select the same Row. Unprefixed conditions remain independent.

```php
// Match one parent and one child; shared scopes select concrete Rows.
$pages->find('@content.sections.section.heading=Introduction, '
    . '@content.sections.section.blocks.text.heading=Overview, '
    . '@content.sections.section.blocks.text.body*=ProcessWire');
```

Each grouped collection requires one type. Grouped paths support leaves, media counts and child collection blank/count tests. Conflicting types, `_type`, counts of the grouped Row itself, OR field names, sub-selectors and alternate operators are rejected. Prefix negation, negative operators and OR values apply within the concrete Row. To exclude owners matching an entire group:

```php
$excluded = $pages->findIDs('@content.blocks.text.heading=Introduction, '
    . '@content.blocks.text.body*=ProcessWire');
$excluded = implode('|', $excluded) ?: '0';
$matches = $pages->find("id!=$excluded");
```

This uses two queries and materializes IDs; `0` handles an empty exclusion. Quoted Flex-specific condition strings are unsupported.

| Query | Meaning |
| --- | --- |
| Global `leaf!=value` | No Row at that path matches value; missing paths match |
| Global `leaf=""` / `leaf!=""` | No populated leaf / at least one populated leaf |
| Grouped leaf test | Applies to the selected concrete Row; positive matching requires an actual Row |
| Nested `.count` | Total descendants outside `@`; descendants of the selected parent inside `@`; missing means zero |

Zero is populated, not blank. Date ranges exclude blank/missing dates. Scalar nonblank inequality includes missing values; Checkbox `=0` requires stored zero, unlike native unchecked-default matching. Use blank tests for missing properties/storage.

Media supports blank/count tests, not filenames/metadata. Whole-field `content*=Welcome` searches flattened values, excluding schema/identity/type metadata and hidden subtrees. JSON databases may reorder object properties: **do not rely on cross-property phrase/order matching**. Text follows database collation; `~=` means all words, `~|=` any word. Escape dynamic selector values with native helpers or use selector arrays.

Sort scalar leaves and root collection/media counts (`sort=content.blocks.count`), including fixed Object paths. Unsupported: repeated leaf/multi-selection/type-specific count sorting, arbitrary JSON paths and advanced fuzzy/full-text operators on Flex JSON text. Invalid paths/operators raise `PageFinderSyntaxException`.

### Root Page-reference traversal

Query one native target Field through a root Page reference:

```php
$pages->find('recipe.ingredient.title=Flour');
$pages->find('recipe.ingredients.title~=Flour'); // multiple Page references
$pages->find('recipe.ingredient.price>=2.5');    // native numeric target field
```

Path: `FLEX_FIELD.PAGE_PROPERTY.TARGET_FIELD`. Targets: native Text/Textarea/PageTitle (including language variants), Email, URL, Integer, Float, Checkbox, Toggle, Datetime, Options and Page. Native PageFinder supplies target operators/language/blank semantics; third-party subclasses are not automatically supported.

- Positive matching requires a matching reference; `ingredient.title!=Flour` means no reference matches `title=Flour`. Separate conditions may match different Pages.
- `ingredient.title=""` requires a reference with blank title; `ingredient.title!=""` also includes empty reference collections. Test the reference itself with `ingredient=""`/`!=""`.
- Native selection restrictions apply; target matching defaults to `include=all`, including hidden/unpublished/access-restricted Pages. This is **not authorization**.
- Unsupported: native properties (`id`, `name`, `path`), File/Image/compound targets, more hops, traversal from Rows, target sorting, same-target `@`, prefix negation and multi-dot OR-field traversal. Ordinary `!=` and OR values work.

Queries materialize target IDs without the editor's 500-option cap. Target indexes help, but Flex references have no dedicated property index; large sets/scans can be expensive. Target edits affect subsequent queries without resaving owners.

## Inputfield settings

Definitions reuse native [[Inputfield]] settings, not Fieldtype storage. See the installed input's PHPDoc and [standalone examples](examples/README.md).

| Setting | Purpose / values |
| --- | --- |
| `label`, `description`, `notes` | Editor text |
| `required`, `columnWidth`, `collapsed` | Native validation/layout |
| `value`, `options` | Missing-key default; choice key/label map |
| `showIf`, `requiredIf` | Native conditions; `requiredIf` also needs `required => true` |
| `useLanguages` | `false` (default), `true`, `'auto'` |
| `derefAsPage` | `0` PageArray, `1` Page/false, `2` Page/NullPage |
| `dateInputFormat`, `dateOutputFormat` | Native date parsing / formatted output |
| `inheritField` | Settings from an existing same-Inputfield-type Field |
| `textformatters` | Ordered installed formatter module names; `[]` disables |

### Dependency contexts

| Reference | Source input |
| --- | --- |
| `enabled=1` | Current Flex scope |
| `parent.enabled=1` | Direct containing scope; invalid at root |
| `root.enabled=1` | This Flex field's root |
| `page.enabled=1` | Owning Page (Repeater item where applicable) |
| `forpage.enabled=1` | Main Page being edited |

```php
// Inside a nested Row; both conditions must match.
'details' => [
    'type' => 'textarea',
    'showIf' => 'parent.show_details=1, forpage.feature_enabled=1',
    'required' => true,
    'requiredIf' => 'root.details_required=1',
],
```

This needs the named properties in their respective scopes and a native `feature_enabled` Checkbox on the main Page. Conditions use submitted native inputs; ordinary fieldsets add no scope. One property name plus native `.count` is supported; arbitrary traversal is not. External sources must be rendered native value inputs, not Rows/media/compound Flex fields. Missing/unsupported sources are definition errors. Conditions do not replace permissions or validation.

```php
'body' => [
    'type' => 'TinyMCE', 'inheritField' => 'rich_text_source', 'label' => __('Body'),
],
```

`inheritField` copies same-type Inputfield settings, compatible Text/File textformatters and Textarea `contentType`/`htmlOptions`; local settings override, including an explicit empty formatter list. TinyMCE `settingsField` is separate.

### Textformatters

Text-valued inputs (including TinyMCE/Email/URL/custom scalar inputs) and media descriptions support ordered native formatters after language resolution/fallback. Absent/empty lists leave text **unescaped**; use `TextformatterEntities` for HTML text/attributes. Non-text/non-media formatter settings are ignored. Repeated reads start raw without altering canonical data or change tracking.

Formatters receive the real Page and a transient Field named `<flex_field>_<property>` without a database ID. Formatters requiring stored Fields or `$page->{$field->name}` need Flex-aware integration.

Native URL `addRoot` and Textarea output options apply. `contentType >= FieldtypeTextarea::contentTypeHTML` enables native HTML URL sleep/wakeup; `htmlOptions => [FieldtypeTextarea::htmlLinkAbstract]` tracks outgoing links across moves/renames. This covers languages, hidden/nested Rows and custom media HTML independently of formatting. TinyMCE alone does not enable it. See [compatibility limits](#limits-of-native-compatibility) for backlinks.

### Language modes

`useLanguages => true` requires LanguageSupport and LanguageSupportFields; `'auto'` enables multilingual Text/Textarea subclasses (including TinyMCE) when both exist, otherwise plain strings. False stays plain. Multilingual scalar assignment updates the current user's language, retaining others; template `noLang` uses default. Enabling languages turns stored scalar text into the default-language value.

Switching to plain mode reads default-language text. **Saving then replaces the language map and discards other translations**; reads preserve them. Before uninstalling LanguageSupport, save a Flex field to remember the default-language ID. Without a live Languages API or remembered ID, old maps cannot be read as plain text. See [language examples](examples/03-text.php).

### Language Alternate Fields

Supports ProcessWire Language Alternate Fields, e.g. `image` / `image_de`. With output formatting enabled, `$page->content->image` resolves the current language and falls back to `image` when the alternate is empty.

## Field settings

These configure the whole Flex Field:

| Setting | Default | Purpose |
| --- | --- | --- |
| `flexDefinition` | Empty | Inline JSON |
| `flexDefinitionFile` | Empty | Templates-relative PHP/JSON path |
| `flexHideWrap` | `false` | Hide outer editor heading |
| `flexMaxDepth` | `0` | Additional nesting cap; zero disables |
| `flexMaxRows` | `2000` | Total descendant Rows; zero disables |
| `flexMaxBytes` | `8388608` | Canonical JSON bytes; zero disables |

Database JSON depth and PHP/resource limits still apply with budgets disabled.

## Maintenance APIs

Ordinary field saves, metadata updates, cloning and deletion maintain canonical data, selector projections and media ownership automatically. No routine rebuild or collector calls are needed.

```php
$type = $modules->get('FieldtypeFlex');

$type->repairClonedFiles($copy);
```

`repairClonedFiles(Page $copy): int` retries registered missing media copies on a saved cloned Page and returns the repaired count. Selective `saveFile()` is covered under [media ownership](#adding-saving-and-ownership).

### Renames and unknown-property cleanup

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

| Method on `FieldtypeFlex` | Purpose |
| --- | --- |
| `checkRenames(Field $field, int $afterPageId = 0, int $limit = 100): array` | Preview rename batch |
| `applyRenames(Field $field, array $preview): array` | Apply checked batch |
| `listOrphans(Field $field, int $afterPageId = 0, int $limit = 100): array` | Read-only inventory |
| `cleanupProperties(Field $field, array $paths, array $preview, bool $apply = false): array` | Preview/apply explicit unknown paths |

**Authorize PHP callers yourself.** Batches include hidden/unpublished Pages and Repeater items; limits are 1–1000. Cleanup selects type-qualified schema paths, never Row IDs. Apply locks/revalidates stored data and schema and rejects stale previews; missing definitions, unknown types and rename conflicts are reported without silent pruning. Updates are atomic per owner and respect existing transactions; earlier successful owners remain applied after later failures.

Results include `checked`, `changed`, `unchanged`, `wouldChange`, `properties`, `renames`, `conflicts`, `errors`, opaque `pages` preview tokens and cursors. Continue successful incomplete batches using `lastPageId`; repair failures and resume from `resumeAfterPageId` (`retryPageIds` identifies failures). `complete` means the selected range was exhausted; `finished` additionally requires success (and no skipped checks for diagnostics). Scans are not global snapshots. Before removing `renamedFrom`, complete all batches and repeat a successful scan from zero with no pending renames.

Superusers can preview/apply renames and selected cleanup under **Field → Details → Data maintenance**. Changing cleanup paths invalidates the preview. Ordinary configuration saves never migrate data.

### Native CLI and consistency checks

Run from the ProcessWire root on a core supporting native CLI dispatch; PHP/Admin maintenance also works without it. Use `php index.php flex --help` for complete options.

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

`schema` inspects definitions/query paths; `lint` reports nonblocking definition diagnostics. `status` inspects storage/versions and media journal counts; pending counts are not failures. `check` reads stored canonical data, budgets, schemas, Row/search projections and media ownership/original existence without normalizing or repairing. Unknown properties are allowed; pending renames warn. Search checks verify content occurrence counts, **not phrase/property order**; Row checks verify identities/order/payloads.

`--page=ID` selects an exact assigned owner (including hidden/Repeater owners), excluding `--all`, `--after` and `--limit`. Without `--all`, operations scan one bounded batch; `--all` streams batches and stops on errors/skipped checks. Resume with `--after=resumeAfterPageId`. Rename/cleanup preview unless `--apply` is supplied; apply creates a fresh checked preview, not a prior terminal approval.

`--json` produces JSON (JSON-Lines batches/summary with `--all`). Check exit status: 0 success/warnings, 1 known errors, 2 request/adapter failure, 3 incomplete check. See [operational details](docs/development.md#cli-operation-and-report-contract) for report fields, skipped checks and selection rules.

## Notes and limitations

<a id="limits-of-native-compatibility"></a>

- Requires ProcessWire 3.0.259+, PHP 8.1+, MySQL 8.0+/MariaDB 10.11+ with InnoDB; SQLite/PostgreSQL are unsupported.
- Native Page-version save/restore excludes Flex values. Fieldtype conversion is limited to Flex; remove saved Flex fields before uninstalling.
- Native Repeater may contain Flex; Flex/Repeater/RepeaterMatrix/PageTable/Password cannot be Flex properties. Inputs needing their own Fieldtype persistence require explicit integration. TinyMCE is supported; CKEditor is not established as fully compatible.
- HTML URL/link transformations do not include native image markup QA. Automatic `$target->links()` discovery excludes Flex. Explicit-field `Page::links()` is core-version-dependent; `MarkupQA::findLinks()` can miss hidden-Row/custom media HTML. Neither provides complete Flex backlink discovery. See [compatibility regression coverage](docs/development.md#html-link-compatibility).
- File cleanup is conservative and eventual. Relevant writes and process completion attempt bounded work; later PHP executions retry pending/expired staging (six hours). No execution means no progress. Referenced/unknown files remain protected; outer transactions postpone cleanup without implicit commits. Whole-Page permanent deletion and native variation deletion retain immediate native filesystem behavior. See [automatic media work](docs/architecture.md#automatic-media-work).
- Use the APIs documented here and referenced native collections. Schema metadata, transformers, view factories, `storage()`/`media()`, owner/address helpers and editor hooks are internal despite public PHP visibility.

Retrieve this reference through native `php index.php docs get FieldtypeFlex` or WireApiDocs where supported; `[[ClassName]]` references intentionally link native documentation.

Related: [Examples](examples/README.md) · [Architecture](docs/architecture.md) · [Development](docs/development.md) · [[WireData]] · [[WireArray]] · [[Inputfield]] · [[InputfieldForm]].

**Module source:** [FieldtypeFlex.module.php](FieldtypeFlex.module.php).
