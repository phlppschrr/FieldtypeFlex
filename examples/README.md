# FieldtypeFlex configuration examples

Each example is a standalone Flex field definition. Copy it to `site/templates/flex-fields/FIELDNAME.php` (or `.json`), replacing `FIELDNAME` with your field name.

You can also combine individual properties from different examples. Keep property names unique within their scope.

## Schema basics

A definition is a PHP array, JSON object or native `InputfieldWrapper`. Each property has a name and a `type` (`text` by default).

```php
<?php namespace ProcessWire;

return [
    'heading' => ['type' => 'text'],
    'link' => [
        'type' => 'object',
        'children' => [
            'url' => ['type' => 'url'],
        ],
    ],
    'blocks' => [
        'type' => 'rows',
        'types' => [
            'text' => [
                'children' => [
                    'body' => ['type' => 'TinyMCE'],
                ],
            ],
        ],
    ],
];
```

- **Native inputs** use regular ProcessWire Inputfield types and settings.
- **Objects** group fixed properties under a separate value scope.
- **Rows** define repeatable collections with named types and their own properties.
- **Fieldsets** group inputs visually without introducing a value scope.

## Configuration cheat sheet

### Native Inputfield settings

Use the settings supported by the selected ProcessWire Inputfield, including:

| Purpose | Settings |
| --- | --- |
| Labels and help | `label`, `description`, `notes` |
| Validation | `required`, `requiredIf` |
| Layout and visibility | `columnWidth`, `collapsed`, `showIf` |
| Defaults and choices | `value`, `options` |
| Input-specific | `rows`, `toolbar`, `extensions`, `maxFiles`, etc. |

### Additional Inputfield settings

| Applies to | Setting | Purpose |
| --- | --- | --- |
| Text-valued inputs, File/Image descriptions | `textformatters` | Ordered list of native Textformatter modules |
| Text, Textarea, TinyMCE | `useLanguages` | Multilingual mode: `false`, `true`, `'auto'` |
| Textarea, TinyMCE | `contentType`, `htmlOptions` | HTML and link handling |
| Page | `derefAsPage` | Return PageArray, Page/false or Page/NullPage |
| File, Image | `outputFormat` | Auto (`0`), collection (`1`), single (`2`) |
| Datetime | `dateOutputFormat` | Formatted date output |
| Toggle | `formatType` | Integer, boolean or label output |

`useLanguages => 'auto'` enables multilingual text when LanguageSupport and LanguageSupportFields are installed, otherwise plain text. `true` requires both modules.

### Flex-specific settings

| Setting | Applies to | Purpose |
| --- | --- | --- |
| `inheritField` | Compatible Inputfields | Inherit settings from an existing ProcessWire Field |
| `renamedFrom` | Stored properties | Map previous property names to a new name |
| `customFields` | File, Image | Additional properties for individual files and images |

`inheritField` requires a matching Inputfield type. Local settings override inherited settings; stored values are not copied.

### Objects and Rows

| Setting | Purpose |
| --- | --- |
| `type => 'object'` | Fixed nested properties |
| `type => 'rows'` | Repeatable typed collection |
| `children` | Properties inside Objects or Row types |
| `types` | Available Row types |
| `rowTitle` | Dynamic titles using `{heading}`, `{@type}`, `#n`, etc. |
| `rowCollapse` | Initial collapsed/expanded state |
| `rowLazyLoad` | Lazy-load eligible collapsed Rows |
| `minItems`, `maxItems` | Row count limits |

### Conditional inputs

`showIf` and `requiredIf` support native ProcessWire conditions with additional scopes:

| Example | Scope |
| --- | --- |
| `enabled=1` | Current scope |
| `parent.enabled=1` | Directly containing Flex scope |
| `root.enabled=1` | Root Flex value |
| `page.enabled=1` | Owning Page |
| `forpage.enabled=1` | Main editor Page |

`requiredIf` also needs `required => true`.

## Examples

| Example | Demonstrates | Requirements / adjustments |
| --- | --- | --- |
| [Basic JSON](01-basic.json) | Simple properties in JSON | None |
| [Basic PHP](02-basic.php) | Same definition in PHP | None |
| [Native Inputfields](02-basic-objects.php) | `InputfieldWrapper` definitions | None |
| [Text and formatting](03-text.php) | Text, TinyMCE, Textformatters, languages | TinyMCE, LanguageSupport modules |
| [Numbers and dates](04-numbers-dates.php) | Integer, Float, Toggle, Datetime | None |
| [Choices](05-choices.php) | Select, Radios, Checkboxes, TextTags | None |
| [References and media](06-references-media.php) | Page references, files, images, custom metadata | Adapt Page selectors; TinyMCE |
| [Typed and nested Rows](07-rows.php) | Mixed blocks, nesting, dependencies, Row titles | TinyMCE, LanguageSupport modules |
| [Advanced settings](08-advanced.php) | Fieldsets, Objects, inheritance, renames | Adapt source Fields; TinyMCE, LanguageSupport modules |

Some examples need existing Fields or installed modules. Check the comments in each file.

## Documentation

The [API reference](../API.md) covers supported settings, value types, runtime methods and compatibility limits.

The [main README](../README.md#quick-start) provides a Quick Start with template access and selectors.
