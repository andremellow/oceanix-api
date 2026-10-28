"""Scope amendments must preserve both prior approvals and same-scope ordering."""
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('architecture_gate', Path(__file__).parents[1] / 'bin/toscanini_architecture.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class ArchitectureScopeTests(unittest.TestCase):
    def check_history(self, worker_scope):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'architecture.md').write_text('Approved architecture')
            digest = hashlib.sha256((root / 'architecture.md').read_bytes()).hexdigest()
            contract = {'validationScope': {'scopeId': 'v2'}, 'architecture': {
                'required': True, 'artifact': 'architecture.md', 'sha256': digest, 'status': 'approved',
                'ownerApproval': {'approvedBy': 'product-owner', 'decision': 'explicit approval', 'artifactSha256': digest, 'scopeId': 'v2'},
            }}
            history = [
                {'role': 'worker', 'event': 'started', 'scopeId': worker_scope},
                {'role': 'architect', 'phase': 'architecture', 'event': 'started', 'scopeId': 'v2'},
                {'role': 'architect', 'phase': 'architecture', 'event': 'completed', 'state': 'completed', 'verdict': 'approved', 'artifact': 'architecture.md', 'architectureSha256': digest, 'scopeId': 'v2'},
                {'role': 'worker', 'event': 'started', 'scopeId': 'v2'},
            ]
            return gate.validate_architecture(root, contract, history)

    def test_prior_scope_worker_does_not_invalidate_approved_amendment(self):
        self.assertEqual([], self.check_history('v1'))

    def test_early_worker_in_same_scope_is_still_rejected(self):
        self.assertIn('Worker started before the applicable Architect verdict', self.check_history('v2'))


if __name__ == '__main__':
    unittest.main()
