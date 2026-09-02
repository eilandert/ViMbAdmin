#!/usr/bin/env bash
set -euo pipefail

readonly destination=${1:?usage: fetch-semgrep-rules.sh DESTINATION}
readonly registry=${SEMGREP_REGISTRY_URL:-https://semgrep.dev/c}
temporary=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-semgrep-rules.XXXXXX")
readonly temporary

cleanup() {
  rm -rf -- "$temporary"
}
trap cleanup EXIT

fetch_locked() {
  local name=$1 expected_sha256=$2
  local downloaded=$temporary/$name.yml

  curl --fail --silent --show-error --location \
    --proto '=https' --tlsv1.2 \
    "$registry/p/$name" --output "$downloaded"
  printf '%s  %s\n' "$expected_sha256" "$downloaded" | sha256sum --check --status
}

# Semgrep Registry packs are mutable aliases. These content hashes make a
# Registry change block CI until the reviewed lock is deliberately refreshed.
fetch_locked php \
  ccfc3341c7ac7a66b85f249713a277f4d4a0e9de93c75c609e2f82e52f0aabac
fetch_locked security-audit \
  b109a039df712f30c6d3e25e1e8358053fd0f1c91b92d0e8d2871cd141fe602f
fetch_locked secrets \
  139b35ad3442bc83d1f0864db82fa4fdc7e1f1ee4b5ac872bfbeb604c82c6518

mkdir -p -- "$destination"
install -m 0644 "$temporary"/*.yml "$destination"/
