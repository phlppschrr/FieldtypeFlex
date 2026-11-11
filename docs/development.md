# Development

FieldtypeFlex uses a disposable ProcessWire installation under .dev/pw. The module is loaded from the repository root through a local module symlink. Generated fixture files, database data and the local admin password remain under ignored .dev/.

## Requirements

- Git, DDEV and Docker
- PHP and database versions provided by the configured DDEV project
- Network access for the first fixture setup to fetch the pinned ProcessWire core and development tools

The DDEV container has no usable Git metadata. Its `COMPOSER_ROOT_VERSION=dev-main`
setting identifies the development root package for Composer without changing module
release metadata or adding a maintained `version` field to composer.json.

No personal AgentTools configuration or provider API credentials are required for setup or testing.

## Setup and checks

Run these commands from the repository root:

    bash scripts/setup.sh
    ddev flex-test
    ddev composer lint
    ddev composer validate --no-check-publish

scripts/setup.sh prepares the disposable fixture, starts DDEV, installs ProcessWire if needed, links the module and seeds the demo data. The fixture and tracked seed scripts include path guards; keep their writes inside .dev/pw.

`ddev flex-test` runs all integration suites in separate PHP processes.
Use `ddev exec php tests/run.php --list` to list suites, or select a subset with
`ddev flex-test definitions rows admin nested media repeaters translations`. Each suite owns its fixture and cleanup;
there are no ordering dependencies between suites. `ddev flex-test compatibility`
compares actual saved native Fields with Flex properties, including Page dereferencing,
access/unpublished states, languages, scalar values, option objects and media output modes.
`ddev flex-test formatting` also compares native text formatter chains with Flex, including
explicit/inherited/empty lists, HTML output, multilingual values and nested rows.
Its media formatter suite covers absent/explicit/empty and inherited description chains,
single/collection output, language changes and fallback, raw exports, read-only
unformatted views, nested images, variations and unchanged canonical metadata.
The formatting suite's `markup.php` compares native Textarea storage and wakeup,
repeated roundtrips, target renames, subdirectory changes, hidden/nested Rows,
languages, File/Image metadata, selective saves, stale conflicts and rollback.
Run suites sequentially against one fixture; their owned baseline field name is shared.

The admin suite executes the production PHP and JavaScript Row-title parsers
against `tests/fixtures/row-titles.json`, including final renderer/fallback cases.
Node.js must be available on PATH (already present in DDEV and used by CI's JS
syntax checks). Run the JS cases alone with `node tests/js/row-title-parts.cjs`;
no package install is needed for these parser cases. The test executes the compiled
production parser, including renderer/fallback behavior, without a copied parser
or production test exports.

The admin context regression loads a Flex value before assigning its owner's
concrete type, then exercises preload, rendering and authenticated processing.
It checks revisions, unsaved rows/unknown data, owned media and draft overlays.
The Repeater suite includes a real native FieldsetPage full save and nested
budget rejection. A Page subclass mirrors RepeaterMatrix's getFields()/null-value
contract so CI does not require proprietary ProFields. This does not replace a
browser check of the complete RepeaterMatrix AJAX dialog in a local integration.

AgentTools is optional. Set `FLEX_INSTALL_AGENTTOOLS=1` when running setup to install
the pinned inspection module. It is not required by the test runner.

The integration runner uses the same command in CI and local development. The portable native contract is FieldtypeFlex.test.php; when the installed core provides WireTests, it can be run with:

    ddev exec php scripts/wiretest.php

The CI workflow runs the integration suite and the available WireTests contract across its configured ProcessWire, PHP and database matrix. Its pinned core references are in scripts/processwire-3.0.259-ref.txt and scripts/processwire-ref.txt.

## Language-alternate verification

