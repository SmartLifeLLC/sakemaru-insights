#!/usr/bin/env bash
set -euo pipefail

GROUP_NAME="laravel"

# Ubuntu前提: Apache実行ユーザーは www-data
DEPLOY_USER="smtlife"
WEB_USER="www-data"

# 共有グループ作成
sudo groupadd -f "${GROUP_NAME}"

# 必要ユーザーを追加
sudo usermod -aG "${GROUP_NAME}" "${DEPLOY_USER}"
sudo usermod -aG "${GROUP_NAME}" "${WEB_USER}"

# rootも必要なら追加
sudo usermod -aG "${GROUP_NAME}" root

# storage と bootstrap/cache だけ対象
sudo chgrp -R "${GROUP_NAME}" ./storage ./bootstrap/cache

# 基本権限
sudo find ./storage -type d -exec chmod 2775 {} \;
sudo find ./storage -type f -exec chmod 664 {} \;

sudo find ./bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find ./bootstrap/cache -type f -exec chmod 664 {} \;

# ACL: 既存 + 今後作成分
sudo setfacl -R -m g:${GROUP_NAME}:rwx ./storage ./bootstrap/cache
sudo setfacl -R -d -m g:${GROUP_NAME}:rwx ./storage ./bootstrap/cache
