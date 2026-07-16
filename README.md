# LibreNMS Windows DHCP Monitoring

A LibreNMS **plugin package** that monitors Windows DHCP server and scope health
**without SNMP**, by polling the [PowerShell Universal](https://powershelluniversal.com/)
HTTPS API that already runs on each DHCP server (deployed by the companion
[`netbox-windows-dhcp`](https://github.com/averyhabbott/netbox-windows-dhcp) plugin).

It follows the LibreNMS plugin guidelines
([docs](https://docs.librenms.org/Extensions/Plugin-System/)): it is a composer
package (the only plugin type that may publish migrations, commands and views),
installed via the upgrade-safe `composer.plugins.json`, and **touches zero core
LibreNMS files** — so the monthly auto-upgrade can't clobber it.

## What it collects

Per poll (every 5 min) it calls `GET /api/dhcp/metrics` on each DHCP server and stores:

- **Per-scope** → `dhcp_scopes` table + per-scope RRD, browsable on a dedicated
  **"DHCP Scopes" plugin page** (navbar → **Plugins → DHCP Scopes**): a server-side
  paginated/searchable table that scales to thousands of scopes across all servers
  (utilization, in-use/free/reserved/pending, total size, state — plus opt-in
  active/inactive **reservation states** and **bad/conflict addresses**). The total is the
  scope's address range minus exclusions, so it doesn't move with lease churn. Each
  scope has a **graph detail page** (timeframe thumbnail strip + custom From/To range) and
  a **6-hour hover preview** on its graph button — all served by the package's own route,
  no core graph files. The device **Overview tab** carries a compact summary (scope counts,
  warning / critical tallies, busiest scopes) that links into the page filtered to that device.
- **Server-level** → LibreNMS **sensors**, so they appear on the built-in **Health tab**
  with graphs + thresholds + native alerting:
  - `percent` — overall address utilization
  - `count` — packet rates (discovers/offers/requests/acks/nacks/declines/releases per sec),
    addresses in use, scope counts, failover state (1 normal / 0 not), PSU API reachable (1/0)
  - `runtime` — server uptime

Server packet counters are cumulative; the poller derives per-second rates and stashes the
last sample in a device attribute.

## Architecture

```text
PowerShell Universal on each DHCP server          LibreNMS (this package)
  GET /api/dhcp/metrics  ◄────HTTPS + Bearer──────  windows-dhcp:poll (scheduled)
  (generic JSON; from the                            ├─ dhcp_scopes rows + RRD → Scopes page + Overview summary
   netbox-windows-dhcp repo)                         └─ sensors + RRD → Health tab + alerts
```

- Collection is a **scheduled Artisan command**, not a core poller module
  (`LibreNMS\Modules\` is not plugin-extensible). It runs via the LibreNMS scheduler,
  or per-poller via cron (`--group`) for network-segmented distributed setups.
- Devices are added **ping-only / SNMP-disabled**; ICMP drives up/down. Only devices
  carrying the `dhcp_psu_url` attribute are ever contacted — nothing else is probed.
- Credentials live in **per-device attributes** (`dhcp_psu_url`, `dhcp_psu_token`,
  `dhcp_psu_ca_cert`, `dhcp_psu_verify`), reusing the existing PSU `DHCPReader` token.

## Requirements

- **LibreNMS** on a recent release (developed and tested against **26.5.x**).
- **PHP 8.2+** (matches LibreNMS 26.x).
- The companion **`netbox-windows-dhcp` PSU endpoint ≥ v1.1.0** deployed on each DHCP server
  (provides `GET /api/dhcp/metrics`), reachable by a `DHCPReader` App Token.
- **IPv4 only** (matches the PSU endpoint and environment).

## Prerequisite: PSU endpoint

The companion `/api/dhcp/metrics` endpoint must be deployed on each DHCP server. It lives
in the `netbox-windows-dhcp` repo (`netbox_windows_dhcp/psu/dhcp_api_endpoints.ps1`,
SECTION 0) and is pushed by that plugin's "Update PSU Scripts" action. It is reachable by
the existing `DHCPReader` App Token.

### Expected `/metrics` response (schema_version 1)

This plugin is written against **`schema_version: 1`**. A response whose `schema_version`
differs is still parsed best-effort (every field is defensively coerced), but the poll logs
a warning — update the plugin or the PSU script when you see it. The shape consumed:

```jsonc
{
  "schema_version": 1,
  "generated_at": "2026-06-18T00:00:00Z",
  "server": {                       // null/absent tolerated — server sensors are skipped
    "uptime_seconds": 123456,
    "scopes_total": 12,             // used to detect a transient empty-scopes response
    "scopes_active": 11,
    "addresses_in_use": 4096,
    "percent_in_use": 63.5,
    "packets": { "discovers": 0, "offers": 0, "requests": 0,
                 "acks": 0, "nacks": 0, "declines": 0, "releases": 0 }  // cumulative
  },
  "scopes": [                       // each entry must be an object; non-objects are skipped
    { "scope_id": "10.0.1.0", "name": "A", "state": "active",
      "addresses_total": 200, "addresses_in_use": 120, "addresses_free": 80,
      "addresses_reserved": 5, "reservations_active": 0, "reservations_inactive": 0,
      "pending_offers": 0, "bad_address_count": 0, "percent_in_use": 60.0 }
  ],
  "failover": [                     // optional
    { "name": "FO-A", "mode": "LoadBalance", "state": "normal" }
  ]
}
```

Resilience contract: if `scopes` is empty **but** `server.scopes_total > 0`, the poller
treats it as a transient server-side enumeration failure and **keeps** the existing scope
rows rather than deleting them. Counters under `server.packets` are cumulative; the poller
derives per-second rates. The full field reference lives in the PSU repo's
[`psu/README.md`](https://github.com/averyhabbott/netbox-windows-dhcp/blob/main/psu/README.md)
("Response Shapes").

## Install

On the **primary**:

```bash
sudo ./install.sh --primary
```

On each **distributed poller** (only if PSU endpoints are reachable only from that poller —
otherwise the primary's scheduler polls everything centrally):

```bash
sudo ./install.sh
```

Then, on the primary, configure the scheduler to route polls by group:

```bash
lnms config:set distributed_poller_group "0,1,2"
```

The installer records the package in `composer.plugins.json` (git-ignored), runs composer,
and on `--primary` runs migrations and enables the plugin. Because LibreNMS's monthly
`daily.sh` re-requires everything in `composer.plugins.json` from Packagist, the plugin is
**zero-touch across upgrades** — no need to re-run the installer after each update.

Optionally import the alert rules in `alert_rules/windows-dhcp-alert-rules.json` via
**Alerts → Alert Rules → Create → Import**.

## Upgrade

The installer is idempotent, so upgrading is as simple as downloading the latest version and re-running the install script.

## Uninstall

```bash
# 1. Disable the plugin (run once, on the primary).
lnms plugin:disable WindowsDhcp

# 2. Remove the require entry from /opt/librenms/composer.plugins.json, then refresh composer
#    so daily.sh stops reinstalling it. On every host:
FORCE=1 php /opt/librenms/scripts/composer_wrapper.php remove averyhabbott/librenms-windows-dhcp

# 3. (optional) drop collected data — the dhcp_scopes table persists otherwise:
#    mysql> DROP TABLE dhcp_scopes;
```

Per-device PSU attributes can be cleared with `lnms windows-dhcp:configure <device> --clear`.

## Add a device

```bash
# 1. Add the DHCP server to LibreNMS as ping-only (no SNMP) via the UI or API.
# 2. Point it at PSU. Avoid passing the token on the command line (shell history /
#    process list) — pipe it on STDIN, or export DHCP_PSU_TOKEN, or omit it to be
#    prompted. --token still works for automation but warns.
printf '%s' '<DHCPReader App Token>' | lnms windows-dhcp:configure dhcp01.example.com --token-stdin \
     [--url=https://dhcp01.example.com:443/api/dhcp] \
     [--ca-cert=/etc/ssl/psu-ca.pem] [--no-verify]
# 3. First poll:
lnms windows-dhcp:poll --device=dhcp01.example.com
```

Then open **Plugins → DHCP Scopes** (full scope browser), the device → **Overview**
(DHCP Scopes summary), and the device → **Health** (server sensors).

## Commands

| Command                                             | Purpose                             |
| --------------------------------------------------- | ----------------------------------- |
| `lnms windows-dhcp:poll [--device=] [--group=]`   | Poll DHCP servers and store data    |
| `lnms windows-dhcp:configure <device> --token=…` | Set/clear PSU connection attributes |

## Settings

The plugin has a settings page at **Plugins → (gear) → WindowsDhcp**
(`/plugin/settings/WindowsDhcp`) — standard LibreNMS plugin settings, no core edits:

| Setting                    | Default           | Effect                                                                                                                                                                                                   |
| -------------------------- | ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Graphed series             | in use / free     | Which series to draw: in use / free / pending / bad / reservations. Pending, bad and reservations are off by default.                                                                                    |
| Graphing reservations      | off (single line) | When "Reservations" is graphed: off draws one total-reserved line; on draws separate active and inactive lines (which read 0 unless "Monitor reservation states" is on).                                 |
| Stack series               | on                | Stack in-use + free as a filled pool vs. drawing every series as a line.                                                                                                                                 |
| Scale to scope size        | off               | Pin the graph y-axis to the scope's size (its total address count) so fullness is shown to scale and graphs are comparable across scopes; off = auto-scale to the data.                                  |
| Warning (%) / Critical (%) | 80 / 95           | Utilization thresholds driving the scopes-table bar colours, the menu critical badge, the device-Overview tallies, and the utilization sensor's alert limits.                                            |
| PSU HTTP timeout (s)       | 30                | Request timeout for the PSU`/metrics` call. Raise it when the opt-in scrapes below are enabled on large estates — they enumerate leases per scope and can exceed the default, failing the whole poll. |
| Monitor reservation states | off               | Split the reserved count into active/inactive. The total reserved count is always collected for free; the split enumerates leases on scopes that have reservations, adding server-side time.             |
| Monitor declined addresses | off               | Count bad/declined (conflict) addresses per scope. The heaviest scrape (scans every scope), so it adds the most collection time on estates with many scopes.                                             |

## Alerts

Importable rules (`alert_rules/windows-dhcp-alert-rules.json`): scope utilization
warn ≥80% / crit ≥95% (defaults; tune via the settings above), "server not responding"
(ACK rate 0), PSU API unreachable (while ICMP up), and failover not normal.

## Notes / roadmap

- Per-scope **historical graphs** (in-use / free / pending / bad) are recorded to RRD and
  shown from the Scopes page — a 6-hour hover preview on the graph button, or its full detail
  page (timeframe strip + custom range). Series, stacking and y-axis scaling are configurable
  (see **Settings**). Server-level trends graph natively on the Health tab.
- This is an early release (`v0.x`): pin it as `^0.1` so monthly upgrades pick up patch
  releases without jumping a (possibly breaking) `0.x` minor.
- IPv4 only (matches the PSU endpoint and environment).
- Live failover `state`/`in_sync` depends on what the DHCP server's cmdlets expose; the
  schema tolerates nulls.
