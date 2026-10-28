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
Run suites sequentially against one fixture; their owned baseline field name is shared.

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

The reusable demo seed is ddev flex-seed. The nested demo has a separate guarded seed:

    ddev exec php scripts/seed-nested.php

Only use these scripts with the disposable .dev/pw installation.
