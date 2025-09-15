<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\util;

final class DurationParser
{
    /**
     * Parse durations like "90", "10m", "1h30m", "2d3h", "1d2h30m15s" to seconds.
     * Also supports UNIX timestamp (>= 1,000,000,000) as absolute expiry.
     */
    public static function parse(string $token) : ?int
    {
        $token = trim($token);
        if ($token === '') return null;
        if (ctype_digit($token)) {
            $val = (int)$token;
            if ($val <= 0) return null;
            if ($val >= 1000000000) {
                $now = time();
                $delta = $val - $now;
                return $delta > 0 ? $delta : null;
            }
            return $val;
        }
        if (!preg_match_all('/(\d+)([smhdw])/i', $token, $m, PREG_SET_ORDER)) {
            return null;
        }
        $total = 0;
        foreach ($m as $part) {
            $n = (int)$part[1];
            $u = strtolower($part[2]);
            $mult = match ($u) {
                's' => 1,
                'm' => 60,
                'h' => 3600,
                'd' => 86400,
                'w' => 604800,
                default => 0,
            };
            $total += $n * $mult;
        }
        return $total > 0 ? $total : null;
    }
}
