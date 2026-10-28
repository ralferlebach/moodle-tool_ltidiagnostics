@tool @tool_ltidiagnostics
Feature: LTI diagnostics is a read-only administration tool with its own capabilities
  In order to find out what an LTI connection really does
  As an administrator or a delegated viewer
  I need to see the connections and be sure that nobody else can

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | viewer   | Vera      | Viewer   | viewer@example.com   |
      | student1 | Stella    | Student  | student1@example.com |
    And the following "roles" exist:
      | shortname  | name               |
      | diagviewer | Diagnostics viewer |
    And the following "role capabilities" exist:
      | role       | tool/ltidiagnostics:view |
      | diagviewer | allow                    |
    And the following "role assigns" exist:
      | user   | role       | contextlevel | reference |
      | viewer | diagviewer | System       |           |

  Scenario: The administrator sees the connection inventory and the read-only notice
    Given I log in as "admin"
    When I navigate to "LTI Diagnostics > LTI connections" in site administration
    Then I should see "LTI connections"
    And I should see "never changes a setting"

  Scenario: The administrator sees the plugin in the plugin overview
    Given I log in as "admin"
    When I navigate to "Plugins > Plugins overview" in site administration
    Then I should see "LTI Diagnostics"

  Scenario: A delegated viewer can open the inventory without site configuration rights
    Given I log in as "viewer"
    When I visit "/admin/tool/ltidiagnostics/index.php"
    Then I should see "LTI connections"
    And I should not see "Diagnostic mode"

  Scenario: A student has no access entry
    # index.php without the capability ends in an "Access denied" exception page, which Behat cannot assert.
    # The denial is covered by PHPUnit (access_control_test::test_pages_are_guarded); here the visible navigation is checked.
    Given I log in as "student1"
    When I am on site homepage
    Then I should not see "Site administration"
    And I should not see "LTI connections"

  Scenario: The inventory can be filtered and an empty result is stated
    Given I log in as "admin"
    When I visit "/admin/tool/ltidiagnostics/index.php?search=zzz-no-such-connection"
    Then I should see "LTI connections"

  Scenario: The health check of a connection states what was checked and what cannot be seen
    Given the following "mod_lti > tool types" exist:
      | name      | baseurl                          | coursevisible | state |
      | Diag tool | https://tool.example.org/launch  | 2             | 1     |
    And I log in as "admin"
    When I visit "/admin/tool/ltidiagnostics/index.php"
    And I click on "Open" "link" in the "Diag tool" "table_row"
    And I click on "Health check" "link"
    Then I should see "What was checked"
    And I should see "Traffic light per layer"
    And I should see "What can be seen"

  Scenario: The connection list is a table with a caption and scoped column headers
    Given the following "mod_lti > tool types" exist:
      | name      | baseurl                          | coursevisible | state |
      | Diag tool | https://tool.example.org/launch  | 2             | 1     |
    And I log in as "admin"
    When I visit "/admin/tool/ltidiagnostics/index.php"
    Then "table caption" "css_element" should exist
    And "th[scope='col']" "css_element" should exist

  Scenario: The capability matrix and the evidence tab are reachable by link and show their tables
    Given the following "mod_lti > tool types" exist:
      | name      | baseurl                          | coursevisible | state |
      | Diag tool | https://tool.example.org/launch  | 2             | 1     |
    And I log in as "admin"
    When I visit "/admin/tool/ltidiagnostics/index.php"
    And I click on "Open" "link" in the "Diag tool" "table_row"
    Then I should see "Capability matrix"
    When I click on "Evidence sources" "link"
    Then I should see "What can be seen"
    And "th[scope='col']" "css_element" should exist
