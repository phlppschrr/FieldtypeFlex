# Changelog

## Unreleased — curated examples

- Replace overlapping examples with ten focused, verified configuration definitions: identical JSON, PHP-array and InputfieldWrapper basics; typed core Inputfields; advanced settings; typed/nested Rows; and a composable Kitchen Sink.
- Demonstrate native `columnWidth`, explicit `TextformatterEntities` and schema reuse within typed Rows. The Kitchen Sink uses ordinary PHP array spread and `require`; a declarative import directive is only a future proposal.
- Keep InputfieldWrapper as a public definition format pending a separate decision. Update the example catalog, translations, fixture tests and DDEV seed references.

## Unreleased — automatic projections and media cleanup

- Keep native `emptyPageField()` atomic with row projections and media ownership,
  using the same table-deletion path as `deletePageField()`.
- Remove the public `rebuildRowIndex()` and `collectFiles()` wrappers; retain
  projection repair and collection only in internal services.
- Automatically process bounded media batches at native request completion in
  Admin, CLI and PHP; defer open outer transactions, preserve references and retry
  failed/protected records fairly with a durable cursor. Cleanup failures are logged.
- Clarify six-hour staging expiry and the need for later PHP execution, without
  requiring cron jobs or application maintenance calls.

## Unreleased — data preservation and explicit maintenance

- Always retain unknown properties, nested data and owned media on normal saves,
  raw serialization and copies. Remove `flexPrune`; old metadata is ignored.
- Add shared batched rename checking/application and potentially orphaned property
  inventory/selected cleanup APIs, native `flex` CLI and superuser field configuration.
- Bind previews to data/schema revisions; preserve per-field atomicity, ownership,
  retry cursors and conservative post-commit file collection.
- Recommend complete definitions with native hidden/locked collapsed states.

## 0.3.0 (pre-release)

- Expanded native field comparison coverage and added a separate CI baseline for 13 Core Fieldtypes. Fixed blank date values incorrectly matching less-than date ranges, including in rows.

- Added root Page-reference selector traversal through supported native target Fields, with native PageFinder matching, single/multiple references and selector discovery. Documented query costs and explicit unsupported paths/operators.
- Aligned Page reference loading, formatting, assignment and selection validation with native FieldtypePage; editor submissions replace selections, including in rows.
- Aligned unformatted Datetime reads with native Unix timestamps, formatted multilingual reads with strings, Float precision and default Checkbox/Toggle values. Added native Email/URL normalization; canonical JSON formats remain unchanged.
- Added direct native-field comparison tests and documented the explicit boundaries of Fieldtype compatibility.

- Added the explicit `rows` structure type with `addRowLabel`, `rowCollapse` and per-type `rowTitle`; native wrapper definitions use `InputfieldFlexRows`.

- Simplified editor error handling and shared technical messages; invalid repeatable presentation settings now use safe defaults. Updated bundled translations to remove obsolete phrases.

- Bundled German, French and Spanish admin translations, including editor messages and the example catalog. Install or update them through Module Information → Languages → install translations.
- Added typed rows collections with stable row IDs and per-type schemas.
- Added recursive nested rows collections, parent-aware selectors and subtree duplication.
- Added Flex-managed File and Image properties with staged edits, native-compatible media objects and cleanup.
- Added use inside native ProcessWire Repeater items.
- Added typed row selectors, collection counts and same-row expressions.
- Added definition source diagnostics, property-label helpers and configurable resource budgets.
- Established the versioned rows:1 envelope for typed repeatable data.
