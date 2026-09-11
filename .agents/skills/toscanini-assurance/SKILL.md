---
name: toscanini-assurance
description: Select or explain Toscanini's fast, standard, or critical assurance level for a software task. Use when the user asks to change rigor, thoroughness, review depth, or iteration cost.
---

# Toscanini Assurance

Determine the active level from the user's explicit request, otherwise use the `Default assurance` declared in the repository's Toscanini instructions.

- `fast`: for low-risk, narrow work. Run applicable gates at one consolidated checkpoint and allow at most one worker remediation cycle.
- `standard`: the normal balance. Run applicable gates together, consolidate findings, and allow at most two worker remediation cycles.
- `critical`: for high-risk work. Increase analysis depth and evidence, with at most two consolidated worker remediation cycles and 17 specialist starts.

Never interpret an iteration limit as permission to ignore a blocker. When the budget is exhausted—or a new blocking problem family appears in final review—stop, present the unresolved findings, and replan with the user. Automatically escalate to `critical` for authentication, authorization, billing, destructive operations, irreversible migrations, concurrency, sensitive data, and credible data-loss risks. Tell the user when an automatic escalation occurs.

An assurance choice in a task changes only that task. To change the repository default, instruct the user to run `toscanini assurance <fast|standard|critical>` or run it when the user explicitly asks you to modify the project configuration.
