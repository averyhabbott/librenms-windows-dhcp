<div class="container-fluid">
    <div class="row" style="margin: 5px 0 10px;">
        <div class="col-md-6">
            <h3 style="margin-top: 5px;">{{ $title }}</h3>
        </div>
        <div class="col-md-4 col-md-offset-2">
            <select id="dhcp-device-filter" class="form-control" title="Filter by DHCP server">
                <option value="">All DHCP servers</option>
                @foreach($devices as $d)
                    <option value="{{ $d->device_id }}">{{ $d->sysName ?: $d->hostname }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <table id="dhcp-scopes" class="table table-hover table-condensed table-striped">
        <thead>
        <tr>
            <th data-column-id="hostname" data-formatter="device" data-width="180px">{{ __('Server') }}</th>
            <th data-column-id="scope_id" data-width="130px">{{ __('Scope') }}</th>
            <th data-column-id="name">{{ __('Name') }}</th>
            <th data-column-id="state" data-formatter="state" data-align="center" data-header-align="center" data-width="90px">{{ __('State') }}</th>
            <th data-column-id="addresses_in_use" data-align="right" data-header-align="right" data-width="80px">{{ __('In Use') }}</th>
            <th data-column-id="addresses_free" data-align="right" data-header-align="right" data-width="80px">{{ __('Free') }}</th>
            <th data-column-id="addresses_reserved" data-align="right" data-header-align="right" data-width="90px" data-visible="false">{{ __('Reserved') }}</th>
            <th data-column-id="reservations_active" data-align="right" data-header-align="right" data-width="90px" data-visible="false">{{ __('Res. Active') }}</th>
            <th data-column-id="reservations_inactive" data-align="right" data-header-align="right" data-width="100px" data-visible="false">{{ __('Res. Inactive') }}</th>
            <th data-column-id="pending_offers" data-align="right" data-header-align="right" data-width="90px" data-visible="false">{{ __('Pending') }}</th>
            <th data-column-id="bad_addresses" data-formatter="bad" data-align="right" data-header-align="right" data-width="70px" data-visible="false">{{ __('Bad') }}</th>
            <th data-column-id="percent_in_use" data-formatter="util" data-header-align="left" data-width="180px">{{ __('Utilization') }}</th>
            <th data-column-id="dhcp_scope_id" data-formatter="graph" data-sortable="false" data-searchable="false" data-align="center" data-header-align="center" data-width="70px">{{ __('Graph') }}</th>
        </tr>
        </thead>
    </table>
</div>

{{-- Hover preview popup: a single element reused for every row, appended to <body>
     (see scripts) so it escapes the table's stacking context. --}}
<div id="dhcp-hover-preview" style="display:none; position:absolute; z-index:1080; pointer-events:none; background:#222; padding:3px; border:1px solid #555; border-radius:3px; box-shadow:0 2px 8px rgba(0,0,0,0.5);">
    <img alt="6h utilization preview" style="display:block;">
</div>

@push('scripts')
<script>
(function () {
    var deviceBase = "{{ url('device') }}";
    var graphBase = "{{ url('plugin/windows-dhcp/scope') }}";
    var initialDevice = {{ $selectedDevice ? (int) $selectedDevice : 'null' }};
    var utilWarn = {{ (int) ($utilWarn ?? 80) }};
    var utilCrit = {{ (int) ($utilCrit ?? 95) }};
    var graphCacheKey = "{{ $graphCacheKey ?? '' }}";

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

    // ---- Hover preview (6h) -------------------------------------------------
    // Bound via inline onmouseenter/onmouseleave in the formatter (not document
    // delegation): bootgrid calls stopPropagation() on table events, so delegated
    // handlers never fire. The popup is moved to <body> so it escapes the table's
    // stacking context; the GraphController sends Cache-Control max-age=300, which
    // gives the 5-minute per-image TTL for free (re-hovers reuse the cached PNG).
    var HOVER_DELAY = 300;
    var hoverTimer = null;
    var $preview = null;

    function previewEl() {
        if (!$preview) {
            $preview = $("#dhcp-hover-preview").appendTo(document.body);
        }
        return $preview;
    }

    window.dhcpHoverGraph = function (el) {
        clearTimeout(hoverTimer);
        var id = el.getAttribute("data-scope");
        if (!id) { return; }
        hoverTimer = setTimeout(function () {
            var $p = previewEl();
            $p.find("img").attr("src", graphBase + "/" + id + "/graph?width=400&height=150&from=-6hour&cb=" + graphCacheKey);

            // Position beside the button, flipping near the right/bottom edge.
            var rect = el.getBoundingClientRect();
            var pw = 410, ph = 162;
            var sx = window.pageXOffset, sy = window.pageYOffset;
            var left = rect.right + sx + 8;
            if (rect.right + 8 + pw > window.innerWidth) {
                left = rect.left + sx - pw - 8;          // flip to the left
            }
            if (left < sx + 2) { left = sx + 2; }
            var top = rect.top + sy;
            if (rect.top + ph > window.innerHeight) {
                top = sy + window.innerHeight - ph - 4;  // nudge up to stay on screen
            }
            if (top < sy + 2) { top = sy + 2; }
            $p.css({ left: left + "px", top: top + "px" }).show();
        }, HOVER_DELAY);
    };

    window.dhcpHideGraph = function () {
        clearTimeout(hoverTimer);
        if ($preview) { $preview.hide(); }
    };

    var grid = $("#dhcp-scopes").bootgrid({
        ajax: true,
        rowCount: [25, 50, 100, 250],
        post: function () {
            var v = $("#dhcp-device-filter").val();
            return v ? {device: v} : {};
        },
        url: "{{ route('windows-dhcp.scopes-table') }}",
        formatters: {
            device: function (column, row) {
                return '<a href="' + deviceBase + '/' + row.device_id + '">' + esc(row.hostname) + '</a>';
            },
            state: function (column, row) {
                var s = (row.state || '').toLowerCase();
                if (s === 'active') {
                    return '<span class="label label-success">Active</span>';
                }
                return '<span class="label label-default">' + esc(row.state || 'unknown') + '</span>';
            },
            util: function (column, row) {
                var p = parseFloat(row.percent_in_use) || 0;
                var cls = p >= utilCrit ? 'progress-bar-danger' : (p >= utilWarn ? 'progress-bar-warning' : 'progress-bar-success');
                // Render the % as a separate label (inherits the theme text colour, so it
                // stays readable in dark mode) instead of inside the bar, where it spills
                // onto the light track and disappears when the bar is near-empty.
                return '<div style="display:flex; align-items:center; min-width:120px;">' +
                    '<span style="width:46px; text-align:right; margin-right:6px;">' + p.toFixed(1) + '%</span>' +
                    '<div class="progress" style="margin-bottom:0; flex:1;">' +
                    '<div class="progress-bar ' + cls + '" role="progressbar" style="width:' + Math.min(p, 100) + '%;"></div>' +
                    '</div></div>';
            },
            bad: function (column, row) {
                var b = parseInt(row.bad_addresses, 10) || 0;
                return b > 0 ? '<span class="text-danger"><strong>' + b + '</strong></span>' : '0';
            },
            graph: function (column, row) {
                // Opens the full scope detail page in a new tab (preserves the
                // table's page/search/filter state); hover shows a 6h preview.
                return '<a class="btn btn-xs btn-default" target="_blank" rel="noopener" ' +
                    'href="' + graphBase + '/' + row.dhcp_scope_id + '" ' +
                    'data-scope="' + row.dhcp_scope_id + '" ' +
                    'title="Open scope graphs" ' +
                    'onmouseenter="dhcpHoverGraph(this)" onmouseleave="dhcpHideGraph()">' +
                    '<i class="fa fa-area-chart"></i></a>';
            }
        }
    });

    if (initialDevice) {
        $("#dhcp-device-filter").val(initialDevice);
        grid.bootgrid("reload");
    }

    $("#dhcp-device-filter").on("change", function () {
        grid.bootgrid("reload");
    });
})();
</script>
@endpush
