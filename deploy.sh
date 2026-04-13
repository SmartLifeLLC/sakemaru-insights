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

trap - EXIT

echo "======================================"
echo "DEPLOY SUCCESS"
echo "======================================"
