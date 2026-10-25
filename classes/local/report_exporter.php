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
 * Serialises a report as JSON, Markdown or CSV.
 *
 * @package    tool_ltidiagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_exporter {
    /** @var string[] Supported formats. */
    public const FORMATS = ['json', 'md', 'csv'];

    /**
     * Export in a format.
     *
     * @param array $report Report from report::build().
     * @param string $format One of FORMATS.
     * @return string
     */
    public static function export(array $report, string $format): string {
        switch ($format) {
            case 'json':
                return self::to_json($report);
            case 'md':
                return self::to_markdown($report);
            case 'csv':
                return self::to_csv($report);
        }
        throw new \coding_exception('Unsupported export format');
    }

    /**
     * Media type and extension of a format.
     *
     * @param string $format Format.
     * @return string[] media type, extension
     */
    public static function file_info(string $format): array {
        $map = ['json' => ['application/json', 'json'], 'md' => ['text/markdown', 'md'], 'csv' => ['text/csv', 'csv']];
        return $map[$format];
    }

    /**
     * JSON.
     *
     * @param array $report Report.
     * @return string
     */
    public static function to_json(array $report): string {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        return json_encode($report, $flags);
    }

    /**
     * Markdown.
     *
     * @param array $report Report.
     * @return string
     */
    public static function to_markdown(array $report): string {
        $m = $report['meta'];
        $c = $report['connection'];
        $t = function (string $key, $a = null) {
            return get_string($key, 'tool_ltidiagnostics', $a);
        };
        $esc = function ($text) {
            return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], (string) $text);
        };
        $md = '# ' . $t('report_title') . "\n\n";
        $md .= '- ' . $t('report_generated') . ': ' . $m['generated'] . ' (' . $m['timezone'] . ")\n";
        $md .= '- ' . $t('report_version') . ': tool_ltidiagnostics ' . $m['pluginversion'];
        $md .= ', Moodle ' . $m['moodleversion'] . "\n";
        $md .= '- ' . $t('report_diagid') . ': ' . $m['diagnosticid'] . "\n";
        $md .= '- ' . $t('report_viewerrole') . ': ' . $esc($m['viewerrole']) . "\n";
        $md .= '- ' . $t('report_identifiers') . ': ' . ($m['identifiersrevealed'] ? $t('yes') : $t('masked')) . "\n";
        $md .= '- ' . $t('report_payloads') . ': ' . $t('report_payloadsexcluded') . "\n\n";
        $md .= '## ' . $t('report_connection') . "\n\n";
        $md .= '- ' . $t('role') . ': ' . $c['role'] . "\n- LTI: " . $esc($c['ltiversion']) . "\n- Issuer: " . $esc($c['issuer'])
            . "\n- Client ID: " . $esc($c['clientid']) . "\n- Deployment ID: " . $esc($c['deploymentid']) . "\n\n";
        $md .= '## ' . $t('report_layers') . "\n\n| " . $t('layer') . " | |\n|---|---|\n";
        foreach ($report['layers'] as $layer => $light) {
            $md .= '| ' . $t('layer_' . $layer) . ' | ' . $light . " |\n";
        }
        $md .= "\n## " . $t('report_matrix') . "\n\n";
        $md .= '| ' . $t('feature') . ' | ' . $t('status') . ' | ' . $t('reasoncol') . " |\n|---|---|---|\n";
        foreach ($report['matrix'] as $row) {
            $md .= '| ' . $esc($row['label']) . ' | ' . $t('status_' . $row['status']) . ' | ' . $esc($row['reason']) . " |\n";
        }
        $md .= "\n## " . $t('report_findings') . "\n\n";
        foreach ($report['findings'] as $f) {
            $md .= '### [' . $t('severity_' . $f['severity']) . '] ' . $esc($f['title']) . "\n\n";
            $md .= '- ' . $t('layer') . ': ' . $t('layer_' . $f['layer']) . ' (' . $f['testid'] . ")\n";
            $md .= '- ' . $t('responsible') . ': ' . $t('responsible_' . $f['responsible']) . "\n";
            $md .= '- ' . $t('detail') . ': ' . $esc($f['detail']) . "\n";
            $md .= '- ' . $t('intervention') . ': ' . $esc($f['fix']) . "\n";
            $md .= '- ' . $t('risk') . ': ' . $t('risk_' . $f['risk']) . "\n";
            $md .= '- ' . $t('verification') . ': ' . $esc($f['verify']) . "\n";
            $conf = $f['confidence'] !== '' ? ', ' . $f['confidence'] : '';
            $md .= '- ' . $t('evidence') . ': ' . $f['evidencekind'] . $conf . "\n\n";
        }
        if ($report['delta']) {
            $md .= '## ' . $t('report_delta') . "\n\n";
            $md .= '| ' . $t('feature') . ' | ' . $t('delta_local') . ' | ' . $t('delta_remote') . ' | ';
            $md .= $t('delta_authorised') . ' | ' . $t('delta_observed') . " |\n|---|---|---|---|---|\n";
            foreach ($report['delta'] as $d) {
                $md .= '| ' . $d['feature'] . ' | ' . $d['local'] . ' | ' . $d['remote'] . ' | ' . $d['authorised'] . ' | '
                    . $d['observed'] . ' (' . $d['observedkind'] . ") |\n";
            }
            $md .= "\n";
        }
        $md .= '## ' . $t('report_limits') . "\n\n";
        foreach ($report['limits'] as $limit) {
            $md .= '- ' . $esc($limit) . "\n";
        }
        return $md;
    }

    /**
     * CSV with the findings and the matrix.
     *
     * Cells that could be read as a spreadsheet formula are prefixed with an apostrophe.
     *
     * @param array $report Report.
     * @return string
     */
    public static function to_csv(array $report): string {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['section', 'id', 'layer_or_feature', 'severity_or_status', 'responsible', 'risk', 'title', 'detail',
            'intervention', 'evidencekind', 'confidence', 'checkedat', 'generated', 'timezone', 'version'], ',', '"', '\\');
        $meta = [$report['meta']['generated'], $report['meta']['timezone'], $report['meta']['pluginversion']];
        foreach ($report['matrix'] as $row) {
            fputcsv($fh, array_map([self::class, 'cell'], array_merge(['matrix', $row['feature'], $row['feature'], $row['status'],
                '', '', $row['label'], $row['reason'], $row['next'], '', '', ''], $meta)), ',', '"', '\\');
        }
        foreach ($report['findings'] as $f) {
            fputcsv($fh, array_map([self::class, 'cell'], array_merge(['finding', $f['testid'], $f['layer'], $f['severity'],
                $f['responsible'], $f['risk'], $f['title'], $f['detail'], $f['fix'], $f['evidencekind'], $f['confidence'],
                $f['checkedat']], $meta)), ',', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * @param mixed $value Cell value.
     * @return string
     */
    public static function cell($value): string {
        $value = (string) $value;
        if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
            return "'" . $value;
        }
        return $value;
    }
}
