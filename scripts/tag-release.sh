#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: ./scripts/tag-release.sh [--local-only]

Creates a git tag from the module version defined in:
  internautengooglefeed/internautengooglefeed.php

By default, the script creates the local tag and pushes it to `origin`.

Options:
  --local-only  Create the tag locally without pushing it
  -h, --help    Show this help
EOF
}

push_tag=true

while [[ $# -gt 0 ]]; do
  case "$1" in
    --local-only)
      push_tag=false
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
MODULE_FILE="${REPO_ROOT}/internautengooglefeed/internautengooglefeed.php"

if [[ ! -f "${MODULE_FILE}" ]]; then
  echo "Module file not found: ${MODULE_FILE}" >&2
  exit 1
fi

if ! git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "No git repository found at ${REPO_ROOT}" >&2
  exit 1
fi

version="$(grep -E "version[[:space:]]*=[[:space:]]*'[^']+'" "${MODULE_FILE}" | head -n 1 | sed -E "s/.*'([^']+)'.*/\1/")"

if [[ -z "${version}" ]]; then
  echo "Could not read module version from ${MODULE_FILE}" >&2
  exit 1
fi

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid version format: ${version}" >&2
  echo "Expected semantic version like 1.2.3" >&2
  exit 1
fi

tag="v${version}"

if [[ -n "$(git -C "${REPO_ROOT}" status --porcelain)" ]]; then
  echo "Working tree is not clean. Commit or stash your changes before tagging." >&2
  exit 1
fi

if git -C "${REPO_ROOT}" rev-parse "${tag}" >/dev/null 2>&1; then
  echo "Tag ${tag} already exists locally."
else
  git -C "${REPO_ROOT}" tag -a "${tag}" -m "Release ${tag}"
  echo "Created tag ${tag} from module version ${version}."
fi

if [[ "${push_tag}" == true ]]; then
  if git -C "${REPO_ROOT}" ls-remote --exit-code --tags origin "refs/tags/${tag}" >/dev/null 2>&1; then
    echo "Tag ${tag} already exists on origin."
  else
    git -C "${REPO_ROOT}" push origin "${tag}"
    echo "Pushed ${tag} to origin."
  fi
else
  echo "Created local tag only. Skipped push to origin."
fi
