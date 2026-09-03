#!/usr/bin/env python3
"""Record privacy-safe Toscanini lifecycle events for local observers."""

from __future__ import annotations

import argparse
import json
import os
import tempfile
from datetime import datetime, timezone
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--agent", required=True)
    parser.add_argument("--role")
    parser.add_argument("--run-id", default="legacy")
    parser.add_argument("--event", required=True, choices=("started", "progress", "handoff", "completed", "blocked", "failed"))
    parser.add_argument("--state", required=True, choices=("active", "waiting", "completed", "blocked", "failed"))
    parser.add_argument("--summary", required=True)
    parser.add_argument("--artifact")
    parser.add_argument("--verdict", choices=("approve", "request-changes", "pass", "pass-with-non-blocking-findings", "fail", "blocked"))
    parser.add_argument("--context-mode", choices=("fresh", "inherited"))
    args = parser.parse_args()
    root = Path.cwd() / ".toscanini" / "runtime"
    root.mkdir(parents=True, exist_ok=True)
    event = {
        "timestamp": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"), "runId": args.run_id,
        "pid": os.getpid(), "agent": args.agent, "role": args.role or args.agent,
        "event": args.event, "state": args.state, "summary": args.summary[:240],
    }
    if args.artifact:
        event["artifact"] = args.artifact[:500]
    if args.verdict:
        event["verdict"] = args.verdict
    if args.context_mode:
        event["contextMode"] = args.context_mode
    with (root / "events.jsonl").open("a", encoding="utf-8") as stream:
        stream.write(json.dumps(event, separators=(",", ":")) + "\n")
    state_path = root / "state.json"
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        state = {"agents": {}}
    state.setdefault("agents", {})[args.agent] = event
    state.setdefault("runs", {}).setdefault(args.run_id, {})[args.agent] = event
    state["updatedAt"] = event["timestamp"]
    handle, temporary = tempfile.mkstemp(prefix="state-", suffix=".json", dir=root)
    try:
        with os.fdopen(handle, "w", encoding="utf-8") as output:
            json.dump(state, output, indent=2)
            output.write("\n")
        os.replace(temporary, state_path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
