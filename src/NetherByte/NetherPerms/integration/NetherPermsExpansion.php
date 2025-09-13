<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\integration;

use NetherByte\NetherPerms\NetherPerms;
use NetherByte\PlaceholderAPI\expansion\Expansion;
use pocketmine\player\Player;

final class NetherPermsExpansion extends Expansion
{
    public function __construct(NetherPerms $plugin)
    {
        parent::__construct($plugin);
    }

    public function getName() : string
    {
        return 'NetherPerms';
    }

    public function onRequest(string $identifier, ?Player $player) : ?string
    {
        // Expect identifiers like:
        //  - netherperms_primary_group
        //  - netherperms_prefix
        //  - netherperms_suffix
        //  - netherperms_groups
        if ($player === null || !$player->isOnline()) {
            // player-scoped placeholders require a player
            return '';
        }
        /** @var NetherPerms $np */
        $np = $this->plugin; // parent defines protected object $plugin
        $pm = $np->getPermissionManager();
        $uuid = $player->getUniqueId()->toString();

        switch ($identifier) {
            case 'netherperms_primary_group':
                return (string)($pm->getPrimaryGroup($uuid) ?? '');
            case 'netherperms_prefix':
                return (string)($pm->getResolvedPrefix($uuid) ?? '');
            case 'netherperms_suffix':
                return (string)($pm->getResolvedSuffix($uuid) ?? '');
            case 'netherperms_groups':
                $groups = $pm->getUserGroups($uuid);
                return implode(',', $groups);
            case 'netherperms_primary_group_name':
                return (string)($pm->getPrimaryGroup($uuid) ?? '');
        }

        // netherperms_meta_<key>
        if (str_starts_with($identifier, 'netherperms_meta_')) {
            $key = substr($identifier, strlen('netherperms_meta_'));
            $val = $pm->getResolvedMeta($uuid, $key);
            return $val !== null ? (string)$val : '';
        }

        // Inherited groups list
        if ($identifier === 'netherperms_inherited_groups') {
            $all = $this->computeInheritedGroups($pm->getAllGroups(), $pm->getUserGroups($uuid));
            return implode(',', $all);
        }

        // Permissions queries
        if (str_starts_with($identifier, 'netherperms_has_permission_')) {
            $node = substr($identifier, strlen('netherperms_has_permission_'));
            return $this->userHasDirectPermission($pm, $uuid, $node) ? 'true' : 'false';
        }
        if (str_starts_with($identifier, 'netherperms_inherits_permission_')) {
            $node = substr($identifier, strlen('netherperms_inherits_permission_'));
            $direct = $this->userHasDirectPermission($pm, $uuid, $node);
            $effective = $this->userEffectivePermission($pm, $uuid, $node);
            return ($effective && !$direct) ? 'true' : 'false';
        }
        if (str_starts_with($identifier, 'netherperms_check_permission_')) {
            $node = substr($identifier, strlen('netherperms_check_permission_'));
            $has = $this->userEffectivePermission($pm, $uuid, $node);
            return $has ? 'true' : 'false';
        }

        // Group membership
        if (str_starts_with($identifier, 'netherperms_in_group_')) {
            $group = strtolower(substr($identifier, strlen('netherperms_in_group_')));
            $in = in_array($group, array_map('strtolower', $pm->getUserGroups($uuid)), true);
            return $in ? 'true' : 'false';
        }
        if (str_starts_with($identifier, 'netherperms_inherits_group_')) {
            $group = strtolower(substr($identifier, strlen('netherperms_inherits_group_')));
            $all = $this->computeInheritedGroups($pm->getAllGroups(), $pm->getUserGroups($uuid));
            $in = in_array($group, array_map('strtolower', $all), true);
            return $in ? 'true' : 'false';
        }

        // Tracks
        if (str_starts_with($identifier, 'netherperms_on_track_')) {
            $track = strtolower(substr($identifier, strlen('netherperms_on_track_')));
            $primary = strtolower((string)($pm->getPrimaryGroup($uuid) ?? ''));
            $order = $pm->getTracks()[$track] ?? [];
            return ($primary !== '' && in_array($primary, $order, true)) ? 'true' : 'false';
        }
        if (str_starts_with($identifier, 'netherperms_has_groups_on_track_')) {
            $track = strtolower(substr($identifier, strlen('netherperms_has_groups_on_track_')));
            $order = $pm->getTracks()[$track] ?? [];
            if (empty($order)) return 'false';
            $userGroups = array_map('strtolower', $pm->getUserGroups($uuid));
            foreach ($userGroups as $g) if (in_array($g, $order, true)) return 'true';
            return 'false';
        }
        if (str_starts_with($identifier, 'netherperms_current_group_on_track_')) {
            $track = strtolower(substr($identifier, strlen('netherperms_current_group_on_track_')));
            $primary = strtolower((string)($pm->getPrimaryGroup($uuid) ?? ''));
            $order = $pm->getTracks()[$track] ?? [];
            return in_array($primary, $order, true) ? $primary : '';
        }
        if (str_starts_with($identifier, 'netherperms_next_group_on_track_')) {
            $track = strtolower(substr($identifier, strlen('netherperms_next_group_on_track_')));
            $primary = strtolower((string)($pm->getPrimaryGroup($uuid) ?? ''));
            $order = $pm->getTracks()[$track] ?? [];
            $idx = array_search($primary, $order, true);
            if ($idx === false || $idx + 1 >= count($order)) return '';
            return (string)$order[$idx + 1];
        }
        if (str_starts_with($identifier, 'netherperms_previous_group_on_track_')) {
            $track = strtolower(substr($identifier, strlen('netherperms_previous_group_on_track_')));
            $primary = strtolower((string)($pm->getPrimaryGroup($uuid) ?? ''));
            $order = $pm->getTracks()[$track] ?? [];
            $idx = array_search($primary, $order, true);
            if ($idx === false || $idx - 1 < 0) return '';
            return (string)$order[$idx - 1];
        }
        if (str_starts_with($identifier, 'netherperms_first_group_on_tracks_')) {
            $tracksCsv = substr($identifier, strlen('netherperms_first_group_on_tracks_'));
            $tracks = array_values(array_filter(array_map('trim', explode(',', $tracksCsv))));
            $groups = array_map('strtolower', $pm->getUserGroups($uuid));
            $allOrders = [];
            foreach ($tracks as $t) {
                $lt = strtolower($t);
                foreach (($pm->getTracks()[$lt] ?? []) as $g) $allOrders[] = strtolower((string)$g);
            }
            foreach ($allOrders as $g) if (in_array($g, $groups, true)) return $g;
            return '';
        }
        if (str_starts_with($identifier, 'netherperms_last_group_on_tracks_')) {
            $tracksCsv = substr($identifier, strlen('netherperms_last_group_on_tracks_'));
            $tracks = array_values(array_filter(array_map('trim', explode(',', $tracksCsv))));
            $groups = array_map('strtolower', $pm->getUserGroups($uuid));
            $allOrders = [];
            foreach ($tracks as $t) {
                $lt = strtolower($t);
                foreach (($pm->getTracks()[$lt] ?? []) as $g) $allOrders[] = strtolower((string)$g);
            }
            for ($i = count($allOrders) - 1; $i >= 0; --$i) {
                if (in_array($allOrders[$i], $groups, true)) return (string)$allOrders[$i];
            }
            return '';
        }

        // Weight-based placeholders
        if ($identifier === 'netherperms_highest_group_by_weight') {
            return $this->selectByWeight($pm->getAllGroups(), $pm->getUserGroups($uuid), true) ?? '';
        }
        if ($identifier === 'netherperms_lowest_group_by_weight') {
            return $this->selectByWeight($pm->getAllGroups(), $pm->getUserGroups($uuid), false) ?? '';
        }
        if ($identifier === 'netherperms_highest_inherited_group_by_weight') {
            $all = $this->computeInheritedGroups($pm->getAllGroups(), $pm->getUserGroups($uuid));
            return $this->selectByWeight($pm->getAllGroups(), $all, true) ?? '';
        }
        if ($identifier === 'netherperms_lowest_inherited_group_by_weight') {
            $all = $this->computeInheritedGroups($pm->getAllGroups(), $pm->getUserGroups($uuid));
            return $this->selectByWeight($pm->getAllGroups(), $all, false) ?? '';
        }

        // Expiry for temporary permissions
        if (str_starts_with($identifier, 'netherperms_expiry_time_')) {
            $node = substr($identifier, strlen('netherperms_expiry_time_'));
            $remain = $this->tempPermissionRemaining($pm, $uuid, $node, false);
            return $this->formatRemaining($remain);
        }
        if (str_starts_with($identifier, 'netherperms_inherited_expiry_time_')) {
            $node = substr($identifier, strlen('netherperms_inherited_expiry_time_'));
            $remain = $this->tempPermissionRemaining($pm, $uuid, $node, true);
            return $this->formatRemaining($remain);
        }
        if (str_starts_with($identifier, 'netherperms_group_expiry_time_')) {
            $group = strtolower(substr($identifier, strlen('netherperms_group_expiry_time_')));
            $remain = $this->tempGroupRemaining($pm, $uuid, $group);
            return $this->formatRemaining($remain);
        }
        if (str_starts_with($identifier, 'netherperms_inherited_group_expiry_time_')) {
            $group = strtolower(substr($identifier, strlen('netherperms_inherited_group_expiry_time_')));
            $remain = $this->tempGroupRemaining($pm, $uuid, $group);
            return $this->formatRemaining($remain);
        }

        return null;
    }

