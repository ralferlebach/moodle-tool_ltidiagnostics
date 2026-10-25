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
 * Detects which LTI features this Moodle installation implements, from its own code base.
 *
 * Presence checks use the component API and fixed strings in the shipped source, never the
 * Moodle version number. A feature is reported as not implemented only if its marker is really
 * absent, so the verdict is evidence for the installed version, not an assumption.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class implementation_detector {
    /** @var array|null Cached result. */
    private static $cache = null;

    /**
     * Detect implementation per role and feature.
     *
     * @return array [role => [feature => bool]]
     */
    public static function detect(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $dir = function (string $component): ?string {
            return \core_component::get_component_directory($component);
        };
        $has = function (string $component, string $file) use ($dir): bool {
            $d = $dir($component);
            return $d !== null && file_exists($d . '/' . $file);
        };
        $modlti = $dir('mod_lti');
        $platform = [
            'lti_core' => $modlti !== null,
            'ags' => $dir('ltiservice_gradebookservices') !== null,
            'nrps' => $dir('ltiservice_memberships') !== null,
            'deeplinking' => $has('mod_lti', 'contentitem.php'),
            'dynreg' => $has('mod_lti', 'openid-registration.php'),
            'submission_review' => self::source_contains($modlti, ['locallib.php'], ['LtiSubmissionReviewRequest']),
            'platform_storage' => self::source_contains(
                $modlti,
                ['locallib.php', 'launch.php', 'amd/src', 'classes'],
                ['lti.get_data', 'lti.put_data', 'lti.capabilities']
            ),
            'course_group' => self::source_contains($modlti, ['locallib.php', 'service', 'classes'], ['lti-gs/scope']),
        ];
        $enrol = $dir('enrol_lti');
        $tool = [
            'lti_core' => $enrol !== null && $dir('auth_lti') !== null,
            'ags' => class_exists('\enrol_lti\task\sync_grades'),
            'nrps' => class_exists('\enrol_lti\task\sync_members'),
            'deeplinking' => $has('enrol_lti', 'launch_deeplink.php'),
            'dynreg' => $has('enrol_lti', 'register.php'),
            'submission_review' => false,
            'platform_storage' => self::source_contains(
                $enrol,
                ['classes', 'launch.php', 'amd/src'],
                ['lti.get_data', 'lti.put_data']
            ),
            'course_group' => self::source_contains($enrol, ['classes'], ['lti-gs/scope']),
        ];
        self::$cache = [connection::ROLE_PLATFORM => $platform, connection::ROLE_TOOL => $tool];
        return self::$cache;
    }

    /**
     * Forget cached results (tests).
     */
    public static function reset(): void {
        self::$cache = null;
    }

    /**
     * Whether any of the needles occurs in PHP or JavaScript files below the given paths.
     *
     * @param string|null $base Component directory.
     * @param string[] $paths Files or directories relative to the base.
     * @param string[] $needles Fixed strings.
     * @return bool
     */
    public static function source_contains(?string $base, array $paths, array $needles): bool {
        if ($base === null) {
            return false;
        }
        foreach ($paths as $rel) {
            $path = $base . '/' . $rel;
            if (is_file($path)) {
                if (self::file_contains($path, $needles)) {
                    return true;
                }
            } else if (is_dir($path)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    $name = $file->getPathname();
                    if (
                        preg_match('/\.(php|js)$/', $name) && strpos($name, '/tests/') === false
                            && strpos($name, '/build/') === false && self::file_contains($name, $needles)
                    ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Whether a file contains one of the fixed strings.
     *
     * @param string $file File path.
     * @param string[] $needles Fixed strings.
     * @return bool
     */
    private static function file_contains(string $file, array $needles): bool {
        if (filesize($file) > 2097152) {
            return false;
        }
        $content = (string) file_get_contents($file);
        foreach ($needles as $needle) {
            if (strpos($content, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
