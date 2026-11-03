# Grade and completion channels (0.4.0, issues #3 to #9; 0.5.0, issues #13, #21 to #24)

Two separate channels per tool side connection (one enrol_lti registration and deployment):

* **Grades** (tab "Grades (AGS)"): standard LTI Assignment and Grade Services, sent by enrol_lti's grade sync. Read-only
  diagnosis, a passive journal of core's sends, and an optional, guarded replay of an unchanged grade to exactly one
  consumer, line item and learner.
* **Completion** (tab "Completion sync"): the outbox, versions and signed acknowledgements of local_lticompletionsync,
  read only, with links to the actions that plugin already has. No grade, score or lastgrade is used for completion.

## 1. Integration decision

| Question | Decision | Why |
|---|---|---|
| Where does the replay live? | In tool_ltidiagnostics, as a separate module that is off by default | The defect is found here; the replay needs exactly the target resolution the diagnosis already does. Core is not patched. |
| Reset lastgrade instead? | **Never** | `enrol_lti_users.lastgrade` exists once per (user, published resource) for all consumers. A reset resends to every consumer and every link. |
| Own completion sender? | **No** | local_lticompletionsync has outbox, versions, ACKs, retry, requeue, reconcile and force resync. The tab reads its tables and posts to its own page (`/local/lticompletionsync/index.php`). |
| Line item creation? | **Never** | The replay uses the line item of the launch claim or exactly one match from the line items service; none or several end the attempt. |
| AGS progress as completion? | **No** | An AGS progress line item (tool-only level of local_lticompletionsync) is shown as "sent (tool only)": limited, unconfirmed. |

## 2. Diagnosis (issue #3)

Per consumer resource link and learner (paged, keyset, bounded): what core will do at its next run (prediction, first
match wins: prerequisites, publication off, grade sync off, invalid context, no AGS service, no score scope, completion
gating, no grade, no maximum, `lastgrade` unchanged, would send), the source grade, the AGS user id core would send, the
last evidence, risks and actions.

Evidence ladder (weakest first): not attempted, skipped, attempt in progress, error, HTTP accepted (booking unknown),
consumer not observable, mismatch, AGS result matches, booked (signed receipt). A launch, a successful task, lastgrade or
an existing line item never count as booked.

Root causes found on the BUW-like consumers (reproduced on the two-site stack, `tests/playwright/grades_two_site.spec.js`):

1. **lastgrade blocks** (`gr_lastgrade_blocks`): the learner reaches the same publication through two consumers; core
   sent to the first one and skips the second one with "grades are the same". Responsible: provider (core behaviour).
2. **Core sends another consumer's subject** (`core_sends_other_subject`): `enrol_lti_users.sourceid` and
   `ltideploymentid` keep the values of the first launch; core sends that `sub` as AGS `userId` to the other consumer.
3. **0 is never sent** (`gr_zero_never_sent`): `lastgrade` defaults to 0, so a grade of 0 equals lastgrade.
4. **No AGS service / no score scope** (`gr_no_service`, `gr_no_scope`): the consumer did not grant it. Responsible:
   consumer; a replay is not offered.

Each finding has severity, count, source, certainty, the limit of what is visible from here, the responsible side
(provider, consumer, both) and a next step.

## 3. Journal (issue #4)

Table `tool_ltidiagnostics_gatt`. Two sources, never mixed:

* `task_log`: imported from core's task log of `sync_tool_grades` (cursor `gradetasklogcursor`, at most 200 runs per call,
  idempotent per run and line). Core prints no HTTP status for a success, no line item and no subject: such rows say
  "sent", HTTP accepted, booking unknown. A line is attributed to a link only when exactly one candidate matches.
  Exception text is classified (`error_classes`) and never kept.
* `instrumented`: own replays, every state change recorded: `eligible, skipped, queued, sending, http_accepted,
  http_rejected, retry_wait, dead, aborted`, with HTTP status, error class, retry time, AGS timestamp, score state
  version, booking state and the operator. No token, body or name is stored; the subject and line item only as hashes.

Retention: `retentiongradedays` (default 365); open attempts are kept.

## 4. Replay (issues #5 and #9)

Switches, all required: site setting `graderepair` (off by default), opt-in of the connection (audited), capability
`tool/ltidiagnostics:repairgrades` (RISK_PERSONAL, RISK_CONFIG, RISK_DATALOSS; no archetype).

Flow: preview (dry run, sends nothing, shows target, AGS user id source, line item, score, blockers) → confirmation with
reason (required, redacted) and an explicit checkbox → queued (ad hoc task `grade_repair_task`) → cron sends. A batch
(at most `repairbatchmax`, default 20) is the same per target.

