# Development

FieldtypeFlex uses a disposable ProcessWire installation under .dev/pw. The module is loaded from the repository root through a local module symlink. Generated fixture files, database data and the local admin password remain under ignored .dev/.

## Requirements

- Git, DDEV and Docker
- PHP and database versions provided by the configured DDEV project
- Network access for the first fixture setup to fetch the pinned ProcessWire core and development tools

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
syntax checks). Run the JS cases alone with
`node tests/js/row-title-parts.cjs`; no package install is needed. The test loads
the actual private functions from FieldtypeFlex.js and fails if their boundaries
change; it does not maintain a second parser or add production test exports.

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
