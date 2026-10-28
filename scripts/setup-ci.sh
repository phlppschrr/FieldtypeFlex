#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [ -e .dev/pw/site/assets/installed.php ]; then
    printf 'CI setup requires a fresh fixture.\n' >&2
    exit 1
fi
mkdir -p .dev
git init .dev/pw
git -C .dev/pw remote add origin https://github.com/processwire/processwire.git
core_ref_file=${FLEX_PROCESSWIRE_REF_FILE:-scripts/processwire-ref.txt}
if [ ! -f "$core_ref_file" ]; then
    printf 'ProcessWire ref file not found: %s\n' "$core_ref_file" >&2
    exit 1
fi
git -C .dev/pw fetch --depth 1 origin "$(cat "$core_ref_file")"
git -C .dev/pw checkout --detach FETCH_HEAD
umask 077
openssl rand -hex 20 > .dev/admin-password
repo_root=$PWD
if grep -Fq 'class InstallerCli extends Installer' .dev/pw/install.php; then
    (
        cd .dev/pw
        php install.php --config "$repo_root/scripts/install-config.php"
    )
else
    # ProcessWire 3.0.259 has only the web installer, so drive its install form locally.
    php scripts/install-ci-legacy.php "$repo_root/scripts/install-config.php"
fi
mkdir -p .dev/pw/site/modules
ln -s ../../../.. .dev/pw/site/modules/FieldtypeFlex
php scripts/seed.php
