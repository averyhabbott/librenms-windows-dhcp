<?php

namespace AveryAbbott\WindowsDhcp\Http;

use App\Http\Controllers\Controller;
use AveryAbbott\WindowsDhcp\Models\DhcpScope;
use AveryAbbott\WindowsDhcp\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Full-page per-scope detail view: a native-feeling graph page (timeframe
 * thumbnail strip + From/To range + large graph) rendered inside the LibreNMS
 * layout. All graph images are served by GraphController, so there is no graph
 * rendering here — this only gates access and hands the view what it needs.
 */
class ScopeDetailController extends Controller
{
    public function __invoke(Request $request, int $scope): View
    {
        $dhcpScope = DhcpScope::with('device')->findOrFail($scope);
        $device = $dhcpScope->device;

        abort_unless($device && $request->user()?->can('view', $device), 403);

        return view('WindowsDhcp::scope', [
            'scope' => $dhcpScope,
            'device' => $device,
            'graphCacheKey' => Settings::graphCacheToken(),
            // rrdtool relative-time specs, mirroring core's port-graph timeframe strip.
            'ranges' => [
                ['label' => '6 Hours', 'from' => '-6hour'],
                ['label' => '24 Hours', 'from' => '-1day'],
                ['label' => '48 Hours', 'from' => '-2day'],
                ['label' => 'One Week', 'from' => '-7day'],
                ['label' => 'One Month', 'from' => '-30day'],
                ['label' => 'One Year', 'from' => '-1year'],
            ],
        ]);
    }
}
