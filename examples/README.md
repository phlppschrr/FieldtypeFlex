# FieldtypeFlex configuration examples

Copy one of these definitions to `site/templates/flex-fields/FIELDNAME.php`
(or `.json`), replacing `FIELDNAME` with your Flex field's name. You can also set
an explicit definition path relative to `site/templates` in the field settings.

PHP arrays support translations, composition, conditional logic and native PHP
constants. JSON works for static definitions. An InputfieldWrapper lets you build
the same schema with native ProcessWire Inputfield objects. Leaf definitions can
also be used inside a typed Row's `children` array.

| File | Demonstrates | Requirements |
| --- | --- | --- |
| [01-basic.json](01-basic.json) | A required title and an email input in two columns | Core inputs |
| [02-basic.php](02-basic.php) | The same two inputs as a PHP array | Core inputs |
| [02-basic-objects.php](02-basic-objects.php) | The same two inputs as an InputfieldWrapper | Core inputs |
| [03-text.php](03-text.php) | Text, Textarea, TinyMCE, URL, Email, Hidden, Icon, formatters and optional languages | InputfieldTinyMCE |
| [04-numbers-dates.php](04-numbers-dates.php) | Integer, Float, Checkbox, Toggle and Datetime | Core inputs |
| [05-choices.php](05-choices.php) | Select, Radios, Checkboxes, SelectMultiple, AsmSelect and TextTags | Core inputs |
| [06-references-media.php](06-references-media.php) | Single/multiple Page references, images and downloads | Adjust Page selectors to your site |
| [07-advanced.php](07-advanced.php) | Dependencies, fieldsets, language support, renames and inherited input settings | A Textarea Field named `rich_text_source` using TinyMCE and a Datetime Field named `date_input_source`, or adapt `inheritField` |
| [08-rows.php](08-rows.php) | Typed content blocks, nested Rows, media and row titles | InputfieldTinyMCE |
| [09-kitchen-sink.php](09-kitchen-sink.php) | Composition of examples 03–08, with choice/number schemas reused inside Rows | The included files and their prerequisites |

## Settings to adapt

- `columnWidth` demonstrates native editor layouts using 33/34, 50 and 100 percent.
- `showIf` and `requiredIf` reference local properties by bare name. Use `parent.`
  for the containing Flex scope, `root.` for this Flex field's root, `page.` for
  the owning Page's native inputs and ProcessWire's `forpage.` for the main
  editor Page. See [dependency contexts](../API.md#dependency-contexts).
- `textformatters => ['TextformatterEntities']` escapes text for HTML text content.
  An absent or empty formatter list leaves text unescaped. Apply escaping for the
  actual output context; TinyMCE values contain HTML markup. File/Image descriptions
  use only configured or inherited formatters; examples 06 and 08 explicitly select Entities, including nested media.
- `useLanguages => 'auto'` enables multilingual text when LanguageSupport and
  LanguageSupportFields are available, and plain text otherwise.
- `findPagesSelector` limits selectable Page references. Adjust it to your site's
  Page tree. Create the source Fields required by `inheritField` or use existing
  Fields with the matching Inputfield type.

## Combine definitions

[09-kitchen-sink.php](09-kitchen-sink.php) loads the PHP arrays from examples 03–08
using `require` and array unpacking. Copy all included files into the same directory.
Their root property names are distinct, so combining them does not overwrite keys.
The `variants` collection also loads the choice and number definitions as separate
Row types; each Row type has its own property scope.

[manifest.php](manifest.php) supplies metadata to the read-only example catalog in
field settings. It is not a field definition and does not need to be copied.

For runtime values, selectors and the complete settings contract, see
[API.md](../API.md). For a complete definition and template output, see the
[README quick start](../README.md#quick-start).
