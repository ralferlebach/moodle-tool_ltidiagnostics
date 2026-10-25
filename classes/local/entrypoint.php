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

namespace tool_ltidiagnostics\local;

/**
 * Classifies the PHP entry point of the current request.
 *
 * The entry point turns an anonymous Moodle event into evidence: the same user_updated event is
 * a profile edit from a form and a launch synchronisation when raised from the LTI launch script.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entrypoint {
    /** @var string Entry point: LTI launch scripts of the tool side. */
    public const LTI_LAUNCH = 'lti_launch';
    /** @var string Entry point: LTI service endpoints of the platform side. */
    public const LTI_SERVICES = 'lti_services';
    /** @var string Entry point: command line. */
    public const CLI = 'cli';
    /** @var string Entry point: web services. */
    public const WS = 'ws';
    /** @var string Entry point: any other web request. */
    public const WEB = 'web';

    /** @var string[] Script path fragments of tool-side launch scripts. */
    private const LAUNCH_SCRIPTS = ['/enrol/lti/launch.php', '/enrol/lti/launch_deeplink.php', '/enrol/lti/login.php',
        '/auth/lti/login.php'];
    /** @var string[] Script path fragments of platform-side service scripts. */
    private const SERVICE_SCRIPTS = ['/mod/lti/services.php', '/mod/lti/service.php', '/mod/lti/token.php'];

    /**
     * Classify a script name.
     *
     * @param string $scriptname Value of SCRIPT_NAME.
     * @param bool $cli True for the command line.
     * @param bool $ws True when running inside a web service server.
     * @return string One of the constants of this class.
     */
    public static function classify(string $scriptname, bool $cli = false, bool $ws = false): string {
        if ($cli) {
            return self::CLI;
        }
        if ($ws) {
            return self::WS;
        }
        foreach (self::LAUNCH_SCRIPTS as $fragment) {
            if (substr($scriptname, -strlen($fragment)) === $fragment) {
                return self::LTI_LAUNCH;
            }
        }
        foreach (self::SERVICE_SCRIPTS as $fragment) {
            if (substr($scriptname, -strlen($fragment)) === $fragment) {
                return self::LTI_SERVICES;
            }
        }
        return self::WEB;
    }

    /**
     * Classify the current request.
     *
     * @return string
     */
    public static function detect(): string {
        $script = isset($_SERVER['SCRIPT_NAME']) ? clean_param((string) $_SERVER['SCRIPT_NAME'], PARAM_PATH) : '';
        $cli = (defined('CLI_SCRIPT') && CLI_SCRIPT) && !(defined('PHPUNIT_TEST') && PHPUNIT_TEST && $script !== '');
        return self::classify($script, $cli, defined('WS_SERVER') && WS_SERVER);
    }
}
