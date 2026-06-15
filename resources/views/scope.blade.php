@extends('layouts.librenmsv1')

@section('content')
<div class="container-fluid">
    <div class="row" style="margin: 10px 0;">
        <div class="col-md-12">
            <h2 style="margin-top: 5px;">
                {{ __('DHCP Scope') }} {{ $scope->scope_id }}
                @if($scope->name)
                    <small>{{ $scope->name }}</small>
                @endif
            </h2>
            <p style="margin-bottom: 5px;">
                {{ __('Server') }}:
                <a href="{{ url('device/' . $device->device_id) }}">{{ $device->sysName ?: $device->hostname }}</a>
                &nbsp;|&nbsp;
                {{ __('State') }}:
                @if(strtolower((string) $scope->state) === 'active')
                    <span class="label label-success">{{ $scope->state }}</span>
                @else
                    <span class="label label-default">{{ $scope->state ?: __('unknown') }}</span>
                @endif
                &nbsp;|&nbsp;
                {{ __('Utilization') }}: <strong>{{ number_format((float) $scope->percent_in_use, 1) }}%</strong>
                ({{ number_format((int) $scope->addresses_in_use) }} {{ __('in use') }},
                {{ number_format((int) $scope->addresses_free) }} {{ __('free') }})
                &nbsp;|&nbsp;
                {{ __('Bad addresses') }}:
                @if((int) $scope->bad_addresses > 0)
                    <span class="label label-danger">{{ number_format((int) $scope->bad_addresses) }}</span>
                @else
                    <span class="text-muted">0</span>
                @endif
            </p>
        </div>
    </div>

    {{-- Timeframe thumbnail strip: click one to load it into the main graph below. --}}
    <div class="row">
        @foreach($ranges as $r)
            <div class="col-sm-2 col-xs-4 text-center" style="margin-bottom: 10px;">
                <a href="#" class="dhcp-range" data-from="{{ $r['from'] }}" title="{{ $r['label'] }}">
                    <div><strong>{{ $r['label'] }}</strong></div>
                    <img class="img-responsive" style="margin: 0 auto; border: 1px solid #444;"
                         src="{{ route('windows-dhcp.scope-graph', $scope->dhcp_scope_id) }}?width=260&height=70&from={{ $r['from'] }}&cb={{ $graphCacheKey }}"
                         alt="{{ $r['label'] }} graph">
                </a>
            </div>
        @endforeach
    </div>

    <hr style="margin: 5px 0 15px;">

    {{-- Custom range + the large main graph. --}}
    <div class="row" style="margin-bottom: 12px;">
        <div class="col-md-12 form-inline text-center">
            <label for="dhcp-from">{{ __('From') }}</label>
            <input type="text" id="dhcp-from" class="form-control input-sm" style="width: 160px;" value="-1day">
            <label for="dhcp-to" style="margin-left: 10px;">{{ __('To') }}</label>
            <input type="text" id="dhcp-to" class="form-control input-sm" style="width: 160px;" value="now">
            <button type="button" id="dhcp-update" class="btn btn-sm btn-primary" style="margin-left: 8px;">{{ __('Update') }}</button>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12 text-center">
            <img id="dhcp-main-graph" class="img-responsive" style="margin: 0 auto;" src="" alt="scope utilization graph">
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var graphUrl = "{{ route('windows-dhcp.scope-graph', $scope->dhcp_scope_id) }}";
    var graphCacheKey = "{{ $graphCacheKey ?? '' }}";

    function render(from, to) {
        $("#dhcp-from").val(from);
        $("#dhcp-to").val(to || "now");
        var f = encodeURIComponent($("#dhcp-from").val());
        var t = encodeURIComponent($("#dhcp-to").val() || "now");
        $("#dhcp-main-graph").attr("src", graphUrl + "?width=1000&height=300&from=" + f + "&to=" + t + "&cb=" + graphCacheKey);
    }

    $(".dhcp-range").on("click", function (e) {
        e.preventDefault();
        render($(this).data("from"), "now");
    });

    $("#dhcp-update").on("click", function () {
        render($("#dhcp-from").val(), $("#dhcp-to").val());
    });

    // Default to the 24-hour view on load.
    render("-1day", "now");
})();
</script>
@endpush
