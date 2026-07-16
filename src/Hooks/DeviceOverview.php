<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Hooks;

use App\Models\Device;
use App\Plugins\Hooks\DeviceOverviewHook;
use Illuminate\Contracts\Auth\Authenticatable;
use AveryAbbott\WindowsDhcp\Models\DhcpScope;
use AveryAbbott\WindowsDhcp\Settings;

/**
 * Adds a compact "DHCP Scopes" summary panel to the device Overview tab, shown
 * only on devices that have DHCP scope data: scope counts, warn/crit tallies,
 * and the few busiest scopes, with a link into the full paginated browser
 * (filtered to this device). Server-level health lives on the Health tab via
 * sensors; the full per-scope list lives on the WindowsDhcp plugin page — the
 * Overview never tries to render hundreds of rows.
 */
class DeviceOverview extends DeviceOverviewHook
{
    /** Blade view (resolved as "{PluginName}::device-overview"). */
    public string $view = 'device-overview';

    public function authorize(Authenticatable $user, Device $device): bool
    {
        return DhcpScope::where('device_id', $device->device_id)->exists();
    }

    public function data(Device $device, array $settings = []): array
    {
        $settings = Settings::merge($settings);
        $warn = $settings['util_warn'];
        $crit = $settings['util_crit'];

        $base = DhcpScope::where('device_id', $device->device_id);

        return [
            'title' => 'DHCP Scopes',
            'device' => $device,
            'utilWarn' => $warn,
            'utilCrit' => $crit,
            'total' => (clone $base)->count(),
            'warning' => (clone $base)->where('percent_in_use', '>=', $warn)->where('percent_in_use', '<', $crit)->count(),
            'critical' => (clone $base)->where('percent_in_use', '>=', $crit)->count(),
            'topScopes' => (clone $base)->orderByDesc('percent_in_use')->limit(5)->get(),
            'lastPollAttempt' => $device->getAttrib('dhcp_psu_last_poll_attempt'),
            'lastPollSuccess' => $device->getAttrib('dhcp_psu_last_poll_success'),
            'schemaMismatch' => $device->getAttrib('dhcp_psu_schema_mismatch'),
            'lastError' => $device->getAttrib('dhcp_psu_last_error'),
        ];
    }
}
