<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp\Tests\Unit;

use AveryAbbott\WindowsDhcp\PollSchedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The frequency mapping must only ever yield scheduler APIs that exist in
 * Laravel (named sub-minute helpers or a cron expression) — never the
 * non-existent everySeconds(int) the plugin originally called.
 */
final class PollScheduleTest extends TestCase
{
    #[Test]
    public function default_five_minute_frequency_maps_to_a_cron_expression(): void
    {
        $this->assertSame(['cron' => '*/5 * * * *'], PollSchedule::frequency(300));
    }

    #[Test]
    public function whole_minute_frequencies_map_to_minute_cron(): void
    {
        $this->assertSame(['cron' => '*/1 * * * *'], PollSchedule::frequency(60));
        $this->assertSame(['cron' => '*/10 * * * *'], PollSchedule::frequency(600));
    }

    #[Test]
    public function sub_minute_frequencies_map_to_named_helpers(): void
    {
        $this->assertSame(['method' => 'everyThirtySeconds'], PollSchedule::frequency(30));
        $this->assertSame(['method' => 'everyFifteenSeconds'], PollSchedule::frequency(20));
        $this->assertSame(['method' => 'everyTenSeconds'], PollSchedule::frequency(10));
        $this->assertSame(['method' => 'everyFiveSeconds'], PollSchedule::frequency(5));
        $this->assertSame(['method' => 'everyTwoSeconds'], PollSchedule::frequency(2));
        $this->assertSame(['method' => 'everySecond'], PollSchedule::frequency(1));
    }

    #[Test]
    public function hour_plus_frequencies_map_to_hourly_cron(): void
    {
        $this->assertSame(['cron' => '0 */1 * * *'], PollSchedule::frequency(3600));
        $this->assertSame(['cron' => '0 */2 * * *'], PollSchedule::frequency(7200));
    }

    #[Test]
    public function zero_or_negative_is_clamped_to_the_fastest_tick(): void
    {
        $this->assertSame(['method' => 'everySecond'], PollSchedule::frequency(0));
        $this->assertSame(['method' => 'everySecond'], PollSchedule::frequency(-100));
    }

    #[Test]
    public function the_named_helpers_all_exist_on_the_laravel_scheduler(): void
    {
        // Guard against re-introducing a non-existent method like everySeconds().
        $methods = array_filter(array_map(
            fn (int $s) => PollSchedule::frequency($s)['method'] ?? null,
            [1, 2, 5, 10, 20, 30, 45]
        ));

        foreach ($methods as $method) {
            $this->assertContains($method, [
                'everySecond', 'everyTwoSeconds', 'everyFiveSeconds',
                'everyTenSeconds', 'everyFifteenSeconds', 'everyThirtySeconds',
            ], "Unexpected scheduler method: {$method}");
        }
    }
}