`ddev flex-test languages` includes saved native alternate Fields as the oracle
for Flex siblings. It compares language/default/fallback and output formatting,
text formatter order, numeric zero settings, Checkbox/Toggle, Page references and
File/Image collection/single output. The Core alternate-name cache is initialized
after the test's owned Fields are created. Further regressions cover local Object,
typed/nested Row and per-file metadata scopes, retained formatted views, raw reads,
write guards, canonical exports and explicit selectors. No editor transport or
storage schema change is involved.

## Fixed Object verification

`ddev flex-test objects` covers fixed child schemas, scoped native processing,
formatting/read guards, unknown data, multilingual values, mixed Object/Row
selectors and numeric sorting, media ownership/copies/selective saves, raw
rename/cleanup and transaction rollback. The API example suite executes the
Object definition from API.md. Ordinary fieldsets must remain flat.

For browser checks, save an Object containing another Object and Rows whose
children contain an Object. Change a checkbox controlling a child through
`parent`, duplicate a Row with unsaved Object values, save and independently
read canonical values and row identities. Inspect browser warnings/errors.
Objects use native fieldsets and require no additional JavaScript or CSS.

## TypeScript admin assets

`ddev flex-test tables` covers table definitions, native widget processing,
header/column preservation, multilingual/nested values, native errors and undo,
and authenticated AJAX add/duplicate (including new draft ancestors). The JS
runtime suite also exercises table ordering, remove/undo, unsaved duplication
and native language-header display states. For browser QA, test a real PageEdit
save and independent readback, dropdowns, drag/keyboard sorting and horizontal
scrolling under a classic theme. Update Bridge from official upstream before
testing it, preserve the old installation and record the tested commit. HTTP
PageEdit tests do not replace visual layout or browser-console inspection.

The editable sources live in `scripts/typescript/`. Run from the module root:

```sh
npm ci
npm run build
npm test
```

`npm run check` type-checks without writing assets. TypeScript strict mode and the
DOM types cover both entry points. The compiler joins namespace scripts into the
existing `FieldtypeFlex.js` and `FlexMaintenance.js`; no bundler, runtime loader or
production npm dependency is needed. Commit the generated assets with their sources.
Installation ZIPs include these ready-to-use assets and omit build/test tooling.
CI rebuilds both assets, runs regressions and rejects stale generated files.
See TypeScript's [outFile reference](https://www.typescriptlang.org/tsconfig/outFile.html).

`FieldtypeFlex.ts` owns native DOM events, Row state, AJAX, uploads and metadata.
`dom.ts` contains the small shared DOM/form helpers. `FlexMaintenance.ts` retains
the native maintenance flow with typed request/report data. The jsdom tests run
the compiled production runtime, mostly without jQuery, and cover nested Rows,
unsaved duplication, serialization, deferred removal/undo and limits, keyboard
ordering, lazy failure/retry and submit blocking, TinyMCE lifecycle, media
refresh/replacement and maintenance preview invalidation. They supplement actual
ProcessPageEdit save/readback checks; jsdom cannot validate native input widgets
or pointer-based jQuery UI sorting.

Two adapters isolate the admin integration from Flex's data and AJAX logic:

- `sortable.ts`: `init(list, start, stop)` and `disabled(list, disabled)` expose
  native Elements to Flex. Core admin themes use jQuery UI Sortable, including
  isolation from containing Repeaters. Bridge uses its native `Bridge.sortable()`
  API and cleans up listeners when lists are removed or replaced.
- `classic.ts`: Inputfields init/toggle/reloaded/opened/closed, TinyMCE's
  jQuery-based saveReady/destroy API, the existing Selector path workaround,
  confirmation/dialog integration and existing external jQuery events. Flex logic
  receives Elements or scalar values, never jQuery collections. Under Bridge the
  adapter uses public native lifecycle, dialog and confirmation APIs.
  Legacy event handlers also bind when a child loads the classic stack later.

