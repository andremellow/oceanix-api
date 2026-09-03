# Installation operations

Inspect the target first. Use the repository's `scripts/inspect-project`, `install-project`, `update-project`, and `doctor` commands. Show dry-run output before material changes when practical. Package installation, destructive replacement, publication, pushes, and pull requests require explicit authorization.

Generated files are tracked in `.toscanini/manifest.json`. Never replace an unmanaged file or a managed file whose content diverged from its recorded hash. Preserve existing `AGENTS.md` content outside the marked Toscanini block.

For Laravel, report Boost status. Do not run Composer or `boost:install` without approval.
