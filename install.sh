#!/usr/bin/env bash
#
# Idempotent installer for the LibreNMS Windows DHCP plugin package.
#
# Zero-touch design: the package is published on Packagist, and the persistent
# install list lives in LibreNMS's git-ignored `composer.plugins.json`. The
# monthly `daily.sh` auto-upgrade resets composer.json/.lock but then re-requires
# everything in composer.plugins.json from Packagist — so once installed, the
# plugin survives every upgrade with no further action.
#
# Run on EVERY LibreNMS host (primary + distributed pollers). Safe to re-run.
#
#   sudo ./install.sh --primary                 # on the primary (does DB work)
#   sudo ./install.sh                           # on a poller (files only)
#   sudo ./install.sh --dev-path "$PWD"         # install from a local checkout
#                                               #   (testing, before Packagist publish)
#
# Options:
#   --librenms DIR    LibreNMS install dir   (default: /opt/librenms or $LIBRENMS_DIR)
#   --user USER       LibreNMS unix user     (default: librenms or $LIBRENMS_USER)
#   --primary         Run migrations + enable the plugin (run once, on the primary)
#   --constraint C    composer version constraint (default: ^0.1)
#   --dev-path PATH   Resolve from a local path repo instead of Packagist (dev/testing)
#
set -euo pipefail

LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
PRIMARY=0
DEV_PATH=""
CONSTRAINT="^0.1"
PKG_NAME="averyhabbott/librenms-windows-dhcp"
PLUGIN_NAME="WindowsDhcp"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --librenms)   LIBRENMS_DIR="$2"; shift 2 ;;
        --user)       LIBRENMS_USER="$2"; shift 2 ;;
        --primary)    PRIMARY=1; shift ;;
        --constraint) CONSTRAINT="$2"; shift 2 ;;
        --dev-path)   DEV_PATH="$2"; shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

# Run a command as the LibreNMS user, preserving FORCE=1 so the LibreNMS composer
# wrapper allows require/update (it blocks them otherwise).
run_as()       { sudo -u "$LIBRENMS_USER" "$@"; }
run_as_force() { sudo -u "$LIBRENMS_USER" env FORCE=1 "$@"; }

COMPOSER="$LIBRENMS_DIR/scripts/composer_wrapper.php"
PLUGINS_JSON="$LIBRENMS_DIR/composer.plugins.json"

[[ -d "$LIBRENMS_DIR" ]]      || { echo "LibreNMS dir not found: $LIBRENMS_DIR" >&2; exit 1; }
[[ -f "$LIBRENMS_DIR/lnms" ]] || { echo "Not a LibreNMS install (no lnms): $LIBRENMS_DIR" >&2; exit 1; }
[[ -f "$COMPOSER" ]]          || { echo "composer wrapper missing: $COMPOSER" >&2; exit 1; }

# In dev-path mode, point the constraint at the path repo's dev version.
if [[ -n "$DEV_PATH" ]]; then
    [[ -d "$DEV_PATH" ]] || { echo "--dev-path not a directory: $DEV_PATH" >&2; exit 1; }
    DEV_PATH="$(cd "$DEV_PATH" && pwd)"
    CONSTRAINT="*@dev"
fi

echo ">> LibreNMS: $LIBRENMS_DIR (user: $LIBRENMS_USER)"
echo ">> Package:  $PKG_NAME:$CONSTRAINT${DEV_PATH:+  (path repo: $DEV_PATH)}"

# 1. Record the package in composer.plugins.json (git-ignored => survives upgrades).
#    daily.php reads only the `require` map from this file when re-installing plugins.
echo ">> Recording package in composer.plugins.json"
php -r '
$f = $argv[1]; $pkg = $argv[2]; $constraint = $argv[3];
$data = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
$req = $data["require"] ?? [];
$req[$pkg] = $constraint;
$data["require"] = $req;
file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "$PLUGINS_JSON" "$PKG_NAME" "$CONSTRAINT"
chown "$LIBRENMS_USER":"$LIBRENMS_USER" "$PLUGINS_JSON" 2>/dev/null || true

# 2. (dev-path only) register the local checkout as a composer path repository.
if [[ -n "$DEV_PATH" ]]; then
    echo ">> Registering local path repository (dev mode)"
    run_as_force php "$COMPOSER" config "repositories.windows-dhcp-dev" \
        "{\"type\":\"path\",\"url\":\"$DEV_PATH\",\"options\":{\"symlink\":true}}" --no-interaction
fi

# 3. Install/refresh the package via the LibreNMS composer wrapper.
#    `require` uses --update-no-dev (composer's require has no --no-dev flag).
echo ">> Installing package with composer"
run_as_force php "$COMPOSER" require "$PKG_NAME:$CONSTRAINT" --update-no-dev --no-interaction

# 3b. Clear caches so the plugin's freshly registered routes/views load (a stale
#     cached route file otherwise hides them until the next restart). This mirrors
#     daily.sh, which runs `optimize:clear` before re-requiring plugins on upgrade.
echo ">> Clearing caches (route/view/config)"
run_as "$LIBRENMS_DIR/lnms" optimize:clear || true

# 4. Primary-only: migrate + enable the plugin (shared DB; do this once).
if [[ "$PRIMARY" == "1" ]]; then
    echo ">> Running migrations"
    run_as "$LIBRENMS_DIR/lnms" migrate --force
    echo ">> Enabling plugin"
    run_as "$LIBRENMS_DIR/lnms" plugin:enable "$PLUGIN_NAME" || true
    echo ">> (Optional) import alert rules via the web UI:"
    echo "     Alerts > Alert Rules > Create > Import"
    echo "     alert_rules/windows-dhcp-alert-rules.json"
fi

echo ">> Done."
echo "   Add a DHCP server (ping-only / SNMP-disabled) in LibreNMS, then:"
echo "     $LIBRENMS_DIR/lnms windows-dhcp:configure <hostname> --token=<DHCPReader token>"
echo "     $LIBRENMS_DIR/lnms windows-dhcp:poll --device=<hostname>"
echo "   Collection runs via the LibreNMS scheduler at its configured polling interval."
echo "   Distributed pollers auto-detect their assigned groups via distributed_poller_group."
