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
            <th data-column-id="hostname" data-formatter="device">{{ __('Server') }}</th>
            <th data-column-id="scope_id">{{ __('Scope') }}</th>
            <th data-column-id="name">{{ __('Name') }}</th>
            <th data-column-id="state" data-formatter="state">{{ __('State') }}</th>
            <th data-column-id="addresses_in_use" data-align="right">{{ __('In Use') }}</th>
            <th data-column-id="addresses_free" data-align="right">{{ __('Free') }}</th>
            <th data-column-id="addresses_reserved" data-align="right" data-visible="false">{{ __('Reserved') }}</th>
            <th data-column-id="pending_offers" data-align="right" data-visible="false">{{ __('Pending') }}</th>
            <th data-column-id="percent_in_use" data-formatter="util">{{ __('Utilization') }}</th>
            <th data-column-id="dhcp_scope_id" data-formatter="graph" data-sortable="false" data-searchable="false" data-align="center">{{ __('Graph') }}</th>
        </tr>
        </thead>
    </table>
</div>

<div class="modal fade" id="dhcp-graph-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="dhcp-graph-title">{{ __('Scope') }}</h4>
            </div>
            <div class="modal-body text-center">
                <img id="dhcp-graph-img" class="img-responsive" style="margin: 0 auto;" src="" alt="scope utilization graph">
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var deviceBase = "{{ url('device') }}";
    var graphBase = "{{ url('plugin/windows-dhcp/scope') }}";
    var initialDevice = {{ $selectedDevice ? (int) $selectedDevice : 'null' }};

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

    // Bound via inline onclick on the button (not document delegation): bootgrid
    // calls stopPropagation() on table clicks, so a delegated handler never fires.
    window.dhcpShowGraph = function (btn) {
        var id = btn.getAttribute('data-scope');
        $("#dhcp-graph-title").text("Scope " + btn.getAttribute('data-name'));
        $("#dhcp-graph-img").attr("src", graphBase + "/" + id + "/graph?width=900&height=200&from=-7day");
        $("#dhcp-graph-modal").modal("show");
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
                var cls = p >= 95 ? 'progress-bar-danger' : (p >= 80 ? 'progress-bar-warning' : 'progress-bar-success');
                return '<div class="progress" style="margin-bottom:0; min-width:120px;">' +
                    '<div class="progress-bar ' + cls + '" role="progressbar" style="width:' + Math.min(p, 100) + '%;">' +
                    p.toFixed(1) + '%</div></div>';
            },
            graph: function (column, row) {
                return '<button type="button" class="btn btn-xs btn-default" ' +
                    'data-scope="' + row.dhcp_scope_id + '" data-name="' + esc(row.scope_id) + '" ' +
                    'title="Utilization history" onclick="dhcpShowGraph(this)">' +
                    '<i class="fa fa-area-chart"></i></button>';
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