Target resolution (`target_resolver`) refuses: another connection, a link outside the connection, an invalid
registration, a learner not in the link, publication or grade sync off, invalid context, completion gating, no grade or
maximum, a source grade changed since the preview (fingerprint), no score scope, no AGS service, an impossible line
item lookup, an endpoint that the URL guard blocks or whose host is not the issuer's or the token endpoint's, an
ambiguous or missing subject. The subject is the auth_lti linked login of this issuer, otherwise the stored `sourceid`
only when its deployment is this one.

Sending: one OAuth token (client credentials, JWT assertion with the tool key; scope score, plus a line item scope only
when the line item has to be looked up; the result check gets its own token with `result.readonly`), one score POST:
`userId, scoreGiven, scoreMaximum, timestamp, activityProgress=Completed, gradingProgress=FullyGraded`.

AGS state monotonicity: per target a version and the last issued timestamp (`tool_ltidiagnostics_gstate`). The timestamp
is "now" in whole seconds and strictly greater than the last one; within the same second, or after the clock went back,
the attempt waits (`retry_wait`, at most one second ahead, never a future timestamp). The state is stored before the
POST. Moodle consumers answer **409** when they hold a score with the same or a newer timestamp: the attempt ends
(`http_rejected`, `http_409`) and is **never** retried with a newer timestamp; compare with the consumer first.

Errors: 429, 5xx, timeout, connect and DNS errors are retried with backoff `repairbackoff · 2^(n-1)` up to
`repairmaxattempts`, then `dead`; 401, 403, 404, other 4xx and 409 end the attempt. Locks per target for the request and
the send; one open attempt per target; rate limit `repairratelimit` per operator and hour. Never touched: lastgrade, the
gradebook, other consumers, completion sync.

## 5. Booking evidence (issue #6)

* **AGS result check** (`results_checker`, optional `repairresultcheck`, on by default; and a button per row): needs the
  `result.readonly` scope in the claim and in the token. Compares user, line item (path; Moodle answers `scoreOf` with
  `?type_id=`), score ratio, maximum, timestamp: `results_match` or `results_mismatch` with the reasons.
* **Signed receipt** (`receipt`): the provider exports a request (`ltidiag-grade-request-1`: hashes, the line item URL,
  the score sent, no names); the consumer's administrator creates a receipt on `receipt.php` of the platform
  connection (`ltidiag-grade-receipt-1`, a JWT signed with the mod_lti platform key, read-only gradebook lookup, no name
  and no user id); the provider imports it and verifies it with the platform's key set (`mod/lti/certs.php`, 300 s
  leeway). Refusals: signature, clock skew, wrong consumer, expired, unknown attempt, request mismatch, already imported.
  Verdicts: `receipt_confirmed` or `receipt_mismatch`.

## 6. Completion tab (issue #7)

Works without local_lticompletionsync (it says so); with it, it reads `local_lticompletionsync_con/out/rcv` with grouped
counts and paging. Sender classes: pending, sent (tool only), retry, acknowledged not applied, applied (signed), rejected,
blocked, dead letter, status unknown, plus revoked (counted additionally). Receiver classes: received, validated, applied,
rejected, conflict, status unknown.

Actions are shown only when the plugin is present and the user has its capability; they post to the plugin's own page:
Check partner, Retry now, Requeue dead letters, Full reconcile, Force resync, each with its honest reach (all are per
connection; force resync creates a new version for every learner; there is no single-learner action).

## 7. UX and support package (issue #8)

Separate tabs and workflows for grades and completion; every table has a caption and row headers; every badge has text.
Support package (`package.php`, JSON, Markdown, CSV; schema `ltidiag-support-2`): findings split into provider,
consumer, both and unknown, pseudonyms (`P-` + 8 hex of an HMAC per connection), codes, times, versions, correlation ids;
no names, user ids, tokens, scores or raw URLs. Audited (`export_support`).

## 7a. Counters, filters and bulk replay (0.5.0, issues #13, #21 to #23)

* **One population.** The rows of a page, the total, the counters and "select every match" come from the same SQL
  (`local\grades\pair_query`): consumer resource links of the connection inside the scope (course, activity, grade
  sync on/off), joined with the learner, the source grade (gradebook item of the published activity or course) and the
  newest journal row of the pair.
* **Defined counters, no "open work".** Source grade: present (not 0), 0, none (NULL). Newest transfer: not attempted,
  skipped by core (with its reason in the row), queued or running, accepted by HTTP (2xx), failed. Booking at the
  consumer: confirmed (AGS result or signed receipt), differs from the source grade, not proven (accepted without
  consumer evidence), nothing to check (no accepted transfer). Each dimension counts every pair exactly once; the
  dimensions are not added. "Not proven" never means "to be sent".
