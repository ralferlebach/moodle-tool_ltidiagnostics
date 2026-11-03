<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_ltidiagnostics;

use tool_ltidiagnostics\local\connection;
use tool_ltidiagnostics\local\identity;
use tool_ltidiagnostics\local\navigation;
use tool_ltidiagnostics\local\scope;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Menu entries, navigation, identities and the scope (issues #10, #12, #13, #15, #27).
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\admin_access
 * @covers     \tool_ltidiagnostics\local\navigation
 * @covers     \tool_ltidiagnostics\local\identity
 * @covers     \tool_ltidiagnostics\local\scope
 */
final class navigation_identity_test extends \advanced_testcase {
    use \tool_ltidiagnostics\tests\deprecation_guarded;

    /**
     * A user holding the given plugin capabilities in the system context.
     *
     * @param string[] $caps Short names.
     * @return \stdClass
     */
    private function user_with(array $caps): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        foreach ($caps as $cap) {
            $name = strpos($cap, '/') === false ? 'tool/ltidiagnostics:' . $cap : $cap;
            assign_capability($name, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * Data provider: each capability alone opens exactly its own page.
     *
     * @return array
     */
    public static function capability_pages(): array {
        return [
            'view' => ['view', 'tool_ltidiagnostics_index'],
            'viewsupport' => ['viewsupport', 'tool_ltidiagnostics_support'],
            'importlogs' => ['importlogs', 'tool_ltidiagnostics_logimport'],
            'viewprivacy' => ['viewprivacy', 'tool_ltidiagnostics_audit'],
        ];
    }

    /**
     * Each single capability gets the category and its own page, no other page and not the settings (issue #10).
     *
     * @dataProvider capability_pages
     * @param string $cap Capability.
     * @param string $page Admin tree node.
     */
    public function test_admin_tree_per_capability(string $cap, string $page): void {
        $this->resetAfterTest();
        $this->setUser($this->user_with([$cap]));
        $root = admin_get_root(true, false);
        $category = $root->locate('tool_ltidiagnostics_category');
        $this->assertNotNull($category, "$cap: category registered");
        $this->assertTrue($category->check_access(), "$cap: category visible");
        foreach (local\admin_access::PAGES as $other) {
            $node = $root->locate($other);
            $this->assertNotNull($node, "$other registered once");
            $this->assertSame($other === $page, $node->check_access(), "$cap: access to $other");
        }
        $this->assertFalse($root->locate('tool_ltidiagnostics_settings')->check_access(), 'Settings need site:config.');
        // Exactly one parent: below 'tools' when the user has that category, else at the root, never both.
        $parents = 0;
        foreach (['tools', 'root'] as $parentname) {
            $parent = $root->locate($parentname);
            if ($parent instanceof \admin_category) {
                foreach ($parent->get_children() as $child) {
                    $parents += (int) ($child->name === 'tool_ltidiagnostics_category');
                }
            }
        }
        $this->assertSame(1, $parents, 'No duplicate menu entry.');
    }

    /**
     * Without any capability of the plugin the category is not even registered; the admin sees everything under
     * "Plugins > Admin tools" and the settings.
     */
    public function test_admin_tree_without_and_with_all(): void {
        $this->resetAfterTest();
        $this->setUser($this->user_with(['viewgrades']));
        $this->assertFalse(local\admin_access::any());
        $this->assertNull(admin_get_root(true, false)->locate('tool_ltidiagnostics_category'));
        $this->setAdminUser();
        $root = admin_get_root(true, false);
        $tools = $root->locate('tools');
        $this->assertInstanceOf(\admin_category::class, $tools);
        $names = array_map(fn($child) => $child->name, $tools->get_children());
        $this->assertContains('tool_ltidiagnostics_category', $names, 'Plugins > Admin tools (Dienstprogramme).');
        foreach (array_merge(array_values(local\admin_access::PAGES), ['tool_ltidiagnostics_settings']) as $name) {
            $this->assertTrue($root->locate($name)->check_access(), $name);
        }
    }

    /**
     * Connection labels carry the name and each local id with its own label; core edit links exist only with the
     * matching core capability.
     */
    public function test_labels_and_core_links(): void {
        $this->resetAfterTest();
        $tool = new connection();
        $tool->role = connection::ROLE_TOOL;
        $tool->name = 'Partner A';
        $tool->localid = 7;
        $tool->deploymentrowid = 9;
        $tool->key = 'tool:7:9';
        $label = navigation::label($tool);
        $this->assertStringContainsString('Partner A', $label);
        $this->assertStringContainsString(
            get_string('nav_ids_tool', 'tool_ltidiagnostics', (object) ['reg' => 7, 'dep' => 9]),
            $label
        );
        $platform = new connection();
        $platform->role = connection::ROLE_PLATFORM;
        $platform->name = 'Tool X';
        $platform->localid = 3;
        $platform->key = 'platform:3';
        $this->assertStringContainsString(get_string('nav_ids_platform', 'tool_ltidiagnostics', 3), navigation::label($platform));

        $this->setAdminUser();
        $kinds = array_column(navigation::core_links($tool), 'kind');
        $this->assertContains('registration', $kinds);
        $this->assertContains('deployments', $kinds);
        $links = navigation::core_links($platform);
        $this->assertSame(['tooltype'], array_column($links, 'kind'));
        $this->assertStringContainsString('/mod/lti/typessettings.php', $links[0]['url']->out(false));
        $this->setUser($this->user_with(['view']));
        $this->assertSame([], navigation::core_links($tool), 'No core edit link without moodle/site:config.');
        $this->assertSame([], navigation::core_links($platform));
    }

    /**
     * The way back to the overview keeps the filters of the overview.
     */
    public function test_back_link_keeps_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        navigation::remember_overview(['role' => 'tool', 'search' => 'buw', 'empty' => '']);
        $url = navigation::overview_url();
        $this->assertSame('tool', $url->get_param('role'));
        $this->assertSame('buw', $url->get_param('search'));
        $this->assertNull($url->get_param('empty'));
        $this->assertStringContainsString('id="tool_ltidiagnostics_backtooverview"', navigation::back_link());
    }

    /**
     * Learners' names are graded by capability: pseudonym only without viewidentities, name and profile link with it;
     * deleted users are named as unknown, never as an empty string.
     */
    public function test_identity_tiers(): void {
        $this->resetAfterTest();
        $learner = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $pseudonym = local\grades\grade_diagnosis::pseudonym('tool:1:1', (int) $learner->id);
        identity::reset();
        $this->setUser($this->user_with(['view', 'viewsupport']));
        $this->assertSame($pseudonym, identity::person('tool:1:1', (int) $learner->id));
        $this->assertStringNotContainsString('Lovelace', identity::person_html('tool:1:1', (int) $learner->id));
        $this->assertStringNotContainsString('profile.php', identity::person_html('tool:1:1', (int) $learner->id));
        $this->setUser($this->user_with(['view', 'viewidentities']));
        identity::reset();
        $this->assertSame('Ada Lovelace (' . $pseudonym . ')', identity::person('tool:1:1', (int) $learner->id));
        $this->assertStringContainsString('/user/profile.php?id=' . $learner->id, identity::person_html(
            'tool:1:1',
            (int) $learner->id
        ));
        $this->assertSame(
            get_string('id_userunknown', 'tool_ltidiagnostics', local\grades\grade_diagnosis::pseudonym('tool:1:1', 999999)),
            identity::person('tool:1:1', 999999)
        );
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Physics <b>I</b>']);
        $html = identity::course_html((int) $course->id);
        $this->assertStringContainsString(get_string('id_idsuffix', 'tool_ltidiagnostics', $course->id), $html);
        $this->assertStringNotContainsString('<b>', $html, 'Course names are formatted, not raw HTML.');
        $this->assertStringNotContainsString('course/edit.php', $html, 'No edit link without moodle/course:update.');
        $this->setAdminUser();
        $this->assertStringContainsString('course/edit.php', identity::course_html((int) $course->id));
        $this->assertSame(get_string('id_coursemissing', 'tool_ltidiagnostics', 999999), identity::course_html(999999));
    }

    /**
     * The scope validates course, activity and dates (viewer's time zone, stored in UTC) and states its errors.
     */
    public function test_scope_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setTimezone('Europe/Berlin');
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $s = scope::make((int) $course->id, (int) $assign->cmid, '2026-11-01', '2026-11-02');
        $this->assertSame([], $s->errors);
        $this->assertSame((int) $assign->cmid, $s->cmid);
        $this->assertSame((new \DateTime('2026-11-01 00:00:00', new \DateTimeZone('Europe/Berlin')))->getTimestamp(), $s->from);
        $this->assertSame((new \DateTime('2026-11-02 23:59:59', new \DateTimeZone('Europe/Berlin')))->getTimestamp(), $s->to);
        $this->assertTrue($s->contains_time($s->from));
        $this->assertFalse($s->contains_time($s->to + 1));
        $this->assertSame(['course', 'cm', 'period'], array_keys($s->describe()));
        $this->assertSame(['scope_err_cmcourse'], scope::make((int) $other->id, (int) $assign->cmid)->errors);
        $this->assertSame(['scope_err_course'], scope::make(999999, 0)->errors);
        $this->assertSame(['scope_err_date'], scope::make(0, 0, '2026-02-30')->errors);
        $this->assertSame(['scope_err_range'], scope::make(0, 0, '2026-11-02', '2026-11-01')->errors);
        $this->assertTrue(scope::make(0, 0)->is_empty());
        $this->assertSame([], scope::make(0, 0)->params());
    }
}
