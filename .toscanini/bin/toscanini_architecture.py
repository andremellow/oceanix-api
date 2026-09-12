"""Architect authoring and implementation conformance evidence; no document reviewer."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path


def architecture_events(root: Path, run_id: str) -> list[dict]:
    try:
        return [event for line in (root / '.toscanini/runtime/events.jsonl').read_text().splitlines()
                if line.strip() and isinstance(event := json.loads(line), dict) and event.get('runId') == run_id]
    except (OSError, ValueError):
        return []


def local_artifact(root: Path, value: object) -> Path | None:
    if not isinstance(value, str) or not value.strip() or Path(value).is_absolute():
        return None
    path = (root / value).resolve()
    if not path.is_relative_to(root.resolve()) or not path.is_file():
        return None
    return path


def directed_findings_resolved(root: Path, run_id: str, event: dict) -> bool:
    identifiers = event.get('findingIds')
    if not isinstance(identifiers, list) or not identifiers or not all(isinstance(value, str) and value for value in identifiers):
        return False
    if not isinstance(event.get('revalidationReason'), str) or not event['revalidationReason'].strip():
        return False
    try:
        ledger = json.loads((root / '.toscanini/runtime/runs' / run_id / 'finding-ledger.json').read_text())
        resolved = {item.get('id') for item in ledger.get('findings', []) if isinstance(item, dict) and item.get('status') == 'resolved'}
        return ledger.get('runId') == run_id and set(identifiers).issubset(resolved)
    except (OSError, ValueError, AttributeError):
        return False


def validate_architecture(root: Path, contract: dict, history: list[dict], *, require_artifact: bool = False, completion: bool = False) -> list[str]:
    architecture = contract.get('architecture') or {}
    applicable = require_artifact or architecture.get('required') or architecture.get('artifact')
    if not applicable:
        return []
    findings = []
    path = local_artifact(root, architecture.get('artifact'))
    if not path or not path.read_bytes().strip():
        findings.append('architecture artifact is missing, empty, or outside the project')
    elif hashlib.sha256(path.read_bytes()).hexdigest() != architecture.get('sha256'):
        findings.append('architecture artifact changed since product-owner approval')
    approval = architecture.get('ownerApproval') or {}
    if not isinstance(approval, dict):
        approval = {}
    scope = contract.get('validationScope', {}).get('scopeId')
    if architecture.get('status') != 'approved' or approval.get('approvedBy') != 'product-owner' or not isinstance(approval.get('decision'), str) or not approval['decision'].strip():
        findings.append('architecture needs explicit product-owner approval')
    if not architecture.get('sha256') or approval.get('artifactSha256') != architecture.get('sha256') or approval.get('scopeId') != scope:
        findings.append('architecture approval is stale for this artifact or scope')

    def role(event: dict) -> str:
        return event.get('role', '').replace('_', '-')

    author = [(i, e) for i, e in enumerate(history) if role(e) == 'architect' and e.get('phase') == 'architecture']
    terminals = [(i, e) for i, e in author if e.get('event') in {'completed', 'blocked', 'failed'}]
    if not terminals:
        findings.append('missing Architect authoring verdict')
    else:
        index, event = terminals[-1]
        if event.get('event') != 'completed' or event.get('state') != 'completed' or event.get('verdict') not in {'approved', 'approved-with-conditions'}:
            findings.append('Architect authoring is not approved')
        if event.get('artifact') != architecture.get('artifact') or event.get('architectureSha256') != architecture.get('sha256') or event.get('scopeId') != scope:
            findings.append('Architect verdict does not match approved architecture and scope')
        if not any(i < index and e.get('event') == 'started' for i, e in author) or any(i > index and e.get('event') == 'started' for i, e in author):
            findings.append('Architect authoring has no completed start/terminal sequence')
        first_worker = next((i for i, e in enumerate(history) if role(e) == 'worker' and e.get('event') == 'started'), None)
        if first_worker is not None and index >= first_worker:
            findings.append('Worker started before the applicable Architect verdict')
    implemented = contract.get('implementationCheckpoint', {}).get('recordedAfterImplementation') is True or any(role(e) == 'worker' for e in history)
    if not completion or not implemented:
        return findings

    checkpoint = contract.get('implementationCheckpoint', {}).get('id')
    conformance = [(i, e) for i, e in enumerate(history) if role(e) == 'architect' and e.get('phase') == 'architecture-conformance']
    full_starts = [(i, e) for i, e in conformance if e.get('event') == 'started' and e.get('reviewMode') == 'independent']
    if len(full_starts) != 1:
        findings.append('architecture conformance requires exactly one full check after implementation')
    for full_index, full in full_starts:
        next_start = next((i for i, e in conformance if i > full_index and e.get('event') == 'started'), len(history))
        full_terminal = [e for i, e in conformance if full_index < i < next_start and e.get('event') in {'completed', 'blocked', 'failed'} and e.get('reviewMode') == 'independent' and e.get('checkpointId') == full.get('checkpointId') and e.get('scopeId') == scope and e.get('architectureSha256') == architecture.get('sha256')]
        if not full_terminal:
            findings.append('full architecture conformance has no matching terminal evidence')
        for required_role in ('worker', 'qa'):
            prior = [e for e in history[:full_index] if role(e) == required_role and e.get('event') in {'started', 'completed', 'blocked', 'failed'}]
            if not prior or prior[-1].get('event') != 'completed':
                findings.append('full architecture conformance must follow completed Worker and QA')
            elif required_role == 'qa' and (prior[-1].get('verdict') not in {'pass', 'pass-with-non-blocking-findings'} or prior[-1].get('checkpointId') != full.get('checkpointId')):
                findings.append('full architecture conformance must follow approved QA at its checkpoint')
    terminals = [(i, e) for i, e in conformance if e.get('event') in {'completed', 'blocked', 'failed'}]
    if not terminals:
        return findings + ['missing architecture-conformance evidence']
    index, event = terminals[-1]
    starts = [(i, e) for i, e in conformance if e.get('event') == 'started' and i < index]
    if not starts or any(i > index and e.get('event') == 'started' for i, e in conformance):
        findings.append('architecture conformance has no completed start/terminal sequence')
    else:
        start_index, start = starts[-1]
        if start.get('reviewMode') != event.get('reviewMode') or start.get('checkpointId') != checkpoint:
            findings.append('architecture conformance start does not match final checkpoint or mode')
        # The latest check must follow the final work and applicable QA.
        for label, predicate in (
            ('Worker', lambda e: role(e) == 'worker'),
            ('QA', lambda e: role(e) == 'qa'),
        ):
            records = [(i, e) for i, e in enumerate(history) if predicate(e) and e.get('event') in {'started', 'completed', 'blocked', 'failed'}]
            if not records or records[-1][0] >= start_index or records[-1][1].get('event') != 'completed':
                findings.append(f'architecture conformance must follow completed {label}')
            elif label == 'QA' and (records[-1][1].get('verdict') not in {'pass', 'pass-with-non-blocking-findings'} or records[-1][1].get('checkpointId') != checkpoint):
                findings.append('architecture conformance requires approved QA at the final checkpoint')
    if event.get('event') != 'completed' or event.get('state') != 'completed' or event.get('verdict') not in {'pass', 'pass-with-non-blocking-findings'}:
        findings.append('architecture conformance is not approved')
    if event.get('scopeId') != scope or event.get('architectureSha256') != architecture.get('sha256') or event.get('checkpointId') != checkpoint:
        findings.append('architecture conformance does not match approved artifact, scope and final checkpoint')
    evidence = local_artifact(root, event.get('artifact'))
    if not evidence or evidence == path or not evidence.read_bytes().strip():
        findings.append('architecture conformance requires a separate nonempty evidence artifact')
    if not isinstance(event.get('findingCount'), int) or event.get('findingCount', -1) < 0:
        findings.append('architecture conformance must report its finding count')
    if event.get('reviewMode') == 'directed':
        earlier = [e for i, e in conformance if i < index and e.get('reviewMode') == 'independent' and e.get('event') in {'completed', 'blocked', 'failed'} and e.get('scopeId') == scope and e.get('architectureSha256') == architecture.get('sha256')]
        if not earlier or not directed_findings_resolved(root, contract.get('runId', ''), event):
            findings.append('directed architecture revalidation requires an earlier full check, resolved ledger finding IDs and an impact reason')
    elif event.get('reviewMode') != 'independent':
        findings.append('architecture conformance mode must be independent or directed')
    return findings
