# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.1.0]: https://github.com/averyhabbott/librenms-windows-dhcp/releases/tag/v0.1.0
