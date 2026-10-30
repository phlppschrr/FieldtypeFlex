#!/usr/bin/env python3
"""Build an installation ZIP from a committed FieldtypeFlex source tree."""

from __future__ import annotations

import argparse
import io
from pathlib import Path
import subprocess
import sys
import zipfile


ROOT = Path(__file__).resolve().parents[1]
REQUIRED = {
    'API.md', 'LICENSE', 'README.md', 'FieldtypeFlex.module.php',
    'InputfieldFlex.module.php', 'InputfieldFlexRows.module.php',
    'FieldtypeFlex.js', 'FieldtypeFlex.css',
    'src/FlexValue.php', 'examples/README.md', 'languages/de.csv',
    'languages/fr.csv', 'languages/es.csv',
}
EXCLUDED = ('.dev/', '.ddev/', '.github/', 'scripts/', 'tests/', 'AGENTS.md', 'FieldtypeFlex.test.php')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('ref', help='committed module version, for example HEAD or a release tag')
    parser.add_argument('destination', type=Path, help='new ZIP outside the source repository')
    args = parser.parse_args()
    destination = args.destination.expanduser().resolve()
    try:
        if destination == ROOT or ROOT in destination.parents:
            raise ValueError('ZIP destination must be outside the source repository')
        source_root = Path(subprocess.check_output(
            ['git', '-C', str(ROOT), 'rev-parse', '--show-toplevel'], text=True
        ).strip()).resolve()
        if source_root != ROOT:
            raise ValueError('Run packaging from an independent module repository')
        commit = subprocess.check_output(
            ['git', '-C', str(ROOT), 'rev-parse', '--verify', '--end-of-options', f'{args.ref}^{{commit}}'],
            text=True,
        ).strip()
        archive = subprocess.check_output(
            ['git', '-C', str(ROOT), 'archive', '--format=zip', '--prefix=FieldtypeFlex/', commit]
        )
        with zipfile.ZipFile(io.BytesIO(archive)) as package:
            files = {name.removeprefix('FieldtypeFlex/') for name in package.namelist() if not name.endswith('/')}
            missing = REQUIRED - files
            if missing:
                raise ValueError(f'Archive is missing required files: {", ".join(sorted(missing))}')
            forbidden = sorted(path for path in files if path.startswith(EXCLUDED))
            if forbidden:
                raise ValueError(f'Archive contains development files: {", ".join(forbidden)}')
            if package.testzip() is not None:
                raise ValueError('Archive integrity check failed')
        with destination.open('xb') as output:
            output.write(archive)
        print(f'Created {destination} from {commit[:12]} ({len(files)} files)')
        return 0
    except (OSError, ValueError, subprocess.CalledProcessError, zipfile.BadZipFile) as error:
        print(f'Package failed: {error}', file=sys.stderr)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
