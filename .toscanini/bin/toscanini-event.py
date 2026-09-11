#!/usr/bin/env python3
"""Record privacy-safe Toscanini lifecycle events for local observers."""

from __future__ import annotations

import argparse
import json
import os
import tempfile
from datetime import datetime, timezone
from pathlib import Path


def contract_defaults(root: Path, run_id: str) -> tuple[str | None, str | None]:
    path = root / "runs" / run_id / "execution-contract.json"
    try:
        contract = json.loads(path.read_text(encoding="utf-8"))
        return contract.get("validationScope", {}).get("scopeId"), contract.get("implementationCheckpoint", {}).get("id")
    except (OSError, json.JSONDecodeError):
        return None, None


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--agent", required=True)
    parser.add_argument("--role")
    parser.add_argument("--run-id", default="legacy")
    parser.add_argument("--event", required=True, choices=("started", "progress", "handoff", "completed", "blocked", "failed"))
    parser.add_argument("--state", required=True, choices=("active", "waiting", "completed", "blocked", "failed"))
    parser.add_argument("--summary", required=True)
    parser.add_argument("--artifact")
    parser.add_argument("--verdict", choices=("approved", "approved-with-conditions", "approve", "request-changes", "pass", "pass-with-non-blocking-findings", "fail", "blocked"))
    parser.add_argument("--context-mode", choices=("fresh", "inherited"))
    parser.add_argument("--round", type=int)
    parser.add_argument("--phase", choices=("contract", "architecture", "architecture-conformance", "implementation", "review", "remediation", "qa", "final-review", "complete"))
    parser.add_argument("--finding-count", type=int)
    parser.add_argument("--review-mode", choices=("independent", "directed"), default="independent")
    parser.add_argument("--scope-id")
    parser.add_argument("--checkpoint-id")
    parser.add_argument("--coverage", action="append", default=[])
    parser.add_argument("--architecture-sha256")
    parser.add_argument("--finding-id", action="append", default=[])
    parser.add_argument("--revalidation-reason")
    args = parser.parse_args()
    if (args.role or args.agent).replace("_", "-") == "architecture-reviewer":
        parser.error("architecture-reviewer is retired; historical events remain readable")
    if (args.role or args.agent).replace("_", "-") == "worker" and args.event == "started":
        from toscanini_contract import contract_path, read_json, validate_readiness
        readiness_errors = validate_readiness(read_json(contract_path(Path.cwd(), args.run_id)))
        if readiness_errors:
            parser.error("Worker cannot start: " + "; ".join(readiness_errors))
    root = Path.cwd() / ".toscanini" / "runtime"
    root.mkdir(parents=True, exist_ok=True)
    default_scope, default_checkpoint = contract_defaults(root, args.run_id)
    event = {
        "timestamp": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"), "runId": args.run_id,
        "pid": os.getpid(), "agent": args.agent, "role": args.role or args.agent,
        "event": args.event, "state": args.state, "summary": args.summary[:240],
        "reviewMode": args.review_mode,
    }
    if args.architecture_sha256:
        event["architectureSha256"] = args.architecture_sha256
    if args.finding_id:
        event["findingIds"] = sorted(set(args.finding_id))
    if args.revalidation_reason:
        event["revalidationReason"] = args.revalidation_reason
    if args.artifact:
        event["artifact"] = args.artifact[:500]
    if args.verdict:
        event["verdict"] = args.verdict
    if args.context_mode:
        event["contextMode"] = args.context_mode
    if args.round is not None:
        event["round"] = args.round
    if args.phase:
        event["phase"] = args.phase
    if args.finding_count is not None:
        event["findingCount"] = args.finding_count
    if args.scope_id or default_scope:
        event["scopeId"] = args.scope_id or default_scope
    if args.checkpoint_id or default_checkpoint:
        event["checkpointId"] = args.checkpoint_id or default_checkpoint
    if args.coverage:
        event["coverage"] = sorted(set(args.coverage))
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
