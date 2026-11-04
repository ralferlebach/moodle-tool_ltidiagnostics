moodle-tool_ltidiagnostics
==========================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-tool_ltidiagnostics/actions/workflows/moodle-plugin-ci-main.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-tool_ltidiagnostics/actions?query=branch%3Amain)

Read-only diagnostics for LTI connections. For every connection (Moodle as platform and Moodle as tool) it shows what is
specified, implemented, enabled, declared, authorised, reachable and really observed, and it says in plain language
what an administrator can do next. A feature is only reported as **verified** when a real transaction was observed.
An enabled checkbox or a reachable endpoint is never enough.

Status: alpha (0.6.0, version 2026110301). See `docs/ACCEPTANCE.md` for what is proven and what is not, and
`docs/GRADES.md` for the grade and completion channels.


Requirements
------------

This plugin requires Moodle 4.5+ (tested on 4.5 and 5.3) and PHP 8.1+. The CI matrices test PHP 8.2 to 8.4 (PHP 8.1 is
no longer part of any CI job; the declared support is unchanged).


Motivation for this plugin
--------------------------

LTI problems are hard to analyse: the setting on one side, the setting on the other side, the network, the certificate,
the clock and the user mapping all play a role, and the standard logs do not tell which layer failed. This plugin
collects the evidence that Moodle can offer through stable APIs and presents it as an evidence ladder per feature and as
a guided health check with findings, the responsible party and the next safe step.


Installation
------------

Install the plugin like any other plugin to folder

    admin/tool/ltidiagnostics

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins.

Notes:

* Upgrading from 0.5.0 needs no upgrade step (0.6.0 changes no table and no setting).
* Upgrading from 0.4.0 runs step 2026110300: the grade journal gets a batch id column and the lifetime of a bulk
  selection (`selectionttl`) is added. Nothing existing is changed.
* Upgrading from 0.3.0 runs step 2026110200: three new tables (grade attempt journal, score state, grade receipts) and
  the grade settings with the replay switched off. Nothing existing is changed.
* Upgrading from 0.2.x or 0.1.0 runs the upgrade steps up to 2026102700 (two indexes, retention per data class; the
  retention of imported log lines starts with the old common value, nothing is deleted earlier). The GitHub `development`
  branch held 0.1.0 before this release; this release replaces it with a real upgrade path.
* No other plugin is required. local_lticompletionsync and tool_ltigroupautoenrol are read when they are installed.
* Active checks are off by default. For partners in a private network prefer the exact allow-list
  ("Exactly allowed private targets", host:port) over "Allow private targets".


Branch policy
-------------

* `development` is the work branch: every change, fix and test addition goes there first, and its CI run on the exact
  commit is the development gate.
* `main` is the release branch. It is changed only by a deliberate pull request from `development` after the functional
  acceptance by the owner and a green development CI; the full release CI then runs on the exact `main` commit.
* No direct pushes to `main`. Plugin versions of the LTI family are independent (no lockstep).


Usage & Settings
----------------

After installing, open *Site administration > Plugins > Admin tools > LTI Diagnostics > LTI connections*
(German: *Website-Administration > Plugins > Dienstprogramme > LTI-Diagnose > LTI-Verbindungen*).

Users with only some of the plugin's capabilities get exactly the entries of their rights. On Moodle 4.5 the category
*Admin tools* exists only for `moodle/site:config`; for delegated users the category "LTI Diagnostics" is then placed at
the top of the site administration. Moodle shows the *Site administration* menu only to users with
`moodle/site:configview`; give that capability to delegated roles that should use the menu (the pages are reachable by
URL and through the quick links of the overview without it).

The overview of connections is filtered in one row by text, role, course and activity. A connection belongs to a
course or activity only when it is proven to be used there (a resource link to a publication of that course or
activity; an external tool activity; a tool type bound to the course); a site-wide tool type or the role alone never
counts, and an unknown course or activity shows nothing. "Used in" names the publications, activities and courses of
each connection, "Name (id: 12)", each with the standard edit pencil when you may edit it.

Every page has a link back to the overview with its filters. The header of a connection holds that link, the name with
its local ids and one pencil for the registration or tool type. Above the tabs a scope bar restricts the report to a
course, an activity and a period (calendar days in your time zone; times in tables are UTC); tabs that show
system-wide facts say so, and exports and the support package hold the same population. Tables with many rows (grade
rows, grade journal, evidence, timeline, completion, history, support, audit) have compact one-row filters, page sizes
20, 50, 100 and 500 and the core paging bar above and below the table; they load without reloading the page and keep
the browser history; everything also works without JavaScript. Journal pages stay stable while new attempts arrive.

* **Connection inventory.** Lists the connections of both roles: LTI 1.3 tool types of mod_lti (Moodle as platform)
  and registrations and deployments of enrol_lti (Moodle as tool). Filter by role, text and course.
