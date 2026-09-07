#!/usr/bin/env bash
set -euo pipefail

readonly image='cimg/php@sha256:338e32e3a9ae908deab383e1731e4d5b2640cb2685684a748ee466db158b206d'
readonly workspace=${GITHUB_WORKSPACE:-$PWD}

if [[ ! -d $workspace || $PWD != "$workspace" ]]; then
  printf 'Static PHP container must run from the workspace root.\n' >&2
  exit 64
fi

uid_gid="$(id -u):$(id -g)"
readonly uid_gid

if [[ ${1:-} == install ]]; then
  runtime_home=$(mktemp -d "${RUNNER_TEMP:-/tmp}/vimbadmin-static-php.XXXXXX")
  readonly runtime_home
  cleanup() {
    # shellcheck disable=SC2317
    rm -rf -- "$runtime_home"
  }
  trap cleanup EXIT

  docker run --rm --network bridge --cap-drop ALL \
    --security-opt no-new-privileges \
    --user "$uid_gid" \
    --env "HOME=$runtime_home" \
    --mount "type=bind,src=$runtime_home,dst=$runtime_home" \
    --mount "type=bind,src=$workspace,dst=$workspace" \
    --workdir "$workspace" \
    "$image" composer install --no-interaction --no-progress --prefer-dist \
    --no-dev --no-scripts --ignore-platform-req=ext-gettext
  exit
fi

readonly writable_dir=${PHP_CONTAINER_WRITE_DIR:-}
if [[ ! $writable_dir =~ ^/tmp/vimbadmin-(alias-destination|residual-stored-xss|control-behaviour)\.[[:alnum:]]+$ ]] ||
  [[ -L $writable_dir ]] ||
  [[ ! -d $writable_dir || ! -w $writable_dir ]] ||
  [[ $(stat -c '%u:%a' "$writable_dir") != "$(id -u):700" ]]; then
  printf 'Static PHP renderer requires a private test fixture directory.\n' >&2
  exit 64
fi

exec docker run --rm --network none --cap-drop ALL \
  --security-opt no-new-privileges \
  --user "$uid_gid" \
  --env "HOME=$writable_dir" \
  --mount "type=bind,src=$workspace,dst=$workspace,readonly" \
  --mount "type=bind,src=$writable_dir,dst=$writable_dir" \
  --workdir "$workspace" \
  "$image" php "$@"
