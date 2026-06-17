<?php

namespace AveryAbbott\WindowsDhcp\Http;

use App\Http\Controllers\Controller;
use App\Models\Device;
use AveryAbbott\WindowsDhcp\Models\DhcpScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Server-side bootgrid endpoint backing the scopes browser page. Returns the
 * standard LibreNMS table JSON shape ({current, rowCount, rows, total}) so the
 * grid can paginate/sort/search thousands of scopes without loading them all.
 *
 * Hand-rolled rather than extending core's TableController to stay decoupled
 * from core internals across LibreNMS auto-upgrades.
 */
class ScopeTableController extends Controller
{
    /** Maps a bootgrid column id to a real, sortable DB column. */
    private array $sortable = [
        'hostname' => 'devices.hostname',
        'scope_id' => 'dhcp_scopes.scope_id',
        'name' => 'dhcp_scopes.name',
        'state' => 'dhcp_scopes.state',
        'addresses_in_use' => 'dhcp_scopes.addresses_in_use',
        'addresses_free' => 'dhcp_scopes.addresses_free',
        'addresses_reserved' => 'dhcp_scopes.addresses_reserved',
        'reservations_active' => 'dhcp_scopes.reservations_active',
        'reservations_inactive' => 'dhcp_scopes.reservations_inactive',
        'pending_offers' => 'dhcp_scopes.pending_offers',
        'bad_addresses' => 'dhcp_scopes.bad_addresses',
        'percent_in_use' => 'dhcp_scopes.percent_in_use',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = DhcpScope::query()
            ->join('devices', 'devices.device_id', '=', 'dhcp_scopes.device_id')
            ->when(Gate::denies('viewAll', Device::class), function ($q) use ($user): void {
                $q->whereIntegerInRaw('dhcp_scopes.device_id', \Permissions::devicesForUser($user));
            });

        if ($request->filled('device')) {
            $query->where('dhcp_scopes.device_id', $request->integer('device'));
        }

        $search = trim((string) $request->get('searchPhrase', ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like): void {
                $q->where('dhcp_scopes.scope_id', 'like', $like)
                    ->orWhere('dhcp_scopes.name', 'like', $like)
                    ->orWhere('devices.hostname', 'like', $like)
                    ->orWhere('devices.sysName', 'like', $like);
            });
        }

        $total = (clone $query)->count();

        $sort = (array) $request->get('sort', []);
        $sorted = false;
        foreach ($sort as $col => $dir) {
            if (isset($this->sortable[$col])) {
                $query->orderBy($this->sortable[$col], strtolower((string) $dir) === 'desc' ? 'desc' : 'asc');
                $sorted = true;
            }
        }
        if (! $sorted) {
            $query->orderBy('devices.hostname')->orderBy('dhcp_scopes.scope_id');
        }

        $rowCount = (int) $request->get('rowCount', 25);
        $current = max(1, (int) $request->get('current', 1));

        $query->select('dhcp_scopes.*', 'devices.hostname as hostname', 'devices.sysName as sysName');

        if ($rowCount > 0) {
            $query->forPage($current, $rowCount);
        }

        $rows = $query->get()->map(fn (DhcpScope $s): array => [
            'dhcp_scope_id' => (int) $s->dhcp_scope_id,
            'device_id' => (int) $s->device_id,
            'hostname' => $s->getAttribute('sysName') ?: $s->getAttribute('hostname'),
            'scope_id' => $s->scope_id,
            'name' => $s->name,
            'state' => $s->state,
            'addresses_in_use' => (int) $s->addresses_in_use,
            'addresses_free' => (int) $s->addresses_free,
            'addresses_reserved' => (int) $s->addresses_reserved,
            'reservations_active' => (int) $s->reservations_active,
            'reservations_inactive' => (int) $s->reservations_inactive,
            'pending_offers' => (int) $s->pending_offers,
            'bad_addresses' => (int) $s->bad_addresses,
            'percent_in_use' => (float) $s->percent_in_use,
        ]);

        return response()->json([
            'current' => $current,
            'rowCount' => $rowCount,
            'rows' => $rows,
            'total' => $total,
        ]);
    }
}
