#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
SUPERVISORCTL_BIN="${SUPERVISORCTL_BIN:-supervisorctl}"

read_env() {
  local key="$1"
  local default="${2:-}"
  local value

  value="$(grep -E "^${key}=" .env 2>/dev/null | tail -n 1 | cut -d '=' -f2- || true)"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"

  if [[ -n "${value}" ]]; then
    printf '%s' "${value}"
  else
    printf '%s' "${default}"
  fi
}

DEPLOY_BRANCH="${DEPLOY_BRANCH:-$(read_env DEPLOY_BRANCH deploy/hana)}"
SUPERVISOR_PROGRAM="${SUPERVISOR_PROGRAM:-$(read_env SUPERVISOR_PROGRAM "")}"
USE_NPM="${USE_NPM:-$(read_env USE_NPM 1)}"
RUN_MIGRATE="${RUN_MIGRATE:-$(read_env RUN_MIGRATE 1)}"
RUN_BUILD="${RUN_BUILD:-$(read_env RUN_BUILD 1)}"

LOCK_FILE="/tmp/$(basename "$APP_DIR")-deploy.lock"

cleanup() {
  cd "$APP_DIR" || exit 1
  ${PHP_BIN} artisan up || true
  if [[ -n "${MONITOR_TIMERS:-}" ]]; then
    sudo systemctl start ${MONITOR_TIMERS} || true
  fi
  # デプロイ窓を閉じる（timer 再開後。開始に失敗していても無害）
  ${PHP_BIN} artisan monitor:deploy-window end >/dev/null 2>&1 || true
}
trap cleanup EXIT

exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  echo "ERROR: another deploy is already running"
  exit 1
fi

cd "$APP_DIR"

echo "======================================"
echo "DEPLOY START"
echo "APP_DIR           : $APP_DIR"
echo "DEPLOY_BRANCH     : $DEPLOY_BRANCH"
echo "SUPERVISOR_PROGRAM: ${SUPERVISOR_PROGRAM:-<none>}"
echo "USE_NPM           : $USE_NPM"
echo "RUN_MIGRATE       : $RUN_MIGRATE"
echo "RUN_BUILD         : $RUN_BUILD"
echo "======================================"

if [[ ! -f artisan ]]; then
  echo "ERROR: artisan not found"
  exit 1
fi

if [[ ! -f .env ]]; then
  echo "ERROR: .env not found"
  exit 1
fi

# 運用監視（算盤）の systemd unit: デプロイ中はキャッシュ再生成の隙間に timer が起動して例外を記録するため止める。
# 終了後に常駐（通知配送・資源収集）を再起動して新しいコードに入れ替える。sudo が無い環境では何もしない
MONITOR_DEPLOYMENT_ID="${MONITOR_DEPLOYMENT_ID:-$(read_env MONITOR_DEPLOYMENT_ID "")}"
MONITOR_TIMERS=""
MONITOR_SERVICES=""
if [[ -n "${MONITOR_DEPLOYMENT_ID}" ]] && command -v systemctl >/dev/null 2>&1 && sudo -v; then
  for u in checks notify retention health detect advisories resources collect; do
    case "$u" in
      notify|resources) unit="sakemaru-monitor-${u}@${MONITOR_DEPLOYMENT_ID}.service" ;;
      *) unit="sakemaru-monitor-${u}@${MONITOR_DEPLOYMENT_ID}.timer" ;;
    esac
    if sudo systemctl is-enabled "${unit}" >/dev/null 2>&1; then
      case "$u" in
        notify|resources) MONITOR_SERVICES="${MONITOR_SERVICES} ${unit}" ;;
        *) MONITOR_TIMERS="${MONITOR_TIMERS} ${unit}" ;;
      esac
    fi
  done
fi
if [[ -n "${MONITOR_TIMERS}" ]]; then
  echo "-> monitor timers stop:${MONITOR_TIMERS}"
  sudo systemctl stop ${MONITOR_TIMERS} || true
fi

# デプロイ窓を開く（30 分で自動的に閉じる）。この間、監視の timer 停止を自己監視の障害にしない
echo "-> monitor deploy window start"
${PHP_BIN} artisan monitor:deploy-window start --minutes=30 --reason="デプロイ ${DEPLOY_BRANCH}" || true

echo "-> maintenance mode ON"
${PHP_BIN} artisan down || true

echo "-> fetch latest"
git fetch origin

echo "-> checkout branch: ${DEPLOY_BRANCH}"
git checkout "${DEPLOY_BRANCH}"

echo "-> pull latest"
git pull origin "${DEPLOY_BRANCH}" --no-edit

echo "-> composer install"
${COMPOSER_BIN} install \
  --no-interaction \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader

if [[ "${RUN_MIGRATE}" == "1" ]]; then
  echo "-> migrate"
  ${PHP_BIN} artisan migrate --force
else
  echo "-> skip migrate"
fi

echo "-> cache hard clear"
${PHP_BIN} artisan cache:hard-clear

if [[ "${USE_NPM}" == "1" && -f package.json ]]; then
  if [[ "${RUN_BUILD}" == "1" ]]; then
    if [[ -f package-lock.json ]]; then
      echo "-> npm ci"
      ${NPM_BIN} ci
    else
      echo "-> npm install"
      ${NPM_BIN} install
    fi

    echo "-> npm run build"
    ${NPM_BIN} run build
  else
    echo "-> skip frontend build"
  fi
else
  echo "-> no frontend build"
fi



if [[ -n "${SUPERVISOR_PROGRAM}" ]]; then
  echo "-> supervisor restart ${SUPERVISOR_PROGRAM}:*"
  ${SUPERVISORCTL_BIN} restart "${SUPERVISOR_PROGRAM}:*" || true
else
  echo "-> no supervisor program configured"
fi

echo "-> maintenance mode OFF"
${PHP_BIN} artisan up

if [[ -n "${MONITOR_SERVICES}" ]]; then
  echo "-> monitor services restart:${MONITOR_SERVICES}"
  sudo systemctl restart ${MONITOR_SERVICES} || true
fi
if [[ -n "${MONITOR_TIMERS}" ]]; then
  echo "-> monitor timers start:${MONITOR_TIMERS}"
  sudo systemctl start ${MONITOR_TIMERS} || true
fi

trap - EXIT

echo "======================================"
echo "DEPLOY SUCCESS"
echo "======================================"