    /** @param array<string, array{permissions:array,parents:array,weight?:int,meta:array}> $allGroups */
    private function computeInheritedGroups(array $allGroups, array $direct) : array
    {
        $visited = [];
        $order = [];
        $direct = array_values(array_unique(array_map('strtolower', $direct)));
        $walk = function(string $g) use (&$walk, &$visited, &$order, $allGroups) : void {
            $g = strtolower($g);
            if (isset($visited[$g]) || !isset($allGroups[$g])) return;
            $visited[$g] = true;
            foreach ((array)($allGroups[$g]['parents'] ?? []) as $p) {
                $walk((string)$p);
            }
            $order[] = $g;
        };
        foreach ($direct as $g) $walk($g);
        return array_values(array_unique($order));
    }

    /** @param array<string, array{permissions:array,parents:array,weight?:int,meta:array}> $allGroups */
    private function selectByWeight(array $allGroups, array $groups, bool $highest) : ?string
    {
        $best = null; $bestW = $highest ? PHP_INT_MIN : PHP_INT_MAX;
        foreach (array_map('strtolower', $groups) as $g) {
            $w = (int)($allGroups[strtolower($g)]['weight'] ?? 0);
            if ($highest) {
                if ($w >= $bestW) { $bestW = $w; $best = strtolower($g); }
            } else {
                if ($w <= $bestW) { $bestW = $w; $best = strtolower($g); }
            }
        }
        return $best;
    }

