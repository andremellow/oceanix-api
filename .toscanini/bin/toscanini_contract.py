#!/usr/bin/env python3
"""Validate a Toscanini execution contract and its finding ledger."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

ASSURANCE_BUDGETS = {
    "fast": {"remediationRounds": 1, "specialistRuns": 7},
    "standard": {"remediationRounds": 2, "specialistRuns": 14},
    "critical": {"remediationRounds": 2, "specialistRuns": 17},
}
LEGACY_ASSURANCE_BUDGETS = {
    "fast": {"remediationRounds": 1, "specialistRuns": 8},
    "standard": {"remediationRounds": 2, "specialistRuns": 15},
    "critical": {"remediationRounds": 2, "specialistRuns": 18},
}
CLASSIFICATIONS = {
    "SPEC_GAP", "ARCHITECTURE_GAP", "IMPLEMENTATION_DEVIATION", "TEST_GAP",
    "QA_GAP", "NEW_REQUIREMENT", "ENVIRONMENT_FAILURE",
}
BLOCKING_BASES = {"acceptance-criterion", "invariant", "direct-regression"}
FAILURE_STAGES = {"specification", "architecture", "implementation", "tests", "qa", "orchestration", "environment"}
LEARNING_DECISIONS = {"pending", "accepted", "rejected", "deferred"}


def read_json(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}


def contract_path(root: Path, run_id: str) -> Path:
    return root / ".toscanini" / "runtime" / "runs" / run_id / "execution-contract.json"


def ledger_path(root: Path, run_id: str) -> Path:
    return root / ".toscanini" / "runtime" / "runs" / run_id / "finding-ledger.json"


def project_spec_kit_enabled(root: Path) -> bool:
    manifest = read_json(root / ".toscanini" / "manifest.json")
    return "spec-kit" in manifest.get("configuration", {}).get("adapters", [])


READINESS_CATEGORIES = {'runtimes', 'dependencies', 'access', 'services', 'verification', 'permissions'}


def validate_readiness(contract: dict) -> list[str]:
    # Historical contracts remain auditable; new runs must record concrete preparation.
    if contract.get('schemaVersion') == 2:
        return []
    readiness = contract.get('readiness')
    if not isinstance(readiness, dict):
        return ['missing execution readiness assessment']
    findings = []
    if not readiness.get('scopeId') or readiness.get('scopeId') != contract.get('validationScope', {}).get('scopeId'):
        findings.append('execution readiness does not match the frozen scope')
    if not isinstance(readiness.get('environment'), str) or not readiness['environment'].strip():
        findings.append('execution readiness must identify the Worker environment')
    checks = readiness.get('checks')
    if not isinstance(checks, list):
        return findings + ['missing execution readiness checks']
    covered = set()
    for check in checks:
        if not isinstance(check, dict):
            findings.append('invalid execution readiness check')
            continue
        category = check.get('category')
        if not isinstance(category, str) or category not in READINESS_CATEGORIES:
            findings.append('unknown execution readiness category')
            continue
        covered.add(category)
        # Never echo evidence or dependency names: they could accidentally contain secrets.
        if check.get('status') not in {'verified', 'not-required'}:
            findings.append(f'execution prerequisite is unresolved: {category}')
        if any(not isinstance(check.get(key), str) or not check[key].strip() for key in ('name', 'evidence')):
            findings.append(f'execution prerequisite needs a name and nonsecret evidence: {category}')
    for category in sorted(READINESS_CATEGORIES - covered):
        findings.append(f'missing execution readiness category: {category}')
    return findings


def validate_contract(contract: dict, run_id: str, spec_kit_enabled: bool | None = None) -> list[str]:
    findings: list[str] = []
    if not contract:
        return ["missing or invalid execution contract"]
    if contract.get("schemaVersion") not in {2, 3}:
        findings.append("execution contract schemaVersion must be 2 or 3")
    if contract.get("runId") != run_id:
        findings.append("contract runId does not match the active run")
    if contract.get("assurance") not in ASSURANCE_BUDGETS:
        findings.append("contract assurance must be fast, standard, or critical")
    if not str(contract.get("goal", "")).strip():
        findings.append("contract goal is missing")
    task = contract.get("task", {})
    if task.get("size") not in {"small", "medium", "large"}:
        findings.append("task size must be small, medium, or large")
    if task.get("decisionRisk") not in {"low", "material"}:
        findings.append("task decisionRisk must be low or material")
    baseline = task.get("estimatedBaselineMinutes")
    if not isinstance(baseline, (int, float)) or isinstance(baseline, bool) or baseline <= 0:
        findings.append("task estimatedBaselineMinutes must be greater than zero")
    if not isinstance(task.get("specKitEnabled"), bool):
        findings.append("task specKitEnabled must be true or false")
    elif spec_kit_enabled is not None and task.get("specKitEnabled") != spec_kit_enabled:
        findings.append("task specKitEnabled does not match the installed project configuration")
    scope = contract.get("scope", {})
    for key in ("in", "out"):
        if not isinstance(scope.get(key), list) or not scope[key]:
            findings.append(f"contract scope.{key} must contain at least one item")
    for field, prefix in (("acceptanceCriteria", "AC-"), ("invariants", "INV-")):
        items = contract.get(field, [])
        if not isinstance(items, list) or not items:
            findings.append(f"contract {field} must contain at least one item")
            continue
        identifiers = []
        for item in items:
            identifier = str(item.get("id", "")) if isinstance(item, dict) else ""
            statement = str(item.get("statement", "")) if isinstance(item, dict) else ""
            if not identifier.startswith(prefix) or not statement.strip():
                findings.append(f"every {field} item requires a {prefix} id and statement")
            identifiers.append(identifier)
        if len(identifiers) != len(set(identifiers)):
            findings.append(f"contract {field} ids must be unique")
    validation_scope = contract.get("validationScope", {})
    if not str(validation_scope.get("scopeId", "")).strip():
        findings.append("validation scope requires a stable scopeId")
    if validation_scope.get("frozen") is not True:
        findings.append("validation scope and QA matrix must be frozen before implementation")
    regression_surfaces = validation_scope.get("regressionSurfaces", [])
    if not isinstance(regression_surfaces, list) or not regression_surfaces:
        findings.append("validation scope requires at least one direct regression surface")
    qa_scenarios = validation_scope.get("qaScenarios", [])
    if not isinstance(qa_scenarios, list) or not qa_scenarios:
        findings.append("validation scope requires at least one pre-approved QA scenario")
    else:
        scenario_ids = []
        for scenario in qa_scenarios:
            identifier = str(scenario.get("id", "")) if isinstance(scenario, dict) else ""
            scenario_ids.append(identifier)
            linked = scenario.get("acceptanceCriteria", []) or scenario.get("invariants", []) if isinstance(scenario, dict) else []
            if not identifier.startswith("QA-") or not str(scenario.get("description", "")).strip() or not linked:
                findings.append("every QA scenario requires a QA- id, description, and linked criterion or invariant")
        if len(scenario_ids) != len(set(scenario_ids)):
            findings.append("QA scenario ids must be unique")
    specification = contract.get("specification", {})
    spec_kit_required = task.get("specKitEnabled") and (
        task.get("size") == "large"
        or (task.get("size") == "medium" and task.get("decisionRisk") == "material")
    )
    if spec_kit_required and not specification.get("required"):
        findings.append("Spec Kit must be required for this task classification")
    if specification.get("required"):
        if specification.get("status") == "waived":
            if not str(specification.get("waiverReason", "")).strip():
                findings.append("a waived required specification needs the user's waiver reason")
            if specification.get("waivedBy") != "user":
                findings.append("a required specification may be waived only by the user")
        elif specification.get("status") != "approved":
            findings.append("required specification is not approved or explicitly waived")
        else:
            if not specification.get("artifact"):
                findings.append("approved specification artifact is missing")
            if specification.get("clarifyStatus") != "approved":
                findings.append("required specification clarification is not approved")
            if specification.get("planStatus") != "approved":
                findings.append("required specification plan is not approved")
    architecture = contract.get("architecture", {})
    architecture_required = task.get("size") == "large" or (
        task.get("size") == "medium" and task.get("decisionRisk") == "material"
    )
    if architecture_required and not architecture.get("required"):
        findings.append("architecture must be required for this task classification")
    if architecture.get("required") and (architecture.get("status") != "approved" or not architecture.get("artifact")):
        findings.append("required architecture is not approved with an artifact")
    if architecture.get("required") or architecture.get("artifact"):
        approval = architecture.get("ownerApproval") or {}
        if not isinstance(approval, dict):
            approval = {}
        if approval.get("approvedBy") != "product-owner" or (not isinstance(approval.get("decision"), str) or not approval["decision"].strip()):
            findings.append("architecture requires explicit product-owner approval with a decision reference")
        if not architecture.get("sha256") or approval.get("artifactSha256") != architecture.get("sha256"):
            findings.append("architecture approval must match the artifact sha256")
        if approval.get("scopeId") != validation_scope.get("scopeId"):
            findings.append("architecture approval must match the frozen scope")
        if contract.get("schemaVersion") == 3:
            alignment = architecture.get("frameworkAlignment")
            if not isinstance(alignment, list) or not alignment:
                findings.append("architecture requires per-repository framework alignment evidence")
            else:
                for item in alignment:
                    if not isinstance(item, dict):
                        findings.append("invalid framework alignment entry")
                        continue
                    label = item.get("repository", "<missing repository>")
                    if any(not isinstance(item.get(key), str) or not item[key].strip() for key in ("repository", "framework", "version", "referenceAssessment")):
                        findings.append(f"incomplete framework/reference assessment: {label}")
                    guidance = item.get("guidance")
                    if not isinstance(guidance, list) or not guidance or any(not isinstance(source, str) or not source.strip() for source in guidance):
                        findings.append(f"missing consulted framework guidance: {label}")
                    if item.get("status") not in {"aligned", "approved-deviation"}:
                        findings.append(f"unresolved framework alignment decision: {label}")
                    if item.get("status") == "approved-deviation" and (not isinstance(item.get("ownerDecision"), str) or not item["ownerDecision"].strip()):
                        findings.append(f"framework deviation requires a specific product-owner decision: {label}")
    if contract.get("schemaVersion") == 3 and contract.get("approvedBy") != "product-owner":
        findings.append("execution contract requires product-owner approval")
    assurance = contract.get("assurance")
    limits = LEGACY_ASSURANCE_BUDGETS if contract.get("schemaVersion") == 2 else ASSURANCE_BUDGETS
    expected = limits.get(assurance, {})
    budgets = contract.get("budgets", {})
    for key, maximum in expected.items():
        value = budgets.get(key)
        if not isinstance(value, int) or value < 1 or value > maximum:
            findings.append(f"{assurance} budget {key} must be between 1 and {maximum}")
    if contract.get("approved") is not True:
        findings.append("execution contract is not approved")
    return findings


def validate_ledger(ledger: dict, run_id: str, completion: bool = False, contract: dict | None = None) -> list[str]:
    if not ledger:
        return []
    findings: list[str] = []
    if ledger.get("runId") != run_id:
        findings.append("finding ledger runId does not match the active run")
    identifiers: list[str] = []
    for item in ledger.get("findings", []):
        if not isinstance(item, dict):
            findings.append("finding ledger contains an invalid entry")
            continue
        identifier = str(item.get("id", ""))
        identifiers.append(identifier)
        if not identifier or not item.get("sourceRole") or not item.get("summary") or not item.get("evidence") or not item.get("requiredOutcome"):
            findings.append(f"finding {identifier or '<missing>'} is incomplete")
        if item.get("classification") not in CLASSIFICATIONS:
            findings.append(f"finding {identifier or '<missing>'} has an invalid classification")
        if item.get("failureStage") not in FAILURE_STAGES:
            findings.append(f"finding {identifier or '<missing>'} must identify the stage that failed")
        if item.get("scope") not in {"in-contract", "follow-up"}:
            findings.append(f"finding {identifier or '<missing>'} has an invalid scope")
        if item.get("severity") not in {"blocking", "non-blocking"}:
            findings.append(f"finding {identifier or '<missing>'} has an invalid severity")
        if item.get("status") not in {"open", "resolved", "deferred"}:
            findings.append(f"finding {identifier or '<missing>'} has an invalid status")
        if item.get("scope") == "follow-up" and item.get("severity") == "blocking":
            findings.append(f"follow-up finding cannot block the current contract: {identifier}")
        if item.get("severity") == "blocking" and item.get("basis") not in BLOCKING_BASES:
            findings.append(f"blocking finding lacks an approved blocking basis: {identifier}")
        if item.get("basis") == "direct-regression" and not str(item.get("regressionSurface", "")).strip():
            findings.append(f"direct-regression finding must name the affected frozen regression surface: {identifier}")
        if item.get("scope") == "in-contract":
            approved = contract or {}
            for field in ("acceptanceCriteria", "invariants"):
                allowed = {entry["id"] for entry in approved.get(field, []) if isinstance(entry, dict) and isinstance(entry.get("id"), str)}
                references = item.get(field, [])
                if not isinstance(references, list) or any(not isinstance(ref, str) or ref not in allowed for ref in references):
                    findings.append(f"finding {identifier} cites {field} outside the frozen contract")
            basis_field = {"acceptance-criterion": "acceptanceCriteria", "invariant": "invariants"}.get(item.get("basis"))
            if item.get("severity") == "blocking" and basis_field and not item.get(basis_field):
                findings.append(f"blocking finding {identifier} must cite its {basis_field} basis")
            if item.get("basis") == "direct-regression" and item.get("regressionSurface") not in approved.get("validationScope", {}).get("regressionSurfaces", []):
                findings.append(f"finding {identifier} cites a regression surface outside the frozen contract")
        if not isinstance(item.get("discoveredRound"), int) or item.get("discoveredRound", 0) < 1:
            findings.append(f"finding must record its discovery round: {identifier}")
        if not str(item.get("discoveredPhase", "")).strip():
            findings.append(f"finding must record its discovery phase: {identifier}")
        learning = item.get("learning", {})
        if learning:
            if learning.get("decision") not in LEARNING_DECISIONS:
                findings.append(f"finding {identifier or '<missing>'} has an invalid learning decision")
            if not str(learning.get("proposal", "")).strip() or not str(learning.get("target", "")).strip():
                findings.append(f"finding {identifier or '<missing>'} has an incomplete learning proposal")
            if learning.get("applied") is True and learning.get("decision") != "accepted":
                findings.append(f"finding {identifier or '<missing>'} applied learning without user acceptance")
        if item.get("scope") == "in-contract" and not item.get("acceptanceCriteria") and not item.get("invariants"):
            findings.append(f"in-contract finding must link to acceptance criteria or invariants: {identifier}")
        if completion and item.get("scope") == "in-contract" and item.get("severity") == "blocking" and item.get("status") != "resolved":
            findings.append(f"unresolved blocking finding: {identifier}")
    if len(identifiers) != len(set(identifiers)):
        findings.append("finding ids must be unique")
    return findings


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--contract")
    parser.add_argument("--ledger")
    parser.add_argument("--completion", action="store_true")
    args = parser.parse_args()
    root = Path.cwd()
    contract_file = Path(args.contract) if args.contract else contract_path(root, args.run_id)
    ledger_file = Path(args.ledger) if args.ledger else ledger_path(root, args.run_id)
    contract = read_json(contract_file)
    findings = validate_contract(contract, args.run_id, project_spec_kit_enabled(root))
    findings += validate_readiness(contract)
    findings += validate_ledger(read_json(ledger_file), args.run_id, args.completion, contract=contract)
    from toscanini_architecture import validate_architecture, architecture_events
    findings += validate_architecture(root, contract, architecture_events(root, args.run_id), completion=args.completion)
    print(json.dumps({"runId": args.run_id, "approved": not findings, "findings": findings}, indent=2))
    return 1 if findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
