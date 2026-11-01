# Architecture

This document records the current persistence, schema and ProcessWire integration decisions for FieldtypeFlex. User-facing examples and method signatures are in [API.md](../API.md).

## Runtime boundaries

The module uses ProcessWire's Field, Page, Inputfield, Pagefile/Pageimage and selector APIs. The Flex field is atomic in Page Edit: native Inputfield children are scoped and validated by ProcessWire, while Flex owns the complete submitted value and commits it through the Fieldtype lifecycle.

The implementation has four responsibilities:

- Definition loading and validation build a page-aware schema and native Inputfield tree.
- FlexValue hydrates stored properties, tracks nested mutations and creates formatted output views.
- FlexRows represents typed row collections with stable IDs and immutable row types.
- Storage, query and media services persist canonical values, derive selector projections and manage owned files.

Keep native Inputfield processing as the input pipeline. A property Inputfield supplies UI, validation and dependencies; it does not supply an independent storage layer. For text-valued inputs and media descriptions, `inheritField` also copies the source Text/File/Image Fieldtype’s formatter list; explicit lists override it completely.

Explicit unformatted Row reads use detached native values, including hidden
backing rows and descendants. Like unformatted media reads, they bypass output
conversion while retaining the formatted source's write guards. This read override
is separate from `of()`; explicitly changing formatting resets it. In-memory Row
search compares raw scalar leaves but counts collections in their current view.
Search results retain their selected membership when read without formatting.

## Definition and schema model

The module's `definitionsPath` selects the automatic PHP/JSON directory relative
to the configured templates directory, defaulting to `flex-fields/`. The existing
source resolver validates it without executing PHP, including existing symlink
ancestors of missing paths. Explicit field file paths retain their templates base;
inline JSON is independent. Path changes do not move files or rewrite data. All
callers share this resolver and the existing missing-source write guards.

Definitions are evaluated for the owning Page context. Dynamic PHP definitions are not globally cached. Query discovery has no individual Page, so a dynamic definition still needs to expose queryable properties there. Metadata Inputfields and returned input trees are fresh instances.

An existing FlexValue retains the definitions used to hydrate it. The editor
rebinds raw data to current definitions before building native inputs and applying
draft overlays, retaining the expected storage revision. Native renderReady and
the first processInput rebuild prepared input trees; after processing, inputs
retain submitted values and native validation errors. This handles ready Repeater
items whose concrete type is assigned after initial value hydration without
replacing the Page's cached value or coupling Flex to RepeaterMatrix internals.
Full-page validation uses each owner's getFields() and traverses single
FieldsetPages as well as Repeater collections, skipping empty values.

Dependency references resolve bare names within the current scope, `parent.` to
the containing Flex scope, `root.` to the current Flex root, `page.` to native
owner inputs (including Repeater item suffixes), and `forpage.` to main editor
inputs. FlexDependencies translates selector fields, preserving native operators
and values. Browser evaluation remains entirely in core inputfields.js.
Processing-only native forms include fresh copies of external dependency sources
and their dependency/visibility context. Private processing names isolate native
source validation errors; original browser references are restored even on
exceptions. Core Inputfield processing supplies
submitted values, unchecked-checkbox handling and chained conditions, avoiding
raw POST comparisons or stale saved ancestor values. Copies never render or
persist; only the owning schema's inputs are applied to canonical data. AJAX
duplication submits current form values as dependency context and applies only
the requested source Row. Standalone AJAX trees reconstruct ancestor scopes
through authenticated draft row identities. No schema/storage format changes.

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

Ordinary fieldsets organize the editor and flatten their leaf properties into the current schema.
The `object` structure owns a child schema and reuses FlexValue at a fixed property
address, rendered with a native fieldset. Objects have no row identity or type.
Their canonical `object:1` envelope holds a `values` map, including an empty JSON
object. Schema-free walkers recognize this envelope to retain unknown descendants,
media ownership and nested Rows. Object steps do not create row projection records;
the nearest containing Row remains the projection parent. The explicit Flex structure type `rows` has a mandatory types map. It resolves
directly to `InputfieldFlexRows`, also used to declare collections in native
wrapper trees. Ordinary `fieldset` definitions resolve to `InputfieldFieldset`
and never introduce a collection. Editor inputs are fresh copies with runtime
row context attached; no fieldset-to-rows adapter is needed. Each type has its own children schema, and names can repeat across different types. A typed path such as sections.section.blocks.text.heading identifies one leaf. There is no union schema or implicit row type.

