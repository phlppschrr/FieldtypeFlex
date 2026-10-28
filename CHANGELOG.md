# Changelog

## Unreleased — dependency contexts

- Extend native `showIf` and `requiredIf` with `parent.`, `root.`, `page.` and
  ProcessWire's `forpage.` references. Distinguish owning Repeater items from
  the main editor Page and resolve containing Flex scopes recursively.
- Process external sources through native Inputfields for current submit values,
  unchecked checkboxes and chained conditions. Include current ancestor/Page
  inputs when duplicating Rows through AJAX.

## Unreleased — de-emphasized Row title text

- Support `[…]` groups around literal text, tokens and badges in `rowTitle`.
  Strip the grouping brackets and dim only the enclosed text on initial render
  and live updates; field values cannot introduce formatting.
- Restore full header text color for ordinary badges and white titles in the
  Original UIKit admin theme. Preserve the active theme palette elsewhere.

## Unreleased — configurable Row titles

- Add field fallbacks, explicit type/context tokens and optional badges to string
  `rowTitle` formats. Custom titles control type visibility; missing templates keep
  the existing default header.
- Use only ancestor type labels for compact breadcrumbs, independent of edited
  titles and row numbers. Omit empty badges and separators, retain zero and render
  values as safe text.

## Unreleased — named Rows collapse modes

- Expose `FieldtypeFlex::ROW_COLLAPSE_EXISTING`, `ROW_COLLAPSE_ALL` and
  `ROW_EXPAND_ALL`; document and use them in the Rows example.

## Unreleased — media description formatters

- Format File/Image descriptions with native Textformatters on formatted reads,
  including languages, nested Rows and image variations. Only configured or
  inherited lists run; absent or empty lists leave descriptions unchanged.
- Preserve raw metadata, revisions and change tracking during description reads,
  serialization and cloning. Public unformatted reads retain raw descriptions.
- Allow custom scalar text inputs to use inherited/explicit formatters without
  requiring an InputfieldText subclass. Ignore irrelevant formatter settings on
  non-text inputs; continue validating effective lists and modules.
- Demonstrate Entities configuration and direct image-description output in the
  media examples and README.

## Unreleased — integration corrections

- Refresh editor definitions for the current Page context while retaining raw
  edits, expected revisions and draft overlays; rebuild preloaded native inputs.
- Traverse native FieldsetPage values as single Pages and respect the concrete
  owner's fields when checking full-page Flex resource budgets.
- Inherit Textformatters only for supported Inputfields; keep explicit unsupported
  configurations invalid, including empty lists.
- Clarify that media descriptions require application output escaping and do not
  run native File/Image description Textformatters.

## Unreleased — review corrections

- Preserve JSON object/list differences as rename conflicts, including unknown
  nested Row values; equal values remain mergeable.
- Allow a revised cleanup selection to be previewed without repeating the orphan scan.
- Link all ten configuration examples, repair stale API links and clarify example
  comments and prerequisites.

## Unreleased — CLI inspection and batch selection

- Add native `schema`, `check` and `status` diagnostics with readable/JSON output.
- Support exact `--page` owners and bounded `--all` continuation, JSON-Lines streams,
  distinct owner aggregation and explicit stop/resume on failures.
- Reuse raw maintenance, definition/query discovery and projection derivation;
  inspect media without hydration, repair or file-manager initialization.
- Document diagnostic boundaries, lifecycle cleanup and exit codes 0/1/2/3.
  Optional native CLI, automatic maintenance and dependency pins stay unchanged.

## Unreleased — curated examples

- Replace overlapping examples with ten focused, verified configuration definitions: identical JSON, PHP-array and InputfieldWrapper basics; typed core Inputfields; advanced settings; typed/nested Rows; and a composable Kitchen Sink.
- Demonstrate native `columnWidth`, explicit `TextformatterEntities` and schema reuse within typed Rows. The Kitchen Sink uses ordinary PHP array spread and `require`.
- Include equivalent InputfieldWrapper definitions alongside PHP arrays and JSON. Update the example catalog, translations, fixture tests and DDEV seed references.

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