The image dialog emits native `flexMediaRefresh` with `{draft, path}` as
`CustomEvent.detail`; existing external jQuery `flexMediaRefresh` and
`flexRowAdded` consumers remain supported by the adapter. `dom.ts` uses Bridge's
existing Trusted Types policy and script runner for AJAX markup, including the
response nonce. Maintenance controls also reinitialize after in-place form saves.

Flex's Inputfields declare the optional Bridge component contract. Each rendered
child retains its own component identity and legacy requirements. Flex File/Image
subclasses declare their borrowed core widget through an
`AdminThemeBridge::nativeMap` hook (`widget => InputfieldFile` / `InputfieldImage`).
Bridge writes `data-bridge-widget` beside the subclass's own component identity
and initializes media on load, AJAX insertion and in-place reload. Flex retains
its existing upload transport and persistence. A child that needs the classic
stack, including the current TinyMCE integration, still loads it through Bridge.

Native Bridge support requires **AdminThemeBridge 0.24.0 or newer**, which provides
public `Bridge.sortable()` and declarative File/Image widgets. See
[Bridge's component contract](https://github.com/theoretic/bridge/blob/master/docs/contract.md).
No Bridge package is bundled with Flex. Core admin themes, including AdminThemeUikit, continue to use
the classic adapters.

Test native-only pages with CSP and Trusted Types enforcement. Pages that load
legacy Inputfields must follow Bridge's legacy CSP/report-only contract; TinyMCE
support does not imply that its classic dependencies support enforced Trusted
Types.

## Browser checks

Changes to Page Edit inputs need a real browser save in the fixture. Verify the submitted values after reload and check the browser console for application errors. For `rows` or media changes, include nested rows, TinyMCE, sorting or duplication
and media lifecycle cases that exercise the changed behavior. Verify both array/JSON
`type => 'rows'` and native `InputfieldFlexRows` definitions, including AJAX-loaded
inputs inside native ProcessWire Repeaters.

For duplication requests inside an AJAX-loaded Repeater, keep the main PageEdit
form's hidden `id` present. Duplicate a root row, edit its unsaved copy and
duplicate that copy, then duplicate a third-level row with unsaved text. The
request must follow the Repeater owner's URL without serializing the main form's
`id` (ProcessPageEdit prioritizes POST `id`). Verify no `Invalid Flex editor
context` error, distinct IDs and copied values after an actual Save/reload,
and unchanged sibling Repeater owners through fresh PHP readback. PHP-only AJAX
handler tests cannot catch incorrect browser form serialization.

For the row type selector layout, place a multi-type Rows collection inside a
67% ordinary fieldset within a Row. Check Core Original and Default/Konkat: the
label stays on one line beside the select when there is room; at a 375px viewport
the controls wrap without page overflow. Retain native `InputfieldSetWidth` on
the select so the admin column rule cannot force it to 100% width. Add the selected
type and verify it after a real PageEdit save through fresh PHP readback.

The reusable demo seed is ddev flex-seed. The nested demo has a separate guarded seed:

    ddev exec php scripts/seed-nested.php

Only use these scripts with the disposable .dev/pw installation.

## Lazy Row verification

Run `ddev flex-test admin lazy lazy-prototype translations` for focused coverage.
The admin suite includes native Text limit copying; `lazy` covers actual deferred
rendering, baseline validation, loaded completeness grants, AJAX authorization,
schema/revision changes, unknown values, hidden dependencies and fresh saved
selector projections. `lazy-prototype` retains the earlier validation experiment.

For browser changes, use isolated saved Fields with `rowLazyLoad => true` in JSON
and native wrapper definitions. Include nested lazy children inside eager parents,
unchecked controls and ancestor `showIf`/`requiredIf`, invalid-save recovery,
unopened remove/reorder, duplicate unsaved values, and TinyMCE/media fallback.
Interrupt a body response after the server rendered it, verify save is blocked,
then Retry and save. Repeat inside a natively AJAX-loaded Repeater. Check canonical
values and unchanged media independently after actual ProcessPageEdit submission.

Measure representative editor pages in the browser before enabling lazy loading.
Compare initial rendering and first-open latency with ordinary collapsed rows.
Keep test suites and other writes out of measurement series.

## Native core Fieldtype baseline

The separate `core-fieldtypes` CI job runs 13 unmodified native WireTests from
our pinned newer core on PHP 8.3 / MariaDB 10.11. Run the same baseline locally:

    ddev exec php scripts/wiretest.php --core

The allowlist covers Text, Textarea, Integer, Float, Datetime, Email, URL,
Checkbox, Toggle, Page, Options, File and Image. Each runs sequentially in a
fresh PHP process; failures are aggregated and missing/skipped tests fail the
job. The runner accepts only the disposable `.dev/pw` site. Core tests retain
reusable WireTests fields/pages there, following native test lifecycle behavior.
No separate WireTests download or dependency update is involved.

This baseline checks the native environment. Flex compatibility remains covered
in every existing matrix configuration by `ddev flex-test compatibility`, now
including `native-selectors.php`: complete result-set comparisons against saved
native Fields with matching, nonmatching, blank, missing-property and absent-field
storage. Existing suites cover formatter chains, Page traversal and media.
Comparisons follow API.md's supported contract: numeric-zero preservation,
choice return types and formatted label escaping can intentionally differ; Flex date-only
and partial-date matching, reference name/path matching and media metadata
selectors are not inferred from the native tests.

Native scalar inequality treats blanks differently across Fieldtypes. Nonblank
`!=` comparisons therefore compare populated owners and separately assert Flex's
missing-value behavior, including the explicit native Text/Flex difference.
Unrounded Float parity uses native `colType=double` to avoid SQL FLOAT precision
loss; this does not change Flex's canonical storage.

## Data maintenance verification

Field → Actions contains separate collapsed sections for property renames and unknown-property cleanup. Both use native Inputfields and buttons; scans and apply operations remain explicit, CSRF-protected requests.

`ddev flex-test maintenance` tests the shared raw data path, typed rename/cleanup,
preview revisions, atomic projections and media ownership. Keep API, CLI and Admin
adapters thin; test raw JSON without hydration/default insertion. Include strict
old/new equality, blanks/zero, missing definitions, unknown types, direct field-table
owner enumeration and error-safe continuation. Preserve old `flexPrune` metadata in
regressions to prove it has no effect.

For browser checks, create an isolated disposable field/template/Page, save legacy
root/row/file keys, then declare `renamedFrom` and omit two other properties. Check
conflict preview, repair the conflict, apply renames, list potentially orphaned
properties, select just one, preview and apply cleanup. Save through ProcessPageEdit
with a native `collapsedHidden` property; verify hidden/unknown values, row/asset IDs,
media addresses and bytes independently from the database. Check console errors.
Opening and normally saving field/module configuration must not scan or migrate data.
Verify the module definitions directory save/reload, path normalization, invalid
input retaining the previous setting, and retained `defaultLanguageId` metadata.
Use an isolated automatic definition in a custom directory for a real PageEdit
save and independent readback; test missing-source protection and recovery too.
After previewing a cleanup, change the selected paths: Remove selected properties must become unavailable,
and Check selection must become available without another orphan scan.
Preview and apply the revised selection, then verify only those paths were removed.

Native CLI checks run from `.dev/pw`: `ddev exec php .dev/pw/index.php flex --help`.
Refresh module metadata after adding CLI support. Test both pinned cores through
the real `index.php flex` dispatcher where the core provides it, including exit 0,
argument exit 2 and conflict exit 1. The pinned 3.0.259 snapshot has no dispatcher;
record that limitation and run the API/Admin/adapter cases there. Keep CLI metadata
and methods without a direct interface declaration so optional CLI support does
not change the module requirements. Add `node --check FlexMaintenance.js` to JS checks. No AgentTools dependency
is required. Keep dependencies/pins unchanged and report unrun matrix configurations.

## Automatic projection/media lifecycle verification

`ddev flex-test media rows nested maintenance public-api` covers automatic
projection writes, native cloning/deletion/emptying, save failures and caller rollback.
`media-lifecycle.php` exercises native `ProcessWire::finished` and an actual plain
PHP subprocess that only bootstraps ProcessWire and exits. No application collector
or rebuild call drives these cases. It covers outer commit/rollback, subtree media,
selected property cleanup, expired staging/upload/clone records, unknown files and
references retained in unknown properties. A full protected/failed batch precedes
later candidates to verify durable cursor continuation, wrapping and retry after a
physical deletion failure. Low-level collector/repair tests use internal services
explicitly; these methods are outside the supported application contract.

For browser verification, save an isolated Page with a nested File property and
TinyMCE body through ProcessPageEdit, removing the File and changing a scalar.
Independently reread canonical JSON, row selectors, the registered asset and actual
paths; retain an unrelated unknown file. Capture browser warnings/errors. Leave
outer transactions open in PHP tests, never force a commit in the lifecycle hook.

When a subprocess changes files, clear the parent process's stat cache before
filesystem readback. Verify journal ownership independently too: a cached positive
`is_file()` result can otherwise misreport successful child-process cleanup.

## CLI inspection regressions

`ddev flex-test maintenance` includes `maintenance-cli.php` through the shared raw
maintenance fixture. It verifies discovery/Page schemas, recursive query paths,
exact hidden/unpublished and Repeater owners, batch streaming and stop/resume,
unique owner aggregation, deliberate JSON/projection/media corruption, original
file checks, status expiry counts and strict option/exit handling. MariaDB-only
syntactically invalid JSON is injected with its session constraint check temporarily
disabled; MySQL native JSON otherwise prevents that storage state.

Snapshot canonical/search, row projections and media journal before/after diagnostic
**handlers**. Do not mistake independent native process-end collection for a handler
write. Test absent assigned storage separately from missing owners/field assignment.
New commands have text and JSON modes; all JSON output is JSON-Lines and progress
is stderr-only. Validate native dispatcher exits where available; the older pinned
core runs shared adapter cases but has no native CLI dispatcher. No admin input
behavior changes are involved in these CLI diagnostics.

## Row visibility verification

`ddev flex-test rows formatting admin nested lazy translations` covers metadata,
formatted backing-data retention, visible-only row/search projections, counts,
types, same-row/negative/blank predicates, rollback, repair and native validation.
Use a real PageEdit fixture with eager/media and lazy scalar Rows. Toggle while
closed, open/edit while hidden, cause and repair a native validation error,
duplicate unsaved hidden content, reorder, remove and show again. Repeat with
native wrapper definitions. Read canonical JSON, descendant states, unknown values,
media ownership and file SHA-256 independently; inspect browser warnings/errors.

## Deferred removal verification

The `rows` suite includes `row-removal.php`: marks, successful Save, invalid-save
recovery/undo, invalid flags/identities, retained unknown properties, hidden state,
minItems, registered unsaved rows, nested parent/child removal and lazy body grants.
Media references and original SHA-256 remain unchanged before the actual Save.
For browser checks, compare pending Flex and native Repeater headers in Core
Original and Default/Konkat. Mark an edited row, undo, mark again, fail another
row's native validation, undo after recovery, and add a replacement at maxItems.
Independently read persisted IDs before and after the successful PageEdit save.
Double-click Remove: cancel leaves marks unchanged; confirm marks siblings only.
Double-click a pending row to undo all sibling marks without a dialog. Check a
nested collection and `minItems=1` (one active row remains), then Save and verify
that only marked rows were deleted through fresh PHP readback. Include unopened
lazy rows so the bulk action does not require loading their bodies.

## File/Image custom metadata verification

Run `ddev flex-test metadata` for direct/native bridge values, dates, languages,
formatters, independent copies, selective saves, stale metadata, outer rollback,
SQL failure, raw renames/orphans/cleanup, adapter validation and native per-file
processing. Include `media admin repeaters maintenance public-api translations`
for the surrounding lifecycle, editor, inspection and executable examples.

Browser checks use isolated root/nested File/Image properties and a natively
AJAX-loaded Repeater, including the same Flex Field on root and Repeater owners.
Verify required failure/recovery, description/tags appearing once, unchecked and
empty multi-choice controls, dependencies, rename/duplicate/replacement, upload
with initially blank required metadata and an image-dialog refresh after unsaved
metadata changes. Verify native Core Original and Default/Konkat. Read saved JSON,
asset identities and original/variation hashes independently; inspect console
warnings/errors. The existing maxFiles=1 replacement stages the new file and
preserves pending metadata; `overwrite` remains unsupported.

Include TinyMCE (normal and inline), pending edits across an image-dialog refresh,
required-rich-text error recovery, Hidden transport, Markup-only definitions and
single/multiple Page selection through Select/AsmSelect. Compare blank/dereference/unpublished Page behavior
with native FieldtypePage and check mutable reference-container isolation.

Keep temporary scripts and browser fixtures under the ignored .dev/ directory.
Remove registered test modules, Fields and Pages after verification.

## CLI operation and report contract

The [API maintenance reference](../API.md#maintenance-apis) covers everyday usage.
Run `php index.php flex --help` from the ProcessWire root for command options.
Native dispatch requires a supporting core: the pinned 3.0.259 lacks it; PHP and
Admin maintenance remain available. Refresh module metadata after CLI updates.

### Selection, batching and writes

- `--page=ID` selects exactly one assigned owner, including hidden/unpublished Pages
  and Repeater items. Missing owner/assignment is an error (exit 2). An assigned
  field without storage returns `stored=false`, `checked=0`; stored empty JSON is
  checked normally. Schema inspection evaluates the Page even without storage.
- `--page` excludes `--all`, `--after` and `--limit`. Otherwise one bounded batch is
  selected unless `--all` advances batches automatically. `--limit` bounds each
  batch (1–1000, default 100), not the run; `--after` starts after an owner ID.
- Numeric options require decimal integers without signs or leading zeros (`0`
  is valid for `--after` only). Unknown/repeated/inapplicable options fail;
  distinct `--path` selections may repeat. `--dry-run` and `--apply` conflict.
- Rename/cleanup preview by default. Apply creates and validates a fresh preview;
  an earlier terminal dry-run is not an approval token. Cleanup requires unique
  type-qualified schema paths, never concrete Row IDs. `--details` adds orphan
  owner IDs and concrete addresses.
- Each owner commits independently. All-runs stop after the first batch with
  errors/skipped checks; earlier successful owners remain applied. Repair failures
  and resume the same operation with `--all --after=RESUME_ID`, using
  `resumeAfterPageId`, not the examination cursor `lastPageId`.
- Scans are not global snapshots. Before dropping `renamedFrom`, complete the
  migration from zero and repeat a full successful preview with no pending renames.

### Reports and output

PHP maintenance arrays contain `fieldId`, `operation`, `paths`, `checked`,
`changed`, `unchanged`, `wouldChange`, `pages`, `properties`, `renames`, `conflicts`,
`errors`, `findings`, `skippedChecks`, `retryPageIds`, `resumeAfterPageId`,
`afterPageId`, `lastPageId`, `complete`, `success` and `finished`.
`pages` contains opaque preview tokens; pass the checked batch unchanged to apply.
Errors/conflicts identify `pageId`, `path`, `message`; rename entries add `from`,
`to` and concrete `address`. Schema paths select properties; addresses locate
individual Row/media occurrences. `findings`/`skippedChecks` are empty for PHP
rename/orphan/cleanup operations; CLI checks populate them.

`complete` means the selected owner range was exhausted. `finished` additionally
requires no errors or skipped checks. Warnings alone do not fail a complete check.
`retryPageIds` identifies failures; `resumeAfterPageId` safely precedes them.

`rename`, `orphans` and `cleanup` default to JSON; inspection commands default to
readable text. `--json` emits one object. With `--all --json`, JSON-Lines contains
`record="batch"` objects and a final `record="summary"`. Without `--json`, all-runs
retain the command's batch format and add a summary. Progress/adapter diagnostics
go to stderr; a failed stream may lack its summary. Ignore blank lines and check
exit status: 0 success/warnings, 1 known errors, 2 request/adapter failure,
3 incomplete check without known errors. Known errors take precedence.

### Inspection boundaries

`schema` and `lint` use discovery/NullPage unless `--page` supplies context. Dynamic
schemas are checked only in that context. Lint warnings do not change exit status;
missing/invalid definitions do. Diagnostics contain `code`, `severity`, `path`,
`property`, `setting`, `message`. Codes: `row_title_unresolved`,
`dependency_expression_invalid`, `dependency_path_invalid`,
`dependency_target_unknown`. External native dependencies remain context-dependent.

Check findings contain stable `category`, `severity`, `field`, `fieldId`, `pageId`,
`path`, `address`, and a value-free `message`. Empty paths denote owner/field-level
findings. Categories: `owner`, `canonical_json`, `definition`, `envelope`,
`schema_data`, `row_schema`, `rename_conflict`, `pending_rename`,
`search_projection`, `row_projection`, `media_reference`, `media_unreferenced`,
`media_original`, `derived_unavailable`. Custom file-path hooks/unavailable roots
can skip original checks; invalid data/query failures can prevent further checks.
Checks cover selected stored owners, not global filesystem, cross-field, version
or staging inventories. They do not verify file bytes, optional variations or
complete index definitions. Concurrent writes may require another check.
Skipped work is explicit, not proof of consistency. Whole-field search checks
compare content counts rather than order because database JSON objects can reorder;
Row projections still verify identities, order and payloads. Regression coverage
is in `tests/integration/maintenance-cli.php`; the comparison rationale is beside
`FlexInspection::checkDerived()`.

Status reports versions, CLI availability, required columns/InnoDB engines, media
journal/expiry counts and cleanup cursor. Missing storage/wrong engines fail;
pending counts are normal. It neither cleans nor audits full indexes. The cursor
is not completion progress; `lastCleanup=unknown` claims no last-run success/time.
Read-only command handlers do not prevent independent process-end media collection.

## HTML link compatibility

Outgoing HTML URL sleep/wakeup and link abstraction are independent of native
backlink discovery. Automatic `Page::links()` does not register Flex fields.
The pinned 3.0.259 accepts an explicit Flex field in
`$target->links($selector, $fieldName)`; the pinned newer core restricts it to
Textarea fields. Direct `MarkupQA::findLinks()` with an explicit Flex field can
find searchable root/visible-Row HTML, but misses links found only in hidden Rows
or custom File/Image HTML because those are absent from whole-field candidates.
These are compatibility observations, not a complete backlink API. The executable
specification is `tests/integration/markup.php` (`ddev flex-test formatting`).

## Lazy Row eligibility

`rowLazyLoad => true` enables deferred bodies only for eligible closed existing
Rows. Text, Textarea, Email, URL, Integer, Float, Checkbox, Select, SelectMultiple,
Checkboxes and Radios are eligible, optionally grouped in ordinary fieldsets.
Rich text, media, dates, Page references, multilingual/custom inputs, nested
structures, native AJAX collapse modes and nonzero custom unchecked checkbox
values render directly. Expanded/new/error Rows render directly as well. Nested
collections can opt in independently for eligible children. Deferred loading
reduces initial input construction/DOM size, not hydration or save-time validation.
Only boolean true enables it; failures block saving until Retry succeeds, and
schema/revision changes require reloading. The full-editor fallback is
`flex_eager=1`. See [verification](#lazy-row-verification).
