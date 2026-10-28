# Privacy

The plugin implements the Moodle Privacy API (`classes/privacy/provider.php`: metadata, contexts, user list, export,
delete). Data is stored only to make diagnosis possible and is deleted after the retention time (default 30 days).

| Table | Personal data | Purpose | Handling on request |
|-------|---------------|---------|---------------------|
| `tool_ltidiagnostics_obs` | user id, course id, activity id, event name/type, entry point, time | Evidence that a launch, enrolment, group addition or grade happened | exported, deleted |
| `tool_ltidiagnostics_audit` | user id (who viewed or exported), action, connection key, time | Accountability of the diagnostic mode | exported, deleted |
| `tool_ltidiagnostics_imp` | user id of the importer, time | Provenance of imported logs | counted in the export, author unlinked (user id set to 0) |
| `tool_ltidiagnostics_acc` | masked address (last part removed), path without query values, time | Access log evidence | deleted by retention; no user id |
| `tool_ltidiagnostics_profile`, `_snap` | author id | Desired-state profiles, snapshots, imported partner packages and traces (0.3.0) | counted in the export, author unlinked |

0.3.0: the observer adds markers `claim.given_name|family_name|email=present|absent` to launch observations (never a value).
Partner packages contain hashes and masked forms of the connection identifiers, endpoints and public key thumbprints, no
personal data. Partner traces contain correlation ids, stages, times and short codes; transfer reports show persons as a
masked id that is stable inside one connection of one site and is not exported. Retention per data class:
`retentiondays` (observations), `retentionaccdays` (imported lines), `retentionauditdays`, `retentionsnapdays`,
`retentionpartnerdays` (imported partner data, default 30 days).

When two accounts are merged with tool_mergeusers, no row of this plugin is rewritten (since 0.3.0): audit, observations,
import jobs, snapshots and profiles keep the original account id, because each records what happened to or was done by
that account (see DOCUMENTATION, "Merging users"). The rows remain subject to retention and to the privacy provider under
the original id.

Imported access log lines can be deleted per import (`logimport.php`, sesskey and confirmation, audited as `deleteimport`).
The health check reads the enabled log stores through the reader API only and stores nothing from them.

Not stored: names, e-mail addresses, tokens, JWT claims, payloads. Payload capture is not implemented. Reports are
redacted by default; revealing identifiers needs the time-limited diagnostic mode, which is audited. The support role
sees a pseudonymised diagnostic ID only; teachers see aggregates for their course.

Tests: `privacy/provider_test`, `redactor_test`, `reporting_test`, `role_audit_test` (no names, e-mail addresses or user ids in packages, traces and transfer reports; every action audited with its actor), `integration/large_data_test::test_retention_per_data_class`, `local/field_matrix_test` (claim markers without values).
