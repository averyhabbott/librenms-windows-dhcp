<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Tests\Unit;

use AveryAbbott\WindowsDhcp\PacketRates;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Edge-case coverage for the per-second counter-rate math: first sample, normal
 * delta, counter reset, non-positive interval, and unknown/extra keys.
 */
final class PacketRatesTest extends TestCase
{
    #[Test]
    public function no_prior_sample_yields_no_rates(): void
    {
        $this->assertSame([], PacketRates::deltas(null, ['acks' => 100], 1000));
    }

    #[Test]
    public function prior_sample_without_timestamp_yields_no_rates(): void
    {
        $this->assertSame([], PacketRates::deltas(['acks' => 50], ['acks' => 100], 1000));
    }

    #[Test]
    public function computes_per_second_rate_over_the_interval(): void
    {
        $last = ['ts' => 1000, 'acks' => 100, 'offers' => 40];
        $current = ['acks' => 160, 'offers' => 70];

        // 60 acks / 30s = 2.0/s ; 30 offers / 30s = 1.0/s
        $this->assertSame(['acks' => 2.0, 'offers' => 1.0], PacketRates::deltas($last, $current, 1030));
    }

    #[Test]
    public function counter_reset_clamps_to_zero_instead_of_negative(): void
    {
        $last = ['ts' => 1000, 'acks' => 5000];
        $current = ['acks' => 10]; // service restarted, counter reset

        $this->assertSame(['acks' => 0.0], PacketRates::deltas($last, $current, 1030));
    }

    #[Test]
    public function non_positive_interval_yields_no_rates(): void
    {
        $last = ['ts' => 1030, 'acks' => 100];

        // same-second poll (interval 0) and clock-skew-backwards (negative) both guard.
        $this->assertSame([], PacketRates::deltas($last, ['acks' => 160], 1030));
        $this->assertSame([], PacketRates::deltas($last, ['acks' => 160], 1000));
    }

    #[Test]
    public function keys_absent_from_the_prior_sample_are_skipped(): void
    {
        $last = ['ts' => 1000, 'acks' => 100];
        $current = ['acks' => 130, 'nacks' => 9]; // nacks is new this round

        $this->assertSame(['acks' => 1.0], PacketRates::deltas($last, $current, 1030));
    }

    #[Test]
    public function fractional_rates_are_rounded_to_four_places(): void
    {
        $last = ['ts' => 1000, 'acks' => 0];
        $current = ['acks' => 1];

        // 1 / 3s = 0.3333...
        $this->assertSame(['acks' => 0.3333], PacketRates::deltas($last, $current, 1003));
    }
}
