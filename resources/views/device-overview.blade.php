<div class="row">
    <div class="col-md-12">
        <div class="panel panel-default panel-condensed">
            <div class="panel-heading">
                <strong>{{ $title }}</strong>
                <a href="{{ url('plugin/WindowsDhcp') }}?device={{ $device->device_id }}" class="pull-right">
                    {{ __('View all') }} {{ number_format($total) }} {{ __('scopes') }} &raquo;
                </a>
            </div>
            <div class="panel-body">
                <div class="row text-center">
                    <div class="col-xs-4">
                        <div style="font-size: 22px;">{{ number_format($total) }}</div>
                        <div class="text-muted">{{ __('Scopes') }}</div>
                    </div>
                    <div class="col-xs-4">
                        <div style="font-size: 22px;" class="{{ $warning ? 'text-warning' : 'text-muted' }}">{{ number_format($warning) }}</div>
                        <div class="text-muted">&ge; 80%</div>
                    </div>
                    <div class="col-xs-4">
                        <div style="font-size: 22px;" class="{{ $critical ? 'text-danger' : 'text-muted' }}">{{ number_format($critical) }}</div>
                        <div class="text-muted">&ge; 95%</div>
                    </div>
                </div>

                @if($topScopes->isNotEmpty())
                    <hr style="margin: 12px 0;">
                    <table class="table table-condensed table-striped" style="margin-bottom: 0;">
                        <thead>
                        <tr>
                            <th>{{ __('Busiest scopes') }}</th>
                            <th class="text-right">{{ __('In Use') }}</th>
                            <th style="width: 160px;">{{ __('Utilization') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($topScopes as $scope)
                            @php
                                $perc = (float) $scope->percent_in_use;
                                $barClass = $perc >= 95 ? 'progress-bar-danger' : ($perc >= 80 ? 'progress-bar-warning' : 'progress-bar-success');
                            @endphp
                            <tr>
                                <td><strong>{{ $scope->scope_id }}</strong> <span class="text-muted">{{ $scope->name }}</span></td>
                                <td class="text-right">{{ number_format($scope->addresses_in_use) }}</td>
                                <td>
                                    <div class="progress" style="margin-bottom: 0;">
                                        <div class="progress-bar {{ $barClass }}" role="progressbar" style="width: {{ min($perc, 100) }}%;">{{ number_format($perc, 1) }}%</div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