PHP, JSON and native InputfieldWrapper definitions use the same tree model. PHP executes as trusted code inside the owning ProcessWire API context. Definition errors keep field and source context. In Page Edit, a missing or invalid definition blocks save when existing data is at risk and preserves submitted values for correction.

Editor removal is transient Inputfield state, separate from row visibility metadata.
Full posted order authenticates every row. InputfieldFlexRows retains an ordered
render-only row list and removal marks for error recovery, while its processed
value excludes pending rows. Native processing copies retain valid pending edits;
pending validation errors are cleared from that subtree rather than blocking a
row that will be deleted. Removed lazy subtrees have no body-grant obligation.
The browser retains disabled original controls plus successful hidden transport
mirrors so HTML validation cannot block deletion and undo/error recovery keeps
unsaved values. Undo restores prior disabled states. AJAX active orders exclude
pending rows for maxItems/resource validation and discovery without unregistering
the rows needed for undo. Canonical JSON and media ownership change only on the
ordinary owning-field save, under the existing revision and transaction checks.

## Canonical data and query projections

The JSON field value is the source of truth. Scalar leaves and native IDs are stored in its unformatted representation. Repeatable sets use a versioned rows:1 envelope containing ordered rows with an ID, type and values. File/Image values use versioned files:1 or images:1 envelopes containing registered asset IDs and native metadata.

Rows may carry an optional boolean `hidden` outside `values`; missing means visible.
The existing envelope version is unchanged. FlexRows retains all backing objects
while a formatted view exposes only visible rows; canonical export always walks
all backing objects. Cloning preserves both the visible view and independent full
backing data. Unformatting restores the full collection. Canonical content checks
include hidden data and retained unknown row leaves. Unknown property names remain
outside the declared property API. Empty retained row envelopes contribute no
content through identity/type metadata alone; native required validation of
declared editor leaves remains independent.

A separate InnoDB row projection supports typed row selectors and counts. Whole-field search uses flattened property values, not schema names, row IDs or row type markers. Projection updates and canonical JSON saves share the field transaction or savepoint. Normal writes, metadata saves, raw maintenance and clones update projections automatically; native deletion and emptying remove them. Both `deletePageField()` and inherited `emptyPageField()` use the transactional `emptyPageFieldTable()` override. `FlexStorage::rebuildRows()` remains an internal repair/migration tool, outside the supported API. The projection is not a second source of truth. It contains only rows whose own
state and every ancestor state are visible. The raw walker still validates and
visits every row for budgets, maintenance and media. Whole-field search skips hidden
subtrees. Filtering at projection derivation keeps existing correlated SQL,
negative/blank handling and count sorts consistent without new query branches.
Pre-feature projections need no rebuild because missing flags are visible. Internal
bounded repair locks canonical data and rebuilds both row and search projections
atomically. Native PageFinder `include=all` does not affect this derivation; an
all-row SQL option would need additional projection/query/search support and is
deferred. Unformatted PHP inspection remains available.

Selector values are bound parameters. Property names, operators, type keys and JSON paths are validated against the active schema before SQL is built. Blank, negative and count queries account for absent fields and row ancestors. Same-row expressions correlate every condition to one concrete row and its descendants.

Before PageFinder compiles individual predicates, Flex normalizes its `@` groups
by field/group name using native Selector objects. The same small grouping
helper introduces child collection/type scopes at each SQL recursion level;
there is no separate schema walker or intermediate array tree. Shared ancestors and descendants each receive one concrete
row correlation. The correlated SQL compiler consumes this tree directly;
selector values are never interpolated into new selector source. This avoids Core
per-predicate negation and multi-dot rewriting changing the meaning of a group.
Blank inner values use `values(true)` so an empty selector value remains a real
predicate. Public quoted same-row strings are rejected; the internal tree is not
a second selector syntax. No new projection, schema or persistence format is required.

`FlexRows::find()` is a separate in-memory boundary. It validates direct paths
against the evaluated collection schema, then applies native `Selector::matches()` to each Row and builds
a new membership map of the existing Row objects. Native WireArray control names
such as `sort` are not interpreted as directives; a declared property with that
name remains searchable without reordering the collection. It never deep-clones Rows or
resets their change tracking. Its formatted backing map contains only selected
visible Rows, preventing later unformatting from resurrecting excluded members.
Leaf comparisons use unformatted values (including full media collections), while
the current Rows view controls visibility and child counts. Native in-memory
comparison quirks, including zero/blank equality, are distinct from SQL semantics.