* **Filters** for each counter value, grade sync, link, learner and (with `tool/ltidiagnostics:viewidentities`) name or
  e-mail; page sizes 20, 50, 100, 500; the grade journal has its own filters (origin core/replay, transfer state,
  attribution, link and learner of one row, period of the scope).
* **Bulk replay.** Mark rows, take every row of the page or every match of the filters (refused when there are more than
  `repairbatchmax`). The selection is stored in the session for `selectionttl` seconds and is bound to the connection;
  the preview lists every target with consumer link, mapping, source grade and blockers; one confirmation queues one
  attempt per ready target under one batch id. The selection is consumed before anything is queued, so a double submit
  or a second confirmation cannot start a second batch. Every target is checked again (`repair_service::request()`:
  open attempt, blockers, fingerprint of the source grade, rate limit, lock). Failures stay with their target; the
  batch status page shows each attempt with HTTP status and booking state, and an accepted HTTP request is never shown
  as booked.

## 8. Threat model (issue #9)

| Threat | Defence |
|---|---|
| Accidental mass resend | No lastgrade reset; one target per attempt; batch limit; dry run and confirmation required |
| Forged request (CSRF) | sesskey, capability and the switches checked on the server for every action; viewers get no form |
| SSRF through a stored claim URL | URL guard (https only unless `allowprivatetargets`, no credentials, no internal ranges), one DNS lookup and an address pin (CURLOPT_RESOLVE), no redirects, host must be the issuer's or the token endpoint's |
| Token or score leaking into logs | No token, body or subject stored; reason redacted; audit codes only |
| Duplicate or reordered sends | Locks, one open attempt per target, monotonic version and timestamp, 409 never overridden |
| Wrong learner | Subject from this issuer's auth_lti link or this deployment's sourceid, otherwise refused (ambiguous, missing) |
| Forged receipt | JWT signature with the consumer's platform key set; request hash, attempt, consumer and expiry checked; one import per receipt |
| Load | Bounded pages, grouped counts, keyset paging, constant query count (`test_query_budget_is_constant`), task log import bounded per call |

## 9. Privacy

New tables: `tool_ltidiagnostics_gatt` (user id, operator id, score, hashes, times, codes, the redacted reason),
`tool_ltidiagnostics_gstate` (target hash, version, time; no user column), `tool_ltidiagnostics_grcpt` (user id, verdict,
values from the receipt). External location `lti_ags_consumer` (user id as AGS `userId`, score, maximum, timestamp).
Export and deletion through the privacy provider; tool_mergeusers exceptions for `gatt` and `grcpt`. See `PRIVACY.md`.

## 10. Disable and rollback

* Switch off: site setting `graderepair` = 0, or remove the opt-in of a connection. Queued attempts are then aborted
  by the task (nothing is sent); diagnosis and journal stay read only.
* Rights: remove `tool/ltidiagnostics:repairgrades`.
* Rollback of a replay is not possible through AGS (a score cannot be withdrawn); the consumer's gradebook history
  shows the change. The replay sends the unchanged source grade, so a rollback is normally not needed; a wrong value
  at the consumer is corrected at the source and sent again by core or a new replay.
* Uninstall removes the tables (no change outside the plugin).

## 11. Not observable from the provider

* Whether a consumer really booked a core send (core prints no status, line item or subject): only the AGS result
  service (if granted) or a signed receipt says so.
* Changes at the consumer after the last check (teacher overrides): shown as a mismatch at the next check.
* Consumers that are not Moodle may handle timestamps and 409 differently; the replay never retries a 409.
* Completion at the platform without a signed acknowledgement or a matched partner trace.

## Kurzfassung (Deutsch)

Zwei getrennte Kanäle pro Tool-Verbindung: **Noten** (AGS von enrol_lti) mit Read-only-Diagnose, Journal und einem
standardmäßig abgeschalteten, abgesicherten Replay einer unveränderten Note an genau einen Consumer, ein Lineitem und
eine:n Lernende:n (Site-Einstellung, Opt-in der Verbindung, Capability `repairgrades`, Dry-Run, Begründung und
Bestätigung; nie lastgrade-Reset, nie neue Lineitems, 409 nie automatisch wiederholt). Buchungsnachweis über das
AGS-Result oder eine signierte Empfangsbestätigung des Consumers. **Abschluss** (local_lticompletionsync) nur lesend mit
Verweisen auf dessen vorhandene Aktionen und ehrlicher Reichweite (immer die ganze Verbindung). Supportpaket ohne Namen,
Nutzer-IDs, Tokens und Punkte, getrennt nach Provider, Consumer und unklar.
