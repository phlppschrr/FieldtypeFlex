# PHP conventions

Follow the installed ProcessWire core's PHP style throughout the module, examples,
fixture scripts and tests. The repository's `.editorconfig` supplies whitespace
defaults.

- Use tabs for indentation, LF line endings and no trailing whitespace.
- Keep `<?php namespace ProcessWire;` together in namespaced files. Put opening
  braces on the declaration or control statement line: `function name() {`,
  `if($condition) {`, `foreach($items as $item) {` and `} else if(...) {`.
- Write method bodies and control blocks on multiple lines, with one statement per
  line and a blank line between methods. Keep related steps together.
- Expand configuration maps and long arrays, with one entry per line and a trailing
  comma. Align adjacent keys when useful; retain short scalar lists inline.
- Use short array syntax and retain the PHP 8.1 minimum. Keep signatures compatible
  with the native ProcessWire methods they override.
- Document every public method, including hook methods, trait methods and native
  overrides. State its purpose, parameters and return value; explain ownership,
  formatting or transaction requirements where they affect the caller.
- Translate module titles/summaries, admin labels, help text, action labels and
  displayed errors. Use `$this->_('Text')` in Wire instances and `__('Text')` in
  static methods or definition files. The latter discovers the calling file's
  textdomain automatically. Use an explicit domain when sharing another file's
  translations deliberately.
- Keep each translation call with its complete static string on one physical line,
  and use one translation call per line. Insert dynamic values with `sprintf()`
  placeholders, preferably numbered when there is more than one. Never translate
  stored keys, selectors, SQL, file paths or protocol values.

Review formatting separately from behavior changes. Do not reflow string literals
or executable PHP embedded in test fixtures. Run `ddev composer lint` and
`ddev flex-test` after repository-wide PHP changes; verify actual admin saves when
input behavior changes.
