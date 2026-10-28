# FieldtypeFlex configuration examples

These files are intentionally small, copyable reference definitions for developers
and coding agents. The same native ProcessWire inputs work within normal Flex
fields and inside typed Rows. **PHP arrays are recommended**: they support
translations, composition, conditional logic and native PHP constants. Plain
JSON is also supported for static definitions (no comments or PHP expressions).

| File | Purpose | Requirements |
| --- | --- | --- |
| `01-basic.json` | Minimal, valid JSON | None |
| `02-basic.php` | Same properties using a recommended PHP array | None |
| `02-basic-objects.php` | Same properties using an InputfieldWrapper | None |
| `03-text.php` | Text, Textarea, TinyMCE, URL, Email, Hidden, Icon; formatters | TinyMCE |
| `04-numbers-dates.php` | Integer, Float, Checkbox, Toggle, Datetime | Core inputs |
| `05-choices.php` | Select, Radios, Checkboxes, multi-select, AsmSelect, TextTags | Core inputs |
| `06-references-media.php` | Page/PageArray, Image, File; file limits | Core inputs |
| `07-advanced.php` | Dependencies, multilingual auto mode, fieldset, renames and native field inheritance | TinyMCE; existing Datetime `date_input_source` and TinyMCE `rich_text_source` Fields |
| `08-rows.php` | Mixed typed Rows, nested Rows, media and row headings | TinyMCE |
| `09-kitchen-sink.php` | Composes 03–08, including reuse of choices/numbers inside Rows | Requirements of all included files |

Use the automatic location `site/templates/flex-fields/FIELDNAME.php` or
`.json`, or configure an explicit path relative to `site/templates`.
Copy dependencies beside `09-kitchen-sink.php`. Do not copy the manifest as
a definition; it is only metadata for the read-only example catalog.

## Conventions

- Unique root property keys are intentional: `text_*`, `number_*`,
  `choice_*`, `ref_*`, `media_*` and `advanced_*` can be composed without
  silent overwrites. Row children have their own scope.
- `columnWidth` illustrates 33/34, 50 and 100 percent native editor layouts.
  `showIf` and `requiredIf` refer to siblings in the same property scope.
- Without `TextformatterEntities` text output is **not** HTML-escaped.
  Escape for the actual HTML context. Rich HTML is intentionally different.
- Page reference selectors and inherited editor settings depend on the site's
  actual installed Fields and Pages. Adjust those examples to your project.
- `InputfieldWrapper` definitions are currently supported. There is no
  decision to remove them; this third basic example deliberately retains coverage.

## Composition and a possible future import directive

`09-kitchen-sink.php` uses standard PHP array unpacking and `require`.
This works today without adding a new Flex-specific configuration syntax. A
future `add` or include directive could simplify imports in both the root
schema and Row `children`; this is **only a proposal**, not a supported setting.
Do not generate `add: filename` definitions until it has an explicit, tested
contract for file resolution, duplicate keys, nesting and cycle prevention.

For the runtime API and exact supported settings, see [API.md](../API.md).