    private function userHasDirectPermission($pm, string $uuid, string $node) : bool
    {
        $user = $pm->getUser($uuid) ?? null;
        if ($user === null) return false;
        $perm = $user['permissions'][$node] ?? null;
        if ($perm === null) return false;
        if (is_bool($perm)) return $perm === true;
        foreach ($perm as $k => $v) {
            if ($k === '__global__') { if ((bool)$v === true) return true; continue; }
            if ((bool)$v === true) return true;
        }
        return false;
    }

    private function userEffectivePermission($pm, string $uuid, string $node) : bool
    {
        $eff = $pm->getEffectivePermissionsForUser($uuid, []);
        return isset($eff[$node]) && $eff[$node] === true;
    }

    private function tempPermissionRemaining($pm, string $uuid, string $node, bool $includeInherited) : int
    {
        $user = $pm->getUser($uuid) ?? null;
        if ($user === null) return 0;
        $now = time();
        $best = 0;
        $temps = (array)($user['temp_permissions'] ?? []);
        foreach ($temps as $ent) {
            if (!is_array($ent)) continue;
            $n = isset($ent['node']) ? (string)$ent['node'] : '';
            $exp = isset($ent['expires']) ? (int)$ent['expires'] : 0;
            if ($n !== '' && $n === $node && $exp > $now) {
                $remain = $exp - $now;
                if ($remain > $best) $best = $remain;
            }
        }
        // includeInherited is unused: group-temp not supported in data model
        return $best;
    }

    private function tempGroupRemaining($pm, string $uuid, string $group) : int
    {
        // Heuristic: treat temp permission nodes like "group.<group>" or "group:<group>" as timed group membership.
        // If your server uses a different convention, you can add it here.
        $user = $pm->getUser($uuid) ?? null;
        if ($user === null) return 0;
        $now = time();
        $best = 0;
        $want = ["group.$group", "group:$group", "netherperms.group.$group"]; // possible node encodings
        $temps = (array)($user['temp_permissions'] ?? []);
        foreach ($temps as $ent) {
            if (!is_array($ent)) continue;
            $n = isset($ent['node']) ? strtolower((string)$ent['node']) : '';
            $exp = isset($ent['expires']) ? (int)$ent['expires'] : 0;
            if ($n !== '' && in_array($n, $want, true) && $exp > $now) {
                $remain = $exp - $now;
                if ($remain > $best) $best = $remain;
            }
        }
        return $best;
    }

    private function formatRemaining(int $seconds) : string
    {
        if ($seconds <= 0) return '';
        $d = intdiv($seconds, 86400); $seconds %= 86400;
        $h = intdiv($seconds, 3600); $seconds %= 3600;
        $m = intdiv($seconds, 60); $s = $seconds % 60;
        $parts = [];
        if ($d > 0) $parts[] = $d . 'd';
        if ($h > 0) $parts[] = $h . 'h';
        if ($m > 0) $parts[] = $m . 'm';
        if ($s > 0 && empty($parts)) $parts[] = $s . 's'; // show seconds only if nothing else
        if ($s > 0 && !empty($parts)) $parts[] = $s . 's';
        // If it's exactly in minutes/hours/days, keep it compact per examples (e.g., 10m, 1h, 244d)
        return implode(' ', $parts);
    }
}
