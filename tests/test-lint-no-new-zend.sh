#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "$0")/.." && pwd)
fixture=$(mktemp -d)
cleanup() {
  rm -rf "$fixture"
}
trap cleanup EXIT

for directory in application library public bin src; do
  mkdir -p "$fixture/$directory"
done

run_lint() {
  VIMBADMIN_LINT_ROOT="$fixture" "$repo_root/tests/lint-no-new-zend.sh" >/dev/null 2>&1
}

printf '<?php echo "clean";\n' >"$fixture/src/clean.php"
run_lint

printf '<?php new Zend_Loader();\n' >"$fixture/src/planted.php"
if run_lint; then
  echo 'planted Zend symbol was not rejected' >&2
  exit 1
fi
rm "$fixture/src/planted.php" "$fixture/src/clean.php"

if run_lint; then
  echo 'empty runtime inventory was not rejected' >&2
  exit 1
fi

fake_find="$fixture/fake-find"
printf '#!/usr/bin/env bash\nexit 23\n' >"$fake_find"
chmod +x "$fake_find"
if VIMBADMIN_LINT_ROOT="$fixture" VIMBADMIN_FIND="$fake_find" "$repo_root/tests/lint-no-new-zend.sh" >/dev/null 2>&1; then
  echo 'failed inventory command was not rejected' >&2
  exit 1
fi

printf '<?php echo "partial";\n' >"$fixture/src/partial.php"
printf '#!/usr/bin/env bash\nprintf "src/partial.php\\0"\nexit 23\n' >"$fake_find"
if VIMBADMIN_LINT_ROOT="$fixture" VIMBADMIN_FIND="$fake_find" "$repo_root/tests/lint-no-new-zend.sh" >/dev/null 2>&1; then
  echo 'partially failed inventory command was not rejected' >&2
  exit 1
fi

echo 'lint-no-new-zend controls passed'
