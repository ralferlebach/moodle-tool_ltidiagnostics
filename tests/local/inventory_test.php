<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_ltidiagnostics\local;

use tool_ltidiagnostics\tests\lti_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/lti_data.php');

/**
 * Integration tests of the connection inventory against real mod_lti and enrol_lti data.
 *
 * @package    tool_ltidiagnostics
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltidiagnostics\local\inventory
 * @covers     \tool_ltidiagnostics\local\connection
 * @covers     \tool_ltidiagnostics\local\resolver
 */
final class inventory_test extends \advanced_testcase {
    use lti_data;

    /**
     * Connection keys round trip and reject anything else.
     */
    public function test_keys(): void {
        $this->assertSame('platform:5', connection::make_key('platform', 5));
        $this->assertSame('tool:3:9', connection::make_key('tool', 3, 9));
        $this->assertSame(['platform', 5, 0], connection::parse_key('platform:5'));
        $this->assertSame(['tool', 3, 9], connection::parse_key('tool:3:9'));
        $invalid = ['', 'platform', 'platform:x', 'platform:5:6', 'tool:3', 'tool:3:x', 'other:1', "platform:5\n", 'platform:-1',
            'platform:12345678901'];
        foreach ($invalid as $bad) {
            $this->assertNull(connection::parse_key($bad), $bad);
        }
    }

    /**
     * Platform role: a real mod_lti type appears with its services and endpoints.
     */
    public function test_platform_connection_from_real_type(): void {
        $this->resetAfterTest();
        $typeid = $this->create_platform_type(['lti_clientid' => 'abc-client-id']);
        $connections = (new inventory())->get_connections('platform');
        $this->assertArrayHasKey("platform:$typeid", $connections);
        $c = $connections["platform:$typeid"];
        $this->assertSame('platform', $c->role);
        $this->assertSame('1.3.0', $c->ltiversion);
        $this->assertSame('abc-client-id', $c->clientid);
        $this->assertSame('active', $c->state);
        $this->assertSame('https://tool.example.org/jwks.json', $c->endpoints['jwks']);
        $this->assertSame('https://tool.example.org/login', $c->endpoints['initiatelogin']);
        $this->assertSame(2, $c->facts['ags']);
        $this->assertSame(1, $c->facts['nrps']);
        $this->assertSame(1, $c->facts['hasredirects']);
        $this->assertContains('ltiservice_gradebookservices', $c->facts['services']);
        $this->assertContains('ltiservice_memberships', $c->facts['services']);
        $this->assertNotNull((new inventory())->get_connection("platform:$typeid"));
        $this->assertNull((new inventory())->get_connection('platform:999999'));
        $this->assertNull((new inventory())->get_connection('garbage'));
    }

    /**
     * A tool type with LTI 1.0 shows up as legacy, with services off.
     */
    public function test_platform_legacy_type(): void {
        $this->resetAfterTest();
        $typeid = $this->create_platform_type([
            'lti_ltiversion' => 'LTI-1p0', 'lti_clientid' => '', 'ltiservice_gradesynchronization' => 0,
            'ltiservice_memberships' => 0, 'lti_publickeyset' => '', 'lti_initiatelogin' => '', 'lti_redirectionuris' => '',
            'lti_keytype' => 'RSA_KEY',
        ]);
        $c = (new inventory())->get_connection("platform:$typeid");
        $this->assertNotNull($c);
        $this->assertSame('LTI-1p0', $c->ltiversion);
        $this->assertSame(0, $c->facts['ags']);
    }

