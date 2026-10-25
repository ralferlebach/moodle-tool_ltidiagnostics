# Changelog

## 0.1.0 - 2026-10-25 (alpha)

First MVP. Read-only diagnostics built on stable Moodle APIs.

* Connection inventory for both roles (mod_lti tool types, enrol_lti registrations and deployments).
* Capability matrix with the seven-step evidence ladder and a pure status engine. `verified` requires an observed
  transaction or an authorised test; configuration alone never verifies.
* Desired-state profile (JSON) and guided health check with layered findings, responsible party, risk and next safe step.
* Evidence sources: standard log store reader, scheduled and adhoc task inventory with `task_log`, own event observer
  (launch, user binding, enrolment, group membership, grade, view, unknown service call) with trace IDs, web server access
  log importer (nginx/apache, timezone and proxy aware), correlation classes direct / strong / tentative / unmatched.
* Safe active checks (GET only): JWKS, metadata, TLS, with an SSRF guard (https only, no credentials, internal and
  reserved ranges blocked, DNS rebinding protection, redirect re-check).
* Reports as Markdown, JSON and CSV with redaction, time-limited diagnostic mode with audit, Privacy API provider,
  eight capabilities, cleanup scheduled task.
* English and German language packs.
* Tests: PHPUnit unit and integration, Behat feature, Playwright two-site story. See `docs/ACCEPTANCE.md` for the status.

Known limits: no hook for OIDC/JWT/AGS/NRPS calls in Moodle (gates G1, G2), no mutating tests, no partner diagnostic
package, no iss/aud mismatch detection.
