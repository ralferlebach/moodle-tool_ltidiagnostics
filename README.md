moodle-tool_ltidiagnostics
==========================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-tool_ltidiagnostics/actions/workflows/moodle-plugin-ci-main.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-tool_ltidiagnostics/actions?query=branch%3Amain)

Read-only diagnostics for LTI connections. For every connection (Moodle as platform and Moodle as tool) it shows what is
specified, implemented, enabled, declared, authorised, reachable and really observed, and it says in plain language
what an administrator can do next. A feature is only reported as **verified** when a real transaction was observed.
An enabled checkbox or a reachable endpoint is never enough.

Status: alpha (0.1.0). See `docs/ACCEPTANCE.md` for what is proven and what is not.


Requirements
------------

This plugin requires Moodle 4.5+ (tested on 4.5 and 5.3) and PHP 8.1+.


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


Usage & Settings
----------------

After installing, open *Site administration > Plugins > Admin tools > LTI Diagnostics > LTI connections*.

* **Connection inventory.** Lists the connections of both roles: LTI 1.3 tool types of mod_lti (Moodle as platform)
  and registrations and deployments of enrol_lti (Moodle as tool). Filter by role, text and course.
* **Capability matrix.** Per connection and per feature (LTI core, AGS, NRPS, Deep Linking, Dynamic Registration,
  Platform Storage, ...) the seven steps of the evidence ladder and a status: supported, configured, authorised,
  verified, blocked, incompatible, not_supported, not_observable, unknown.
* **Health check.** Compares a desired-state profile (JSON or form) with what is local, declared, authorised and
  observed, and lists findings by layer (configuration, network, TLS, OIDC/JWT, OAuth2, services, tasks, privacy).
* **Timeline.** Observed launches, enrolments, group additions, grades, access-log lines, tasks and log events
  side by side. Each row names its source and a correlation confidence (direct, strong, tentative, unmatched).
* **Active checks.** Optional, off by default, GET only: JWKS, metadata and TLS check of an endpoint, with an SSRF guard.
* **Log import.** Retrospective import of nginx/apache access logs from an admin-configured allow-listed path or an
  uploaded file. Addresses are masked, query values removed.
* **Reports.** Export as Markdown, JSON or CSV with redaction by default, a time-limited diagnostic mode (TTL) with
  audit, and a pseudonymised support view.

Settings (*Site administration > Plugins > Admin tools > LTI Diagnostics > Settings*): record events, retention days,
log allow-list, maximum lines per import, active checks (off by default), allow private targets (off by default),
maximum minutes of the diagnostic mode.


Capabilities
------------

This plugin introduces these capabilities (none is granted by default except `viewcourse`):

* **tool/ltidiagnostics:view** - Open the connection inventory, matrix, health check and timeline (admin; risk: personal, config).
* **tool/ltidiagnostics:viewcourse** - Aggregate summary of the LTI activities of a course (editing teacher, manager).
* **tool/ltidiagnostics:viewsupport** - Pseudonymised support view with a diagnostic ID (support staff).
* **tool/ltidiagnostics:viewprivacy** - Audit log of views and exports (privacy officer).
* **tool/ltidiagnostics:configure** - Switch the diagnostic mode, manage desired-state profiles.
* **tool/ltidiagnostics:runchecks** - Run the active network checks.
* **tool/ltidiagnostics:importlogs** - Import web server logs.
* **tool/ltidiagnostics:export** - Export reports.

Site administrators have all of them through the normal Moodle rules.


Scheduled Tasks
---------------

* **\tool_ltidiagnostics\task\cleanup_task** - Deletes recorded observations, imported log lines, snapshots and audit
  entries older than the retention time and ends an expired diagnostic mode. Enabled by default, runs daily at 03:17.


How this plugin works / Pitfalls
--------------------------------

* The plugin only uses stable Moodle APIs: the log manager readers, the task manager and `task_log`, the events API,
  the LTI inventory tables and the Moodle curl wrapper. It does not patch core and does not need a fork.
* Moodle offers no hook for the OIDC login, the JWT validation or the AGS/NRPS service calls. Those steps are shown as
  `not_observable` unless the web server access log is imported. This is a limit of Moodle, not a bug (docs/DOCUMENTATION.md, gates).
* A tool-side AGS or NRPS feature can never become `verified` in 0.1.0, because the authorised test call that would prove
  it is not implemented.
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
| local_ltiidentityprofile | Identity profile for LTI users |
| ltisource_scopedidentity | Scoped (pseudonymised) identity in the launch |
| local_ltiaccountreconcile | Reconciliation of accounts created by LTI launches |
| local_lticrossembed | Cross-site embedding of LTI content |
| tool_ltidiagnostics (this plugin) | Diagnostics of LTI connections |
| tool_ltigroupautoenrol (existing) | Automatic enrolment of LTI users into groups |

Names are provisional, see `docs/NAMING.md`.


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
