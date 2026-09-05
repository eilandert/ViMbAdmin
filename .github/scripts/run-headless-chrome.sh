#!/usr/bin/env bash
set -euo pipefail

readonly image='cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d'

profile=''
for arg in "$@"; do
  case "$arg" in
  --user-data-dir=*)
    profile=${arg#--user-data-dir=}
    ;;
  esac
done

if [[ -z "$profile" || "${profile##*/}" != profile ]]; then
  echo 'FAIL: Chrome container requires a test-owned --user-data-dir' >&2
  exit 64
fi

readonly fixture_dir=${profile%/profile}
if [[ ! $fixture_dir =~ ^/tmp/vimbadmin-(alias-destination|residual-stored-xss|confirm-guard)\.[[:alnum:]]+$ ]] ||
  [[ -L $fixture_dir ]] ||
  [[ ! -d $fixture_dir || ! -w $fixture_dir ]] ||
  [[ $(stat -c '%u:%a' "$fixture_dir") != "$(id -u):700" ]]; then
  echo 'FAIL: Chrome container requires a private test fixture directory' >&2
  exit 64
fi

exec docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges \
  --user "$(id -u):$(id -g)" \
  --env "HOME=$fixture_dir" \
  --mount "type=bind,src=$fixture_dir,dst=$fixture_dir" \
  "$image" google-chrome --no-sandbox "$@"
