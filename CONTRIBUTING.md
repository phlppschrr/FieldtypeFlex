# Contributing to FieldtypeFlex

Thanks for helping improve FieldtypeFlex. Contributions should preserve native ProcessWire form behavior, canonical JSON data and safe media ownership.

## Before changing code

Read [README.md](README.md) and the relevant section of [API.md](API.md). For changes to storage, selectors, schema evolution or repeatable/media behavior, read [docs/architecture.md](docs/architecture.md) first.

Use the disposable DDEV fixture described in [docs/development.md](docs/development.md). It lives under the ignored .dev/ directory. Do not run fixture seed or test scripts against a production site.

## Changes and review

Start features on `feature/<short-name>` branches from current `main`. Record every enhancement and fix in [CHANGELOG.md](CHANGELOG.md). When merging into `main`, increase the version using SemVer (major for breaking changes, minor for compatible features, patch for compatible fixes), keeping module metadata, the documented version and dated changelog entries consistent. Use SemVer prerelease identifiers where applicable.

Keep changes focused and use the existing PHP and JavaScript conventions. Add or update integration coverage for behavior changes, including failure paths where data integrity is involved. For admin input changes, verify a real ProcessPageEdit save and inspect browser errors.

Before opening a pull request, run the applicable fixture checks and review the diff. Describe the user-visible behavior, the reason for the change and the verification you performed. Do not include private site data, passwords, generated fixtures or local logs.

## Packaging

Build an installation ZIP from a committed module version:

```sh
python3 scripts/package.py HEAD /tmp/FieldtypeFlex.zip
```

The archive retains documentation and runtime examples. Tests, fixture setup and
CI remain in the source repository. Releases use commits and tags from this
repository.

By contributing, you agree that your changes are provided under the project's [MIT License](LICENSE).
