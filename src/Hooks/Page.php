<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Hooks;

use App\Models\Device;
use App\Plugins\Hooks\PageHook;
use AveryAbbott\WindowsDhcp\Models\DhcpScope;
use AveryAbbott\WindowsDhcp\Settings;
use AveryAbbott\WindowsDhcp\WindowsDhcpServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Full-page, server-side-paginated/searchable browser for all DHCP scopes
 * across every monitored DHCP server. Reachable at /plugin/WindowsDhcp via the
 * navbar "Plugins" submenu. Built to scale to thousands of scopes — the table
 * pages/sorts/searches on the server (bootgrid AJAX), never dumping every row.
 */
class Page extends PageHook
{
    /** Blade view, resolved as "WindowsDhcp::page". */
    public string $view = 'page';

    public function authorize(Authenticatable $user): bool
    {
        return $user->can('global-read');
    }

    public function data(array $settings = []): array
    {
        // The PluginManager injects the plugin's stored settings here.
        $settings = Settings::merge($settings);

        $request = request();
        $user = $request->user();

        // Devices that actually have scopes, restricted to what the user may see.
        $deviceIds = DhcpScope::query()
            ->when(Gate::denies('viewAll', Device::class), function ($q) use ($user): void {
                $q->whereIntegerInRaw('device_id', \Permissions::devicesForUser($user));
            })
            ->distinct()
            ->pluck('device_id');

        $devices = Device::whereIn('device_id', $deviceIds)
            ->orderBy('hostname')
            ->get(['device_id', 'hostname', 'sysName']);

        return [
            'title' => 'Windows DHCP Scopes',
            'devices' => $devices,
            'selectedDevice' => $request->integer('device') ?: null,
            'utilWarn' => $settings['util_warn'],
            'utilCrit' => $settings['util_crit'],
            'graphCacheKey' => Settings::graphCacheToken(),
            'version' => WindowsDhcpServiceProvider::version(),
        ];
    }
}
