# Semgrep rule policy

Pull-request scans use the reviewed rule bundle in `locked/`. This makes a
rerun of the same commit use the same rules even when the Semgrep Registry
changes. The weekly scheduled scan intentionally continues to use the current
`p/php`, `p/security-audit`, and `p/secrets` Registry packs.

To update the pull-request bundle, download those three Registry packs into
their corresponding YAML files, review the rule changes, update
`locked/manifest.json`, and run:

```console
sha256sum .semgrep/locked/*.yml
python3 tests/test-semgrep-rule-lock.py
semgrep scan --config .semgrep/locked --validate
```

The manifest covers both file membership and content. Unreviewed additions,
removals, or modifications therefore fail before the pull-request scan.
