<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp;

use App\Models\Plugin;

/**
 * Single source of truth for the plugin's settings (stored as JSON in the core
 * `plugins.settings` column via the official SettingsHook). Hooks receive the
 * raw stored array injected by the PluginManager; non-hook callers (the poll
 * command, the graph controllers) load it from the Plugin model. Either way,
 * run it through merge() to get a complete, type-coerced, validated array.
 */
class Settings
{
    /**
     * Canonical order of the user-selectable graph series. 'reservations' is a
     * meta-series: when selected it expands at draw time to either a single
     * total-reserved line or active/inactive lines, per graph_reservations_split.
     */
    public const DATASETS = ['inuse', 'free', 'pending', 'bad', 'reservations'];

    public const DEFAULTS = [
        // Basic graph out of the box: just the in-use/free pool. Pending, bad and
        // reservations are opt-in series the user enables on the settings page.
        'graph_datasets' => ['inuse', 'free'],
        'graph_stacked' => true,
        'graph_scale_to_size' => false,
        // Draw reservations as distinct active/inactive lines vs. a single total line.
        'graph_reservations_split' => false,
        'util_warn' => 80,
        'util_crit' => 95,
        'http_timeout' => 30,
        // Opt-in lease-enumeration scrapes (each adds server-side time on large
        // estates). Off by default -> cheapest poll. See windows-dhcp:poll.
        'monitor_reservation_states' => false,
        'monitor_declined' => false,
    ];

    /**
     * Cache-busting token for graph image URLs, mirroring how core LibreNMS keys
     * its port graphs (`graph.php?...&to=<time.now>`): a 5-minute time bucket
     * aligned to the poll interval, so the URL is stable within a bucket
     * (browser-cacheable) and changes once fresh data could exist. We also fold
     * in a short fingerprint of the render-affecting settings — which core has no
     * equivalent of — so toggling datasets / stacking / scaling busts the cache
     * immediately instead of after up to 5 minutes. GraphController ignores this
     * param; it exists purely to key the browser cache.
     */
    public static function graphCacheToken(): string
    {
        $s = self::load();
        $bucket = time() - (time() % 300);
        $fingerprint = substr(md5((string) json_encode([
            $s['graph_datasets'],
            $s['graph_stacked'],
            $s['graph_scale_to_size'],
            $s['graph_reservations_split'],
        ])), 0, 8);

        return $bucket . '-' . $fingerprint;
    }

    /**
     * Load and merge the stored settings for non-hook callers.
     */
    public static function load(): array
    {
        try {
            $stored = (array) Plugin::where('plugin_name', WindowsDhcpServiceProvider::PLUGIN_NAME)->value('settings');
        } catch (\Throwable $e) {
            $stored = [];
        }

        return self::merge($stored);
    }

    /**
     * Overlay stored settings on the defaults, coercing types and dropping
     * anything invalid so downstream code can trust the result.
     */
    public static function merge(array $stored): array
    {
        return [
            'graph_datasets' => self::normalizeDatasets($stored['graph_datasets'] ?? null),
            'graph_stacked' => (bool) ($stored['graph_stacked'] ?? self::DEFAULTS['graph_stacked']),
            'graph_scale_to_size' => (bool) ($stored['graph_scale_to_size'] ?? self::DEFAULTS['graph_scale_to_size']),
            'graph_reservations_split' => (bool) ($stored['graph_reservations_split'] ?? self::DEFAULTS['graph_reservations_split']),
            'util_warn' => self::percent($stored['util_warn'] ?? null, self::DEFAULTS['util_warn']),
            'util_crit' => self::percent($stored['util_crit'] ?? null, self::DEFAULTS['util_crit']),
            'http_timeout' => self::positiveInt($stored['http_timeout'] ?? null, self::DEFAULTS['http_timeout']),
            'monitor_reservation_states' => (bool) ($stored['monitor_reservation_states'] ?? self::DEFAULTS['monitor_reservation_states']),
            'monitor_declined' => (bool) ($stored['monitor_declined'] ?? self::DEFAULTS['monitor_declined']),
        ];
    }

    /**
     * Accepts either a list (["inuse","free"]) or the form's checkbox map
     * (["inuse" => "1", "free" => "0"]); returns known datasets in canonical
     * order. Falls back to all datasets when nothing valid is selected.
     */
    private static function normalizeDatasets(mixed $value): array
    {
        if (! is_array($value)) {
            return self::DEFAULTS['graph_datasets'];
        }

        $selected = [];
        foreach ($value as $key => $val) {
            if (is_int($key)) {
                $selected[] = $val;            // list form
            } elseif ((int) $val === 1) {
                $selected[] = $key;            // checkbox-map form
            }
        }

        $datasets = array_values(array_filter(
            self::DATASETS,
            fn (string $d): bool => in_array($d, $selected, true)
        ));

        return $datasets ?: self::DEFAULTS['graph_datasets'];
    }

    private static function percent(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $n = (int) $value;

        return ($n >= 0 && $n <= 100) ? $n : $default;
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $n = (int) $value;

        return $n > 0 ? $n : $default;
    }
}
