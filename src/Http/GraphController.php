<?php

namespace AveryAbbott\WindowsDhcp\Http;

use App\Facades\Rrd;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use LibreNMS\Exceptions\RrdGraphException;
use AveryAbbott\WindowsDhcp\Models\DhcpScope;
use AveryAbbott\WindowsDhcp\Settings;

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

        $settings = Settings::load();
        $datasets = $settings['graph_datasets'];   // subset of DATASETS, canonical order
        $stacked = $settings['graph_stacked'];
        $split = $settings['graph_reservations_split'];

        // pool=true series can be stacked into a filled area (they sum to the pool);
        // every other series is a line (not part of the in-use/free total). Keyed by
        // the concrete RRD dataset name. Reservations are lines too — active
        // reservations already sit inside in-use, so they're never part of the pool.
        $meta = [
            'inuse' => ['color' => '#cc0000', 'label' => 'In Use      ', 'pool' => true, 'line' => '1'],
            'free' => ['color' => '#3da233', 'label' => 'Free        ', 'pool' => true, 'line' => '1'],
            'pending' => ['color' => '#0000ff', 'label' => 'Pending     ', 'pool' => false, 'line' => '1'],
            'bad' => ['color' => '#ff8c00', 'label' => 'Bad         ', 'pool' => false, 'line' => '2'],
            'reserved' => ['color' => '#9933cc', 'label' => 'Reserved    ', 'pool' => false, 'line' => '1'],
            'resactive' => ['color' => '#00aaaa', 'label' => 'Res Active  ', 'pool' => false, 'line' => '1'],
            'resinactive' => ['color' => '#888888', 'label' => 'Res Inactive', 'pool' => false, 'line' => '1'],
        ];

        // Expand the selected series into the concrete RRD datasets to draw. The
        // 'reservations' meta-series becomes either a single total-reserved line or
        // an active+inactive pair. Off => nothing reservation-related is drawn,
        // regardless of the split toggle.
        $draw = [];
        foreach ($datasets as $ds) {
            if ($ds === 'reservations') {
                array_push($draw, ...($split ? ['resactive', 'resinactive'] : ['reserved']));
            } else {
                $draw[] = $ds;
            }
        }

        $options = [
            '--start', $from,
            '--end', $to,
            '--width', (string) $width,
            '--height', (string) $height,
            '--imgformat=PNG',
            '--lower-limit=0',
            '--title=' . $dhcpScope->scope_id . ' addresses',
        ];

        // Optionally pin the y-axis to the scope's full address pool so the graph
        // shows fullness to scale (and is comparable across scopes) instead of
        // auto-zooming to the data range. Skip when the total is unknown (0) —
        // a rigid 0..0 axis would render an empty/broken graph.
        if ($settings['graph_scale_to_size'] && $dhcpScope->addresses_total > 0) {
            $options[] = '--upper-limit=' . (int) $dhcpScope->addresses_total;
            $options[] = '--rigid';
        }

        foreach ($draw as $ds) {
            $options[] = "DEF:$ds=$rrd:$ds:AVERAGE";
        }

        $poolStarted = false;
        foreach ($draw as $ds) {
            $m = $meta[$ds];
            if ($stacked && $m['pool']) {
                $options[] = ($poolStarted ? 'STACK:' : 'AREA:') . "{$ds}{$m['color']}:{$m['label']}";
                $poolStarted = true;
            } else {
                $options[] = 'LINE' . $m['line'] . ":{$ds}{$m['color']}:{$m['label']}";
            }
            $options[] = "GPRINT:$ds:LAST:Cur\\: %6.0lf";
            $options[] = "GPRINT:$ds:MAX:Max\\: %6.0lf\\n";
        }

        try {
            $image = Rrd::graph($options);

            // No explicit cache headers — same as core LibreNMS graphs (graph.inc.php
            // sets only Content-type). Caching is keyed in the URL instead: callers
            // append a `cb` token (5-minute time bucket + a settings fingerprint, see
            // Settings::graphCacheToken) so the browser reuses the PNG within a poll
            // window but refetches the instant settings change or new data lands.
            return response($image, 200, [
                'Content-Type' => 'image/png',
            ]);
        } catch (RrdGraphException $e) {
            return response($e->generateErrorImage(), 500, ['Content-Type' => 'image/png']);
        }
    }
}
