<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Tests\Unit;

use AveryAbbott\WindowsDhcp\Settings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers Settings::merge — the type-coercion / validation layer downstream code
 * trusts. Pure (no DB), so it runs standalone without bootstrapping LibreNMS.
 */
final class SettingsTest extends TestCase
{
    #[Test]
    public function empty_input_returns_the_documented_defaults(): void
    {
        $this->assertSame(Settings::merge([]), [
            'graph_datasets' => ['inuse', 'free'],
            'graph_stacked' => true,
            'graph_scale_to_size' => false,
            'graph_reservations_split' => false,
            'util_warn' => 80,
            'util_crit' => 95,
            'http_timeout' => 30,
            'monitor_reservation_states' => false,
            'monitor_declined' => false,
        ]);
    }

    #[Test]
    public function datasets_accept_the_checkbox_map_form_in_canonical_order(): void
    {
        // Form posts a {key => "0"|"1"} map; selected keys come back in DATASETS order.
        $merged = Settings::merge(['graph_datasets' => ['bad' => '1', 'inuse' => '1', 'free' => '0']]);

        $this->assertSame(['inuse', 'bad'], $merged['graph_datasets']);
    }

    #[Test]
    public function datasets_accept_the_plain_list_form_and_drop_unknown_series(): void
    {
        $merged = Settings::merge(['graph_datasets' => ['free', 'bogus', 'pending']]);

        $this->assertSame(['free', 'pending'], $merged['graph_datasets']);
    }

    #[Test]
    public function datasets_fall_back_to_defaults_when_nothing_valid_is_selected(): void
    {
        $this->assertSame(['inuse', 'free'], Settings::merge(['graph_datasets' => ['bogus']])['graph_datasets']);
        $this->assertSame(['inuse', 'free'], Settings::merge(['graph_datasets' => 'not-an-array'])['graph_datasets']);
        $this->assertSame(['inuse', 'free'], Settings::merge(['graph_datasets' => ['inuse' => '0', 'free' => '0']])['graph_datasets']);
    }

    #[Test]
    public function booleans_are_coerced_from_form_strings(): void
    {
        $merged = Settings::merge([
            'graph_stacked' => '0',
            'graph_scale_to_size' => '1',
            'monitor_declined' => '1',
        ]);

        $this->assertFalse($merged['graph_stacked']);
        $this->assertTrue($merged['graph_scale_to_size']);
        $this->assertTrue($merged['monitor_declined']);
    }

    #[Test]
    public function percent_thresholds_are_clamped_to_0_100_else_default(): void
    {
        $this->assertSame(50, Settings::merge(['util_warn' => '50'])['util_warn']);
        $this->assertSame(80, Settings::merge(['util_warn' => 150])['util_warn']);   // out of range -> default
        $this->assertSame(80, Settings::merge(['util_warn' => -1])['util_warn']);    // out of range -> default
        $this->assertSame(95, Settings::merge(['util_crit' => ''])['util_crit']);    // blank -> default
        $this->assertSame(0, Settings::merge(['util_warn' => 0])['util_warn']);      // 0 is valid
    }

    #[Test]
    public function http_timeout_must_be_a_positive_int_else_default(): void
    {
        $this->assertSame(45, Settings::merge(['http_timeout' => '45'])['http_timeout']);
        $this->assertSame(30, Settings::merge(['http_timeout' => 0])['http_timeout']);
        $this->assertSame(30, Settings::merge(['http_timeout' => -5])['http_timeout']);
        $this->assertSame(30, Settings::merge(['http_timeout' => ''])['http_timeout']);
    }
}
