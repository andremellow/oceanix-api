from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


class ToscaniniEventTest(unittest.TestCase):
    def test_concurrent_events_preserve_every_agent_state(self) -> None:
        script = Path(__file__).parents[1] / "bin" / "toscanini-event.py"

        with tempfile.TemporaryDirectory() as directory:
            workspace = Path(directory)
            processes = [
                subprocess.Popen(
                    [
                        sys.executable,
                        str(script),
                        "--run-id",
                        "concurrent-run",
                        "--agent",
                        f"reviewer-{index}",
                        "--role",
                        "code-reviewer",
                        "--event",
                        "completed",
                        "--state",
                        "completed",
                        "--summary",
                        "Concurrent reviewer completed",
                        "--verdict",
                        "approve",
                        "--context-mode",
                        "fresh",
                    ],
                    cwd=workspace,
                )
                for index in range(20)
            ]

            for process in processes:
                self.assertEqual(process.wait(), 0)

            runtime = workspace / ".toscanini" / "runtime"
            state = json.loads((runtime / "state.json").read_text(encoding="utf-8"))
            events = (runtime / "events.jsonl").read_text(encoding="utf-8").splitlines()

            self.assertEqual(len(state["runs"]["concurrent-run"]), 20)
            self.assertEqual(len(events), 20)


if __name__ == "__main__":
    unittest.main()