* **Function check** (until 0.4.0 "Capability matrix"). Per connection and per feature (LTI core, AGS, NRPS, Deep Linking, Dynamic Registration,
  Platform Storage, ...) the seven steps of the evidence ladder and a status: supported, configured, authorised,
  verified, blocked, incompatible, not_supported, not_observable, unknown. Each function says what is checked, how to
  collect missing evidence and offers drill-down actions marked read-only or "changes data at the partner".
* **Connection diagnosis** (until 0.4.0 "Health check"). Rates launch, key set, network checks, AGS transport and
  booking separately and compares a desired-state profile (JSON or form) with what is local, declared, authorised and
  observed, and lists findings by layer (configuration, network, TLS, OIDC/JWT, OAuth2, services, tasks, privacy).
  Every network check names target, method, executor, UTC time, addresses, proxy path and what it proves; a GET 405 on
  the POST-only token endpoint is expected, and a failed check contradicted by real launches is never the root cause.
* **Timeline.** Observed launches, enrolments, group additions, grades, access-log lines, tasks and log events
  side by side. Each row names its source and a correlation confidence (direct, strong, tentative, unmatched).
* **Active checks.** Optional, off by default, GET only: JWKS, metadata and TLS check of an endpoint, with an SSRF guard.
* **Log import.** Retrospective import of nginx/apache access logs from an admin-configured allow-listed path or an
  uploaded file. Addresses are masked, query values removed.
* **Reports.** Export as Markdown, JSON or CSV with redaction by default, a time-limited diagnostic mode (TTL) with
  audit, and a pseudonymised support view.
* **Messages and fields** (0.3.0). Per protocol message and data direction: configured, observed, verified, booked or not
  observable, with end-to-end chains; an HTTP 200 in a web server log never counts as verified. A field matrix of the
  launch message shows which claims were sent and demonstrably received (values are never stored).
* **Partner package** (0.3.0). Exchange a non-sensitive description of a connection with the other site and compare
  issuer, client id, deployment, endpoints and signing keys; optional read-only live check of the partner's key set.
* **Completion sync transfers** (0.3.0). The path of each completion message of local_lticompletionsync on both sites,
  joined by its correlation id after a partner trace exchange; persons are masked.
