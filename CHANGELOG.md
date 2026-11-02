# Changelog

## 0.5.1 — 2026-11-02

- Fix double application of textformatters to formatted Language Alternate values.

## 0.5.0 — 2026-11-02

- Migrate admin JavaScript to vanilla TypeScript; retain support for classic admin themes.
- Add native AdminThemeBridge 0.24.0+ support for nested Rows and media.
- Support Language Alternate Fields such as `image` / `image_de` with native fallback.
- Add fixed Objects, configurable Row titles, visibility, lazy loading and removal with undo.
- Improve nested editing, duplication, dependencies and File/Image metadata handling.
- Simplify field configuration, examples and maintenance tools.
- Improve selectors, data preservation, media cleanup and integration coverage.

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
