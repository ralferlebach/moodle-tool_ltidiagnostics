# Load tests

Out of scope for the 0.1.0 MVP. The plugin is a read-only diagnostic tool for administrators; it adds no student-facing
endpoint and one small observer on a handful of LTI-related events (a single insert per matching event). A load test would
measure Moodle, not this plugin. The observer cost is bounded by the retention task (`tool_ltidiagnostics_cleanup`).
