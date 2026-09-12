#!/usr/bin/env python3
"""Generate a concise, privacy-safe execution report from Toscanini artifacts."""

from __future__ import annotations

from collections import Counter
from datetime import datetime
import json
from pathlib import Path

from toscanini_contract import contract_path, ledger_path, read_json


def run_events(root: Path, run_id: str) -> list[dict]:
    path = root / ".toscanini" / "runtime" / "events.jsonl"
    try:
        return [event for line in path.read_text(encoding="utf-8").splitlines()
                if line.strip() and (event := json.loads(line)).get("runId") == run_id]
    except (OSError, ValueError):
        return []


def elapsed_seconds(events: list[dict]) -> int | None:
    timestamps = []
    for event in events:
        try:
            timestamps.append(datetime.fromisoformat(event.get("timestamp", "").replace("Z", "+00:00")))
        except ValueError:
            pass
    if len(timestamps) < 2:
        return None
    return int((max(timestamps) - min(timestamps)).total_seconds())


def elapsed(events: list[dict]) -> str:
    seconds = elapsed_seconds(events)
    if seconds is None:
        return "unavailable"
    return f"{seconds // 60}m {seconds % 60}s"


def efficiency_score(contract: dict, events: list[dict], findings: list[dict], gate_findings: list[str]) -> tuple[int, list[str]]:
    """Return a transparent directional score, not a claim of scientific precision."""
    score = 100
    signals: list[str] = []
    seconds = elapsed_seconds(events)
    baseline = contract.get("task", {}).get("estimatedBaselineMinutes")
    if seconds is not None and isinstance(baseline, (int, float)) and baseline > 0:
        ratio = seconds / (baseline * 60)
        penalty = min(45, max(0, round((ratio - 1) * 2)))
        score -= penalty
        signals.append(f"Elapsed/baseline ratio: {ratio:.1f}x (-{penalty})")
    starts = [event for event in events if event.get("event") == "started" and event.get("role") not in {"orchestrator", "product-owner"}]
    unique_roles = {(event.get("role", "unknown"), event.get("phase") if event.get("role") == "architect" else None) for event in starts}
    repeated_start_penalty = min(20, max(0, len(starts) - len(unique_roles)) * 2)
    score -= repeated_start_penalty
    signals.append(f"Repeated specialist starts: {max(0, len(starts) - len(unique_roles))} (-{repeated_start_penalty})")
    remediation_rounds = len({event.get("round") for event in starts if event.get("role", "").replace("_", "-") == "worker" and event.get("phase") == "remediation"})
    remediation_penalty = max(0, remediation_rounds - 1) * 8
    score -= remediation_penalty
    signals.append(f"Additional remediation batches: {max(0, remediation_rounds - 1)} (-{remediation_penalty})")
    late = sum(1 for item in findings if item.get("discoveredRound", 1) > 1)
    late_penalty = min(15, late * 3)
    score -= late_penalty
    signals.append(f"Late findings: {late} (-{late_penalty})")
    followups = sum(1 for item in findings if item.get("scope") == "follow-up")
    followup_penalty = min(10, followups * 2)
    score -= followup_penalty
    signals.append(f"Out-of-contract findings: {followups} (-{followup_penalty})")
    if gate_findings:
        score -= 10
        signals.append("Execution ended blocked (-10)")
    return max(0, score), signals


def write_report(root: Path, run_id: str, gate_findings: list[str]) -> Path:
    contract = read_json(contract_path(root, run_id))
    ledger = read_json(ledger_path(root, run_id))
    events = run_events(root, run_id)
    findings = ledger.get("findings", [])
    starts = [event for event in events if event.get("event") == "started" and event.get("role") not in {"orchestrator", "product-owner"}]
    roles = Counter(event.get("role", "unknown").replace("_", "-") for event in starts)
    rounds = sorted({event.get("round") for event in events if isinstance(event.get("round"), int)})
    followups = [item for item in findings if item.get("scope") == "follow-up"]
    resolved = [item for item in findings if item.get("status") == "resolved"]
    open_items = [item for item in findings if item.get("status") != "resolved"]
    responsibilities = Counter(f"architect ({event.get('phase', 'unknown')})" if event.get("role") == "architect" else event.get("role", "unknown") for event in starts)
    repeated = {role: count for role, count in responsibilities.items() if count > 1}
    score, score_signals = efficiency_score(contract, events, findings, gate_findings)
    result = "PASS" if not gate_findings else "BLOCKED"
    lines = [
        f"# Toscanini execution report — {run_id}", "",
        f"- Result: **{result}**",
        f"- Goal: {contract.get('goal', 'unavailable')}",
        f"- Assurance: {contract.get('assurance', 'unavailable')}",
        f"- Elapsed telemetry time: {elapsed(events)}",
        f"- Specialist starts: {len(starts)}",
        f"- Remediation rounds observed: {max(rounds) if rounds else 0}", "",
        "## Efficiency", "",
        f"- Score: **{score}/100**",
        "- This is a directional execution score; its deductions are listed below.",
    ]
    lines.extend(f"- {signal}" for signal in score_signals)
    lines += ["",
        "## Agent usage", "",
    ]
    lines.extend(f"- {role}{' (legacy evidence; retired role)' if role == 'architecture-reviewer' else ''}: {count}" for role, count in sorted(roles.items()))
    if not roles:
        lines.append("- No specialist telemetry was recorded.")
    lines += ["", "## Findings", "",
              f"- Total: {len(findings)}", f"- Resolved: {len(resolved)}",
              f"- Open or deferred: {len(open_items)}", f"- Non-blocking follow-ups: {len(followups)}", ""]
    for item in findings:
        lines.append(f"- `{item.get('id', 'unknown')}` [{item.get('scope', 'unknown')}] {item.get('summary', 'No summary')} — detected by {item.get('sourceRole', 'unknown')}; attributed to {item.get('failureStage', 'unknown')} — {item.get('status', 'unknown')}")
    lines += ["", "## Efficiency signals", ""]
    lines.append(f"- Roles started more than once: {', '.join(f'{role} ({count})' for role, count in sorted(repeated.items())) or 'none'}")
    lines.append(f"- Findings discovered after round 1: {sum(1 for item in findings if item.get('discoveredRound', 1) > 1)}")
    lines.append(f"- Out-of-contract observations retained as follow-ups: {len(followups)}")
    lines += ["", "## Proposed learnings — user approval required", ""]
    proposals = [(item, item.get("learning", {})) for item in findings if item.get("learning", {}).get("proposal")]
    for item, learning in proposals:
        lines.append(f"- `{item.get('id', 'unknown')}` → **{learning.get('target', 'unspecified target')}**: {learning.get('proposal')} — decision: `{learning.get('decision', 'pending')}`, applied: `{str(bool(learning.get('applied'))).lower()}`")
    if not proposals:
        lines.append("- No reusable learning was proposed. Findings remain execution-specific.")
    lines += ["", "Toscanini never applies a learning automatically. Accepting a proposal requires an explicit user decision and a separate change."]
    lines += ["", "## Gate result", ""]
    lines.extend(f"- {finding}" for finding in gate_findings)
    if not gate_findings:
        lines.append("- All required gates approved.")
    output = root / ".toscanini" / "runtime" / "runs" / run_id / "execution-report.md"
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return output
