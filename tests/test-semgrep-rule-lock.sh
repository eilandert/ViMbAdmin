#!/usr/bin/env bash
set -euo pipefail

readonly fetcher=$PWD/.github/scripts/fetch-semgrep-rules.sh
fixture_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-semgrep-rule-lock.XXXXXX")
readonly fixture_root

cleanup() {
  rm -rf -- "$fixture_root"
}
trap cleanup EXIT

mkdir -p "$fixture_root/bin"
cat > "$fixture_root/bin/curl" <<'SH'
#!/bin/sh
output=
url=
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) shift; output=${1:?missing output} ;;
    https://*) url=$1 ;;
  esac
  shift
done
[ -n "$output" ] && [ -n "$url" ] || exit 97
name=${url##*/}
case "$name" in
  php) content='php rules fixture' ;;
  security-audit) content='security audit rules fixture' ;;
  secrets) content='secrets rules fixture' ;;
  *) exit 98 ;;
esac
printf '%s\n' "$content" > "$output"
SH
chmod +x "$fixture_root/bin/curl"

# Exercise the real digest check with a private copy whose fixture hashes are
# deterministic; production hashes remain untouched.
cp "$fetcher" "$fixture_root/fetch.sh"
sed -i \
  -e 's/ccfc3341c7ac7a66b85f249713a277f4d4a0e9de93c75c609e2f82e52f0aabac/e0a7915fec66f1637e0eec5cafa07b5495d9c2b62fc5acdac627f38f56859932/' \
  -e 's/b109a039df712f30c6d3e25e1e8358053fd0f1c91b92d0e8d2871cd141fe602f/8020badbd14c95402d7be2c9a264f7da31c53fd3b827a5161c778e6b7f308293/' \
  -e 's/139b35ad3442bc83d1f0864db82fa4fdc7e1f1ee4b5ac872bfbeb604c82c6518/3d7e08aaed4bbd43d7a38447316d0bbd892b20520eedfca93b33299a20af8520/' \
  "$fixture_root/fetch.sh"

PATH="$fixture_root/bin:/usr/bin:/bin" \
  SEMGREP_REGISTRY_URL=https://registry.example.invalid/c \
  bash "$fixture_root/fetch.sh" "$fixture_root/rules"
[[ $(find "$fixture_root/rules" -type f -name '*.yml' | wc -l) -eq 3 ]]

sed -i 's/php rules fixture/tampered php rules fixture/' "$fixture_root/bin/curl"
if PATH="$fixture_root/bin:/usr/bin:/bin" \
  SEMGREP_REGISTRY_URL=https://registry.example.invalid/c \
  bash "$fixture_root/fetch.sh" "$fixture_root/tampered" \
  >"$fixture_root/output" 2>&1; then
  printf 'Semgrep rule lock accepted mutated Registry content.\n' >&2
  exit 1
fi
[[ ! -e $fixture_root/tampered ]]

printf 'Semgrep Registry inputs are content-locked and fail closed.\n'
