<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Console;

use App\Models\Device;
use App\Models\Sensor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LibreNMS\RRD\RrdDefinition;
use AveryAbbott\WindowsDhcp\Models\DhcpScope;
use AveryAbbott\WindowsDhcp\PacketRates;
use AveryAbbott\WindowsDhcp\Settings;

/**
 * Polls every device that has a `dhcp_psu_url` attribute by calling the
 * PowerShell Universal aggregate endpoint (GET /api/dhcp/metrics) and stores:
 *   - one dhcp_scopes row per scope (+ per-scope utilization RRD)
 *   - server-level metrics as sensors (Health tab) + PSU reachability
 *
 * No SNMP. Only devices carrying the dhcp_psu_url attribute are ever contacted.
 */
class PollDhcpCommand extends Command
{
    protected $signature = 'windows-dhcp:poll
        {--device= : Limit to a single device id or hostname}
        {--group= : Limit to a poller_group (for distributed pollers)}';

    protected $description = 'Poll Windows DHCP servers via PowerShell Universal and store scope + server health';

    /** poller_type marker so the core SNMP sensor poller ignores our sensors */
    private const POLLER_TYPE = 'dhcp';
    private const HTTP_TIMEOUT = 30;

    /** Highest PSU metrics schema_version this poller is written against. */
    private const SCHEMA_VERSION = 1;

    /** Lock TTL (seconds) guarding a device against overlapping polls; auto-expires if a poll dies. */
    private const POLL_LOCK_TTL = 600;

    /** @var array merged plugin settings (thresholds, http timeout, ...) */
    private array $settings = [];

