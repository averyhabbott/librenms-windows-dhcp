<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Tests\Feature;

use App\Models\Device;
use App\Models\Sensor;
use AveryAbbott\WindowsDhcp\Models\DhcpScope;
use Illuminate\Support\Facades\Http;
use LibreNMS\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration coverage for windows-dhcp:poll against a faked PSU endpoint.
 *
 * Requires the LibreNMS application + database (run from within a LibreNMS
 * install with DBTEST=1). The standalone "Unit" suite covers the pure logic.
 *
 * Each test installs a no-op Datastore so the poller's RRD writes don't touch
 * disk; we assert only the DB/attribute side effects.
 */
final class PollDhcpCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('DBTEST')) {
            $this->markTestSkipped('Database tests not enabled. Set DBTEST=1 to run.');
        }

        $this->dbSetUp();

        // Swallow RRD writes — these tests are about DB/attribute effects.
        $this->app->instance('Datastore', new class
        {
            public function put($device, $measurement, $tags, $fields): void {}
        });
    }

    protected function tearDown(): void
    {
        if (getenv('DBTEST')) {
            $this->dbTearDown();
        }

        parent::tearDown();
    }

    private function dhcpDevice(): Device
    {
        $device = Device::factory()->create();
        $device->setAttrib('dhcp_psu_url', 'https://psu.example.test/api/dhcp');
        $device->setAttrib('dhcp_psu_token', 'test-token');

        return $device;
    }

    private function metricsBody(array $scopes, int $scopesTotal): array
    {
        return [
            'schema_version' => 1,
            'generated_at' => '2026-06-18T00:00:00Z',
            'server' => [
                'scopes_total' => $scopesTotal,
                'scopes_active' => $scopesTotal,
                'addresses_in_use' => 10,
                'percent_in_use' => 12.5,
            ],
            'scopes' => $scopes,
            'failover' => [],
        ];
    }

    #[Test]
    public function it_stores_scopes_from_a_successful_poll(): void
    {
        $device = $this->dhcpDevice();
        Http::fake(['*' => Http::response($this->metricsBody([
            ['scope_id' => '10.0.1.0', 'name' => 'A', 'state' => 'active', 'addresses_in_use' => 5, 'addresses_free' => 5, 'percent_in_use' => 50],
        ], 1), 200)]);

        $this->artisan('windows-dhcp:poll', ['--device' => $device->device_id])->assertSuccessful();

        $this->assertDatabaseHas('dhcp_scopes', ['device_id' => $device->device_id, 'scope_id' => '10.0.1.0']);
        $this->assertNull($device->fresh()->getAttrib('dhcp_psu_last_error'));
    }

    /** H2: a transient empty scope list (server still reports scopes) must NOT delete rows. */
    #[Test]
    public function it_keeps_existing_scopes_when_psu_returns_empty_but_reports_a_total(): void
    {
        $device = $this->dhcpDevice();
        DhcpScope::create(['device_id' => $device->device_id, 'scope_id' => '10.0.1.0', 'addresses_total' => 10]);
        DhcpScope::create(['device_id' => $device->device_id, 'scope_id' => '10.0.2.0', 'addresses_total' => 10]);

        Http::fake(['*' => Http::response($this->metricsBody([], 2), 200)]);

        $this->artisan('windows-dhcp:poll', ['--device' => $device->device_id])->assertSuccessful();

        $this->assertSame(2, DhcpScope::where('device_id', $device->device_id)->count());
    }

    /** Normal pruning still happens when the server genuinely has zero scopes. */
    #[Test]
    public function it_prunes_scopes_when_server_reports_zero(): void
    {
        $device = $this->dhcpDevice();
        DhcpScope::create(['device_id' => $device->device_id, 'scope_id' => '10.0.9.0', 'addresses_total' => 10]);

        Http::fake(['*' => Http::response($this->metricsBody([], 0), 200)]);

        $this->artisan('windows-dhcp:poll', ['--device' => $device->device_id])->assertSuccessful();

        $this->assertSame(0, DhcpScope::where('device_id', $device->device_id)->count());
    }

    /** M2: a 401 records a distinct failure class, not a generic outage. */
    #[Test]
    public function it_records_auth_failure_class_on_401(): void
    {
        $device = $this->dhcpDevice();
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->artisan('windows-dhcp:poll', ['--device' => $device->device_id])->assertSuccessful();

        $this->assertSame('http_401', $device->fresh()->getAttrib('dhcp_psu_last_error'));
        $reach = Sensor::where('device_id', $device->device_id)->where('sensor_type', 'reachability')->first();
        $this->assertNotNull($reach);
        $this->assertSame(0.0, (float) $reach->sensor_current);
    }

    /** M7 + H1: a malformed scope entry must not abort the poll. */
    #[Test]
    public function it_tolerates_a_non_array_scope_entry(): void
    {
        $device = $this->dhcpDevice();
        Http::fake(['*' => Http::response($this->metricsBody([
            'i-am-a-string',
            ['scope_id' => '10.0.1.0', 'addresses_in_use' => 1, 'addresses_free' => 1, 'percent_in_use' => 50],
        ], 2), 200)]);

        $this->artisan('windows-dhcp:poll', ['--device' => $device->device_id])->assertSuccessful();

        $this->assertDatabaseHas('dhcp_scopes', ['device_id' => $device->device_id, 'scope_id' => '10.0.1.0']);
    }
}
