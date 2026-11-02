# Two-site stack of the grade and completion channel suites

`grades_two_site.spec.js` (issues #3, #5, #6, #8, #9) and `completion_two_site.spec.js` (issue #7) run on two real
Moodle sites with real LTI 1.3 launches, real AGS calls and real completion sync messages:

| Role | Site | URL | Plugins |
|---|---|---|---|
| Tool (provider) | Moodle 4.5 | `http://localhost` | tool_ltidiagnostics, local_lticompletionsync |
| Platform (consumer) | Moodle 5.2 | `http://127.0.0.1:8052` | tool_ltidiagnostics, local_lticompletionsync |

Consumers on the platform (LTI 1.3 tool types, one enrol_lti registration each on the tool):

* **Control**: AGS with line item management. The learner launches here first; core's grade sync sends 65/100.
* **BUW**: same platform, second tool type, AGS enabled. The same tool user launches; core's grade sync skips it
  ("grades are the same"): `enrol_lti_users.lastgrade` exists once per user and published resource. This is the
  defect the replay repairs.
* **BUW without AGS**: third tool type with grade synchronisation off: second defective constellation (`no_service`,
  responsible: consumer).
* **CSYNC tool dg**: created by the fixture of local_lticompletionsync (`tests/playwright/fixtures/csync_seed.php`,
  scenario `dg`): a published page with completion on view, sender and receiver connections.

## Files

* `grades_stack.php` - CLI fixture (core APIs only), one action per step, state in a JSON file.
* `lti_launch.py` - the OIDC launch flow over HTTP (platform login, `mod/lti/launch.php`, the tool's initiate login,
  `mod/lti/auth.php`, the `id_token` POST). A scripted client instead of a browser: on plain http with two hosts,
  browsers drop the cross-site cookies of the flow (SameSite). Requests and validation on both sites are the real ones.
* `setup.sh` - builds the whole stack on two installed sites in the order above.

## Setup

```bash
# both sites installed with both plugins; allow loopback requests only for this lab (config.php of both sites,
# not for PHPUnit/Behat):  $CFG->curlsecurityblockedhosts = ''; $CFG->curlsecurityallowedport = '';
php -S 127.0.0.1:80 -t /home/claude/moodle &                    # 4.5, wwwroot http://localhost
php -S 127.0.0.1:8052 -t /home/claude/moodle52/public &         # 5.2, wwwroot http://127.0.0.1:8052
TOOL_ROOT=/home/claude/moodle PLATFORM_ROOT=/home/claude/moodle52 tests/playwright/stack/setup.sh
```

`setup.sh` sets `allowprivatetargets` (both sites) and `graderepair` (tool), the admin password
(`E2E_ADMIN_PASSWORD`, default `E2eAdmin123!`) and a read-only viewer `diagviewer` (view and export only). The specs
change data; take a database snapshot after the setup and restore it before every run.

## Run

```bash
NODE_PATH=<dir with @playwright/test> npx playwright test -c tests/playwright/playwright.channels.config.js
```

Environment: `GRADES_STATE` (state file), `TOOL_CONFIG`/`PLATFORM_CONFIG` or `TOOL_ROOT`/`PLATFORM_ROOT`,
`CHROME_PATH` (browser binary), `PW_OUTPUT_DIR`.

What is real and what is set up: launches, the core grade sync (run through the CLI like cron), the AGS token, score
and result calls, the receipt signature with the platform key from `mod/lti/certs.php`, and the completion messages
and acknowledgements are real HTTP between the sites. Set up through APIs: the teacher's grades on both sites, the
consumer grade with a future timestamp (409 case), the completion override at the platform (conflict), the closed port
of the completion endpoint (retry), and the disabled receiver connection (missing partner).