    public function handle(): int
    {
        $this->settings = Settings::load();

        $query = Device::whereHas('attribs', fn ($q) => $q->where('attrib_type', 'dhcp_psu_url'));

        if ($device = $this->option('device')) {
            $query->where(fn ($q) => $q->where('device_id', $device)->orWhere('hostname', $device));
        }
        if ($group = $this->option('group')) {
            $query->where('poller_group', $group);
        }

        $devices = $query->get();
        if ($devices->isEmpty()) {
            $this->info('No DHCP devices found. Set the dhcp_psu_url attribute (windows-dhcp:configure) to enable a device.');

            return self::SUCCESS;
        }

        foreach ($devices as $device) {
            // Per-device lock so a manual `--device` run and the scheduled run
            // can't poll the same server at once (which would race the cumulative
            // counter attribute and the (device_id, scope_id) unique index).
            $lock = Cache::lock("windows-dhcp-poll-{$device->device_id}", self::POLL_LOCK_TTL);
            if (! $lock->get()) {
                $this->warn("[{$device->hostname}] poll already in progress; skipping");

                continue;
            }

            // Isolate each device: one server's failure (network, bad payload,
            // DB error) must never abort the rest of the batch.
            try {
                $this->pollDevice($device);
            } catch (\Throwable $e) {
                $this->error("[{$device->hostname}] poll failed: " . $e->getMessage());
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }

    private function pollDevice(Device $device): void
    {
        $data = $this->fetchMetrics($device);

        // PSU reachability is recorded every run, independent of ICMP up/down
        $this->recordReachability($device, $data !== null);

        if ($data === null) {
            $this->warn("[{$device->hostname}] PSU metrics endpoint unreachable");

            return;
        }

        // Normalise the top-level shape so a malformed payload degrades to empty
        // collections instead of throwing on array access downstream.
        $server = is_array($data['server'] ?? null) ? $data['server'] : [];
        $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

        // Guard against a transient PSU cmdlet failure: the metrics endpoint
        // returns HTTP 200 with an empty `scopes` array when scope enumeration
        // fails server-side. Pruning then deletes every scope row for the device.
        // If the server still reports scopes, treat the empty list as a soft
        // failure and keep existing rows/RRD untouched.
        $serverScopeTotal = (int) ($server['scopes_total'] ?? 0);
        if (empty($scopes) && $serverScopeTotal > 0) {
            $this->warn("[{$device->hostname}] PSU returned no scopes but reports {$serverScopeTotal}; keeping existing scopes");
        } else {
            $scopeCount = $this->syncScopes($device, $scopes);
            $this->info("[{$device->hostname}] polled: {$scopeCount} scopes");
        }

        $rates = $this->computePacketRates($device, $server['packets'] ?? []);
        $this->syncServerSensors($device, $data, $rates);
    }

    // ---- HTTP ----

    private function fetchMetrics(Device $device): ?array
    {
        $base = $device->getAttrib('dhcp_psu_url');
        if (empty($base)) {
            $port = $device->getAttrib('dhcp_psu_port') ?: 443;
            $base = "https://{$device->hostname}:{$port}/api/dhcp";
        }
        $url = rtrim((string) $base, '/') . '/metrics';
        $token = $device->getAttrib('dhcp_psu_token');

        $verify = $this->resolveVerify($device);
        $caTempFile = is_string($verify) ? $verify : null;

        try {
            // No in-poll retry: Laravel's retry() throws on a 4xx (to drive the
            // retry), which would misclassify an expired token as a transport
            // error and lose the distinct http_401 signal. Single transient blips
            // are absorbed at the alert layer instead — the "PSU API unreachable"
            // rule requires 2 consecutive failed polls before firing.
            $request = Http::timeout($this->settings['http_timeout'] ?? self::HTTP_TIMEOUT)
                ->withOptions(['verify' => $verify])
                ->acceptJson();

            if (! empty($token)) {
                $request = $request->withToken(trim((string) $token));
            }

            // Opt-in lease-enumeration scrapes. Off by default keeps the call cheap;
            // each gated scrape on the endpoint costs per-scope lease enumeration.
            $query = [];
            if (! empty($this->settings['monitor_reservation_states'])) {
                $query['reservations'] = 'true';
            }
            if (! empty($this->settings['monitor_declined'])) {
                $query['declined'] = 'true';
            }

            $response = $request->get($url, $query);

            if (! $response->successful()) {
                $this->recordError($device, 'http_' . $response->status());
                $this->warn("[{$device->hostname}] {$url} returned HTTP {$response->status()}");

                return null;
            }

            $json = $response->json();
            if (! is_array($json) || ! isset($json['schema_version'])) {
                $this->recordError($device, 'bad_response');
                $this->warn("[{$device->hostname}] unexpected response shape from {$url}");

                return null;
            }

            // The plugin is written against a specific schema; surface a mismatch
            // (the documented "PSU endpoint >= v1.1.0" requirement) loudly rather
            // than consuming a breaking newer schema silently. Parsing is
            // best-effort (every field is defensively coerced) so we still continue.
            if ((int) $json['schema_version'] !== self::SCHEMA_VERSION) {
                $this->warn("[{$device->hostname}] PSU schema_version {$json['schema_version']} != supported " . self::SCHEMA_VERSION . '; parsing best-effort — update the plugin or PSU script');
            }

            $this->recordError($device, null);

            return $json;
        } catch (\Throwable $e) {
            $this->recordError($device, 'transport');
            $this->warn("[{$device->hostname}] error fetching {$url}: " . $e->getMessage());

            return null;
        } finally {
            if ($caTempFile && is_file($caTempFile)) {
                @unlink($caTempFile);
            }
        }
    }

    /**
     * Guzzle "verify": false (disabled), temp CA file path (pinned PEM), or true (system trust).
     */
    private function resolveVerify(Device $device): bool|string
    {
        if ((string) $device->getAttrib('dhcp_psu_verify') === '0') {
            return false;
        }

        $ca = $device->getAttrib('dhcp_psu_ca_cert');
        if (! empty($ca)) {
            $tmp = tempnam(sys_get_temp_dir(), 'dhcp_ca_');
            file_put_contents($tmp, $ca);

            return $tmp;
        }

        return true;
    }

    // ---- Scopes ----

    private function syncScopes(Device $device, array $scopes): int
    {
        $keep = [];

        foreach ($scopes as $s) {
            if (! is_array($s)) {
                continue;
            }
            $scopeId = (string) ($s['scope_id'] ?? '');
            if ($scopeId === '') {
                continue;
            }
            $keep[] = $scopeId;

            $inUse = (int) ($s['addresses_in_use'] ?? 0);
            $free = (int) ($s['addresses_free'] ?? 0);
            $pending = (int) ($s['pending_offers'] ?? 0);
            // Conflicting addresses (BAD_ADDRESS / declined). Defaults to 0 when the
            // PSU endpoint predates this field, so older endpoints stay compatible.
            $bad = (int) ($s['bad_address_count'] ?? 0);
            // Total reserved is always collected; the active/inactive split is 0
            // unless reservation-state monitoring is on. All three are graphable.
            $reserved = (int) ($s['addresses_reserved'] ?? 0);
            $resActive = (int) ($s['reservations_active'] ?? 0);
            $resInactive = (int) ($s['reservations_inactive'] ?? 0);

            DhcpScope::updateOrCreate(
                ['device_id' => $device->device_id, 'scope_id' => $scopeId],
                [
                    'name' => $s['name'] ?? null,
                    'state' => $s['state'] ?? null,
                    'addresses_total' => (int) ($s['addresses_total'] ?? ($inUse + $free)),
                    'addresses_in_use' => $inUse,
                    'addresses_free' => $free,
                    'addresses_reserved' => $reserved,
                    // Active/inactive split; 0 unless reservation-state monitoring is on.
                    'reservations_active' => $resActive,
                    'reservations_inactive' => $resInactive,
                    'pending_offers' => $pending,
                    'bad_addresses' => $bad,
                    'percent_in_use' => round((float) ($s['percent_in_use'] ?? 0), 2),
                ]
            );

            $this->storeRrd($device, ['dhcp-scope', $this->rrdSafe($scopeId)],
                RrdDefinition::make()
                    ->addDataset('inuse', 'GAUGE', 0)
                    ->addDataset('free', 'GAUGE', 0)
                    ->addDataset('pending', 'GAUGE', 0)
                    ->addDataset('bad', 'GAUGE', 0)
                    ->addDataset('reserved', 'GAUGE', 0)
                    ->addDataset('resactive', 'GAUGE', 0)
                    ->addDataset('resinactive', 'GAUGE', 0),
                [
                    'inuse' => $inUse, 'free' => $free, 'pending' => $pending, 'bad' => $bad,
                    'reserved' => $reserved, 'resactive' => $resActive, 'resinactive' => $resInactive,
                ]
            );
        }

        // remove scopes that no longer exist on the server
        DhcpScope::where('device_id', $device->device_id)
            ->when($keep, fn ($q) => $q->whereNotIn('scope_id', $keep))
            ->delete();

        return count($keep);
    }

    // ---- Sensors (server-level health -> Health tab) ----

    private function syncServerSensors(Device $device, array $data, array $rates): void
    {
        $kept = [];

        foreach ($this->serverSensorSpecs($data, $rates) as $spec) {
            $sensor = $this->upsertSensor($device, $spec);
            $kept[] = $sensor->sensor_index;
        }

        // remove stale dhcp sensors (e.g. deleted failover relationship); keep reachability
        Sensor::where('device_id', $device->device_id)
            ->where('poller_type', self::POLLER_TYPE)
            ->where('sensor_type', '!=', 'reachability')
            ->whereNotIn('sensor_index', $kept)
            ->delete();
    }

    /**
     * @return array<int, array{0:string,1:string,2:string,3:string,4:int|float|null,5:?float,6:?float,7:?float,8:?float}>
     */
    private function serverSensorSpecs(array $data, array $rates): array
    {
        $server = $data['server'] ?? [];
        $specs = [];

        if ($server) {
            $specs[] = ['percent', 'utilization', 'utilization', 'DHCP Address Utilization',
                round((float) ($server['percent_in_use'] ?? 0), 2),
                $this->settings['util_crit'] ?? Settings::DEFAULTS['util_crit'],
                $this->settings['util_warn'] ?? Settings::DEFAULTS['util_warn'], null, null];
            $specs[] = ['count', 'addresses', 'addresses_in_use', 'DHCP Addresses In Use',
                (int) ($server['addresses_in_use'] ?? 0), null, null, null, null];
            $specs[] = ['count', 'scopes', 'scopes_active', 'DHCP Active Scopes',
                (int) ($server['scopes_active'] ?? 0), null, null, null, null];
            $specs[] = ['count', 'scopes', 'scopes_total', 'DHCP Total Scopes',
                (int) ($server['scopes_total'] ?? 0), null, null, null, null];
            if (isset($server['uptime_seconds'])) {
                $specs[] = ['runtime', 'uptime', 'uptime', 'DHCP Server Uptime',
                    (int) $server['uptime_seconds'], null, null, null, null];
            }
        }

        // Address conflicts (BAD_ADDRESS / declined) summed across all scopes, so
        // it's graphable + alertable on the Health tab. 0 when no scope reports any.
        $badTotal = array_sum(array_map(
            fn ($s) => (int) ($s['bad_address_count'] ?? 0),
            $data['scopes'] ?? []
        ));
        $specs[] = ['count', 'bad_addresses', 'bad_addresses', 'DHCP Bad Addresses',
            $badTotal, null, null, null, null];

        // Packet rates (per second); created during first poll without a value.
        foreach (['discovers', 'offers', 'requests', 'acks', 'nacks', 'declines', 'releases'] as $pkt) {
            $specs[] = ['count', 'packet_rate', "rate_{$pkt}", 'DHCP ' . ucfirst($pkt) . '/sec',
                $rates[$pkt] ?? null, null, null, null, null];
        }

        // Failover relationships -> 1 (normal) / 0 (not normal); alert when below 1.
        foreach ($data['failover'] ?? [] as $fo) {
            $name = (string) ($fo['name'] ?? 'failover');
            $state = strtolower((string) ($fo['state'] ?? ''));
            $descr = "DHCP Failover: {$name}";
            if (! empty($fo['mode'])) {
                $descr .= ' (' . $fo['mode'] . ')';
            }
            if ($state !== '') {
                $descr .= ' [' . $state . ']';
            }
            $specs[] = ['count', 'failover', 'failover_' . $this->rrdSafe($name), $descr,
                $state === 'normal' ? 1 : 0, null, null, null, 1];
        }

        return $specs;
    }

    /**
     * @param  array{0:string,1:string,2:string,3:string,4:int|float|null,5:?float,6:?float,7:?float,8:?float}  $spec
     */
    private function upsertSensor(Device $device, array $spec): Sensor
    {
        [$class, $type, $index, $descr, $value, $limit, $limitWarn, $limitLow, $limitLowWarn] = $spec;

        $sensor = Sensor::updateOrCreate([
            'device_id' => $device->device_id,
            'poller_type' => self::POLLER_TYPE,
            'sensor_class' => $class,
            'sensor_type' => $type,
            'sensor_index' => $index,
        ], [
            'sensor_oid' => "dhcp.{$type}.{$index}",
            'sensor_descr' => $descr,
            'sensor_limit' => $limit,
            'sensor_limit_warn' => $limitWarn,
            'sensor_limit_low' => $limitLow,
            'sensor_limit_low_warn' => $limitLowWarn,
            'rrd_type' => 'GAUGE',
        ]);

        if ($value !== null) {
            if ($sensor->sensor_current === null || (float) $sensor->sensor_current !== (float) $value) {
                $sensor->sensor_current = $value;
                $sensor->save();
            }
            $this->storeSensorRrd($device, $sensor);
        }

        return $sensor;
    }

    private function recordReachability(Device $device, bool $reachable): void
    {
        $this->upsertSensor($device, [
            'count', 'reachability', 'api_reachable', 'DHCP PSU API Reachable',
            $reachable ? 1 : 0, null, null, null, 1,
        ]);
    }

    /**
     * Stash the last fetch outcome on the device so an auth failure / token
     * expiry (e.g. "http_401") is diagnosable instead of looking identical to a
     * network outage in the reachability sensor. Pass null to clear on success.
     */
    private function recordError(Device $device, ?string $reason): void
    {
        $current = (string) $device->getAttrib('dhcp_psu_last_error');
        $new = (string) $reason;
        if ($current === $new) {
            return;
        }

        if ($reason === null) {
            $device->forgetAttrib('dhcp_psu_last_error');
        } else {
            $device->setAttrib('dhcp_psu_last_error', $reason);
        }
    }

    // ---- RRD ----

    private function storeSensorRrd(Device $device, Sensor $sensor): void
    {
        $this->storeRrd(
            $device,
            ['sensor', $sensor->sensor_class, $sensor->sensor_type, $sensor->sensor_index],
            RrdDefinition::make()->addDataset('sensor', $sensor->rrd_type ?: 'GAUGE'),
            ['sensor' => $sensor->sensor_current],
            [
                'sensor_class' => $sensor->sensor_class,
                'sensor_type' => $sensor->sensor_type,
                'sensor_descr' => $sensor->sensor_descr,
                'sensor_index' => $sensor->sensor_index,
            ]
        );
    }

    private function storeRrd(Device $device, array $rrdName, RrdDefinition $def, array $fields, array $extraTags = []): void
    {
        $measurement = $rrdName[0];
        $tags = array_merge($extraTags, ['rrd_name' => $rrdName, 'rrd_def' => $def]);
        app('Datastore')->put($device->toArray(), $measurement, $tags, $fields);
    }

    // ---- Helpers ----

    /**
     * Per-second packet rates from cumulative counters, stashing the last sample
     * in a device attribute. Returns [] until a second sample (or after reset).
     */
    private function computePacketRates(Device $device, array $packets): array
    {
        if (empty($packets)) {
            return [];
        }

        $now = time();
        $last = json_decode((string) $device->getAttrib('dhcp_psu_counters'), true);

        $device->setAttrib('dhcp_psu_counters', json_encode(['ts' => $now] + $packets));

        return PacketRates::deltas(is_array($last) ? $last : null, $packets, $now);
    }

    private function rrdSafe(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $value);
    }
}
