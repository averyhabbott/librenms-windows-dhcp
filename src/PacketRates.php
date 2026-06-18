<?php

declare(strict_types=1);

namespace AveryAbbott\WindowsDhcp;

/**
 * Pure per-second rate computation from two cumulative DHCP packet-counter
 * samples. Kept free of any LibreNMS/Laravel dependency so the tricky edge cases
 * (no prior sample, non-positive interval, counter reset) are unit-testable in
 * isolation. The poll command owns the I/O (reading/writing the device's stashed
 * sample); this owns only the arithmetic.
 */
class PacketRates
{
    /**
     * @param  array<string,mixed>|null  $last     previous sample including a 'ts' key, or null on first run
     * @param  array<string,int|string>  $current  current cumulative counters (no 'ts')
     * @param  int  $now  current Unix timestamp
     * @return array<string,float>  per-second rate per counter; [] when nothing is comparable
     */
    public static function deltas(?array $last, array $current, int $now): array
    {
        if ($last === null || empty($last['ts'])) {
            return [];
        }

        $interval = $now - (int) $last['ts'];
        if ($interval <= 0) {
            return [];
        }

        $rates = [];
        foreach ($current as $key => $value) {
            if ($key === 'ts' || ! isset($last[$key])) {
                continue;
            }
            $delta = (int) $value - (int) $last[$key];
            // Guard a counter reset (service restart): a negative delta -> 0, not a
            // huge negative spike.
            $rates[$key] = $delta < 0 ? 0.0 : round($delta / $interval, 4);
        }

        return $rates;
    }
}
