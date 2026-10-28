# Naming (provisional)

## Rules checked

* Frankenstyle: type prefix + `_` + name; name lowercase letters and digits only, starting with a letter, no underscore.
* The DB table prefix is the full component name plus `_` (`tool_ltidiagnostics_`, 20 characters). An XMLDB table name
  may have at most `xmldb_table::NAME_MAX_LENGTH = 63 - 10 = 53` characters in 4.5 and 5.3 (the old 28-character limit
  no longer applies); the short suffixes keep every name within the historical 28 anyway. The tables are `obs`,
  `audit`, `snap`, `imp`, `acc`, `profile` (the longest, `tool_ltidiagnostics_profile`, has 27 characters).
* The type must fit: admin tools live under `admin/tool/<name>` and show up in *Plugins > Admin tools*.

## Alternatives

| # | Component | Prefix length | Pros | Cons |
|---|-----------|---------------|------|------|
| 1 | `tool_ltidiagnostics` | 20 | Describes the purpose, matches the family names (`lti...`), `tool` is the right type for an administrator utility | Longest prefix; free table part is 8 characters |
| 2 | `report_ltidiagnostics` | 22 | A `report_` plugin is listed in course and system reports | Only 6 characters for table names (too tight for `profile`); reports are expected to be pure views, but this plugin has settings, a task and import pages |
| 3 | `local_ltidiagnostics` | 21 | Free layout, no type restrictions | `local` has no standard place in the admin tree; hides an admin tool among customisations |
| 4 | `tool_ltihealth` | 15 | Short; 13 free table characters | "health" suggests monitoring, but the plugin is mainly forensic and evidence based |
| 5 | `tool_ltitrace` | 14 | Short, evokes the timeline | Narrower than the capability matrix and health check |

## Recommendation

Keep **`tool_ltidiagnostics`** (1): correct type, clearest name, consistent with the family, and short table names (within the historical 28-character limit)
is met by the chosen table names. If more tables are needed, short names (up to 8 characters) must be used.
