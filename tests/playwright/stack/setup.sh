#!/bin/bash
# Two-site stack of the grade and completion channel suites (issues #3 to #9) - see README.md in this directory.
#
# Tool (provider): Moodle 4.5 at TOOL_URL (default http://localhost), tree TOOL_ROOT.
# Platform (consumer): Moodle 5.2 at PLATFORM_URL (default http://127.0.0.1:8052), tree PLATFORM_ROOT.
# Both sites must be installed, with tool_ltidiagnostics and local_lticompletionsync, and their web servers running.
# Writes the state file STATE (default /home/claude/e2e/state.json) that the specs read.
set -euo pipefail

TOOL_ROOT=${TOOL_ROOT:-/home/claude/moodle}
PLATFORM_ROOT=${PLATFORM_ROOT:-/home/claude/moodle52}
PLATFORM_URL=${PLATFORM_URL:-http://127.0.0.1:8052}
STATE=${STATE:-/home/claude/e2e/state.json}
CSYNC=${CSYNC_FIXTURE:-$TOOL_ROOT/local/lticompletionsync/tests/playwright/fixtures/csync_seed.php}
ADMINPW=${E2E_ADMIN_PASSWORD:-E2eAdmin123!}
HERE=$(cd "$(dirname "$0")" && pwd)

tool() { php "$HERE/grades_stack.php" --config="$TOOL_ROOT/config.php" --state="$STATE" --action="$@"; }
platform() { php "$HERE/grades_stack.php" --config="$PLATFORM_ROOT/config.php" --state="$STATE" --action="$@"; }
launch() { python3 "$HERE/lti_launch.py" --platform "$PLATFORM_URL" --username "$1" --password "$2" --cmid "$3"; }
cmid() { python3 -c "import json,sys; print(json.load(open('$STATE'))['platform']['types'][sys.argv[1]]['cmid'])" "$1"; }

rm -f "$STATE"
for root in "$TOOL_ROOT" "$PLATFORM_ROOT"; do
  php "$root/admin/cli/reset_password.php" --username=admin --password="$ADMINPW" --ignore-password-policy >/dev/null
  php "$root/admin/cli/cfg.php" --component=tool_ltidiagnostics --name=allowprivatetargets --set=1
done
php "$TOOL_ROOT/admin/cli/cfg.php" --component=tool_ltidiagnostics --name=graderepair --set=1

echo "== Grade channel: tool, platform (Control, BUW), registrations"
tool tool1 --arg=x
platform platform --arg=x
tool tool2 --arg=x
tool viewer --arg=x

echo "== Learner: Control first, the teacher grades 65/100, core sends; then BUW, core skips (lastgrade)"
launch learner1 'Learner123!' "$(cmid control)"
tool grade --arg=65
tool sync --arg=x
launch learner1 'Learner123!' "$(cmid buw)"
tool sync --arg=x

echo "== Second BUW constellation: a consumer without AGS"
tool noags --arg=tool1
platform noags --arg=platform
tool noags --arg=tool2
launch learner1 'Learner123!' "$(cmid noags)"
tool sync --arg=x

echo "== Completion channel (fixture of local_lticompletionsync, scenario dg)"
P1=$(php "$CSYNC" --root="$PLATFORM_ROOT" --stage=platform1 --scenario=dg --toolbase="$(python3 -c "import json;print(json.load(open('$STATE'))['tool']['url'])")" | tail -1)
get() { python3 -c "import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])" "$1" "$2"; }
T=$(php "$CSYNC" --root="$TOOL_ROOT" --stage=tool --scenario=dg --issuer="$(get "$P1" issuer)" --clientid="$(get "$P1" clientid)" \
  --deploymentid="$(get "$P1" deploymentid)" --endpoint="$(get "$P1" endpoint)" | tail -1)
P2=$(php "$CSYNC" --root="$PLATFORM_ROOT" --stage=platform2 --scenario=dg --typeid="$(get "$P1" typeid)" \
  --loginurl="$(get "$T" loginurl)" --uuid="$(get "$T" uuid)" --learners=csyncstudentdg | tail -1)
python3 - "$STATE" "$P1" "$T" "$P2" "$CSYNC" <<'PY'
import json, sys
state, p1, t, p2, fixture = sys.argv[1], json.loads(sys.argv[2]), json.loads(sys.argv[3]), json.loads(sys.argv[4]), sys.argv[5]
s = json.load(open(state))
s['completion'] = {'scenario': 'dg', 'typeid': p1['typeid'], 'toolkey': 'tool:%d:%d' % (t['regid'], t['deploymentrow']),
                   'pagecmid': t['pagecmid'], 'platformcmid': p2['cmid'], 'learner': p2['learners'][0]['username'],
                   'fixture': fixture}
json.dump(s, open(state, 'w'), indent=2)
PY
launch csyncstudentdg 'E2e!Pass-csyncstudentdg-2026' "$(get "$P2" cmid)"
php "$CSYNC" --root="$TOOL_ROOT" --stage=cron --scenario=dg >/dev/null
php "$CSYNC" --root="$PLATFORM_ROOT" --stage=state --scenario=dg --cmid="$(get "$P2" cmid)" --username=csyncstudentdg | tail -1
echo "== Stack ready: $STATE"