Root Page-reference traversal resolves one terminal native Field from an explicit
supported-type list and delegates its predicate to PageFinder. Target inclusion,
static reference restrictions and negative/blank semantics follow native custom
Page-field traversal. Target IDs are materialized without a discovery limit,
converted to integer literals like core and tested in owner-correlated EXISTS
predicates. Multiple references expand through JSON_TABLE; single references use
a numeric JSON expression. There is no added schema or reference projection.
Native target indexes remain available, while Flex JSON relationship lookup can
scan candidate owners. Discovery adds native target descriptors only at the root,
without recursive Fieldtype discovery. Unsupported negation/grouping, target
types, native-property aliases and deeper paths fail explicitly; API.md records
the current boundary. Template reference hydration is independent.

## Values and output formatting

Persisted values are never entity encoded or converted to display-date strings. Output formatting follows the owning Page and is a cloned view. Text, choice labels and dates are formatted when read; date runtime reads are Unix timestamps while canonical JSON remains ISO strings. Raw multilingual values are language objects; formatted values are current-language strings. Rich HTML follows the native TinyMCE/Inputfield pipeline. All formatted text-valued property reads, including custom scalar inputs, delegate to native FieldtypeText/Textarea/Email/URL with a transient property Field and the real owning Page. Missing or empty `textformatters` lists apply no text transformation; explicit/inherited lists apply native formatters in order. Flex adds no entity encoding; developers opt into `TextformatterEntities` for HTML text output. Toggle applies validated `formatType` modes on reads using native Inputfield label methods; direct FieldtypeToggle formatting would discard custom numeric keys. Canonical keys stay unchanged. Other choice keys remain raw data, while formatted property and option display labels are HTML entity encoded. Formatter output is never cached into canonical values. File/Image descriptions apply the configured native formatter modules lazily after language resolution, with a transient native File/Image Field. Only explicitly configured or inherited lists run; absent and empty lists leave descriptions unchanged. Description reads never replace native metadata; canonical export and collection cloning read raw descriptions explicitly. Irrelevant formatter settings on non-text/non-media properties are tolerated, while effective formatter lists remain validated. Native URL and Textarea output options apply; HTML storage delegates URL/link sleep and wakeup to MarkupQA. Page-reference access follows native derefAsPage, allowUnpub hydration and formatting. Native FieldtypePage validates canonical reference IDs during serialization; a transient Field carries each property’s native settings without creating a second database field. Direct multi-reference assignments append unless a PageArray replaces the collection; editor submissions replace the selection. Nested language and PageArray objects participate in change tracking and are rebound to the owning Page after cloning or assignment.

`FlexMarkup` shares transient text Field construction with output formatting.
Known HTML leaves wake during FlexValue hydration, after rename resolution and
before language conversion. Native HTML descriptions wake in the media bridge;
file conflict baselines retain the original stored metadata. Sleep walks detached
exports using evaluated Row/media schemas before encoding and search projection,
including hidden descendants and all language entries. Selective metadata saves
sleep only their patch. Runtime raw exports contain awake URLs; revision hashes
and post-save media baselines use exact sleeping storage. Unknown keys remain
untouched. Only native URL/link transformations run, without image markup QA or
native inbound-link discovery integration.

Unknown stored properties are always retained on saves, serialization and copies,
including nested rows and owned media. `flexPrune` no longer exists as a setting or
behavior switch; old metadata is ignored. `renamedFrom` resolves keys on hydration
and individual saves; differing old/new values are errors. Prefer complete schemas
with native collapsedHidden/Locked Inputfield presentation states for role/Page
context differences. Dynamic omission remains possible, but undermines reliable
orphan classification. Frontend rendering remains application responsibility.

`FlexMaintenance` traverses persisted canonical JSON before hydration, with the
owning Page's current schema. API methods, native CliModule and superuser field
configuration adapters share this single transformation/persistence path. Typed
schema paths identify selectable properties; concrete row-ID addresses identify
occurrences and media owners. Missing/invalid schemas and unknown row types are
errors, never reasons to erase data. Rename sources are excluded from orphan
selection; wholly removed subtrees are selectable units.

