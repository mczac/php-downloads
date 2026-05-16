#!/usr/bin/env bash
#
# Deploy download_site + downloads_private to sumbaprop (cPanel) via rsync over SSH.
# Modeled after Sumba Core theme deploy.sh (working POI/latest/build/deploy.sh):
#   - SSH identity file (-i), keepalives, optional ControlMaster
#   - rsync_with_retry for transient SSH drops
#
# Prerequisites: rsync, SSH key allowed on server, ssh-agent with key loaded if passphrase-protected.
#
# Setup once:
#   cp scripts/deploy.env.example scripts/deploy.env
#   # Edit SSH_USER, REMOTE_* paths (cPanel File Manager shows /home/USER/...)
#   ssh-add --apple-use-keychain ~/.ssh/id_rsa_sumbaprop   # macOS: passphrase once
#   chmod +x scripts/deploy_downloads.sh
#
# Run from anywhere:
#   ./scripts/deploy_downloads.sh
#   DRY_RUN=1 ./scripts/deploy_downloads.sh
#
# Unlike theme deploy.sh we do NOT use --exclude='.*' — download_site includes .htaccess.
#
set -euo pipefail

export LC_ALL=C

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${DEPLOY_ENV_FILE:-${SCRIPT_DIR}/deploy.env}"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing ${ENV_FILE}"
  echo "Copy scripts/deploy.env.example → scripts/deploy.env and edit."
  exit 1
fi

# If caller sets DRY_RUN on the command line, keep it after sourcing deploy.env
# (deploy.env may contain DRY_RUN=0 which would otherwise override DRY_RUN=1 ./script.sh).
if [[ "${DRY_RUN+isset}" == "isset" ]]; then
  _DEPLOY_DRY_RUN_CLI="${DRY_RUN}"
fi

# shellcheck source=/dev/null
source "${ENV_FILE}"

if [[ "${_DEPLOY_DRY_RUN_CLI+isset}" == "isset" ]]; then
  DRY_RUN="${_DEPLOY_DRY_RUN_CLI}"
  unset _DEPLOY_DRY_RUN_CLI
fi

: "${SSH_HOST:?Set SSH_HOST in deploy.env}"
: "${SSH_USER:?Set SSH_USER in deploy.env}"
: "${REMOTE_PUBLIC:?Set REMOTE_PUBLIC in deploy.env}"
: "${REMOTE_PRIVATE:?Set REMOTE_PRIVATE in deploy.env}"

SSH_PORT="${SSH_PORT:-22}"
SSH_KEY="${SSH_KEY:-${HOME}/.ssh/id_rsa_sumbaprop}"
USE_SSH_MUX="${USE_SSH_MUX:-0}"
RSYNC_CHECKSUM="${RSYNC_CHECKSUM:-0}"
PUBLIC_DELETE="${PUBLIC_DELETE:-0}"
DRY_RUN="${DRY_RUN:-0}"

if [[ ! -f "${SSH_KEY}" ]]; then
  echo "SSH key not found: ${SSH_KEY}"
  echo "Set SSH_KEY in deploy.env or install the key at ~/.ssh/id_rsa_sumbaprop"
  exit 1
fi

REMOTE="${SSH_USER}@${SSH_HOST}"
CTRL_SOCKET="/tmp/deploy-php-downloads-$$"

RSYNC_OPTS=(-avz)
if [[ "${RSYNC_CHECKSUM}" == "1" ]]; then
  RSYNC_OPTS+=(--checksum)
fi

if [[ "${DRY_RUN}" == "1" ]]; then
  RSYNC_OPTS+=(-n)
  echo "DRY RUN (rsync -n — no file changes)"
fi

# SSH command string passed to rsync -e (matches Sumba Core deploy.sh pattern)
if [[ "${USE_SSH_MUX}" == "1" ]]; then
  SSH_CMD="ssh -p ${SSH_PORT} -i ${SSH_KEY} -o ControlMaster=auto -o ControlPath=${CTRL_SOCKET} -o ControlPersist=300 -o ServerAliveInterval=15 -o ServerAliveCountMax=6"
else
  SSH_CMD="ssh -p ${SSH_PORT} -i ${SSH_KEY} -o ServerAliveInterval=10 -o ServerAliveCountMax=6"
fi

rsync_with_retry() {
  local err
  for attempt in 1 2 3; do
    if "$@"; then
      return 0
    fi
    err=$?
    if [[ ${attempt} -lt 3 ]] && { [[ ${err} -eq 11 ]] || [[ ${err} -eq 255 ]]; }; then
      echo "    ⚠ Connection dropped (exit ${err}), retrying in 5s… (${attempt}/3)"
      sleep 5
    else
      return "${err}"
    fi
  done
  return 1
}

cleanup() {
  if [[ "${USE_SSH_MUX}" == "1" ]]; then
    ssh -O exit -o ControlPath="${CTRL_SOCKET}" "${REMOTE}" 2>/dev/null || true
  fi
}
trap cleanup EXIT

if [[ "${USE_SSH_MUX}" == "1" ]]; then
  ssh -fN -p "${SSH_PORT}" -i "${SSH_KEY}" \
    -o ControlMaster=yes -o ControlPath="${CTRL_SOCKET}" -o ControlPersist=300 \
    -o ServerAliveInterval=15 -o ServerAliveCountMax=6 \
    "${REMOTE}" 2>/dev/null || true
fi

RSYNC_EXCLUDES=(--exclude '.DS_Store' --exclude '.git/' --exclude '._*')

echo ""
echo "📦 PHP downloads deploy → ${REMOTE}"
echo "   Public:  ${REMOTE_PUBLIC}/"
echo "   Private: ${REMOTE_PRIVATE}/"
echo ""

if [[ "${DRY_RUN}" != "1" ]]; then
  echo "→ Ensure remote directories exist…"
  ${SSH_CMD} "${REMOTE}" "mkdir -p $(printf '%q' "${REMOTE_PUBLIC}") $(printf '%q' "${REMOTE_PRIVATE}") $(printf '%q' "${REMOTE_PRIVATE}/documents") $(printf '%q' "${REMOTE_PRIVATE}/lib")"
fi

if [[ "${PUBLIC_DELETE}" == "1" ]]; then
  echo "→ Public rsync uses --delete"
fi

echo "→ [1/2] download_site/ → public docroot"
if [[ "${PUBLIC_DELETE}" == "1" ]]; then
  rsync_with_retry rsync "${RSYNC_OPTS[@]}" --delete "${RSYNC_EXCLUDES[@]}" \
    -e "${SSH_CMD}" \
    "${ROOT}/download_site/" \
    "${REMOTE}:${REMOTE_PUBLIC}/"
else
  rsync_with_retry rsync "${RSYNC_OPTS[@]}" "${RSYNC_EXCLUDES[@]}" \
    -e "${SSH_CMD}" \
    "${ROOT}/download_site/" \
    "${REMOTE}:${REMOTE_PUBLIC}/"
fi

echo "→ [2/2] downloads_private/ → private app (server-only bootstrap.php / configs untouched)"
rsync_with_retry rsync "${RSYNC_OPTS[@]}" "${RSYNC_EXCLUDES[@]}" \
  -e "${SSH_CMD}" \
  "${ROOT}/downloads_private/" \
  "${REMOTE}:${REMOTE_PRIVATE}/"

echo ""
echo "✅ Deploy complete."
if [[ "${DRY_RUN}" == "1" ]]; then
  echo "   This was a dry run; set DRY_RUN=0 in deploy.env to apply."
fi
