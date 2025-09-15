<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\util;

final class ContextUtil
{
    /**
     * Parse key=value context arguments from a specific start offset.
     * Allowed keys: world, gamemode
     * @return array<string,string>
     */
    public static function parseContextArgs(array $args, int $start) : array
    {
        $allowed = ['world','gamemode'];
        $out = [];
        for ($i = $start; $i < count($args); $i++) {
            $token = (string)$args[$i];
            $eq = strpos($token, '=');
            if ($eq === false) continue;
            $k = strtolower(substr($token, 0, $eq));
            $v = strtolower(substr($token, $eq + 1));
            if ($k !== '' && in_array($k, $allowed, true) && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Parse contexts allowing multiple values per key separated by ',' or ';'
     * Example: world=hub,world gamemode=creative -> ['world' => ['hub','world'], 'gamemode' => ['creative']]
     * @return array<string,string[]>
     */
    public static function parseMultiContextArgs(array $args, int $start) : array
    {
        $allowed = ['world','gamemode'];
        $out = [];
        for ($i = $start; $i < count($args); $i++) {
            $token = (string)$args[$i];
            $eq = strpos($token, '=');
            if ($eq === false) continue;
            $k = strtolower(substr($token, 0, $eq));
            $v = strtolower(substr($token, $eq + 1));
            if ($k === '' || !in_array($k, $allowed, true) || $v === '') continue;
            $vals = preg_split('/[;,]/', $v) ?: [];
            $vals = array_values(array_filter(array_map('trim', $vals), fn($s) => $s !== ''));
            if (!empty($vals)) { $out[$k] = $vals; }
        }
        return $out;
    }

    /**
     * Expand multi-value contexts into all distinct context maps.
     * @param array<string,string[]> $multi
     * @return array<int,array<string,string>>
     */
    public static function expandContextVariants(array $multi) : array
    {
        if (empty($multi)) return [];
        $keys = array_keys($multi);
        $result = [[]];
        foreach ($keys as $k) {
            $next = [];
            foreach ($result as $base) {
                foreach ($multi[$k] as $val) {
                    $copy = $base; $copy[$k] = $val; $next[] = $copy;
                }
            }
            $result = $next;
        }
        return $result;
    }
}
