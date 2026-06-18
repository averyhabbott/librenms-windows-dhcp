<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Hooks;

use AveryAbbott\WindowsDhcp\Models\DhcpScope;
use AveryAbbott\WindowsDhcp\Settings;
use App\Plugins\Hooks\MenuEntryHook;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Adds a "DHCP Scopes" entry to the navbar "Plugins" submenu, badged with the
 * number of scopes at/over the configured critical utilization threshold so the
 * alarm count is visible from any page.
 */
class Menu extends MenuEntryHook
{
    /** Blade view, resolved as "WindowsDhcp::menu". */
    public string $view = 'menu';

    public function authorize(Authenticatable $user): bool
    {
        return $user->can('global-read');
    }

    public function data(array $settings = []): array
    {
        $critThreshold = Settings::merge($settings)['util_crit'];

        return [
            'critical' => DhcpScope::where('percent_in_use', '>=', $critThreshold)->count(),
        ];
    }
}
