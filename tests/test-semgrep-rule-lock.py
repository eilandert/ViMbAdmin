#!/usr/bin/env python3
"""Test the Semgrep lock and the workflow's event-specific rule selection."""

import json
import re
import shlex
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LOCK = ROOT / ".semgrep" / "locked"
VERIFY = ROOT / ".github" / "scripts" / "verify-semgrep-lock.py"


def run_verifier(root: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(VERIFY), "--root", str(root)],
        check=False,
        text=True,
        capture_output=True,
    )


def run_semgrep(target: Path) -> dict:
    scan = subprocess.run(
        [
            "semgrep",
            "scan",
            "--config",
            str(LOCK),
            "--no-git-ignore",
            "--json",
            str(target),
        ],
        check=False,
        text=True,
        capture_output=True,
    )
    assert scan.returncode == 0, scan.stderr
    return json.loads(scan.stdout)


assert run_verifier(LOCK).returncode == 0, "reviewed rule bundle must verify"

with tempfile.TemporaryDirectory(prefix="vimbadmin-semgrep-lock-") as temporary:
    tampered = Path(temporary) / "locked"
    shutil.copytree(LOCK, tampered)
    manifest = json.loads((tampered / "manifest.json").read_text(encoding="utf-8"))
    victim = tampered / next(iter(manifest))
    victim.write_bytes(victim.read_bytes() + b"\n# tampered\n")
    result = run_verifier(tampered)
    assert result.returncode != 0, "tampered rule bundle must fail verification"
    assert "digest differs" in result.stderr, "failure must identify digest drift"

with tempfile.TemporaryDirectory(prefix="vimbadmin-semgrep-lock-") as temporary:
    extended = Path(temporary) / "locked"
    shutil.copytree(LOCK, extended)
    nested = extended / "unreviewed" / "extra.yml"
    nested.parent.mkdir()
    nested.write_text("rules: []\n", encoding="utf-8")
    result = run_verifier(extended)
    assert result.returncode != 0, "nested unreviewed rules must fail verification"
    assert "unexpected=['unreviewed/extra.yml']" in result.stderr

with tempfile.TemporaryDirectory(prefix="vimbadmin-semgrep-lock-") as temporary:
    fixture = Path(temporary) / "slack-webhooks.txt"
    prefix = "https://hooks.slack.com/services/"
    placeholder = prefix + "T" + "0" * 8 + "/B" + "0" * 8 + "/" + "X" * 24
    detected = prefix + "T" + "A" * 8 + "/B" + "B" * 8 + "/" + "C" * 24
    fixture.write_text(f"{placeholder}\n{detected}\n", encoding="utf-8")
    findings = [
        finding
        for finding in run_semgrep(fixture)["results"]
        if finding["check_id"].endswith(
            ".generic.secrets.security.detected-slack-webhook.detected-slack-webhook"
        )
    ]
    assert [finding["start"]["line"] for finding in findings] == [2], (
        "placeholder must stay excluded while a webhook-shaped value is detected"
    )

def workflow_step(workflow_text: str, step_id: str) -> dict[str, str]:
    """Extract the scalar fields this test asserts without a YAML dependency."""
    marker = f"        id: {step_id}\n"
    assert workflow_text.count(marker) == 1, f"workflow must contain exactly one {step_id} step"
    marker_offset = workflow_text.index(marker)
    start = workflow_text.rfind("\n      - ", 0, marker_offset)
    end = workflow_text.find("\n      - ", marker_offset)
    block = workflow_text[start : end if end != -1 else None]

    condition = re.search(r"^        if: (.+)$", block, re.MULTILINE)
    run_header = re.search(r"^        run: (.+)$", block, re.MULTILINE)
    assert condition is not None, f"{step_id} must have an if condition"
    assert run_header is not None, f"{step_id} must have a run command"
    run = run_header.group(1)
    if run in {"|", ">-"}:
        run = "\n".join(
            line[10:]
            for line in block[run_header.end() :].splitlines()
            if line.startswith("          ")
        )
    return {"if": condition.group(1), "run": run}


workflow_path = ROOT / ".github" / "workflows" / "security.yml"
workflow = workflow_path.read_text(encoding="utf-8")
locked = workflow_step(workflow, "semgrep_locked")
current = workflow_step(workflow, "semgrep_current")
locked_negative = workflow_step(workflow, "semgrep_locked_negative")
current_negative = workflow_step(workflow, "semgrep_current_negative")
assert locked["if"] == "github.event_name == 'pull_request'"
assert current["if"] == "github.event_name != 'pull_request'"
assert locked_negative["if"] == locked["if"]
assert current_negative["if"] == current["if"]


def semgrep_configs(command: str) -> list[str]:
    tokens = shlex.split(command.replace("\\\n", " "))
    return [
        tokens[index + 1]
        for index, token in enumerate(tokens[:-1])
        if token == "--config"
    ]


def semgrep_excludes(command: str) -> list[str]:
    tokens = shlex.split(command.replace("\\\n", " "))
    return [
        tokens[index + 1]
        for index, token in enumerate(tokens[:-1])
        if token == "--exclude"
    ]


assert semgrep_configs(locked["run"]) == [".semgrep/locked"]
assert semgrep_configs(current["run"]) == ["p/php", "p/security-audit", "p/secrets"]
assert semgrep_configs(locked_negative["run"]) == semgrep_configs(locked["run"])
assert semgrep_configs(current_negative["run"]) == semgrep_configs(current["run"])
assert semgrep_excludes(locked["run"]) == [".semgrep/locked/**"]
assert semgrep_excludes(current["run"]) == [".semgrep/locked/**"]

print("ALL PASSED")
