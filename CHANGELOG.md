# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-06-13

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
  scopes, with on-demand per-scope utilization graphs.
- Compact DHCP scopes summary on the device Overview tab (counts, ≥80% / ≥95% tallies,
  busiest scopes) linking into the page filtered to that device.
- Server-level health as LibreNMS sensors (utilization, packet rates, scope/address counts,
  failover state, PSU reachability, uptime) surfaced on the built-in Health tab.
- Importable alert rules (`alert_rules/windows-dhcp-alert-rules.json`).
- Idempotent `install.sh` using the upgrade-safe `composer.plugins.json` flow (Packagist),
  with a `--dev-path` option for local installs.

[0.1.0]: https://github.com/averyhabbott/librenms-windows-dhcp/releases/tag/v0.1.0
