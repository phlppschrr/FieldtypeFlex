# Changelog

## WIP

### Changed

- Place the collection Actions menu beside the add control, reuse it for bulk actions, and align the selection count to the right.

## 1.0.0-beta.3 - 2026-11-17

### Fixed

- Add padding to table choices, activate native fields and detail editors from empty cell space, and keep tag suggestions visible beyond the table edge.
- Let TinyMCE previews use the available row height for multiple lines.
- Avoid a TinyMCE error when leaving a table cell before its editor is ready.

## 1.0.0-beta.2 - 2026-11-17

### Added

- Paste from the collection header, and select, copy, hide or mark multiple rows for removal.
- Resolve pasted Page references across installations by exact page path, parent path and template name.
- Copy rows through the browser clipboard and paste them into compatible collections, with warnings for values or files that could not be imported.

### Changed

- Add rows directly from the type picker and use a button for collections with a single type.
- Show an icon and a text label for every action in the table row menu.
- Use checkboxes for row selection and make the entire table row select on click in selection mode.

### Fixed

- Open the collection menu above the editor without adding a scrollbar to the field header.
- Show native upload errors, including the configured file limit, when a Flex upload is rejected.

## 1.0.0-beta.1 - 2026-11-16

### Added

- Edit iframe and inline TinyMCE fields in a full-width table detail row.
- Open File and PageList editors in a full-width table detail row.
- Support Checkboxes, Radios and TextTags for Page references in table cells.
- Add the `rowTitleValues` and `rowTitleParts` hooks for custom tokens and structured title overrides, with live updates from unsaved editor values.

### Changed

- **Breaking:** Migrate `rowTitle` from `heading` to `{heading}`, `#n` to `#{@index}`, `_{heading}_` to `{heading}{.flex-badge}`, and `[{heading}]` to `{heading}{.flex-muted}`. Bracketed groups now represent optional segments and accept adjacent `{.class}` attributes.
- Keep the card sort handle visible and reveal the compact row actions and chevron on hover or keyboard focus. Dim individual action icons on hover.
- Move the table sort handle to the first column and group Duplicate and Hide/Show in a disclosure next to Remove.
- Show image thumbnails in table cells and open the native editor in a full-width detail row that closes when focus moves to another cell.
- Match native Repeater headers in Konkat, including the bold header option.

### Fixed

- Remove the extra Inputfield frame and padding from the table image editor.
- Remove the doubled border around card rows in Konkat.
- Honor the focused-input background option on editable table headings.
- Preserve configured unchecked values when saving and reopening native Checkbox inputs.

## 0.7.0 - 2026-11-11

### Added

- Add tables with native editable cells, optional headers and row labels, column widths, and shared Rows actions.
- Allow editors to drag column dividers to resize table columns until the page is reloaded.

### Changed

- Show editable header values as column titles and keep multilingual cells compact with language tabs.
- Reduce nested field borders and spacing in the table editor, including the row actions.
- Give Uikit and Konkat tables bold gray headings, white data cells and a transparent header above the row actions.
- Focus native inputs when clicking their table cells, stretch text fields to the row height and tighten language tabs.

### Fixed

- Distinguish classic Uikit's admin global from Bridge when initializing inputs and inserting AJAX rows.

## 0.6.1 - 2026-11-09

### Changed

- Move rename and cleanup tools to individual native sections in the field Actions tab.

### Fixed

- Restore execution of documented CLI examples in integration tests and configure the DDEV Composer root version.

## 0.6.0 - 2026-11-08

### Added

- Add PHPDoc class stubs and usage examples to field settings, with a hookable `getPhpDoc()` API for custom exports.

## 0.5.1 - 2026-11-02

### Fixed

- Fix double application of textformatters to formatted Language Alternate values.

## 0.5.0 - 2026-11-02

### Added

- Add native AdminThemeBridge 0.24.0+ support for nested Rows and media.
- Support Language Alternate Fields such as `image` / `image_de` with native fallback.
- Add fixed Objects, configurable Row titles, visibility, lazy loading and removal with undo.

### Changed

- Migrate admin JavaScript to vanilla TypeScript and retain support for classic admin themes.
- Improve nested editing, duplication, dependencies and File/Image metadata handling.
- Simplify field configuration, examples and maintenance tools.
- Improve selectors, data preservation, media cleanup and integration coverage.

## 0.3.0 (pre-release)

### Added

- Expand native field comparison coverage and add a separate CI baseline for 13 Core Fieldtypes.
- Add root Page-reference selector traversal through supported native target Fields, with native PageFinder matching, single/multiple references and selector discovery. Document query costs and explicit unsupported paths/operators.
- Add direct native-field comparison tests and document the explicit boundaries of Fieldtype compatibility.
- Add the explicit `rows` structure type with `addRowLabel`, `rowCollapse` and per-type `rowTitle`. Native wrapper definitions use `InputfieldFlexRows`.
- Bundled German, French and Spanish admin translations, including editor messages and the example catalog. Install or update them through Module Information → Languages → install translations.
- Added typed rows collections with stable row IDs and per-type schemas.
- Added recursive nested rows collections, parent-aware selectors and subtree duplication.
- Added Flex-managed File and Image properties with staged edits, native-compatible media objects and cleanup.
- Added use inside native ProcessWire Repeater items.
- Added typed row selectors, collection counts and same-row expressions.
- Added definition source diagnostics, property-label helpers and configurable resource budgets.
- Established the versioned rows:1 envelope for typed repeatable data.

### Changed

- Align Page reference loading, formatting, assignment and selection validation with native FieldtypePage. Editor submissions replace selections, including in rows.
- Align unformatted Datetime reads with native Unix timestamps, formatted multilingual reads with strings, Float precision and default Checkbox/Toggle values. Add native Email/URL normalization and preserve canonical JSON formats.
- Simplify editor error handling and shared technical messages. Invalid repeatable presentation settings use safe defaults. Update bundled translations to remove obsolete phrases.

### Fixed

- Fix blank date values incorrectly matching less-than date ranges, including in rows.
