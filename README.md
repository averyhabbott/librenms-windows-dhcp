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
  (utilization, in-use/free/reserved/pending, state), with an **on-demand** history
  graph per scope (served by the package's own route — no core graph files). The device
  **Overview tab** carries a compact summary (scope counts, ≥80% / ≥95% tallies, busiest
  scopes) that links into the page filtered to that device.
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

## Install

On the **primary**:

```bash
sudo ./install.sh --primary
```

On each **distributed poller** (only if PSU endpoints are reachable only from that poller —
otherwise the primary's scheduler polls everything centrally):

```bash
sudo ./install.sh --group <poller_group_id>
```

The installer records the package in `composer.plugins.json` (git-ignored), runs composer,
and on `--primary` runs migrations and enables the plugin. Because LibreNMS's monthly
`daily.sh` re-requires everything in `composer.plugins.json` from Packagist, the plugin is
**zero-touch across upgrades** — no need to re-run the installer after each update.

Before the package is published to Packagist (or to install from a local checkout), point
composer at the working copy instead:

```bash
sudo ./install.sh --primary --dev-path "$PWD"
```

Optionally import the alert rules in `alert_rules/windows-dhcp-alert-rules.json` via
**Alerts → Alert Rules → Create → Import**.

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
# 2. Point it at PSU:
lnms windows-dhcp:configure dhcp01.example.com \
     --token='<DHCPReader App Token>' \
     [--url=https://dhcp01.example.com:443/api/dhcp] \
     [--ca-cert=/etc/ssl/psu-ca.pem] [--no-verify]
# 3. First poll:
lnms windows-dhcp:poll --device=dhcp01.example.com
```

Then open **Plugins → DHCP Scopes** (full scope browser), the device → **Overview**
(DHCP Scopes summary), and the device → **Health** (server sensors).

## Commands

| Command | Purpose |
|---------|---------|
| `lnms windows-dhcp:poll [--device=] [--group=]` | Poll DHCP servers and store data |
| `lnms windows-dhcp:configure <device> --token=…` | Set/clear PSU connection attributes |

## Alerts

Importable rules (`alert_rules/windows-dhcp-alert-rules.json`): scope utilization
warn ≥80% / crit ≥95%, "server not responding" (ACK rate 0), PSU API unreachable
(while ICMP up), and failover not normal.

## Notes / roadmap

- Per-scope **historical graphs** (in-use / free / pending) are recorded to RRD and shown
  on demand from the Scopes page (click a scope's graph button). Server-level trends graph
  natively on the Health tab.
- This is an early release (`v0.x`): pin it as `^0.1` so monthly upgrades pick up patch
  releases without jumping a (possibly breaking) `0.x` minor.
- IPv4 only (matches the PSU endpoint and environment).
- Live failover `state`/`in_sync` depends on what the DHCP server's cmdlets expose; the
  schema tolerates nulls.
