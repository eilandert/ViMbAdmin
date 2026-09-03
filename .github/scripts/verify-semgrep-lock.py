#!/usr/bin/env python3
"""Verify that the reviewed Semgrep bundle exactly matches its manifest."""

import argparse
import hashlib
import json
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--root",
        type=Path,
        default=Path(__file__).resolve().parents[2] / ".semgrep" / "locked",
    )
    args = parser.parse_args()

    manifest_path = args.root / "manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    expected_files = {"manifest.json", *manifest}
    actual_files = {
        path.relative_to(args.root).as_posix()
        for path in args.root.rglob("*")
        if path.is_file()
    }
    if actual_files != expected_files:
        missing = sorted(expected_files - actual_files)
        unexpected = sorted(actual_files - expected_files)
        raise SystemExit(
            f"Semgrep lock file set differs: missing={missing}, "
            f"unexpected={unexpected}"
        )

    for filename, expected_digest in manifest.items():
        digest = hashlib.sha256((args.root / filename).read_bytes()).hexdigest()
        if digest != expected_digest:
            raise SystemExit(
                f"Semgrep lock digest differs for {filename}: "
                f"expected {expected_digest}, got {digest}"
            )

    print("Semgrep rule lock verified")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
