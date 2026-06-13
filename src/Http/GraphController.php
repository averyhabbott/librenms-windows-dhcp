<?php

namespace AveryAbbott\WindowsDhcp\Http;

use App\Facades\Rrd;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use LibreNMS\Exceptions\RrdGraphException;
use AveryAbbott\WindowsDhcp\Models\DhcpScope;

/**
 * Renders a per-scope utilization graph (in-use / free / pending) straight from
 * the RRD written by windows-dhcp:poll. Self-contained — no core graph templates.
 */
class GraphController extends Controller
{
    public function __invoke(Request $request, int $scope)
    {
        $dhcpScope = DhcpScope::findOrFail($scope);
        $device = $dhcpScope->device;

        abort_unless($device && $request->user()?->can('view', $device), 403);

        $width = $request->integer('width', 1000);
        $height = $request->integer('height', 150);
        $from = (string) $request->get('from', '-1day');
        $to = (string) $request->get('to', 'now');

        $safe = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $dhcpScope->scope_id);
        $rrd = Rrd::name($device->hostname, ['dhcp-scope', $safe]);

        $options = [
            '--start', $from,
            '--end', $to,
            '--width', (string) $width,
            '--height', (string) $height,
            '--imgformat=PNG',
            '--lower-limit=0',
            '--title=' . $dhcpScope->scope_id . ' addresses',
            "DEF:inuse=$rrd:inuse:AVERAGE",
            "DEF:free=$rrd:free:AVERAGE",
            "DEF:pending=$rrd:pending:AVERAGE",
            'CDEF:total=inuse,free,+',
            'AREA:inuse#cc0000:In Use ',
            'GPRINT:inuse:LAST:Cur\: %6.0lf',
            'GPRINT:inuse:MAX:Max\: %6.0lf\n',
            'STACK:free#3da233:Free   ',
            'GPRINT:free:LAST:Cur\: %6.0lf',
            'GPRINT:free:MAX:Max\: %6.0lf\n',
            'LINE1:pending#0000ff:Pending',
            'GPRINT:pending:LAST:Cur\: %6.0lf',
            'GPRINT:pending:MAX:Max\: %6.0lf\n',
        ];

        try {
            $image = Rrd::graph($options);

            return response($image, 200, ['Content-Type' => 'image/png']);
        } catch (RrdGraphException $e) {
            return response($e->generateErrorImage(), 500, ['Content-Type' => 'image/png']);
        }
    }
}
