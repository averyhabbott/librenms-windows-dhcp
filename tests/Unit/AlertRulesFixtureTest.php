<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the shipped alert_rules/*.json fixtures consumed by
 * windows-dhcp:install-alert-rules. Pure (no DB), so it runs standalone
 * without bootstrapping LibreNMS.
 */
final class AlertRulesFixtureTest extends TestCase
{
    private const RULES_FILE = __DIR__ . '/../../alert_rules/windows-dhcp-alert-rules.json';
    private const GROUP_FILE = __DIR__ . '/../../alert_rules/windows-dhcp-device-group.json';

    /** Rules that reference dhcp_scopes and must bypass the visual query builder. */
    private const DHCP_SCOPES_RULES = [
        'Windows DHCP scope utilization warning',
        'Windows DHCP scope utilization critical',
        'Windows DHCP scope high bad-address rate',
    ];

    #[Test]
    public function rules_file_is_valid_json_with_the_expected_rule_count(): void
    {
        $rules = json_decode((string) file_get_contents(self::RULES_FILE), true);

        $this->assertIsArray($rules);
        $this->assertCount(7, $rules);
    }

    #[Test]
    public function every_rule_has_a_unique_name_and_a_valid_builder(): void
    {
        $rules = json_decode((string) file_get_contents(self::RULES_FILE), true);

        $names = array_column($rules, 'name');
        $this->assertCount(count($names), array_unique($names), 'Rule names must be unique (used as the upsert key).');

        foreach ($rules as $rule) {
            $this->assertArrayHasKey('severity', $rule);
            $this->assertArrayHasKey('builder', $rule);
            $this->assertArrayHasKey('condition', $rule['builder']);
            $this->assertArrayHasKey('rules', $rule['builder']);
        }
    }

    #[Test]
    public function dhcp_scopes_rules_use_a_hand_written_join_instead_of_the_broken_builder_glue(): void
    {
        $rules = json_decode((string) file_get_contents(self::RULES_FILE), true);
        $byName = array_column($rules, null, 'name');

        foreach (self::DHCP_SCOPES_RULES as $name) {
            $this->assertArrayHasKey($name, $byName, "Expected rule \"$name\" to exist");
            $rule = $byName[$name];

            $extra = json_decode((string) ($rule['extra'] ?? ''), true);
            $this->assertTrue(
                $extra['options']['override_query'] ?? false,
                "\"$name\" must set extra.options.override_query so it bypasses the query builder's join resolution"
            );

            $this->assertNotEmpty($rule['adv_query'] ?? null, "\"$name\" must define adv_query");
            $this->assertStringContainsString('JOIN dhcp_scopes ON dhcp_scopes.device_id = devices.device_id', $rule['adv_query']);
            $this->assertStringContainsString('devices.device_id = ?', $rule['adv_query']);
        }
    }

    #[Test]
    public function device_group_fixture_is_dynamic_and_matches_the_dhcp_psu_url_attribute(): void
    {
        $group = json_decode((string) file_get_contents(self::GROUP_FILE), true);

        $this->assertSame('dynamic', $group['type']);
        $this->assertNotEmpty($group['name']);

        $condition = $group['rules']['rules'][0];
        $this->assertSame('devices_attribs.attrib_type', $condition['field']);
        $this->assertSame('equal', $condition['operator']);
        $this->assertSame('dhcp_psu_url', $condition['value']);
    }
}
