<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp;

/**
 * Maps a desired poll interval (seconds, from LibreNMS's poller frequency) onto
 * what Laravel's scheduler actually supports. Laravel has named sub-minute
 * helpers (everySecond … everyThirtySeconds) and minute-granular cron, but NO
 * arbitrary everySeconds(int) — so we translate. Pure + unit-testable; the
 * service provider applies the result to the scheduled Event.
 *
 * Returns one of:
 *   ['method' => 'everyThirtySeconds']  // call directly on the Event
 *   ['cron'   => '*\/5 * * * *']         // pass to Event::cron()
 */
class PollSchedule
{
    /**
     * @return array{method?:string, cron?:string}
     */
    public static function frequency(int $seconds): array
    {
        $seconds = max(1, $seconds);

        if ($seconds < 60) {
            return ['method' => match (true) {
                $seconds >= 30 => 'everyThirtySeconds',
                $seconds >= 15 => 'everyFifteenSeconds',
                $seconds >= 10 => 'everyTenSeconds',
                $seconds >= 5 => 'everyFiveSeconds',
                $seconds >= 2 => 'everyTwoSeconds',
                default => 'everySecond',
            }];
        }

        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return ['cron' => '*/' . $minutes . ' * * * *'];
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return ['cron' => '0 */' . $hours . ' * * *'];
        }

        return ['cron' => '0 0 * * *']; // cap at once daily
    }
}
