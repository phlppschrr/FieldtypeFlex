# Architecture

This document records the current persistence, schema and ProcessWire integration decisions for FieldtypeFlex. User-facing examples and method signatures are in [API.md](../API.md).

## Runtime boundaries

The module uses ProcessWire's Field, Page, Inputfield, Pagefile/Pageimage and selector APIs. The Flex field is atomic in Page Edit: native Inputfield children are scoped and validated by ProcessWire, while Flex owns the complete submitted value and commits it through the Fieldtype lifecycle.

The implementation has four responsibilities:

- Definition loading and validation build a page-aware schema and native Inputfield tree.
- FlexValue hydrates stored properties, tracks nested mutations and creates formatted output views.
- FlexRows represents typed row collections with stable IDs and immutable row types.
- Storage, query and media services persist canonical values, derive selector projections and manage owned files.

Keep native Inputfield processing as the input pipeline. A property Inputfield supplies UI, validation and dependencies; it does not supply an independent storage layer. For text-valued inputs, `inheritField` also copies the source Text Fieldtype’s formatter list; explicit lists override it completely.

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

Ordinary fieldsets organize the editor and flatten their leaf properties into the current schema. The explicit Flex structure type `rows` has a mandatory types map. It resolves
directly to `InputfieldFlexRows`, also used to declare collections in native
wrapper trees. Ordinary `fieldset` definitions resolve to `InputfieldFieldset`
and never introduce a collection. Editor inputs are fresh copies with runtime
row context attached; no fieldset-to-rows adapter is needed. Each type has its own children schema, and names can repeat across different types. A typed path such as sections.section.blocks.text.heading identifies one leaf. There is no union schema or implicit row type.

PHP, JSON and native InputfieldWrapper definitions use the same tree model. PHP executes as trusted code inside the owning ProcessWire API context. Definition errors keep field and source context. In Page Edit, a missing or invalid definition blocks save when existing data is at risk and preserves submitted values for correction.

## Canonical data and query projections

The JSON field value is the source of truth. Scalar leaves and native IDs are stored in its unformatted representation. Repeatable sets use a versioned rows:1 envelope containing ordered rows with an ID, type and values. File/Image values use versioned files:1 or images:1 envelopes containing registered asset IDs and native metadata.

A separate InnoDB row projection supports typed row selectors and counts. Whole-field search uses flattened property values, not schema names, row IDs or row type markers. Projection updates and canonical JSON saves share the field transaction or savepoint. The projection can be rebuilt from canonical data with rebuildRowIndex(); it is not a second source of truth.

Selector values are bound parameters. Property names, operators, type keys and JSON paths are validated against the active schema before SQL is built. Blank, negative and count queries account for absent fields and row ancestors. Same-row expressions correlate every condition to one concrete row and its descendants.

## Values and output formatting

Persisted values are never entity encoded or converted to display-date strings. Output formatting follows the owning Page and is a cloned view. Text, choice labels and dates are formatted when read; date runtime reads are Unix timestamps while canonical JSON remains ISO strings. Raw multilingual values are language objects; formatted values are current-language strings. Rich HTML follows the native TinyMCE/Inputfield pipeline. Explicit or inherited `textformatters` lists delegate formatted text reads to native FieldtypeText/Textarea/Email/URL with a transient property Field and the real owning Page. These lists, including empty lists, replace Flex’s automatic value encoding; labels retain `flexEntityEncode`. Missing lists retain existing encoding defaults. Formatter output is never cached into canonical values. Native Textarea output HTML options apply, but storage-time markup QA/link abstraction is not introduced. Page-reference access follows native derefAsPage, allowUnpub hydration and formatting. Native FieldtypePage validates canonical reference IDs during serialization; a transient Field carries each property’s native settings without creating a second database field. Direct multi-reference assignments append unless a PageArray replaces the collection; editor submissions replace the selection. Nested language and PageArray objects participate in change tracking and are rebound to the owning Page after cloning or assignment.

flexPrune=true removes properties omitted by the current schema on save. When dynamic definitions require omitted values to survive, flexPrune=false keeps unknown keys and nested values. renamedFrom maps an old key to a new one, and differing values in both keys cause an error rather than data loss.

## Media lifecycle

Media records use native Pagefile/Pageimage subclasses and remain owned by the actual ProcessWire Page and Flex Field, including inside native Repeater items. The canonical JSON stores registered asset identities; ownership records and the derived row projection support lifecycle and queries.

Uploads, replacements and changes to original images use staged copies. Generated derivatives use the native variation-management dialog and may be deleted immediately without a field save; original ownership and canonical data remain unchanged. Flex validates field/property access, draft ownership and freshness, image-edit permission and CSRF for deletion. Native form actions and post-deletion redirects retain the draft and parent variation counter. PageEdit validates and processes the addressed native Inputfield, then saves field data and asset ownership transactionally. Cleanup removes only registered originals and variations that are no longer referenced, after the canonical save commits. Unknown files are retained. A failed page clone can be repaired from recorded source ownership with repairClonedFiles().

Page-version save/restore, native overwrite mode, custom media metadata templates and predefined tags are outside the current media contract.

Rename separates logical identity from physical staging. A free native-sanitized
basename is used exactly; page files, Flex reservations and target image variations
cause a collision suffix. A separate staged asset record holds the copied original
and variations and records the stable source ID and baseline basename. During the
canonical field transaction, validated staging receives that stable ID; the former
physical record receives a cleanup ID and becomes pending deletion. Rollback restores
both ownership records and canonical names. Superseded unsaved staging expires through
the normal conservative collector. Upload, replacement and duplication naming is
unchanged. No schema or canonical-envelope change is required.

## Resource and compatibility limits

Supported databases are MySQL 8.0+ and MariaDB 10.11+ with InnoDB. Flex requires ProcessWire 3.0.259+ and PHP 8.1+. Query support uses MySQL/MariaDB JSON functions.

Database JSON nesting limits apply to the entire value, not just repeatable levels. MariaDB accepts up to 32 JSON levels and MySQL up to 100; nested repeatables, media and language values consume these levels differently. The effective depth is schema-dependent. Flex also enforces configurable row-count, byte-size and optional depth budgets, and refuses incomplete PageEdit posts before saving.

Definitions that use Fieldtype-specific persistence, arbitrary JSON paths, nested Page-reference traversal, sorting repeated values and native page-version restore need additional explicit integration before they can be supported. SQLite and PostgreSQL are not supported.
