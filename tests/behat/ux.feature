@tool @tool_ltidiagnostics @tool_ltidiagnostics_ux
Feature: Readable navigation, scope, grade rows, support and audit of the LTI diagnostics
  In order to find and fix the cause of an LTI problem without guessing
  As an administrator, a support person or a privacy officer
  I need menu entries for my rights, readable names, defined counters and a way from a ticket to the evidence

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | lang |
      | learner1 | Lena      | Learner  | learner1@example.com | en   |
      | support1 | Sam       | Support  | support1@example.com | en   |
      | dpo1     | Dana      | Privacy  | dpo1@example.com     | en   |
      | import1  | Ivo       | Import   | import1@example.com  | en   |
      | names1   | Nora      | Names    | names1@example.com   | en   |
    And the following "roles" exist:
      | shortname   | name                |
      | diagsupport | Diagnostics support |
      | diagprivacy | Diagnostics privacy |
      | diagimport  | Diagnostics import  |
      | diagnames   | Diagnostics names   |
    And the following "role capabilities" exist:
      | role        | tool/ltidiagnostics:viewsupport | tool/ltidiagnostics:viewprivacy | tool/ltidiagnostics:importlogs | tool/ltidiagnostics:view | tool/ltidiagnostics:viewidentities | moodle/site:configview |
      | diagsupport | allow                           | inherit                         | inherit                        | inherit                  | inherit                            | allow                  |
      | diagprivacy | inherit                         | allow                           | inherit                        | inherit                  | inherit                            | allow                  |
      | diagimport  | inherit                         | inherit                         | allow                          | inherit                  | inherit                            | allow                  |
      | diagnames   | inherit                         | inherit                         | inherit                        | allow                    | allow                              | inherit                |
    And the following "role assigns" exist:
      | user     | role        | contextlevel | reference |
      | support1 | diagsupport | System       |           |
      | dpo1     | diagprivacy | System       |           |
      | import1  | diagimport  | System       |           |
      | names1   | diagnames   | System       |           |
    And the LTI grade scenario exists for "learner1"

  Scenario: The administrator finds the plugin under Plugins > Admin tools, in English and in German
    Given I log in as "admin"
    When I navigate to "Plugins > Admin tools > LTI Diagnostics > LTI connections" in site administration
    Then I should see "LTI connections"
    And the German language pack for LTI diagnostics is installed
    And the LTI diagnostics user "admin" uses the language "de"
    And I log out
    And I log in as "admin"
    # The core navigation step only knows the English "Site administration"; the German path is followed by hand.
    When I am on site homepage
    And I follow "Website-Administration"
    And I click on "Plugins" "link" in the ".secondary-navigation" "css_element"
    Then I should see "Dienstprogramme"
    And I should see "LTI-Diagnose"
    When I click on "LTI-Verbindungen" "link" in the "#region-main" "css_element"
    Then I should see "LTI-Verbindungen"
    And I should see "Support" in the "#tool_ltidiagnostics_quicklinks" "css_element"

  Scenario Outline: A user with a single capability gets exactly the entry of that capability
    Given I log in as "<user>"
    When I navigate to "LTI Diagnostics > <entry>" in site administration
    Then I should see "<heading>"
    When I visit "/admin/category.php?category=tool_ltidiagnostics_category"
    Then I should see "<entry>"
    And I should not see "<other>"

    Examples:
      | user     | entry                          | other                          | heading                        |
      | support1 | LTI diagnostics for support    | Audit log of diagnostic views  | LTI diagnostics for support    |
      | dpo1     | Audit log of diagnostic views  | LTI connections                | Audit log of diagnostic views  |
      | import1  | Import web server logs         | Audit log of diagnostic views  | Import web server logs         |

  Scenario: The connection page has German tabs in the required order and leads back to the filtered overview
    Given the German language pack for LTI diagnostics is installed
    And the LTI diagnostics user "admin" uses the language "de"
    And I log in as "admin"
    When I visit "/admin/tool/ltidiagnostics/index.php?search=BUW"
    And I click on "Öffnen" "link" in the "BUW Moodle" "table_row"
    Then I should see "Funktionscheck" in the "#tool_ltidiagnostics_tabs .nav-item:nth-child(1)" "css_element"
    And I should see "Nachrichten und Felder" in the "#tool_ltidiagnostics_tabs .nav-item:nth-child(2)" "css_element"
    And I should see "Verbindungs-Diagnose" in the "#tool_ltidiagnostics_tabs .nav-item:nth-child(3)" "css_element"
    And I should not see "Health-Check"
    And I should not see "Fähigkeitsmatrix"
    And I should see "Registrierungs-ID:"
    And I should see "Deployment-ID:"
    When I click on "Zurück zur Übersicht der LTI-Verbindungen" "link"
    Then the field "search" matches value "BUW"

  Scenario: The grade rows show names by right, defined counters and filters that agree with the counts
    Given I log in as "names1"
    When I open the LTI diagnostics tab "grades" of registration "Control LMS"
    Then I should see "Lena Learner"
    And "#tool_ltidiagnostics_cnt_grade" "css_element" should exist
    And "#tool_ltidiagnostics_cnt_transfer" "css_element" should exist
    And "#tool_ltidiagnostics_cnt_booking" "css_element" should exist
    And I should see "not proven" in the "#tool_ltidiagnostics_cnt_booking" "css_element"
    And I should not see "Open work"
    And I should see "\"Not proven\" does not mean \"to be sent\""
    When I set the field "Source grade" to "grade present (not 0)"
    And I press "Filter"
    Then I should see "1 to 1 of 1 pairs" in the "#tool_ltidiagnostics_rows_count" "css_element"
    And I should see "Lena Learner"
    When I click on "Journal of Lena Learner" "link"
    Then I should see "Only entries of resource link"

  Scenario: A viewer without the identity right sees pseudonyms, never names or scores
    Given the following "roles" exist:
      | shortname  | name               |
      | diagviewer | Diagnostics viewer |
    And the following "role capabilities" exist:
      | role       | tool/ltidiagnostics:view |
      | diagviewer | allow                    |
    And the following "users" exist:
      | username | firstname | lastname | email               |
      | viewer2  | Vic       | Viewer   | viewer2@example.com |
    And the following "role assigns" exist:
      | user    | role       | contextlevel | reference |
      | viewer2 | diagviewer | System       |           |
    And I log in as "viewer2"
    When I open the LTI diagnostics tab "grades" of registration "Control LMS"
    Then I should not see "Lena Learner"
    And I should not see "65.00"
    And "Learner (name or email)" "field" should not exist

  Scenario: Bulk replay goes through a selection, a preview with every target and a batch status
    Given the following config values are set as admin:
      | graderepair | 1 | tool_ltidiagnostics |
    And I log in as "admin"
    When I open the LTI diagnostics tab "grades" of registration "BUW Moodle"
    And I press "Allow grade replay for this connection"
    And I open the LTI diagnostics tab "grades" of registration "BUW Moodle"
    And I press "Select all 1 matches of the filter"
    Then I should see "Preview of the bulk replay"
    And I should see "all matches of the filter"
    And I should see "Each target is checked again before it is sent."
    And I should see "no reset of lastgrade"
    And I set the following fields to these values:
      | Reason for the replay | BUW never received the grades |
      | Confirmation          | 1                             |
    And I press "Queue replay"
    Then I should see "Status of the bulk replay"
    And I should see "queued"
    And I should see "An accepted HTTP request (2xx) is not a booking"

  Scenario: The connection diagnosis rates services separately and explains a GET 405 on the token endpoint
    Given the LTI network checks of registration "BUW Moodle" found a GET 405 on the token endpoint
    And I log in as "admin"
    When I open the LTI diagnostics tab "health" of registration "BUW Moodle"
    Then I should see "Rating per service"
    And I should see "The token endpoint answers a GET with 405"
    And I should see "this Moodle server" in the "#tool_ltidiagnostics_probes" "css_element"
    And I should see "no answer (packets possibly dropped)" in the "#tool_ltidiagnostics_probes" "css_element"
    And I should see "It proves reachability from this server at that time"
    And I should see "Collect partner evidence for this connection"

  Scenario: The completion tab is absent without local_lticompletionsync and a direct link says why
    Given I log in as "admin"
    When I open the LTI diagnostics tab "grades" of registration "BUW Moodle"
    Then I should not see "Completion sync" in the "#tool_ltidiagnostics_tabs" "css_element"
    And I should not see "Completion transfers"
    When I open the LTI diagnostics tab "completion" of registration "BUW Moodle"
    Then I should see "is not installed"
    And I should see "grade transfers (AGS) work independently of it"
    And "button[type='submit']" "css_element" should not exist in the "#tool_ltidiagnostics_compl_unavailable" "css_element"

  Scenario: The history without snapshots says so and is not the journal
    Given I log in as "admin"
    When I open the LTI diagnostics tab "history" of registration "BUW Moodle"
    Then I should see "No snapshot has been stored for this connection yet."
    And I should see "It is not the grade journal and not the audit log."

  Scenario: Support finds a case by name, follows it without self-links and without connection rights sees no detail links
    Given I log in as "support1"
    When I visit "/admin/tool/ltidiagnostics/support.php"
    Then I should see "BUW Moodle"
    And I should see "registration id:"
    And I should see "LTD-"
    And "a[href*='support.php?id=']" "css_element" should not exist
    When I set the field "Name, local id, issuer or diagnostic ID" to "BUW"
    And I press "Filter"
    Then I should see "BUW Moodle"
    And I should not see "Control LMS"
    When I click on "Support case" "link" in the "BUW Moodle" "table_row"
    Then I should see "Diagnostic ID for tickets"
    And "#tool_ltidiagnostics_support_journey" "css_element" should exist
    And I should not see "Grade journal"
    And I should not see "Audit of this connection"
    And I should not see "Find a learner in this connection"

  Scenario: The administrator follows the support journey to the grade journal and the audit
    Given I log in as "admin"
    When I visit "/admin/tool/ltidiagnostics/support.php?search=BUW"
    And I click on "Support case" "link" in the "BUW Moodle" "table_row"
    Then I should see "Fault diagnosis (connection diagnosis)"
    And I should see "Find a learner in this connection"
    When I click on "Grade journal" "link"
    Then I should see "Grades (AGS)"

  Scenario: The audit shows real names, localised actions and filters
    Given the LTI diagnostics audit has an export by "support1" of registration "BUW Moodle"
    And I log in as "dpo1"
    When I visit "/admin/tool/ltidiagnostics/audit.php"
    Then I should see "Sam Support"
    And I should see "Report exported"
    And I should see "Viewed: Grades (AGS)"
    And I should see "BUW Moodle"
    When I set the field "Kind of action" to "exports"
    And I press "Filter"
    Then I should see "Report exported"
    And I should not see "Viewed: Grades (AGS)"
    And I should see "1 of 1 audit entries shown."
