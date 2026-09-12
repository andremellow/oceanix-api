#!/usr/bin/env python3
"""Block Toscanini completion until required independent gates approve."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

from toscanini_contract import ASSURANCE_BUDGETS, contract_path, ledger_path, project_spec_kit_enabled, read_json, validate_contract, validate_ledger, validate_readiness
from toscanini_report import write_report
from toscanini_architecture import validate_architecture, directed_findings_resolved

DEFAULT_GATES = {
    "code-reviewer": {"approve"},
    "test-analyst": {"approve"},
    "qa": {"pass", "pass-with-non-blocking-findings"},
}
OPTIONAL_GATES = {
    "design-reviewer": {"approve"},
    "specification-reviewer": {"approve"},
}
FRESH_CONTEXT_GATES = {"test-analyst", "qa", "code-reviewer", "design-reviewer", "specification-reviewer"}


def run_events(root: Path, run_id: str) -> list[dict]:
    path = root / ".toscanini" / "runtime" / "events.jsonl"
    try:
        events = []
        for line in path.read_text(encoding="utf-8").splitlines():
            if not line.strip():
                continue
            event = json.loads(line)
            if event.get("runId") == run_id:
                events.append(event)
        return events
    except (OSError, json.JSONDecodeError):
        return []


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--require-architecture", action="store_true")
    parser.add_argument("--require-design", action="store_true")
    parser.add_argument("--require-contract", action="store_true")
    args = parser.parse_args()
    root = Path.cwd()
    path = root / ".toscanini" / "runtime" / "state.json"
    try:
        state = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        state = {}
    events = state.get("runs", {}).get(args.run_id, {})
    required = dict(DEFAULT_GATES)
    if args.require_design:
        required["design-reviewer"] = OPTIONAL_GATES["design-reviewer"]
    findings = []
    history = run_events(root, args.run_id)
    contract = {}
    scope_id = None
    checkpoint_id = None
    required_qa_coverage = set()
    contract_required = args.require_contract or args.require_architecture or contract_path(root, args.run_id).exists()
    if contract_required:
        contract = read_json(contract_path(root, args.run_id))
        findings.extend(validate_contract(contract, args.run_id, project_spec_kit_enabled(root)))
        findings.extend(validate_ledger(read_json(ledger_path(root, args.run_id)), args.run_id, completion=True, contract=contract))
        if contract.get("specification", {}).get("required"):
            required["specification-reviewer"] = OPTIONAL_GATES["specification-reviewer"]
        findings.extend(validate_architecture(root, contract, history, require_artifact=args.require_architecture, completion=True))
        assurance = contract.get("assurance")
        budgets = contract.get("budgets", ASSURANCE_BUDGETS.get(assurance, {}))
        scope_id = contract.get("validationScope", {}).get("scopeId")
        checkpoint = contract.get("implementationCheckpoint", {})
        checkpoint_id = checkpoint.get("id")
        architecture_only = args.require_architecture and not args.require_contract and checkpoint.get("recordedAfterImplementation") is not True and not any(event.get("role") == "worker" for event in history)
        if architecture_only:
            required = {}
        else:
            findings.extend(validate_readiness(contract))
        if not architecture_only and (not checkpoint_id or checkpoint.get("recordedAfterImplementation") is not True):
            findings.append("implementation checkpoint must be recorded after implementation")
        required_qa_coverage = {scenario.get("id") for scenario in contract.get("validationScope", {}).get("qaScenarios", []) if scenario.get("id")}
        specialist_starts = [event for event in history if event.get("event") == "started" and event.get("role", "").replace("_", "-") not in {"orchestrator", "product-owner"}]
        remediation_batches = {
            event.get("round") for event in history
            if event.get("event") == "started"
            and event.get("role", "").replace("_", "-") == "worker"
            and event.get("phase") == "remediation"
            and isinstance(event.get("round"), int)
        }
        specialist_limit = budgets.get("specialistRuns")
        round_limit = budgets.get("remediationRounds")
        if specialist_limit is not None and len(specialist_starts) > specialist_limit:
            findings.append(f"specialist budget exceeded: {len(specialist_starts)}/{specialist_limit}; replan required")
        if round_limit is not None and len(remediation_batches) > round_limit:
            findings.append(f"remediation budget exceeded: {len(remediation_batches)}/{round_limit}; replan required")
        independent_starts = [event for event in history if event.get("event") == "started" and event.get("reviewMode") == "independent" and event.get("role", "").replace("_", "-") not in {"architect", "architecture-reviewer", "product-owner"}]
        allowed_independent_starts = {
            "code-reviewer": 1,
            "design-reviewer": 2,
        }
        for role in {event.get("role", "").replace("_", "-") for event in independent_starts}:
            count = sum(1 for event in independent_starts if event.get("role", "").replace("_", "-") == role)
            maximum = allowed_independent_starts.get(role, 1)
            if count > maximum:
                findings.append(f"repeated whole-scope review: {role} ({count}/{maximum}); replan required")
        final_blockers = [item.get("id", "unknown") for item in read_json(ledger_path(root, args.run_id)).get("findings", [])
                          if item.get("scope") == "in-contract" and item.get("severity") == "blocking" and item.get("discoveredPhase") == "final-review"]
        if final_blockers:
            findings.append(f"new blocker family discovered during final review: {', '.join(final_blockers)}; replan required")
    for role, accepted in required.items():
        role_history = [value for value in history if value.get("role", "").replace("_", "-") == role]
        terminal_history = [value for value in role_history if value.get("event") in {"completed", "blocked", "failed"} and value.get("reviewMode") == "independent"]
        event = terminal_history[-1] if terminal_history else None
        # A directed closure retains the independent approval and checks only the delta.
        directed = [value for value in role_history if value.get("event") in {"completed", "blocked", "failed"} and value.get("reviewMode") == "directed"]
        if event and directed and role_history.index(directed[-1]) > role_history.index(event):
            candidate = directed[-1]
            directed_starts = [value for value in role_history[:role_history.index(candidate)] if value.get("event") == "started" and value.get("reviewMode") == "directed" and value.get("checkpointId") == candidate.get("checkpointId")]
            if event.get("state") == "completed" and (event.get("verdict") in accepted or (event.get("verdict") in {"request-changes", "fail"} and isinstance(event.get("findingCount"), int) and event["findingCount"] > 0)) and event.get("contextMode") == "fresh" and event.get("scopeId") == scope_id and directed_starts and directed_findings_resolved(root, args.run_id, candidate):
                event = candidate
            else:
                findings.append(f"invalid directed revalidation evidence: {role}")
        if not event:
            findings.append(f"missing gate: {role}")
        elif event.get("state") != "completed":
            findings.append(f"incomplete gate: {role} ({event.get('state', 'unknown')})")
        elif event.get("verdict") not in accepted:
            findings.append(f"unapproved gate: {role} ({event.get('verdict', 'missing verdict')})")
        elif role in FRESH_CONTEXT_GATES and event.get("reviewMode") == "independent" and event.get("contextMode") != "fresh":
            findings.append(f"non-independent gate: {role} ({event.get('contextMode', 'missing context mode')})")
        if contract_required:
            starts = [value for value in role_history if value.get("event") == "started" and value.get("reviewMode") == "independent"]
            if not starts:
                findings.append(f"missing started event: {role}")
            elif not isinstance(starts[-1].get("round"), int) or not starts[-1].get("phase"):
                findings.append(f"incomplete started event metadata: {role}")
            if event and not isinstance(event.get("findingCount"), int):
                findings.append(f"missing finding count: {role}")
            if event and event.get("scopeId") != scope_id:
                findings.append(f"scope mismatch: {role}")
            if event and event.get("checkpointId") != checkpoint_id:
                findings.append(f"checkpoint mismatch: {role}")
            if role == "qa" and event and not required_qa_coverage.issubset(set(event.get("coverage", []))):
                missing = sorted(required_qa_coverage - set(event.get("coverage", [])))
                findings.append(f"incomplete QA coverage: {', '.join(missing)}")
    if contract_required:
        first_qa = next((index for index, event in enumerate(history) if event.get("role", "").replace("_", "-") == "qa" and event.get("event") == "started" and event.get("reviewMode") == "independent"), None)
        if first_qa is not None:
            for prerequisite in ("code-reviewer", "test-analyst"):
                cleared = any(
                    event.get("role", "").replace("_", "-") == prerequisite
                    and event.get("event") == "completed"
                    and event.get("verdict") == "approve"
                    and event.get("reviewMode") == "independent"
                    and event.get("checkpointId") == history[first_qa].get("checkpointId")
                    for event in history[:first_qa]
                )
                if not cleared:
                    findings.append(f"QA started before approved {prerequisite}")
    report = write_report(root, args.run_id, findings)
    print(json.dumps({"runId": args.run_id, "approved": not findings, "findings": findings, "report": str(report)}, indent=2))
    return 1 if findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