    /**
     * Tool role: a real registration, deployment and published tool appear, linked through a resource link.
     */
    public function test_tool_connection_from_real_registration(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $reg = $this->create_registration();
        $tool = $this->create_published_tool($course, ['gradesync' => 1, 'membersync' => 1]);
        $this->create_resource_link($reg->depid, (int) $tool->id, [
            'lineitemsservice' => 'https://platform.example.org/lineitems',
            'scorescope' => 'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            'contextmembershipsurl' => 'https://platform.example.org/memberships',
        ]);
        $key = "tool:{$reg->regid}:{$reg->depid}";
        $connections = (new inventory())->get_connections('tool');
        $this->assertArrayHasKey($key, $connections);
        $c = $connections[$key];
        $this->assertSame('tool', $c->role);
        $this->assertSame('https://platform.example.org', $c->issuer);
        $this->assertSame('tool-client-1', $c->clientid);
        $this->assertSame($reg->deploymentid, $c->deploymentid);
        $this->assertSame('complete', $c->state);
        $this->assertSame('https://platform.example.org/jwks', $c->endpoints['jwks']);
        $this->assertSame('https://platform.example.org/token', $c->endpoints['oauth']);
        $this->assertCount(1, $c->tools);
        $this->assertSame(1, $c->tools[$tool->id]['gradesync']);
        $this->assertSame(hash('sha256', 'https://platform.example.org'), $c->facts['issuerhash']);
        // Resolution works in both directions.
        $this->assertSame($key, resolver::tool_key_for_enrol_instance((int) $tool->enrolid));
        $this->assertSame($key, resolver::tool_key_for_issuer_hash(hash('sha256', 'https://platform.example.org')));
        $this->assertSame('', resolver::tool_key_for_issuer_hash(hash('sha256', 'https://other.example.org')));
    }

    /**
     * A registration without any deployment is listed with row id zero.
     */
    public function test_registration_without_deployment(): void {
        $this->resetAfterTest();
        $reg = $this->create_registration(['deploymentname' => null, 'deploymentid' => null]);
        $this->assertArrayHasKey("tool:{$reg->regid}:0", (new inventory())->get_connections('tool'));
    }

    /**
     * A draft registration is reported as draft.
     */
    public function test_draft_registration(): void {
        $this->resetAfterTest();
        $reg = $this->create_registration(['platformid' => '', 'clientid' => '', 'authrequesturl' => '', 'jwksurl' => '',
            'accesstokenurl' => '']);
        $c = (new inventory())->get_connection("tool:{$reg->regid}:{$reg->depid}");
        $this->assertNotNull($c);
        $this->assertSame('draft', $c->state);
    }

    /**
     * The same issuer registered twice is ambiguous, so no connection key is returned for it.
     */
    public function test_ambiguous_issuer_is_not_resolved(): void {
        $this->resetAfterTest();
        $this->create_registration(['clientid' => 'one', 'deploymentid' => 'd1']);
        $this->create_registration(['clientid' => 'two', 'deploymentid' => 'd2']);
        $this->assertSame('', resolver::tool_key_for_issuer_hash(hash('sha256', 'https://platform.example.org')));
    }

    /**
     * Role filter and text search.
     */
    public function test_filters(): void {
        $this->resetAfterTest();
        $typeid = $this->create_platform_type(['name' => 'Searchable Banana Tool']);
        $reg = $this->create_registration(['name' => 'Platform Cherry']);
        $inventory = new inventory();
        $all = $inventory->get_connections();
        $this->assertArrayHasKey("platform:$typeid", $all);
        $this->assertArrayHasKey("tool:{$reg->regid}:{$reg->depid}", $all);
        $this->assertCount(1, $inventory->get_connections('platform', 'banana'));
        $this->assertCount(1, $inventory->get_connections('tool', 'CHERRY'));
        $this->assertCount(0, $inventory->get_connections('platform', 'cherry'));
        $this->assertCount(1, $inventory->get_connections('', $reg->deploymentid));
    }

    /**
     * Course filter: course tools only for their course, tool-role connections stay.
     */
    public function test_course_filter(): void {
        $this->resetAfterTest();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $sitetype = $this->create_platform_type(['name' => 'Site tool']);
        $coursetype = $this->getDataGenerator()->get_plugin_generator('mod_lti')->create_course_tool_types([
            'name' => 'Course tool', 'baseurl' => 'https://c.example.org/launch', 'course' => $course1->id,
        ]);
        $inventory = new inventory();
        $one = $inventory->get_connections('platform', '', (int) $course1->id);
        $two = $inventory->get_connections('platform', '', (int) $course2->id);
        $this->assertArrayHasKey("platform:$sitetype", $one);
        $this->assertArrayHasKey("platform:$coursetype", $one);
        $this->assertArrayHasKey("platform:$sitetype", $two);
        $this->assertArrayNotHasKey("platform:$coursetype", $two);
    }
}