Maintenance batches query the field table directly by owner ID so access/status
filters and Repeater visibility cannot silently omit records. Preview tokens bind
raw data revisions and recursive schema membership/kind/rename decisions. Apply
locks and rereads each field row, re-evaluates definitions and rejects changed
preview foundations. Only selected raw keys move/disappear: no FlexValue hydration,
normalization or default insertion runs. Each changed field updates canonical JSON,
whole-field search, row projection and media ownership under the existing transaction
or savepoint. Removed media enters the normal pending-delete lifecycle; physical
collection is automatically deferred until outside the outer transaction. Editor draft revision
checks prevent older edits from overwriting maintenance results.

Per-page failures retain explicit retry IDs and a safe resume cursor; the forward
cursor records examination, not success. Previously committed pages survive later
failures. No global transaction/snapshot is promised. Preview/apply summaries are
per batch, and a final full scan is needed before dropping rename attributes.
Admin runs 50-page CSRF-protected superuser POSTs; normal field configuration reads
and saves perform no maintenance scan. CLI explicitly exits nonzero on failures
because native ProcessWire CLI catches module exceptions.

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

Definitions that use Fieldtype-specific persistence, arbitrary JSON paths, Page-reference traversal from rows or across additional hops, sorting repeated values and native page-version restore need additional explicit integration before they can be supported. SQLite and PostgreSQL are not supported.

## Automatic media work

The autoload module hooks native `ProcessWire::finished`. Both pinned cores also
invoke this lifecycle event on destruction for a bootstrapped CLI/PHP instance that
has not finished normally. Ordinary save/delete/upload-abandon/clone paths retain
bounded eager attempts through the same best-effort media service. Each attempt
skips an open caller transaction. There is no commit interception or implicit commit.
If a transaction remains open at completion, later PHP execution handles eligible
journal records. No execution means no progress; hard process termination can defer
work too.

Each pass selects at most 100 eligible ownership records, not a filesystem or
canonical-data inventory. The global pass persists an asset-ID keyset cursor in
native WireCache, advancing even for protected records and failures. At the end of
the range it wraps for retries. Scoped eager attempts do not alter that cursor.
Deleting or clearing the cache safely restarts the range; journal records remain
the durable work source. Per-candidate transactions reread/lock ownership and
canonical references; existing native-field/version/path protections remain.
Collector and logging errors cannot escape the lifecycle wrapper and invalidate
successful writes. Failed records remain registered for later retry. Unsaved staging
and unfinished uploads expire after six hours; referenced clone records are retained.
The internal collector and projection-repair methods have public PHP visibility for module services
and tests, without being an application API or CLI maintenance command.

These transaction guarantees concern Flex field writes and Flex's collector.
Deleting a whole ProcessWire Page also clears its directory in core
`PagesEditor::clear()` via non-hookable `PagefilesManager::emptyAllPaths()`;
Flex does not make that native filesystem operation rollback-safe. Whole-Page
permanent deletion and explicit native image-variation deletion retain core's
immediate filesystem semantics and must not be confused with removing Flex
properties, rows or originals through a field save.

## CLI diagnostics and selection

Exact owner selection delegates to the same maintenance batch transformer, after
resolving the Page/field assignment. A missing field-table row is empty storage,
not a missing owner. `--all` advances ID-keyset batches and stops on the first
failed or incomplete batch. Output streams bounded batch details; summaries keep
only path counters and the last batch's retry information. No global transaction
or retained terminal approval is introduced.

