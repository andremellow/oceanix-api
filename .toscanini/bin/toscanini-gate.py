#!/usr/bin/env python3
"""Block Toscanini completion until required independent gates approve."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

DEFAULT_GATES = {
    "test-expert": {"approve"},
    "qa": {"pass", "pass-with-non-blocking-findings"},
    "code-reviewer": {"approve"},
}
OPTIONAL_GATES = {
    "architecture-reviewer": {"approve"},
    "design-reviewer": {"approve"},
}
FRESH_CONTEXT_GATES = {"test-expert", "qa", "code-reviewer", "architecture-reviewer", "design-reviewer"}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--require-architecture", action="store_true")
    parser.add_argument("--require-design", action="store_true")
    args = parser.parse_args()
    path = Path.cwd() / ".toscanini" / "runtime" / "state.json"
    try:
        state = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        state = {}
    events = state.get("runs", {}).get(args.run_id, {})
    required = dict(DEFAULT_GATES)
    if args.require_architecture:
        required["architecture-reviewer"] = OPTIONAL_GATES["architecture-reviewer"]
    if args.require_design:
        required["design-reviewer"] = OPTIONAL_GATES["design-reviewer"]
    findings = []
    for role, accepted in required.items():
        event = next((value for value in events.values() if value.get("role", "").replace("_", "-") == role), None)
        if not event:
            findings.append(f"missing gate: {role}")
        elif event.get("state") != "completed":
            findings.append(f"incomplete gate: {role} ({event.get('state', 'unknown')})")
        elif event.get("verdict") not in accepted:
            findings.append(f"unapproved gate: {role} ({event.get('verdict', 'missing verdict')})")
        elif role in FRESH_CONTEXT_GATES and event.get("contextMode") != "fresh":
            findings.append(f"non-independent gate: {role} ({event.get('contextMode', 'missing context mode')})")
    print(json.dumps({"runId": args.run_id, "approved": not findings, "findings": findings}, indent=2))
    return 1 if findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