* **Grades (AGS)** (0.4.0). Read-only grade diagnosis per consumer: what core's grade sync will do for each resource
  link and learner, why (for example `lastgrade` shared by several consumers), what is known to have happened (journal
  of core's sends from the task log and of own replays) and who is responsible. Optional **grade replay** of an
  unchanged grade to exactly one consumer, line item and learner: off by default, needs the site setting, the opt-in
  of the connection and `tool/ltidiagnostics:repairgrades`, a dry run and a confirmation. Booking evidence through the
  AGS result service or a signed receipt of the consumer. See `docs/GRADES.md`.
* **Completion sync** (0.4.0). Separate tab with the outbox and receiver states of local_lticompletionsync (pending,
  sent tool-only, retry, acknowledged, applied with signed acknowledgement, rejected, blocked, dead, revoked, unknown)
  and links to that plugin's own actions with their real reach.
* **Support package** (0.4.0). Redacted JSON, Markdown or CSV for the partner's administration, split into provider,
  consumer, both and unknown.
* **Bulk grade replay** (0.5.0). Select marked rows, a page or every match of the filters (at most the batch size), check
  the preview of every target and confirm once; a batch status page follows the attempts. A selection lives 15 minutes
  (setting) in the session and can start one batch only.
* **Support and audit** (0.5.0). Support cases by connection name, local ids and diagnostic id with the most urgent
  finding, the next step and the way to diagnosis, grade journal and audit (each only with the right). Learners' names
  need `tool/ltidiagnostics:viewidentities`; documents for external partners stay pseudonymised. The audit shows real
  names, localised actions and filters for person, kind of action, connection and period.

Settings (*Site administration > Plugins > Admin tools > LTI Diagnostics > Settings*): record events, retention days,
log allow-list, maximum lines per import, active checks (off by default), allow private targets (off by default),
exactly allowed private targets, log imports per person and hour, retention of imported lines, audit, snapshots and
partner data, maximum minutes of the diagnostic mode; grade replay (off by default), result check after a replay,
batch size, replays per person and hour, attempts, retry delay, retention of the grade journal, lifetime of a bulk
selection.


Capabilities
------------

This plugin introduces these capabilities (none is granted by default except `viewcourse`):

* **tool/ltidiagnostics:view** - Open the connection inventory, function check, connection diagnosis and timeline (admin; risk: personal, config).
* **tool/ltidiagnostics:viewcourse** - Aggregate summary of the LTI activities of a course (editing teacher, manager).
* **tool/ltidiagnostics:viewsupport** - Support view: connections by name and local ids, diagnostic ID, most urgent finding and next step (support staff).
* **tool/ltidiagnostics:viewprivacy** - Audit log of views and exports (privacy officer).
* **tool/ltidiagnostics:configure** - Switch the diagnostic mode, manage desired-state profiles.
* **tool/ltidiagnostics:runchecks** - Run the active network checks.
* **tool/ltidiagnostics:runprivatechecks** - Additionally required when private target addresses are allowed in the settings (administrators only by default, every run is audited).
* **tool/ltidiagnostics:importlogs** - Import web server logs.
* **tool/ltidiagnostics:export** - Export reports.
* **tool/ltidiagnostics:viewgrades** - See score values in the grade diagnosis (0.4.0; risk: personal).
* **tool/ltidiagnostics:viewidentities** - See learners' names (with profile links) instead of pseudonyms in grade rows, journal and support (0.5.0; risk: personal).
* **tool/ltidiagnostics:repairgrades** - Replay a grade to a consumer (0.4.0; risks: personal, config, data loss; needs the
  site setting and the opt-in of the connection as well).

Site administrators have all of them through the normal Moodle rules.


Scheduled Tasks
---------------

* **\tool_ltidiagnostics\task\cleanup_task** - Deletes recorded observations, imported log lines, snapshots, imported
  partner data and audit entries older than their retention times and ends an expired diagnostic mode. Enabled by
  default, runs daily at 03:17. Since 0.4.0 it also imports core's grade sync task log into the grade journal
  (bounded per run) and applies the grade journal retention.
* **\tool_ltidiagnostics\task\grade_repair_task** (ad hoc, 0.4.0) - Sends one queued grade replay; never throws, an
  unexpected error ends the attempt as aborted.


How this plugin works / Pitfalls
--------------------------------

* The plugin only uses stable Moodle APIs: the log manager readers, the task manager and `task_log`, the events API,
  the LTI inventory tables and the Moodle curl wrapper. It does not patch core and does not need a fork.
* Moodle offers no hook for the OIDC login, the JWT validation or the AGS/NRPS service calls. Those steps are shown as
  `not_observable` unless the web server access log is imported. This is a limit of Moodle, not a bug (docs/DOCUMENTATION.md, gates).
* In the capability matrix a tool-side AGS or NRPS feature does not become `verified`, because the authorised test call
  that would prove it is not implemented. The Grades tab (0.4.0) shows the booking of a grade only with the consumer's AGS
  result or a signed receipt; an HTTP success alone is "accepted, booking unknown".
* What happens on the partner site is visible only through the partner package and the partner trace, which the other
  administrator has to export. Without them reports say "limited".
* The observer records the event type, the entry point, a trace ID, ids of course/activity/user and a time. It never
  records tokens, claims, e-mail addresses or names.


Theme support
-------------

This plugin acts behind the scenes (administration pages), therefore it should work with all Moodle themes.
It is developed and tested on Moodle Core's Boost theme.


The LTI plugin family
---------------------

This plugin belongs to a family of LTI plugins. **Each plugin is independently installable and does not depend on
another one.** They talk to each other, if at all, only through Moodle events and read-only services.

| Plugin | Purpose |
|--------|---------|
| local_ltiidentity | Identity profile for LTI users |
| ltisource_ltipseudonym | Scoped (pseudonymised) identity in the launch |
| local_ltiaccountmerge | Reconciliation of accounts created by LTI launches |
| local_ltixsembed | Cross-site embedding of LTI content |
| tool_ltidiagnostics (this plugin) | Diagnostics of LTI connections |
| tool_ltigroupautoenrol (existing) | Automatic enrolment of LTI users into groups |

The names of the four sibling plugins were fixed by the owner on 2026-10-25 (renamed in their release 0.2.0, fresh install,
no upgrade path from their 0.1.0 alpha). This plugin keeps its name, see `docs/NAMING.md`.


Plugin repositories
-------------------

This plugin is not published in the Moodle plugins repository.

The latest development version can be found on Github:
https://github.com/ralferlebach/moodle-tool_ltidiagnostics


Bug and problem reports / Support requests
------------------------------------------

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-tool_ltidiagnostics/issues

Please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Please issue feature proposals on Github:
https://github.com/ralferlebach/moodle-tool_ltidiagnostics/issues

Pull requests are welcome: https://github.com/ralferlebach/moodle-tool_ltidiagnostics/pulls


Moodle release support
----------------------

Supported: Moodle 4.5 (LTS) and 5.3 (`$plugin->supported = [405, 503]`). Support means a passing test on that version, see `docs/ACCEPTANCE.md`.


Translating this plugin
-----------------------

The plugin ships English and German language packs. Other languages should be contributed through AMOS (https://lang.moodle.org).


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.


Maintainers
-----------

Ralf Erlebach


Copyright
---------

The copyright of this plugin is held by Ralf Erlebach.

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
