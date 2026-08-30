#!/usr/bin/env bash
# Regression lint for the 2026-07-10 audit MAJOR: the 2FA gate must not be
# bypassable by a stale `totp_verified` surviving logout / re-login. Two source
# invariants in AuthController must hold (grep-level, no DB/session runtime):
#
#   1. logoutAction() fully wipes the session (session_destroy), not just the
#      identity — else totp_verified persists past logout.
#   2. completeLogin() removes totp_verified BEFORE it reads the 2FA gate — so a
#      stale flag from a prior login in the same session cannot skip the check.
#
# Exit 0 = invariants present, 1 = a regression.
set -euo pipefail

f="$(dirname "$0")/../src/Kernel/Controller/AuthController.php"
rc=0

if ! grep -q 'session_destroy()' "$f"; then
	echo "FAIL: AuthController logout no longer calls session_destroy() — totp_verified can survive logout"
	rc=1
else
	echo "  ok   logout wipes the session (session_destroy present)"
fi

# The removal must sit inside completeLogin, above the gate that reads it.
# Accept both the legacy magic-property syntax and the equivalent
# SessionStorage API used by MagicPropertyStorage.
remove_line=$(grep -nF \
	-e "unset(\$session->totp_verified" \
	-e "\$session->remove('totp_verified')" \
	"$f" | head -1 | cut -d: -f1 || true)
gate_line=$(grep -nF \
	-e "!\$session->totp_verified" \
	-e "!\$session->get('totp_verified')" \
	"$f" | head -1 | cut -d: -f1 || true)
if [ -z "$remove_line" ] || [ -z "$gate_line" ] || [ "$remove_line" -ge "$gate_line" ]; then
	echo "FAIL: completeLogin must remove totp_verified before the 2FA gate reads it"
	rc=1
else
	echo "  ok   completeLogin resets totp_verified before the gate (line $remove_line < $gate_line)"
fi

[ "$rc" -eq 0 ] && echo "OK: 2FA session-hygiene invariants hold"
exit "$rc"
