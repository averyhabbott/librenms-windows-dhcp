# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.2] - 2026-07-16

### Fixed

- **RRD writes were silently lost on scheduled polls** due to systemd `KillMode=control-group`
  killing the backgrounded poll process before RRD writes reached rrdcached. Removed
  `runInBackground()` from the scheduled event — the poll now runs synchronously in the
  foreground so RRD completion depends only on the poll itself, not on the process supervisor.
  Database writes (which happen in-process before RRD) continued to work, making the data loss
  invisible until graphing (RRD empty, database up-to-date). Affects all installations on stock
  `librenms-scheduler.service` with no manual `KillMode` override. No action needed beyond the
  upgrade: the installer is idempotent, and the fix ships in the code.
- **DHCP Server Uptime sensor stored raw seconds, not minutes** — the `runtime` sensor class
  is a minutes convention (confirmed by core's own graph template and discovery code), but the
  plugin passed raw seconds straight through without conversion. Every reading was ~60× too
  large, visible as impossible per-day increments on the graph (e.g. 85k "minutes" in 24 hours).
  Now converts to minutes at the sensor spec, matching core's own convention. Existing RRD
  history remains in the wrong scale; a visible 60× step-down at deploy time is expected and
  self-explanatory, not worth a backfill for an informational sensor.

## [0.1.1] - 2026-06-18

Production-readiness hardening.

### Added

- Per-device poll isolation: one server's failure (network, malformed payload, DB
  error) no longer aborts the rest of the batch.
- Per-device lock around polling so a manual `--device` run and the scheduled run
  can't poll the same server concurrently (avoids racing the cumulative counter
  attribute and the scope unique index).
- Transient-failure guard: when the PSU returns an empty `scopes` list but still
  reports `scopes_total > 0`, existing scope rows are kept instead of being pruned.
- A 2-poll count gate on the "PSU API unreachable" alert rule so a single transient
  poll failure doesn't flap (mirrors the existing "no ACKs" rule).
- `dhcp_psu_last_error` device attribute recording the last failure class (e.g.
  `http_401`) so an expired/invalid token is diagnosable, not masked as an outage.
- `schema_version` is now validated (supported: `1`) and logs a warning on mismatch.
- `--token-stdin` option and `DHCP_PSU_TOKEN` env var, plus an interactive hidden
  prompt, so the PSU token need not be passed on the command line.
- PHPUnit test suite: unit tests for settings validation and packet-rate math
  (standalone), and feature tests for the poll command (run within LibreNMS).
- Documented the expected `/metrics` response schema in the README.

### Changed

- Clamped caller-supplied graph `width`/`height` and the scopes-table `rowCount` to
  bound resource use from authenticated requests.
- Device-overview utilization tallies/bars now follow the configured warn/crit
  thresholds instead of hardcoded 80/95.

## [0.1.0] - 2026-06-16

Initial release.

### Added

- Scheduled `windows-dhcp:poll` Artisan command that collects DHCP data over the
  PowerShell Universal HTTPS API (`GET /api/dhcp/metrics`) — no SNMP. Targets only devices
  carrying a `dhcp_psu_url` attribute; runs every 5 minutes via the LibreNMS scheduler, or
  per poller group via cron (`--group`).
- `windows-dhcp:configure` command to set/clear per-device PSU attributes
  (`dhcp_psu_url`, `dhcp_psu_token`, `dhcp_psu_ca_cert`, `dhcp_psu_verify`), reusing the
  existing `DHCPReader` token.
- Per-scope data stored in a `dhcp_scopes` table (+ per-scope RRD), browsable on a dedicated
  server-side paginated/searchable **DHCP Scopes** plugin page that scales to thousands of
  scopes.
- **Per-scope graph detail page** (navbar Plugins → DHCP Scopes → a scope's graph button): a
  full-page view with a timeframe thumbnail strip (6h/24h/48h/1w/1m/1y) and a From/To custom
  range, opened in a new tab so the table's page/search/filter state is preserved.
- **Hover preview** — hovering a scope's graph button shows a 6-hour quick-look graph, edge-
  flipping near the screen edges (like core's port-graph popups).
- **Bad-address (conflict) tracking** — per-scope `bad_addresses` (column + RRD dataset, graphed
  alongside in-use/free/pending) and a server-level "DHCP Bad Addresses" count sensor. Opt-in
  (see **Monitor declined addresses** below); 0 when disabled.
- **Reservation states** — per-scope `addresses_reserved` (total, always collected) plus an opt-in
  active/inactive split (`reservations_active` / `reservations_inactive`), shown on the scope
  detail page and as default-hidden table columns. All three are also recorded to the per-scope RRD
  and selectable as graph series (see **Settings**).
- **True scope size** — `addresses_total` is now the scope's address range minus its exclusion
  ranges, instead of in-use + free. This is independent of lease churn and doesn't double-count
  active reservations (which already sit inside the in-use count).
- **Per-plugin Settings page** (`/plugin/settings/WindowsDhcp`, no core edits — uses the official
  `SettingsHook`): choose which series are graphed (in use / free / pending / bad / reservations —
  the basic graph defaults to just in use + free), draw reservations as one total line or distinct
  active/inactive lines, stacked-pool vs. separate lines, **scale graphs to the scope's size**
  (pin the y-axis to the scope's size),
  utilization warning/critical thresholds, the PSU HTTP timeout, and two opt-in collection toggles —
  **Monitor reservation states** (active/inactive split) and **Monitor declined addresses** (bad/
  conflict counts). Both are off by default because they enumerate leases on the DHCP server, which
  adds collection time on large estates. Thresholds drive the scopes-table bar colours, the menu
  critical badge, the device-Overview tallies, and the utilization sensor's alert limits from one place.
- Compact DHCP scopes summary on the device Overview tab (counts, warning / critical tallies,
  busiest scopes) linking into the page filtered to that device.
- Server-level health as LibreNMS sensors (utilization, packet rates, scope/address counts,
  failover state, PSU reachability, uptime) surfaced on the built-in Health tab.
- Importable alert rules (`alert_rules/windows-dhcp-alert-rules.json`).
- Idempotent `install.sh` using the upgrade-safe `composer.plugins.json` flow (Packagist),
  with a `--dev-path` option for local installs.

### Notes

- Graph images carry no cache headers (matching core LibreNMS); the cache is keyed in the URL
  via a `cb` token (a 5-minute time bucket plus a fingerprint of the graph settings), so graphs
  are browser-cacheable within a poll window but refetch immediately when a setting changes.

[Unreleased]: https://github.com/averyhabbott/librenms-windows-dhcp/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/averyhabbott/librenms-windows-dhcp/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/averyhabbott/librenms-windows-dhcp/releases/tag/v0.1.0
