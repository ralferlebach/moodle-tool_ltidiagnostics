# User stories and test coverage (issue #28)

Every user story of issues #10 to #27 with its fixture, the automated tests on each layer, the target system, the
artefact a reviewer can look at and the CI job that runs it. "Local" means: executed on the development machine in the
cells of `docs/ACCEPTANCE.md` (release 0.5.0); the GitHub workflows run the PHPUnit and Behat jobs on every push, the
two-site Playwright suites need the local stack (`tests/playwright/stack/README.md`) and are not part of GitHub CI.

Fixtures:

* **G** - `tests/fixtures/grade_scenario.php`: Moodle as tool with a control consumer (A) and a BUW-like consumer (B),
  learners with 65 points (sent, HTTP 200), no grade (NULL), 0 points (skipped by core: lastgrade 0) and a BUW learner
  whose send failed with HTTP 401; core's real `sync_tool_grades` writes the task log.
* **S** - two-site stack `tests/playwright/stack/grades_stack.php`: Moodle 4.5 tool (provider) and Moodle 5.2 platform
  (consumer) with the tool types Control and BUW and real OIDC launches; `manylearners` adds 60 learners to the Control
  link (grades 65 / 0 / none).
* **B** - Behat step `the LTI grade scenario exists for` (same data as G through core's generators) and the minimal
  German language pack `tests/fixtures/langpack_de/` (core strings of the menu path, copied from the official pack).
* **L** - load data written in bulk inside `tests/integration/grades_large_data_test.php`.

| Story (issue) | Fixture | PHPUnit | Behat | Playwright (S) | Target | Artefact | CI job |
|---|---|---|---|---|---|---|---|
| Menu entry per capability, once, also with pending upgrade (#10) | - | `navigation_identity_test::test_admin_tree_per_capability` (4 capabilities), `test_admin_tree_without_and_with_all` | `ux.feature` "single capability" outline, "Plugins > Admin tools" en/de | - | 4.5, 5.2, 5.3 | menu path in README | PHPUnit, Behat |
| No hard-coded text, en/de parity, machine ids unchanged (#11) | - | `lang_test::test_i18n_scan_of_output_code` (reviewed exception list), `test_languages_match`, `test_placeholders_match`, `test_literal_string_ids_exist` (incl. `$t()`/`self::t()`), `render_smoke_test` en/de | German tabs scenario | screenshots en/de, `[[` never shown | 4.5 | `screens/*.png` | PHPUnit, Behat |
| Names and ids with labels, edit links, back to overview (#12) | G | `navigation_identity_test::test_labels_and_core_links`, `test_back_link_keeps_filters`, `test_identity_tiers` | German tabs scenario (ids, back link keeps the search) | support journey | 4.5 | connection header | PHPUnit, Behat |
| Scope bar and defined counters, one population (#13) | G, S | `pair_query_test` (counter = filtered total, disjoint dimensions, empty course, NULL, 0, lastgrade skip, 2xx without booking, 401, two consumers, paging = every match), `test_scope_validation` | grade rows scenario ("not proven" note, filter = count) | counters without JavaScript, scope chips and reset | 4.5 -> 5.2 | rows count, counters | PHPUnit, Behat |
| Tab names and order, no duplicate headings, partner evidence only in the diagnosis (#14) | B | `render_smoke_test` | German tabs scenario, completion scenario | completion/partner test, read-only views never POST | 4.5 | tab bar | PHPUnit, Behat |
| Support list and case, audit with names and filters, role matrix (#15, #27) | B | `reporting_test::test_audit_page_filters_and_labels`, `access_control_test` (pages per capability) | support (support role without view, administrator), audit (privacy officer) scenarios | support journey and audit | 4.5 | support/audit screenshots | PHPUnit, Behat |
| Network check names target, method, executor, time, path, limits; 405 expected (#16) | - | `matrix_health_test::test_token_endpoint_405_is_expected`, `reporting_test::test_probe_snapshot_keeps_redacted_target`, network negative tests (0.3.0) | 405 scenario | - | 4.5 | probes table | PHPUnit, Behat |
| Completion view without plugin, history baseline (#17, #24, #26) | - | `render_smoke_test` (all states), `completion_view_test` | completion absent scenario, history scenario | completion present (8 tests of `completion_two_site`), history screenshots | 4.5 (absent), 4.5 -> 5.2 (present) | completion note | PHPUnit, Behat |
| Function check: ladder, how-to, actions with effect (#18) | - | `render_smoke_test`, `matrix_health_test` | function check scenario (0.4.0 feature updated) | read-only views never POST, effect badges | 4.5 | function check screenshot | PHPUnit, Behat |
| Messages and fields: requirement, runtime, core validation, filters (#19) | - | `message_matrix_test`, `field_matrix_test`, `render_smoke_test` (filter by message) | - | `messages_two_site` (0.3.0) | 4.5 | messages screenshot | PHPUnit |
| Separate ratings, counter-evidence, no generic outage (#20) | - | `matrix_health_test::test_probe_contradicted_by_real_launch`, `test_ags_and_booking_rated_separately` | 405 scenario | diagnosis screenshot | 4.5 | services table | PHPUnit, Behat |
| Grade rows: filters, names by right, AJAX paging 20-500, history, non-JS (#21) | G, S, L | `pair_query_test`, `sections_evidence_test::test_page_sizes`, `grades_large_data_test` (500 rows: read budget) | grade rows, pseudonym scenarios | AJAX paging/back/forward/reload, 500 rows, non-JS, viewer without names | 4.5 -> 5.2 | rows section | PHPUnit, Behat |
| Bulk replay: selection, preview, one batch, partial failure, no 2xx as booking (#22) | G, S | `repair_service_test::test_bulk_selection_and_batch` (TTL, foreign connection, too large, empty, one batch id, used selection, double submit, 409 partial failure) | bulk scenario | bulk replay with double submit at the real consumer | 4.5 -> 5.2 | batch status page | PHPUnit, Behat |
| Journal: filters, chain, unattributable core rows (#23) | G, S, L | `journal_test::test_server_side_filters`, `grades_large_data_test` (100 000 rows, 500 per page) | journal link scenario | journal of one row | 4.5 -> 5.2 | journal section | PHPUnit, Behat |
| Evidence and timeline: filters, attribution, paging, AJAX (#25) | L | `sections_evidence_test` (filters, attribution, external function rights and parameters), `grades_large_data_test` (100 000 observations) | - | read-only views | 4.5 | evidence screenshot | PHPUnit |
| Accessibility (WCAG 2.1 A/AA automated part), keyboard, live region | S | `render_smoke_test` (labels, badges, no nested forms) | tables with captions (0.2.0) | axe on 11 views x 2 languages, keyboard selection, `aria-live` | 4.5 | axe result in the test | PHPUnit |
| Upgrade 0.4.0 -> 0.5.0 | - | `upgrade_test::test_upgrade_from_040` | - | E2E sites upgraded from the 0.4.0 snapshot before every run | 4.5, 5.2 | - | PHPUnit |
| Performance (100 000 entries, 1 000 mappings, query budgets) | L | `grades_large_data_test` | - | - | 4.5 | reads and seconds asserted | PHPUnit |

Not automated and therefore open for the manual acceptance of the owner: screen reader output (NVDA/VoiceOver), the
visual judgement of the German texts, and the reproduction on the productive BUW connection.
