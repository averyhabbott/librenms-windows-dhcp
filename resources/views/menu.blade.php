<a href="{{ url('plugin/WindowsDhcp') }}">
    <i class="fa fa-server fa-fw fa-lg" aria-hidden="true"></i> DHCP Scopes
    @if(! empty($critical))<span class="label label-danger">{{ $critical }}</span>@endif
</a>
