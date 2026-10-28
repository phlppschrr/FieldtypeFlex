#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .dev
if [ ! -f .dev/pw/index.php ]; then
    git init .dev/pw
    git -C .dev/pw remote add origin https://github.com/processwire/processwire.git
    git -C .dev/pw fetch --depth 1 origin "$(cat scripts/processwire-ref.txt)"
    git -C .dev/pw checkout --detach FETCH_HEAD
fi
if [ ! -f .dev/admin-password ]; then
    umask 077
    openssl rand -hex 20 > .dev/admin-password
fi
ddev start
if [ ! -f .dev/pw/site/assets/installed.php ]; then
    ddev exec --dir=/var/www/html/.dev/pw php install.php --config /var/www/html/scripts/install-config.php
fi
mkdir -p .dev/pw/site/modules
if [ ! -e .dev/pw/site/modules/FieldtypeFlex ]; then
    ln -s ../../../.. .dev/pw/site/modules/FieldtypeFlex
fi
if [ "${FLEX_INSTALL_AGENTTOOLS:-0}" = "1" ]; then
    if [ ! -f .dev/pw/site/modules/AgentTools/AgentTools.module.php ]; then
        git init .dev/pw/site/modules/AgentTools
        git -C .dev/pw/site/modules/AgentTools remote add origin https://github.com/ryancramerdesign/AgentTools.git
        git -C .dev/pw/site/modules/AgentTools fetch --depth 1 origin "$(cat scripts/agenttools-ref.txt)"
        git -C .dev/pw/site/modules/AgentTools checkout --detach FETCH_HEAD
    fi
else
    printf 'Skipping optional AgentTools install. Set FLEX_INSTALL_AGENTTOOLS=1 to install the pinned copy.\n'
fi
ddev exec php /var/www/html/scripts/seed.php
printf 'Admin username: admin\nPassword file: .dev/admin-password\n'
