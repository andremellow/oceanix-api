# Research decisions

This is a derivative index; architecture.md is authoritative for technical decisions.

| Question | Decision | Evidence | Alternative rejected |
| --- | --- | --- | --- |
| Provider owner | Account operational; Compliance AuthKit only | User D02; architecture§1/6 | Per-product direct fallback |
| Transport | Existing HTTP/durable Laravel jobs | User D03; architecture§2/7.4 | Broker/pubsub/events now |
| Legacy import | Local provenance; no product call or fabricated confirmation | D01/P01; architecture§7.1 | Require newCompliance endpoint before initial import |
| Identity link | Automatic first-use mapping-only protocol | D05/P02; architecture§7.2 | New local IDs assumed equal or email-only merge |
| Projection | Immutable versioned HTTP observation target independent from provider read | P03; architecture§7.3/7.4 | Re-run successful provider work to repair delivery |
| Credential scope | Product/system identity, current company binding + live human proof | D04; architecture§7.5 | UUIDallowlist or token as blanket human authority |
| Legacy recovery | Preserve immutable records; quarantine uncertain dispatch | P04; architecture§7.1/7.7 | Replay old migration or delete evidence |

Consulted official references and per-repo Laravel13.26.1 alignment are in architecture§1 and architecture-paths.json. Boost is optional and absent; no unavailable documentation tool was claimed. No new libraries/services are proposed. Production data conflicts remain operational inventory conditions, not assumed local facts.
