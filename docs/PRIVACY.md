# Privacy

The plugin implements the Moodle Privacy API (`classes/privacy/provider.php`: metadata, contexts, user list, export,
delete). Data is stored only to make diagnosis possible and is deleted after the retention time (default 30 days).

| Table | Personal data | Purpose | Handling on request |
|-------|---------------|---------|---------------------|
| `tool_ltidiagnostics_obs` | user id, course id, activity id, event name/type, entry point, time | Evidence that a launch, enrolment, group addition or grade happened | exported, deleted |
| `tool_ltidiagnostics_audit` | user id (who viewed or exported), action, connection key, time | Accountability of the diagnostic mode | exported, deleted |
| `tool_ltidiagnostics_imp` | user id of the importer, time | Provenance of imported logs | counted in the export, author unlinked (user id set to 0) |
| `tool_ltidiagnostics_acc` | masked address (last part removed), path without query values, time | Access log evidence | deleted by retention; no user id |
| `tool_ltidiagnostics_profile`, `_snap` | author id | Desired-state profiles and snapshots | counted in the export, author unlinked |

Not stored: names, e-mail addresses, tokens, JWT claims, payloads. Payload capture is not implemented. Reports are
redacted by default; revealing identifiers needs the time-limited diagnostic mode, which is audited. The support role
sees a pseudonymised diagnostic ID only; teachers see aggregates for their course.

Tests: `privacy/provider_test`, `redactor_test`, `reporting_test`.
