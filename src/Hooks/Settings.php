<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Hooks;

use App\Plugins\Hooks\SettingsHook;
use AveryAbbott\WindowsDhcp\Settings as SettingsStore;

/**
 * Plugin settings page (rendered by core at /plugin/settings/WindowsDhcp and
 * persisted to the plugins.settings JSON column). Exposes graph series choice,
 * stacked vs. lines, utilization thresholds, and the PSU HTTP timeout.
 */
class Settings extends SettingsHook
{
    /** Blade view, resolved as "WindowsDhcp::settings". */
    public string $view = 'settings';

    public function data(array $settings = []): array
    {
        // NOTE: core's SettingsHook::handle() invokes data() TWICE, nested —
        // it passes this method's own return value back in as $settings on the
        // outer call. Relying on the injected $settings therefore collapses to
        // defaults (the outer array has no graph_* keys). Read the persisted
        // settings straight from the DB instead: correct and idempotent.
        return [
            'settings' => SettingsStore::load(),
        ];
    }
}
