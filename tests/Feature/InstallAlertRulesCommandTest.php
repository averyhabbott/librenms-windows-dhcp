<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Tests\Feature;

use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceGroup;
use LibreNMS\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration coverage for windows-dhcp:install-alert-rules.
 *
 * Requires the LibreNMS application + database (run from within a LibreNMS
 * install with DBTEST=1). The standalone "Unit" suite (AlertRulesFixtureTest)
 * covers the shipped JSON fixtures themselves.
 */
final class InstallAlertRulesCommandTest extends TestCase
{
    private const GROUP_NAME = 'Windows DHCP Servers';
    private const RULE_NAME = 'Windows DHCP scope utilization critical';

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('DBTEST')) {
            $this->markTestSkipped('Database tests not enabled. Set DBTEST=1 to run.');
        }

        $this->dbSetUp();
    }

    protected function tearDown(): void
    {
        if (getenv('DBTEST')) {
            $this->dbTearDown();
        }

        parent::tearDown();
    }

    #[Test]
    public function it_creates_the_device_group_and_scopes_rules_to_it_on_first_run(): void
    {
        $this->artisan('windows-dhcp:install-alert-rules')->assertSuccessful();

        $group = DeviceGroup::where('name', self::GROUP_NAME)->first();
        $this->assertNotNull($group);
        $this->assertSame('dynamic', $group->type);

        $rule = AlertRule::where('name', self::RULE_NAME)->first();
        $this->assertNotNull($rule);
        $this->assertTrue($rule->groups->contains($group->id));
        $this->assertStringContainsString('JOIN dhcp_scopes', $rule->query);
    }

    /**
     * Regression: alert_rules.extra is a non-nullable column with no default. Rules that
     * don't define an `extra` key in the fixture (unlike self::RULE_NAME, which does) must
     * still insert successfully instead of hitting a NOT NULL constraint violation.
     */
    #[Test]
    public function it_creates_a_rule_that_has_no_extra_key_in_the_fixture(): void
    {
        $this->artisan('windows-dhcp:install-alert-rules')->assertSuccessful();

        $rule = AlertRule::where('name', 'Windows DHCP failover not normal')->first();
        $this->assertNotNull($rule);
        $this->assertSame([], $rule->extra);
    }

    #[Test]
    public function only_devices_carrying_the_dhcp_psu_url_attribute_join_the_group(): void
    {
        $dhcpDevice = Device::factory()->create();
        $dhcpDevice->setAttrib('dhcp_psu_url', 'https://psu.example.test/api/dhcp');
        $otherDevice = Device::factory()->create();

        $this->artisan('windows-dhcp:install-alert-rules')->assertSuccessful();

        $group = DeviceGroup::where('name', self::GROUP_NAME)->first();
        $memberIds = $group->devices()->pluck('devices.device_id')->all();

        $this->assertContains($dhcpDevice->device_id, $memberIds);
        $this->assertNotContains($otherDevice->device_id, $memberIds);
    }

    #[Test]
    public function default_run_leaves_an_existing_rule_untouched(): void
    {
        $this->artisan('windows-dhcp:install-alert-rules')->assertSuccessful();

        $rule = AlertRule::where('name', self::RULE_NAME)->first();
        $rule->severity = 'warning'; // simulate a user customization
        $rule->save();

        $this->artisan('windows-dhcp:install-alert-rules')->assertSuccessful();

        $this->assertSame('warning', $rule->fresh()->severity);
    }

    #[Test]
    public function force_hard_resets_the_named_rule_to_its_packaged_severity(): void
    {
        $this->artisan('windows-dhcp:install-alert-rules')->assertSuccessful();

        $rule = AlertRule::where('name', self::RULE_NAME)->first();
        $rule->severity = 'warning';
        $rule->save();

        $this->artisan('windows-dhcp:install-alert-rules', ['--force' => true])->assertSuccessful();

        $this->assertSame('critical', $rule->fresh()->severity);
    }
}
