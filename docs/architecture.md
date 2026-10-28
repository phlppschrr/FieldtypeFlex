# Architecture

This document records the current persistence, schema and ProcessWire integration decisions for FieldtypeFlex. User-facing examples and method signatures are in [API.md](../API.md).

## Runtime boundaries

The module uses ProcessWire's Field, Page, Inputfield, Pagefile/Pageimage and selector APIs. The Flex field is atomic in Page Edit: native Inputfield children are scoped and validated by ProcessWire, while Flex owns the complete submitted value and commits it through the Fieldtype lifecycle.

The implementation has four responsibilities:

- Definition loading and validation build a page-aware schema and native Inputfield tree.
- FlexValue hydrates stored properties, tracks nested mutations and creates formatted output views.
- FlexRows represents typed row collections with stable IDs and immutable row types.
- Storage, query and media services persist canonical values, derive selector projections and manage owned files.

Keep native Inputfield processing as the input pipeline. A property Inputfield supplies UI, validation and dependencies; it does not supply an independent storage layer or automatically inherit the source Fieldtype's formatters.

## Definition and schema model

Definitions are evaluated for the owning Page context. Dynamic PHP definitions are not globally cached. Query discovery has no individual Page, so a dynamic definition still needs to expose queryable properties there. Metadata Inputfields and returned input trees are fresh instances.

`useLanguages` accepts false (single-language), true (required multilingual
support), or `'auto'` (optional multilingual text). Definition loading resolves
auto using the owning instance's Languages API and installed
LanguageSupportFields module, before building native inputs or exposing schemas.
Auto applies to Text/Textarea inputs and their subclasses, including TinyMCE.
When text resolves to single-language mode, hydration exposes the default-language
entry of existing language maps. The active Languages API supplies its ID; without
it, the ID remembered in FieldtypeFlex module configuration is used. Successful
Flex field writes with an active Languages API refresh that installation metadata.
Reads never write metadata or canonical JSON. The next field save persists scalar
text, also within nested rows. There is no bulk migration or uninstall hook.

Ordinary fieldsets organize the editor and flatten their leaf properties into the current schema. Every repeatable fieldset has a types map. Each type has its own children schema, and names can repeat across different types. A typed path such as sections.section.blocks.text.heading identifies one leaf. There is no union schema or implicit row type.

PHP, JSON and native InputfieldWrapper definitions use the same tree model. PHP executes as trusted code inside the owning ProcessWire API context. Definition errors keep field and source context. In Page Edit, a missing or invalid definition blocks save when existing data is at risk and preserves submitted values for correction.

## Canonical data and query projections

The JSON field value is the source of truth. Scalar leaves and native IDs are stored in its unformatted representation. Repeatable sets use a versioned rows:1 envelope containing ordered rows with an ID, type and values. File/Image values use versioned files:1 or images:1 envelopes containing registered asset IDs and native metadata.

A separate InnoDB row projection supports typed row selectors and counts. Whole-field search uses flattened property values, not schema names, row IDs or row type markers. Projection updates and canonical JSON saves share the field transaction or savepoint. The projection can be rebuilt from canonical data with rebuildRowIndex(); it is not a second source of truth.

Selector values are bound parameters. Property names, operators, type keys and JSON paths are validated against the active schema before SQL is built. Blank, negative and count queries account for absent fields and row ancestors. Same-row expressions correlate every condition to one concrete row and its descendants.

## Values and output formatting

Persisted values are never entity encoded or converted to display-date strings. Output formatting follows the owning Page and is a cloned view. Text, choice labels and dates are formatted when read; rich HTML follows the native TinyMCE/Inputfield pipeline. Page-reference access follows derefAsPage; unformatted storage retains IDs. Nested language and PageArray objects participate in change tracking and are rebound to the owning Page after cloning or assignment.

flexPrune=true removes properties omitted by the current schema on save. When dynamic definitions require omitted values to survive, flexPrune=false keeps unknown keys and nested values. renamedFrom maps an old key to a new one, and differing values in both keys cause an error rather than data loss.

## Media lifecycle

Media records use native Pagefile/Pageimage subclasses and remain owned by the actual ProcessWire Page and Flex Field, including inside native Repeater items. The canonical JSON stores registered asset identities; ownership records and the derived row projection support lifecycle and queries.

Uploads, replacements and destructive image edits use staged copies. PageEdit validates and processes the addressed native Inputfield, then saves field data and asset ownership transactionally. Cleanup removes only registered originals and variations that are no longer referenced, after the canonical save commits. Unknown files are retained. A failed page clone can be repaired from recorded source ownership with repairClonedFiles().

Page-version save/restore, native overwrite mode, custom media metadata templates, predefined tags and the native variation management dialog are outside the current media contract.

## Resource and compatibility limits

Supported databases are MySQL 8.0+ and MariaDB 10.11+ with InnoDB. Flex requires ProcessWire 3.0.259+ and PHP 8.1+. Query support uses MySQL/MariaDB JSON functions.

Database JSON nesting limits apply to the entire value, not just repeatable levels. MariaDB accepts up to 32 JSON levels and MySQL up to 100; nested repeatables, media and language values consume these levels differently. The effective depth is schema-dependent. Flex also enforces configurable row-count, byte-size and optional depth budgets, and refuses incomplete PageEdit posts before saving.

Definitions that use Fieldtype-specific persistence, arbitrary JSON paths, nested Page-reference traversal, sorting repeated values and native page-version restore need additional explicit integration before they can be supported. SQLite and PostgreSQL are not supported.