`FlexInspection` reads schemas through FlexFieldDefs and selector descriptors through
FlexQuery, using the same evaluated definitions. Writes and consistency checks share
FlexStorage's raw projection generator. Check uses the raw maintenance validator and
walker; unknown properties do not become errors, pending renames retain raw addresses.
Media inspection queries the current journal, avoiding hydration/cache and native
file objects whose initialization could create/rename directories. Original probes
use guarded normal/secure/extended and journal paths; custom native path hooks leave
the check explicitly incomplete. No diagnostics handler repairs or collects. Native
completion cleanup remains independent and may still run when the PHP process ends.
The CLI report contract in [development.md](development.md#cli-operation-and-report-contract)
defines categories, skipped work, output framing and exit precedence.

## Optional lazy Row bodies

`FlexLazy` owns eligibility, semantic schema fingerprints, draft body grants and
validation-only baseline transport. `InputfieldFlexRows::renderRow()` emits a
header and placeholder for eligible closed existing Rows without building their
concrete Inputfield tree. `renderReady()` still prepares sample inputs/assets per
declared type. Definitions, canonical JSON and all row identities remain available.
The feature introduces no persistent format or dependency change.

`FlexAdmin::ajax()` handles `row-render` with the existing editor owner, field,
permission, CSRF and revision checks, plus schema and issued-row checks. A successful
render permanently upgrades the draft's row grant; retries reissue it. The browser
inserts only the body, initializes native Inputfields and prevents submit while
loading or after failure. Root completeness plus per-body start/end grants reject
partial submissions even if the browser submit guard is bypassed.

`InputfieldFlex::processInput()` prepares a native validation POST with baseline
scalar values for unopened Rows; `InputfieldFlexRows::processInput()` validates
these through the existing dependency bridge but does not apply their leaves.
Loaded leaves use normal processing. Explicit order controls govern removal and
sorting; unknown properties survive the ordinary merge. Parents containing Rows
render directly, so nested children can opt in without partial parent transport.
Schemas changing mid-editor are rejected; row types remain immutable. Error rows
render from their processed input trees. No cached fragment HTML or cross-request
schema evaluation is introduced.

## Per-file metadata

Each evaluated File/Image definition owns a `customFields` FlexFieldDefs schema.
Metadata leaves reuse a privately held FlexValue for normalization, dates,
languages, defaults, formatting, unknown values and strict renames. The real
Page/Field context is retained; native metadata Templates and Core's fieldValues
cache are not used. Native names are reserved by the actual Pagefile/Pageimage
API and documented magic properties. The ordinary scalar input allowlist
also accepts native TinyMCE, HTML Textarea, Hidden and Page references. Markup
and ordinary fieldsets only organize the editor. Rows, descendant media and
unsupported third-party inputs remain rejected.

Each files:1/images:1 item may add an ordinary `values` property map. The encoder
rebuilds arrays without mutating nested PHP references and keeps even empty maps
as JSON objects, while multi-choice values remain lists.
Native description, tags and filedata retain their existing slots. Old envelopes
without values remain valid and need no eager rewrite. The map participates in
canonical revisions and byte/database-depth budgets without an ownership-table
schema change. Whole-field search still uses basename, description and tags.

The owned file bridge shares raw export/hydration across field saves, metadata
saves, clones, baselines and drafts. Hydration baselines retain exact persisted
metadata rather than inserting new schema defaults. Selective native saves do
not reset pending custom values. A save of the actual cached file can advance
its owning root's matching optimistic revision; detached views and already stale
roots cannot advance it. Variations delegate metadata reads to their original
and reject divergent metadata writes. Scalar values never become filedata, so
changing them does not schedule variation cleanup.

Native per-file inputs replace getItemInputfields/processItemInputfields.
Description/tags adapters edit the existing native slots, independently suppress
native rendering and POST processing, and retain native language/tag methods.
Metadata names include outer input, Row address and stable asset identity. The
small dependency resolver accepts an explicit containing-scope address; bare,
parent/root and page/forpage conditions still run through InputfieldForm. Per-item
completeness controls reject truncated saves, with required validation deferred
until editor Save and skipped for deleted items. Cached native trees retain
validation errors. Refresh and single-file staged replacement preserve current
controls (including unchecked/empty values), with an explicit transfer only for
the existing maxFiles=1 operation.
TinyMCE saveReady synchronizes normal/inline transports before snapshots; the
native destroy/init lifecycle rebuilds editors only in the affected media scope.
Inline markup is restored with its transport value. Hidden inputs carry their
asset/property attributes directly because they have no wrapper. Native Page
adapters store IDs and reuse dereference, validation and formatting rules; live
PageArray mutation is confined to unformatted originals, with independent
containers in copies, formatted views and variation reads. Metadata has no SQL
selectors or Page-reference traversal. The basic Page editor allowlist is Select
and AsmSelect; widgets with independent AJAX/tree selection lists are rejected
until their additional refresh state is supported.

Raw maintenance enters each asset values map with schema paths independent of
asset identities. Checked patches carry explicit container/property/ID steps for
Rows and media instead of using the Row-only FlexPath resolver. Schema fingerprints
include metadata; original JSON object/list distinctions, locks and stale-preview
checks remain in force. Diagnostics inspect raw maps without hydrating defaults.
