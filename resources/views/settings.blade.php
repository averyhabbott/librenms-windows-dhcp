@php
    $datasetLabels = ['inuse' => 'In Use', 'free' => 'Free', 'pending' => 'Pending', 'bad' => 'Bad', 'reservations' => 'Reservations'];
    $selectedDatasets = $settings['graph_datasets'] ?? array_keys($datasetLabels);
@endphp

<div style="margin: 15px;">
    <h3 style="margin-top: 0;">{{ $plugin_name }} {{ __('Settings') }} <small class="text-muted">v{{ $version }}</small></h3>

    {{-- Posts to the same URL (the core plugin.update route). --}}
    <form method="post" class="form-horizontal" style="max-width: 720px;">
        @csrf

        <fieldset>
            <legend style="font-size: 16px;">{{ __('Graphs') }}</legend>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('Graphed series') }}</label>
                <div class="col-sm-8">
                    @foreach($datasetLabels as $key => $label)
                        {{-- hidden 0 before each checkbox so unchecked boxes still submit --}}
                        <input type="hidden" name="settings[graph_datasets][{{ $key }}]" value="0">
                        <label class="checkbox-inline">
                            <input type="checkbox" name="settings[graph_datasets][{{ $key }}]" value="1"
                                   @checked(in_array($key, $selectedDatasets, true))>
                            {{ $label }}
                        </label>
                    @endforeach
                    <p class="help-block">{{ __('Which series to draw on the scope graphs.') }}</p>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('Graphing reservations') }}</label>
                <div class="col-sm-8">
                    <input type="hidden" name="settings[graph_reservations_split]" value="0">
                    <label class="checkbox">
                        <input type="checkbox" name="settings[graph_reservations_split]" value="1"
                               @checked(! empty($settings['graph_reservations_split']))>
                        {{ __('Graph active/inactive reservations distinctly') }}
                    </label>
                    <p class="help-block">{{ __('Only applies when "Reservations" is graphed above. Off: a single line for total reserved. On: separate active and inactive lines. Active/inactive read 0 unless "Monitor reservation states" is enabled below.') }}</p>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('Stack series') }}</label>
                <div class="col-sm-8">
                    <input type="hidden" name="settings[graph_stacked]" value="0">
                    <label class="checkbox">
                        <input type="checkbox" name="settings[graph_stacked]" value="1"
                               @checked(! empty($settings['graph_stacked']))>
                        {{ __('Stack In Use + Free as a filled pool (unchecked: draw every series as a separate line)') }}
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('Scale to scope size') }}</label>
                <div class="col-sm-8">
                    <input type="hidden" name="settings[graph_scale_to_size]" value="0">
                    <label class="checkbox">
                        <input type="checkbox" name="settings[graph_scale_to_size]" value="1"
                               @checked(! empty($settings['graph_scale_to_size']))>
                        {{ __('Fix the graph height to the scope\'s size (unchecked: auto-scale to the data)') }}
                    </label>
                    <p class="help-block">{{ __('When on, the y-axis tops out at the scope\'s size, so fullness is shown to scale and graphs are comparable across scopes.') }}</p>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend style="font-size: 16px;">{{ __('Utilization thresholds') }}</legend>

            <div class="form-group">
                <label for="util_warn" class="col-sm-4 control-label">{{ __('Warning (%)') }}</label>
                <div class="col-sm-3">
                    <input type="number" min="0" max="100" id="util_warn" class="form-control"
                           name="settings[util_warn]" value="{{ $settings['util_warn'] }}">
                </div>
            </div>

            <div class="form-group">
                <label for="util_crit" class="col-sm-4 control-label">{{ __('Critical (%)') }}</label>
                <div class="col-sm-3">
                    <input type="number" min="0" max="100" id="util_crit" class="form-control"
                           name="settings[util_crit]" value="{{ $settings['util_crit'] }}">
                </div>
            </div>
            <p class="help-block col-sm-offset-4 col-sm-8">
                {{ __('Drives the utilization sensor alert limits, the scopes-table bar colours, and the menu critical badge.') }}
            </p>
        </fieldset>

        <fieldset>
            <legend style="font-size: 16px;">{{ __('Collection') }}</legend>

            <div class="form-group">
                <label for="http_timeout" class="col-sm-4 control-label">{{ __('PSU HTTP timeout (s)') }}</label>
                <div class="col-sm-3">
                    <input type="number" min="1" max="600" id="http_timeout" class="form-control"
                           name="settings[http_timeout]" value="{{ $settings['http_timeout'] }}">
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('Monitor reservation states') }}</label>
                <div class="col-sm-8">
                    <input type="hidden" name="settings[monitor_reservation_states]" value="0">
                    <label class="checkbox">
                        <input type="checkbox" name="settings[monitor_reservation_states]" value="1"
                               @checked(! empty($settings['monitor_reservation_states']))>
                        {{ __('Split reserved addresses into active vs. inactive') }}
                    </label>
                    <p class="help-block">{{ __('Off: only the total reserved count is collected (free, from scope statistics). On: enumerates leases on scopes that have reservations to split active/inactive - adds server-side time, more so on servers with many reservations.') }}</p>
                </div>
            </div>

            <div class="form-group">
                <label class="col-sm-4 control-label">{{ __('Monitor declined addresses') }}</label>
                <div class="col-sm-8">
                    <input type="hidden" name="settings[monitor_declined]" value="0">
                    <label class="checkbox">
                        <input type="checkbox" name="settings[monitor_declined]" value="1"
                               @checked(! empty($settings['monitor_declined']))>
                        {{ __('Count bad / declined (conflict) addresses per scope') }}
                    </label>
                    <p class="help-block">{{ __('Off: bad-address counts stay 0. On: scans for declined/conflict leases on every scope - the heaviest scrape; adds the most server-side time on estates with many scopes.') }}</p>
                </div>
            </div>
        </fieldset>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8">
                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            </div>
        </div>
    </form>
</div>
