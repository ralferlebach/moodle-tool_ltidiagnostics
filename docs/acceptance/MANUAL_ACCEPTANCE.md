# Manual acceptance 0.6.0 (to be filled in by the owner)

The screenshots in `screens/` were taken automatically by `tests/playwright/ux_two_site.spec.js` on the two-site stack
(Moodle 4.5 tool, Moodle 5.2 platform, official German language pack) on 2026-11-03 for release 0.6.0, after the axe
check of the same page passed (WCAG 2.1 A and AA rules, no serious or critical violation). They show the BUW consumer
connection (the grade toolbars and the journal pages on the Control consumer with 61 learners); `en_`
and `de_` are the same page in English and German. Long pages are cut after the first screens.

| View | English | German |
|---|---|---|
| Overview of connections | `screens/en_overview.jpg` | `screens/de_overview.jpg` |
| Overview filtered by a course (#29) | `screens/en_overview_course.jpg` | `screens/de_overview_course.jpg` |
| Grades with toolbars, checkboxes, paging bars, journal (#31, #32) | `screens/en_control_grades.jpg` | `screens/de_control_grades.jpg` |
| Function check | `screens/en_buw_matrix.jpg` | `screens/de_buw_matrix.jpg` |
| Messages and fields | `screens/en_buw_messages.jpg` | `screens/de_buw_messages.jpg` |
| Connection diagnosis | `screens/en_buw_health.jpg` | `screens/de_buw_health.jpg` |
| Grades (AGS) | `screens/en_buw_grades.jpg` | `screens/de_buw_grades.jpg` |
| Completion sync (plugin present) | `screens/en_buw_completion.jpg` | `screens/de_buw_completion.jpg` |
| Timeline | `screens/en_buw_timeline.jpg` | `screens/de_buw_timeline.jpg` |
| Evidence sources | `screens/en_buw_evidence.jpg` | `screens/de_buw_evidence.jpg` |
| History | `screens/en_buw_history.jpg` | `screens/de_buw_history.jpg` |
| Support | `screens/en_support.jpg` | `screens/de_support.jpg` |
| Audit | `screens/en_audit.jpg` | `screens/de_audit.jpg` |

## Checklist

Mark each line with the date, the Moodle version and the result. Automated evidence is listed in `docs/STORY_MATRIX.md`;
these items cannot be decided by a test.

| # | Check | Result |
|---|---|---|
| 1 | German texts read naturally and use the terms of the department (Funktionscheck, Verbindungs-Diagnose, Notenjournal, Verbuchung) | |
| 2 | English texts read naturally | |
| 3 | Menu path in your production site: *Website-Administration > Plugins > Dienstprogramme > LTI-Diagnose* | |
| 4 | A delegated support role (with `moodle/site:configview`) sees exactly its entries | |
| 5 | Keyboard only: scope bar, tabs, filters, page size, row selection, bulk preview and confirmation | |
| 6 | Screen reader (NVDA or VoiceOver): tab names, table captions, the announcement "Updated: n matching entries" after a filter | |
| 7 | 200 % zoom and a narrow window: tables scroll, nothing is cut off | |
| 8 | On the BUW connection of the production site: the counters match what you know about the learners (no "open work") | |
| 9 | A support ticket followed from the support list to the grade journal and the audit | |
| 10 | Bulk replay on a test connection: preview, confirmation, batch status, booking only after the AGS result or a receipt | |
| 11 | Overview filtered by one of your courses: only the connections used in that course or its activities; filtered by an activity: only that activity (#29) | |
| 12 | Header of a connection: back link, name with ids, one pencil; pencils stand next to what they edit and open the right core page (#30) | |
| 13 | Paging bars above and below grade rows and journal; a new grade attempt while you page does not shift the journal pages (#31) | |
| 14 | Filters in one row at your usual window width; status details folded; checkboxes with "select all shown" (#32) | |
