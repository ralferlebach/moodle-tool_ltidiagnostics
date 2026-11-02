@tool @tool_ltidiagnostics @tool_ltidiagnostics_grades
Feature: Separate grade and completion channels with a guarded grade replay
  In order to repair a grade that one consumer never received
  As an administrator
  I need a read-only diagnosis per consumer and a replay that only an authorised person can trigger on purpose

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | learner1 | Lena      | Learner  | learner1@example.com |
      | viewer   | Vera      | Viewer   | viewer@example.com   |
    And the following "roles" exist:
      | shortname  | name               |
      | diagviewer | Diagnostics viewer |
    And the following "role capabilities" exist:
      | role       | tool/ltidiagnostics:view | tool/ltidiagnostics:export |
      | diagviewer | allow                    | allow                      |
    And the following "role assigns" exist:
      | user   | role       | contextlevel | reference |
      | viewer | diagviewer | System       |           |
    And the LTI grade scenario exists for "learner1"

  Scenario: The BUW connection shows that lastgrade blocks the grade and that core would send another subject
    Given I log in as "admin"
    When I open the LTI diagnostics tab "grades" of registration "BUW Moodle"
    Then I should see "Grade channel: standard LTI Assignment and Grade Services"
    And I should see "skipped: equals lastgrade"
    And I should see "lastgrade may block delivery"
    And I should see "core sends the subject of another consumer"
    And I should see "Core grade sync" in the "#tool_ltidiagnostics_grades_prereq caption" "css_element"
    And "#tool_ltidiagnostics_grades_findings tr[data-code='gr_lastgrade_blocks']" "css_element" should exist
    And I should not see "Preview replay for"

  Scenario: The control consumer shows the accepted core send as unconfirmed, never as booked
    Given I log in as "admin"
    When I open the LTI diagnostics tab "grades" of registration "Control LMS"
    Then I should see "HTTP accepted, booking unknown"
    And I should not see "booked (signed receipt)" in the "#tool_ltidiagnostics_grades_rows" "css_element"
    And "#tool_ltidiagnostics_grades_findings tr[data-code='gr_lastgrade_blocks']" "css_element" should not exist

  Scenario: The replay needs the site setting, the opt-in and a confirmed dry run, and then only queues
    Given the following config values are set as admin:
      | graderepair | 1 | tool_ltidiagnostics |
    And I log in as "admin"
    When I open the LTI diagnostics tab "grades" of registration "BUW Moodle"
    And I should not see "Preview replay for"
    And I press "Allow grade replay for this connection"
    Then I should see "Grade replay is allowed for this connection."
    And I click on "Preview replay for" "link"
    And I should see "Nothing has been sent."
    And I should see "auth_lti link of this issuer"
    And I should see "65.00000 / 100.00000"
    And I press "Queue replay"
    And I should see "Required"
    And I set the following fields to these values:
      | Reason for the replay | BUW never received the grade |
      | Confirmation          | 1                            |
    And I press "Queue replay"
    And I should see "The replay is queued"
    And I should see "queued"
    And I open the LTI diagnostics tab "grades" of registration "BUW Moodle"
    And I click on "Preview replay for" "link"
    And I should see "An attempt for this target is still open"
    And "Queue replay" "button" should not exist

  Scenario: A read-only viewer sees the channels but no mutating action and no score
    Given the following config values are set as admin:
      | graderepair            | 1 | tool_ltidiagnostics |
    And I log in as "viewer"
    When I open the LTI diagnostics tab "grades" of registration "BUW Moodle"
    Then I should see "lastgrade may block delivery"
    And I should not see "Preview replay for"
    And I should not see "Allow grade replay for this connection"
    And I should not see "65.00"
    And "Support package JSON" "link" should exist
    And I click on "Support package JSON" "link"
    And the LTI download should contain "ltidiag-support-2"
    And the LTI download should contain "gr_lastgrade_blocks"
    And the LTI download should not contain "learner1"
    And I open the LTI diagnostics tab "completion" of registration "BUW Moodle"
    And I should see "Completion channel: local_lticompletionsync"

  Scenario: A dry run is visible without the right, the form is not
    Given the following config values are set as admin:
      | graderepair | 1 | tool_ltidiagnostics |
    And I log in as "viewer"
    When I open the LTI grade replay preview of registration "BUW Moodle" for "learner1"
    Then I should see "Dry run only"
    And I should see "Nothing has been sent."
    And "Queue replay" "button" should not exist
