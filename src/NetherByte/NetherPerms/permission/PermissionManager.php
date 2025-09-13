<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\permission;

use NetherByte\NetherPerms\storage\StorageInterface;
use pocketmine\permission\PermissionManager as PMPermissionManager;

final class PermissionManager
{
    /** @var array{
     *  users: array<string, array{
     *      name: string,
     *      permissions: array<string, bool|array<string,bool>>, // bool or context-map
     *      temp_permissions?: array<int, array{node:string,value:bool,context:string,expires:int}>,
     *      groups: string[],
     *      primary?: string,
     *      meta?: array{prefix?: string, suffix?: string}
     *  }>,
     *  groups: array<string, array{
     *      permissions: array<string, bool|array<string,bool>>, // bool or context-map
     *      parents: string[],
     *      weight?: int,
     *      meta?: array{prefix?: string, suffix?: string}
     *  }>,
     *  tracks: array<string, string[]>
     * }
     */
    private array $data = [
        'users' => [],
        'groups' => [],
        'tracks' => []
    ];

    private bool $denyPrecedence;
    private string $primaryGroupCalcMode = 'parents-by-weight';
    /** @var string[]|null */
    private ?array $registeredCache = null;
    /** @var array<string, array<string,bool>> cache keyed by uuid + ctx key */
    private array $effectiveCache = [];

    public function __construct(private StorageInterface $storage, private string $defaultGroup, bool $denyPrecedence = true, ?string $primaryGroupCalcMode = null)
    {
        $this->defaultGroup = strtolower($this->defaultGroup);
        $this->denyPrecedence = $denyPrecedence;
        if ($primaryGroupCalcMode !== null) {
            $mode = strtolower($primaryGroupCalcMode);
            if (!in_array($mode, ['stored','parents-by-weight','all-parents-by-weight'], true)) {
                $mode = 'parents-by-weight';
            }
            $this->primaryGroupCalcMode = $mode;
        }
    }

    /**
     * Find a known user's UUID by their last known name (case-insensitive).
     */
    public function findUserUuidByName(string $name) : ?string
    {
        $lname = strtolower($name);
        foreach ($this->data['users'] as $uuid => $u) {
            if (strtolower((string)($u['name'] ?? '')) === $lname) return (string)$uuid;
        }
        return null;
    }

    public function load() : void
    {
        $this->storage->reload();
        $this->data['users'] = $this->storage->getUsers();
        $this->data['groups'] = $this->storage->getGroups();
        $this->data['tracks'] = $this->storage->getTracks();
        // Normalize group names to lowercase
        $normalizedGroups = [];
        foreach ($this->data['groups'] as $name => $info) {
            $lname = strtolower($name);
            $info['parents'] = array_values(array_unique(array_map('strtolower', $info['parents'] ?? [])));
            $meta = (array)($info['meta'] ?? []);
            // normalize all meta values to strings
            foreach ($meta as $mk => $mv) { $meta[$mk] = (string)$mv; }
            $normalizedGroups[$lname] = [
                'permissions' => (array)($info['permissions'] ?? []),
                'parents' => $info['parents'],
                'weight' => (int)($info['weight'] ?? 0),
                'meta' => $meta
            ];
        }
        $this->data['groups'] = $normalizedGroups;
        // Normalize tracks: lowercase names, dedupe, and filter to existing groups
        $normalizedTracks = [];
        foreach ($this->data['tracks'] as $tName => $order) {
            $lt = strtolower((string)$tName);
            $order = array_values(array_unique(array_map('strtolower', (array)$order)));
            // keep only groups that exist
            $order = array_values(array_filter($order, fn(string $g) => isset($this->data['groups'][$g])));
            if (!isset($normalizedTracks[$lt])) {
                $normalizedTracks[$lt] = $order;
            } else {
                // merge if duplicated by case
                $normalizedTracks[$lt] = array_values(array_unique(array_merge($normalizedTracks[$lt], $order)));
                // also filter to existing groups again
                $normalizedTracks[$lt] = array_values(array_filter($normalizedTracks[$lt], fn(string $g) => isset($this->data['groups'][$g])));
            }
        }
        $this->data['tracks'] = $normalizedTracks;
        // Normalize user group refs
        foreach ($this->data['users'] as &$user) {
            $user['groups'] = array_values(array_unique(array_map('strtolower', $user['groups'] ?? [])));
            if (!isset($user['meta']) || !is_array($user['meta'])) $user['meta'] = [];
            if (!isset($user['temp_permissions']) || !is_array($user['temp_permissions'])) $user['temp_permissions'] = [];
            // Purge expired temp nodes on load
            $now = time();
            $user['temp_permissions'] = array_values(array_filter((array)$user['temp_permissions'], function($e) use ($now) {
                if (!is_array($e)) return false;
                $exp = isset($e['expires']) ? (int)$e['expires'] : 0;
                $node = isset($e['node']) ? (string)$e['node'] : '';
                // context can be empty for global temp permission
                return $node !== '' && $exp > $now;
            }));
        }
        unset($user);
        // Ensure default group exists
        if (!isset($this->data['groups'][$this->defaultGroup])) {
            $this->data['groups'][$this->defaultGroup] = [
                'permissions' => [
                    'pocketmine.command.help' => true
                ],
                'parents' => [],
                'weight' => 0,
                'meta' => []
            ];
            $this->save();
        }
    }

    public function save() : void
    {
        $this->storage->setUsers($this->data['users']);
        $this->storage->setGroups($this->data['groups']);
        $this->storage->setTracks($this->data['tracks']);
        $this->storage->save();
        // invalidate caches after persistent change
        $this->effectiveCache = [];
    }

    public function getDefaultGroup() : string { return $this->defaultGroup; }

    // Users
    public function userExists(string $uuid) : bool { return isset($this->data['users'][$uuid]); }
    public function createUser(string $uuid, string $name) : void
    {
        $this->data['users'][$uuid] = [
            'name' => $name,
            'permissions' => [],
            'temp_permissions' => [],
            'groups' => [],
            'primary' => null,
            'meta' => []
        ];
    }

    public function addUserGroup(string $uuid, string $group) : bool
    {
        $group = strtolower($group);
        if (!isset($this->data['groups'][$group])) return false;
        $groups = &$this->data['users'][$uuid]['groups'];
        if (!in_array($group, $groups, true)) $groups[] = $group;
        return true;
    }

    public function removeUserGroup(string $uuid, string $group) : void
    {
        $group = strtolower($group);
        $groups = &$this->data['users'][$uuid]['groups'];
        $groups = array_values(array_filter($groups, fn(string $g) => $g !== $group));
        // If primary was removed, unset it
        if (($this->data['users'][$uuid]['primary'] ?? null) === $group) {
            $this->data['users'][$uuid]['primary'] = null;
        }
    }

    /**
     * Set a user permission, optionally with context conditions.
     * If $context is empty, stores a global boolean. Otherwise stores under a normalized context key.
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    public function setUserPermission(string $uuid, string $node, bool $value, array $context = []) : void
    {
        if (empty($context)) {
            $this->data['users'][$uuid]['permissions'][$node] = $value;
            return;
        }
        $key = $this->makeContextKey($context);
        $current = $this->data['users'][$uuid]['permissions'][$node] ?? null;
        if (!is_array($current)) {
            $current = is_bool($current) ? ['__global__' => $current] : [];
        }
        $current[$key] = $value;
        $this->data['users'][$uuid]['permissions'][$node] = $current;
    }

    public function unsetUserPermission(string $uuid, string $node) : void
    {
        unset($this->data['users'][$uuid]['permissions'][$node]);
    }

    // Groups
    public function createGroup(string $group) : void
    {
        $group = strtolower($group);
        $this->data['groups'][$group] = [
            'permissions' => [],
            'parents' => [],
            'weight' => 0,
            'meta' => []
        ];
        $this->syncGroupMetaNodes($group);
    }

    public function deleteGroup(string $group) : void
    {
        $group = strtolower($group);
        unset($this->data['groups'][$group]);
        // Remove from all users and unset primary if matching
        foreach ($this->data['users'] as &$user) {
            $user['groups'] = array_values(array_filter((array)($user['groups'] ?? []), fn(string $g) => strtolower($g) !== $group));
            if (isset($user['primary']) && strtolower((string)$user['primary']) === $group) {
                unset($user['primary']);
            }
        }
        unset($user);
        // Remove from parents of other groups
        foreach ($this->data['groups'] as &$gd) {
            $gd['parents'] = array_values(array_filter((array)($gd['parents'] ?? []), fn(string $p) => strtolower($p) !== $group));
        }
        unset($gd);
        // Remove from all tracks
        foreach ($this->data['tracks'] as $t => &$order) {
            $order = array_values(array_filter((array)$order, fn(string $g) => strtolower($g) !== $group));
        }
        unset($order);
    }

    /**
     * Set a group permission, optionally with context conditions.
     * If $context is empty, stores a global boolean. Otherwise stores under a normalized context key.
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    public function setGroupPermission(string $group, string $node, bool $value, array $context = []) : void
    {
        $group = strtolower($group);
        if (empty($context)) {
            $this->data['groups'][$group]['permissions'][$node] = $value;
            return;
        }
        $key = $this->makeContextKey($context);
        $current = $this->data['groups'][$group]['permissions'][$node] ?? null;
        if (!is_array($current)) {
            $current = is_bool($current) ? ['__global__' => $current] : [];
        }
        $current[$key] = $value;
        $this->data['groups'][$group]['permissions'][$node] = $current;
    }

    public function unsetGroupPermission(string $group, string $node) : void
    {
        $group = strtolower($group);
        unset($this->data['groups'][$group]['permissions'][$node]);
    }

    /**
     * Unset only a specific context-mapped value for a group permission node.
     * If the node becomes empty after removal, it is fully unset. If only a __global__ remains,
     * the node is flattened back to a boolean.
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    public function unsetGroupPermissionContext(string $group, string $node, array $context) : void
    {
        $group = strtolower($group);
        $current = $this->data['groups'][$group]['permissions'][$node] ?? null;
        if ($current === null) return;
        if (is_bool($current)) {
            // nothing to unset specifically; treat as full unset if context empty
            if (empty($context)) {
                unset($this->data['groups'][$group]['permissions'][$node]);
            }
            return;
        }
        $key = $this->makeContextKey($context);
        unset($current[$key]);
        // normalize remainder
        if (empty($current)) {
            unset($this->data['groups'][$group]['permissions'][$node]);
            return;
        }
        if (count($current) === 1 && array_key_exists('__global__', $current)) {
            $this->data['groups'][$group]['permissions'][$node] = (bool)$current['__global__'];
            return;
        }
        $this->data['groups'][$group]['permissions'][$node] = $current;
    }

    public function addParent(string $group, string $parent) : bool
    {
        $group = strtolower($group);
        $parent = strtolower($parent);
        if (!isset($this->data['groups'][$parent])) return false;
        $parents = &$this->data['groups'][$group]['parents'];
        if (!in_array($parent, $parents, true)) $parents[] = $parent;
        return true;
    }

    public function removeParent(string $group, string $parent) : void
    {
        $group = strtolower($group);
        $parent = strtolower($parent);
        $parents = &$this->data['groups'][$group]['parents'];
        $parents = array_values(array_filter($parents, fn(string $g) => $g !== $parent));
    }

    // Back-compat wrappers used by UI layer
    public function addGroupParent(string $group, string $parent) : void { $this->addParent($group, $parent); }
    public function removeGroupParent(string $group, string $parent) : void { $this->removeParent($group, $parent); }

    /**
     * Resolve effective permissions for a user (groups with inheritance, overridden by user perms)
     * @return array<string,bool>
     */
    /**
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    public function getEffectivePermissionsForUser(string $uuid, array $context = []) : array
    {
        $ckey = $this->makeContextKey($context);
        $cacheKey = $uuid . '|' . $ckey;
        // Purge expired temps first; this may mutate user data
        $this->purgeExpiredForUser($uuid);
        // Determine if user currently has any active temp nodes; if so, bypass cache
        $hasTemp = !empty($this->data['users'][$uuid]['temp_permissions']);
        if (!$hasTemp && isset($this->effectiveCache[$cacheKey])) return $this->effectiveCache[$cacheKey];
        /** @var array<string, array<int, array{value:bool,spec:int,source:string}>> $candidates */
        $candidates = [];
        // Build group traversal order (parents first), including:
        // - groups the user is a member of
        // - groups granted via explicit user permission nodes like `group.<name>` (context-aware)
        $visited = [];
        $order = [];
        // 1) from membership
        foreach (($this->data['users'][$uuid]['groups'] ?? []) as $g) {
            $this->traverseGroupOrder(strtolower($g), $visited, $order);
        }
        // 2) from explicit user permission nodes `group.<name>` granted true in this context
        foreach (($this->data['users'][$uuid]['permissions'] ?? []) as $node => $value) {
            if (str_starts_with($node, 'group.')) {
                $groupName = strtolower(substr($node, strlen('group.')));
                if ($groupName !== '' && isset($this->data['groups'][$groupName])) {
                    $res = $this->resolveWithSpecificity($value, $context);
                    if ($res !== null && $res['value'] === true) {
                        $this->traverseGroupOrder($groupName, $visited, $order);
                    }
                }
            }
        }
        // Collect group candidates (parents first) and expose LuckPerms-style group.<name> nodes
        foreach ($order as $g) {
            // expose virtual `group.<name>` node as true
            $candidates['group.' . $g][] = ['value' => true, 'spec' => 0, 'source' => 'group-meta'];
            // include group's permission set
            foreach (($this->data['groups'][$g]['permissions'] ?? []) as $node => $value) {
                $res = $this->resolveWithSpecificity($value, $context);
                if ($res === null) continue;
                $candidates[$node][] = ['value' => $res['value'], 'spec' => $res['spec'], 'source' => 'group'];
            }
        }
        // Collect user candidates (processed after groups so equal-specificity can override when deny-precedence is false)
        foreach (($this->data['users'][$uuid]['permissions'] ?? []) as $node => $value) {
            $res = $this->resolveWithSpecificity($value, $context);
            if ($res === null) continue;
            $candidates[$node][] = ['value' => $res['value'], 'spec' => $res['spec'], 'source' => 'user'];
        }
        // Collect temporary user candidates (only non-expired)
        $temps = (array)($this->data['users'][$uuid]['temp_permissions'] ?? []);
        foreach ($temps as $ent) {
            if (!is_array($ent)) continue;
            $node = isset($ent['node']) ? (string)$ent['node'] : '';
            $val = isset($ent['value']) ? (bool)$ent['value'] : null;
            $exp = isset($ent['expires']) ? (int)$ent['expires'] : 0;
            $ck = isset($ent['context']) ? (string)$ent['context'] : '';
            if ($node === '' || $val === null || $exp <= time()) continue;
            $conds = $this->parseContextKey($ck);
            if ($this->contextMatches($conds, $context)) {
                $spec = count($conds);
                $candidates[$node][] = ['value' => (bool)$val, 'spec' => $spec, 'source' => 'user-temp'];
            }
        }
        // Decide per node by highest specificity. If tie:
        // - Prefer user-temp entries over others (so temp grants can override group denies at equal specificity)
        // - Then apply deny precedence or last-added wins among the remaining set
        $effective = [];
        foreach ($candidates as $node => $list) {
            $bestSpec = -1;
            foreach ($list as $ent) { if ($ent['spec'] > $bestSpec) $bestSpec = $ent['spec']; }
            $top = array_values(array_filter($list, fn($e) => $e['spec'] === $bestSpec));
            // Prefer user-temp entries if present at this specificity
            $hasUserTempAtTop = false;
            foreach ($top as $ent) { if (($ent['source'] ?? '') === 'user-temp') { $hasUserTempAtTop = true; break; } }
            if ($hasUserTempAtTop) {
                $top = array_values(array_filter($top, fn($e) => ($e['source'] ?? '') === 'user-temp'));
            }
            $value = null;
            if ($this->denyPrecedence) {
                // if any deny at top specificity, result is false; else true if any allow
                $hasDeny = false; $hasAllow = false;
                foreach ($top as $ent) { if ($ent['value'] === false) $hasDeny = true; else $hasAllow = true; }
                if ($hasDeny) { $value = false; }
                elseif ($hasAllow) { $value = true; }
            } else {
                // last one wins (user processed after groups)
                $value = $top[count($top)-1]['value'] ?? null;
            }
            if ($value !== null) { $effective[$node] = (bool)$value; }
        }
        // Expand wildcards to concrete registered permissions, without overriding explicit nodes
        $expanded = $this->expandWildcardPermissions($effective);
        // Cache only when not time-sensitive (no active temp permissions)
        if (!$hasTemp) {
            $this->effectiveCache[$cacheKey] = $expanded;
        }
        return $expanded;
    }

    /**
     * Compute the user's primary group according to configured strategy.
     * - stored: return stored primary (can be null)
     * - parents-by-weight: choose highest weight among direct groups
     * - all-parents-by-weight: choose highest weight among all inherited groups (direct + parents)
     */
    public function getComputedPrimaryGroup(string $uuid) : ?string
    {
        $mode = $this->primaryGroupCalcMode;
        $user = $this->data['users'][$uuid] ?? null;
        if ($user === null) return null;
        $groups = array_values(array_map('strtolower', (array)($user['groups'] ?? [])));
        if ($mode === 'stored') {
            $p = $user['primary'] ?? null;
            return is_string($p) && $p !== '' ? strtolower($p) : null;
        }
        if (empty($groups)) return null;
        $candidates = [];
        if ($mode === 'parents-by-weight') {
            $candidates = array_values(array_unique($groups));
        } elseif ($mode === 'all-parents-by-weight') {
            // collect all inherited groups via traversal
            $visited = [];
            $order = [];
            foreach ($groups as $g) { $this->traverseGroupOrder($g, $visited, $order); }
            $candidates = array_values(array_unique($order));
        } else {
            $candidates = array_values(array_unique($groups));
        }
        $best = null; $bestWeight = PHP_INT_MIN;
        foreach ($candidates as $g) {
            $w = (int)($this->data['groups'][$g]['weight'] ?? 0);
            if ($w >= $bestWeight) { $bestWeight = $w; $best = $g; }
        }
        return $best;
    }

    /** Allow runtime change of primary group calc mode (e.g., on reload). */
    public function setPrimaryGroupCalcMode(string $mode) : void
    {
        $mode = strtolower($mode);
        if (!in_array($mode, ['stored','parents-by-weight','all-parents-by-weight'], true)) {
            $mode = 'parents-by-weight';
        }
        $this->primaryGroupCalcMode = $mode;
    }

    /**
     * Resolve an arbitrary meta key for a user with priority:
     * user meta > primary group's meta > highest-weight group's meta.
     */
    public function getResolvedMeta(string $uuid, string $key) : ?string
    {
        $user = $this->data['users'][$uuid] ?? null;
        if ($user !== null) {
            $um = (array)($user['meta'] ?? []);
            if (isset($um[$key]) && $um[$key] !== '') return (string)$um[$key];
            $primary = $this->getComputedPrimaryGroup($uuid);
            if (is_string($primary) && $primary !== '') {
                $gm = (array)($this->data['groups'][$primary]['meta'] ?? []);
                if (isset($gm[$key]) && $gm[$key] !== '') return (string)$gm[$key];
            }
            $best = null; $bestWeight = PHP_INT_MIN;
            foreach ((array)($user['groups'] ?? []) as $g) {
                $w = (int)($this->data['groups'][$g]['weight'] ?? 0);
                $val = $this->data['groups'][$g]['meta'][$key] ?? null;
                if ($val !== null && $val !== '' && $w >= $bestWeight) { $bestWeight = $w; $best = (string)$val; }
            }
            return $best;
        }
        return null;
    }

    public function getResolvedPrefix(string $uuid) : ?string {
        // user meta wins; else primary group's prefix; else highest-weight group's prefix
        $user = $this->data['users'][$uuid] ?? null;
        if ($user !== null && !empty($user['meta']['prefix'])) return (string)$user['meta']['prefix'];
        $primary = $this->getComputedPrimaryGroup($uuid);
        if (is_string($primary) && $primary !== '') {
            $pfx = $this->data['groups'][$primary]['meta']['prefix'] ?? null;
            if ($pfx !== null && $pfx !== '') return (string)$pfx;
        }
        // Fallback to highest-weight group prefix among direct groups
        $best = null; $bestWeight = PHP_INT_MIN;
        foreach ((array)($user['groups'] ?? []) as $g) {
            $w = (int)($this->data['groups'][$g]['weight'] ?? 0);
            $p = $this->data['groups'][$g]['meta']['prefix'] ?? null;
            if ($p !== null && $p !== '' && $w >= $bestWeight) { $bestWeight = $w; $best = (string)$p; }
        }
        return $best;
    }

    public function getResolvedSuffix(string $uuid) : ?string {
        $user = $this->data['users'][$uuid] ?? null;
        if ($user !== null && !empty($user['meta']['suffix'])) return (string)$user['meta']['suffix'];
        // Prefer computed primary group's suffix if set
        $primary = $this->getComputedPrimaryGroup($uuid);
        if (is_string($primary) && $primary !== '') {
            $sfx = $this->data['groups'][$primary]['meta']['suffix'] ?? null;
            if ($sfx !== null && $sfx !== '') return (string)$sfx;
        }
        // Fallback to highest-weight group suffix among direct groups
        $best = null; $bestWeight = PHP_INT_MIN;
        foreach ((array)($user['groups'] ?? []) as $g) {
            $w = (int)($this->data['groups'][$g]['weight'] ?? 0);
            $s = $this->data['groups'][$g]['meta']['suffix'] ?? null;
            if ($s !== null && $s !== '' && $w >= $bestWeight) { $bestWeight = $w; $best = (string)$s; }
        }
        return $best;
    }

    /**
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    private function traverseGroupOrder(string $group, array &$visited, array &$order) : void
    {
        $group = strtolower($group);
        if (isset($visited[$group]) || !isset($this->data['groups'][$group])) return;
        $visited[$group] = true;
        foreach (($this->data['groups'][$group]['parents'] ?? []) as $parent) {
            $this->traverseGroupOrder(strtolower((string)$parent), $visited, $order);
        }
        $order[] = $group;
    }

    // Info helpers
    public function getUser(string $uuid) : ?array { return $this->data['users'][$uuid] ?? null; }
    public function getGroup(string $group) : ?array { $group = strtolower($group); return $this->data['groups'][$group] ?? null; }
    public function getUserGroups(string $uuid) : array { return $this->data['users'][$uuid]['groups'] ?? []; }
    public function getAllGroups() : array { return $this->data['groups']; }
    public function getTracks() : array { return $this->data['tracks']; }
    public function createTrack(string $track) : void { $this->data['tracks'][strtolower($track)] = $this->data['tracks'][strtolower($track)] ?? []; }
    public function deleteTrack(string $track) : void { unset($this->data['tracks'][strtolower($track)]); }
    /** @return array<string,array{name:string,groups:array,permissions:array,meta:array}> */
    public function getAllUsers() : array { return $this->data['users']; }
    public function getUserName(string $uuid) : ?string { return isset($this->data['users'][$uuid]['name']) ? (string)$this->data['users'][$uuid]['name'] : null; }
    public function getPrimaryGroup(string $uuid) : ?string { $p = $this->data['users'][$uuid]['primary'] ?? null; return $p !== null ? (string)$p : null; }
    public function setPrimaryGroup(string $uuid, ?string $group) : bool {
        if ($group === null || $group === '') { $this->data['users'][$uuid]['primary'] = null; return true; }
        $group = strtolower($group);
        if (!isset($this->data['groups'][$group])) return false;
        // Ensure user is a member of the group
        $ug = &$this->data['users'][$uuid]['groups'];
        if (!in_array($group, $ug, true)) $ug[] = $group;
        $this->data['users'][$uuid]['primary'] = $group;
        return true;
    }
    public function unsetPrimaryGroup(string $uuid) : void {
        // Explicit method for clarity and future hooks
        $this->data['users'][$uuid]['primary'] = null;
    }
    /**
     * @return string[] list of unknown groups; if empty, track was set.
     */
    public function setTrack(string $track, array $groups) : array {
        $track = strtolower($track);
        $groups = array_values(array_unique(array_map('strtolower', $groups)));
        $unknown = array_values(array_filter($groups, fn(string $g) => !isset($this->data['groups'][$g])));
        if (!empty($unknown)) {
            return $unknown;
        }
        $this->data['tracks'][$track] = $groups;
        return [];
    }

    /**
     * Insert a group into a track at a 1-based position. Returns null on success,
     * or an error message string on failure.
     */
    public function insertGroupIntoTrack(string $track, string $group, int $position) : ?string {
        $track = strtolower($track); $group = strtolower($group);
        if (!isset($this->data['tracks'][$track])) return 'Track not found';
        if (!isset($this->data['groups'][$group])) return 'Group not found';
        $order = (array)($this->data['tracks'][$track] ?? []);
        if (in_array($group, $order, true)) return 'already-contains';
        $position = max(1, $position);
        $position = min($position, count($order) + 1);
        array_splice($order, $position - 1, 0, [$group]);
        $this->data['tracks'][$track] = array_values($order);
        return null;
    }

    public function removeGroupFromTrack(string $track, string $group) : ?string {
        $track = strtolower($track); $group = strtolower($group);
        if (!isset($this->data['tracks'][$track])) return 'Track not found';
        $order = (array)($this->data['tracks'][$track] ?? []);
        $idx = array_search($group, $order, true);
        if ($idx === false) return 'not-present';
        array_splice($order, (int)$idx, 1);
        $this->data['tracks'][$track] = array_values($order);
        return null;
    }

    public function appendGroupToTrack(string $track, string $group) : ?string {
        $track = strtolower($track); $group = strtolower($group);
        if (!isset($this->data['tracks'][$track])) return 'Track not found';
        if (!isset($this->data['groups'][$group])) return 'Group not found';
        $order = (array)($this->data['tracks'][$track] ?? []);
        if (in_array($group, $order, true)) return 'already-contains';
        $order[] = $group;
        $this->data['tracks'][$track] = array_values($order);
        return null;
    }

    // Maintenance helpers: rename/clone groups & tracks, manage track members
    public function renameGroup(string $old, string $new) : bool {
        $old = strtolower($old); $new = strtolower($new);
        if ($old === $new) return true;
        if (!isset($this->data['groups'][$old])) return false;
        if (isset($this->data['groups'][$new])) return false;
        // Move group data
        $this->data['groups'][$new] = $this->data['groups'][$old];
        unset($this->data['groups'][$old]);
        // Update users' group memberships and primary group
        foreach ($this->data['users'] as $uid => &$u) {
            $u['groups'] = array_map(fn(string $g) => strtolower($g) === $old ? $new : $g, (array)($u['groups'] ?? []));
            if (($u['primary'] ?? null) !== null && strtolower((string)$u['primary']) === $old) {
                $u['primary'] = $new;
            }
        }
        unset($u);
        // Update parents lists of other groups
        foreach ($this->data['groups'] as $g => &$gd) {
            $gd['parents'] = array_map(fn(string $p) => strtolower($p) === $old ? $new : $p, (array)($gd['parents'] ?? []));
        }
        unset($gd);
        // Update tracks contents
        foreach ($this->data['tracks'] as $t => &$order) {
            $order = array_map(fn(string $g) => strtolower($g) === $old ? $new : $g, (array)$order);
        }
        unset($order);
        return true;
    }

    public function cloneGroup(string $source, string $target) : bool {
        $source = strtolower($source); $target = strtolower($target);
        if (!isset($this->data['groups'][$source])) return false;
        if (isset($this->data['groups'][$target])) return false;
        $this->data['groups'][$target] = $this->data['groups'][$source];
        return true;
    }

    public function renameTrack(string $old, string $new) : bool {
        $old = strtolower($old); $new = strtolower($new);
        if ($old === $new) return true;
        if (!isset($this->data['tracks'][$old])) return false;
        if (isset($this->data['tracks'][$new])) return false;
        $this->data['tracks'][$new] = $this->data['tracks'][$old];
        unset($this->data['tracks'][$old]);
        return true;
    }

    public function cloneTrack(string $source, string $target) : bool {
        $source = strtolower($source); $target = strtolower($target);
        if (!isset($this->data['tracks'][$source])) return false;
        if (isset($this->data['tracks'][$target])) return false;
        $this->data['tracks'][$target] = $this->data['tracks'][$source];
        return true;
    }

    // Weights
    public function setGroupWeight(string $group, int $weight) : void {
        $group = strtolower($group);
        if (!isset($this->data['groups'][$group])) return;
        $this->data['groups'][$group]['weight'] = $weight;
        $this->syncGroupMetaNodes($group);
    }
    public function getGroupWeight(string $group) : int {
        $group = strtolower($group);
        return (int)($this->data['groups'][$group]['weight'] ?? 0);
    }

    // Meta
    public function setGroupMeta(string $group, string $key, string $value) : void {
        $group = strtolower($group);
        $this->data['groups'][$group]['meta'][$key] = $value;
        $this->syncGroupMetaNodes($group);
    }
    public function unsetGroupMeta(string $group, string $key) : void {
        $group = strtolower($group);
        unset($this->data['groups'][$group]['meta'][$key]);
        $this->syncGroupMetaNodes($group);
    }
    public function setUserMeta(string $uuid, string $key, string $value) : void {
        $this->data['users'][$uuid]['meta'][$key] = $value;
    }
    public function unsetUserMeta(string $uuid, string $key) : void {
        unset($this->data['users'][$uuid]['meta'][$key]);
    }

    /**
     * Ensure group permissions contain LuckPerms-style nodes for weight/prefix/suffix.
     * Regenerates these nodes whenever weight or meta changes.
     */
    private function syncGroupMetaNodes(string $group) : void
    {
        $group = strtolower($group);
        if (!isset($this->data['groups'][$group])) return;
        $perms = &$this->data['groups'][$group]['permissions'];
        // Remove existing generated nodes
        foreach (array_keys($perms) as $node) {
            if (str_starts_with($node, 'weight.') || str_starts_with($node, 'prefix.') || str_starts_with($node, 'suffix.')) {
                unset($perms[$node]);
            }
        }
        $meta = $this->data['groups'][$group]['meta'] ?? [];
        $weight = (int)($this->data['groups'][$group]['weight'] ?? 0);
        if ($weight > 0) {
            $perms['weight.' . $weight] = true;
        }
        $priority = $weight > 0 ? $weight : 1;
        $prefix = $meta['prefix'] ?? null;
        $suffix = $meta['suffix'] ?? null;
        $prefixCat = $meta['prefix-category'] ?? null;
        $suffixCat = $meta['suffix-category'] ?? null;
        if ($prefix !== null && $prefix !== '') {
            $node = $prefixCat !== null && $prefixCat !== ''
                ? ('prefix.' . $priority . '.' . $prefixCat . '.' . $prefix)
                : ('prefix.' . $priority . '.' . $prefix);
            $perms[$node] = true;
        }
        if ($suffix !== null && $suffix !== '') {
            $node = $suffixCat !== null && $suffixCat !== ''
                ? ('suffix.' . $priority . '.' . $suffixCat . '.' . $suffix)
                : ('suffix.' . $priority . '.' . $suffix);
            $perms[$node] = true;
        }
    }

    /**
     * Expand nodes ending with ".*" to all registered permissions with that prefix.
     * Does not overwrite explicitly specified concrete nodes.
     * Removes wildcard entries from the final result.
     * @param array<string,bool> $perms
     * @return array<string,bool>
     */
    private function expandWildcardPermissions(array $perms) : array
    {
        $wildcards = [];
        foreach ($perms as $node => $val) {
            if (str_ends_with($node, '.*')) {
                $wildcards[$node] = (bool)$val;
            }
        }
        if (empty($wildcards)) return $perms;
        // Get all registered permission names from PMMP (cached)
        if ($this->registeredCache === null) {
            $registered = [];
            $pm = PMPermissionManager::getInstance();
            foreach ($pm->getPermissions() as $perm) { // Permission objects
                $registered[] = $perm->getName();
            }
            $this->registeredCache = $registered;
        } else {
            $registered = $this->registeredCache;
        }
        // Apply each wildcard
        foreach ($wildcards as $wc => $val) {
            $prefix = substr($wc, 0, -2); // drop .*
            foreach ($registered as $name) {
                if (str_starts_with($name, $prefix)) {
                    if (!array_key_exists($name, $perms)) {
                        $perms[$name] = $val;
                    }
                }
            }
            // remove wildcard key itself
            unset($perms[$wc]);
        }
        return $perms;
    }

    /**
     * Normalize context into a stable sorted key like "gamemode=survival;world=hub;dimension=overworld".
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    private function makeContextKey(array $context) : string
    {
        $allowed = ['world','gamemode'];
        $parts = [];
        foreach ($allowed as $k) {
            if (isset($context[$k]) && $context[$k] !== '') {
                $parts[$k] = strtolower((string)$context[$k]);
            }
        }
        ksort($parts);
        $chunks = [];
        foreach ($parts as $k => $v) { $chunks[] = $k.'='.$v; }
        return implode(';', $chunks);
    }

    /**
     * Resolve a stored permission value (bool or context map) to an actual boolean for the given context.
     * Returns null if no matching rule exists.
     * @param bool|array<string,bool> $value
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    private function resolveWithSpecificity(bool|array $value, array $context) : ?array
    {
        if (is_bool($value)) return ['value' => $value, 'spec' => 0];
        $bestVal = null; $bestCount = -1;
        foreach ($value as $key => $val) {
            if ($key === '__global__') continue;
            $conds = $this->parseContextKey($key);
            if ($this->contextMatches($conds, $context)) {
                $count = count($conds);
                if ($count >= $bestCount) { $bestCount = $count; $bestVal = (bool)$val; }
            }
        }
        if ($bestVal !== null) return ['value' => $bestVal, 'spec' => $bestCount];
        if (isset($value['__global__'])) return ['value' => (bool)$value['__global__'], 'spec' => 0];
        return null;
    }

    /** @return array<string,string> */
    private function parseContextKey(string $key) : array
    {
        $out = [];
        // Support both ';' and ',' as separators
        $key = str_replace(', ', ',', $key);
        $parts = preg_split('/[;,]/', $key);
        if ($parts === false) $parts = [];
        foreach ($parts as $pair) {
            if ($pair === '') continue;
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $out[strtolower($parts[0])] = strtolower($parts[1]);
            }
        }
        return $out;
    }

    /** @param array<string,string> $conds */
    private function contextMatches(array $conds, array $context) : bool
    {
        foreach ($conds as $k => $v) {
            if (!isset($context[$k])) return false;
            if (strtolower((string)$context[$k]) !== $v) return false;
        }
        return true;
    }

    /**
     * Add a temporary user permission node with a duration in seconds.
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    public function addUserTempPermission(string $uuid, string $node, bool $value, int $durationSeconds, array $context = []) : void
    {
        if ($durationSeconds <= 0) return;
        $ckey = $this->makeContextKey($context);
        $expires = time() + $durationSeconds;
        if (!isset($this->data['users'][$uuid]['temp_permissions']) || !is_array($this->data['users'][$uuid]['temp_permissions'])) {
            $this->data['users'][$uuid]['temp_permissions'] = [];
        }
        // Remove any existing identical entry to avoid duplicates
        $this->data['users'][$uuid]['temp_permissions'] = array_values(array_filter(
            (array)$this->data['users'][$uuid]['temp_permissions'],
            function($e) use ($node, $ckey) {
                return !is_array($e) || (isset($e['node']) && $e['node'] === $node && isset($e['context']) && $e['context'] === $ckey) ? false : true;
            }
        ));
        $this->data['users'][$uuid]['temp_permissions'][] = [
            'node' => $node,
            'value' => $value,
            'context' => $ckey,
            'expires' => $expires
        ];
    }

    /**
     * Unset a temporary user permission node. If context provided, remove only that context entry; else remove all temp entries for the node.
     * @param array{world?:string,gamemode?:string,dimension?:string} $context
     */
    public function unsetUserTempPermission(string $uuid, string $node, array $context = []) : void
    {
        $ckey = $this->makeContextKey($context);
        $list = (array)($this->data['users'][$uuid]['temp_permissions'] ?? []);
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) continue;
            if (!isset($e['node'])) continue;
            if ($e['node'] !== $node) { $out[] = $e; continue; }
            if ($ckey !== '' && isset($e['context']) && $e['context'] !== $ckey) { $out[] = $e; continue; }
            // else drop
        }
        $this->data['users'][$uuid]['temp_permissions'] = array_values($out);
    }

    private function purgeExpiredForUser(string $uuid) : void
    {
        $list = (array)($this->data['users'][$uuid]['temp_permissions'] ?? []);
        if (empty($list)) return;
        $now = time();
        $this->data['users'][$uuid]['temp_permissions'] = array_values(array_filter($list, function($e) use ($now) {
            return is_array($e) && isset($e['expires']) && (int)$e['expires'] > $now && isset($e['node']) && $e['node'] !== '' && isset($e['context']);
        }));
    }

    /**
     * Promote a user one step forward on a track. Returns ['next' => group] on success or null on failure.
     */
    public function promote(string $uuid, string $track) : ?array
    {
        $track = strtolower($track);
        $order = $this->data['tracks'][$track] ?? null;
        if ($order === null) return null;
        $order = array_values((array)$order);
        if (empty($order)) return null;

        $userGroups = array_map('strtolower', (array)($this->data['users'][$uuid]['groups'] ?? []));
        // Find the highest index the user currently has on this track
        $pos = -1;
        foreach ($order as $i => $g) {
            if (in_array(strtolower((string)$g), $userGroups, true)) $pos = max($pos, $i);
        }
        $nextIndex = $pos + 1;
        if ($nextIndex >= count($order)) return null; // already at top or not on track with nothing to promote to
        $next = (string)$order[$nextIndex];
        $this->addUserGroup($uuid, $next);
        $this->setPrimaryGroup($uuid, $next);
        return ['next' => $next];
    }

    /**
     * Demote a user one step backward on a track. Returns ['next' => group] on success or null on failure.
     */
    public function demote(string $uuid, string $track) : ?array
    {
        $track = strtolower($track);
        $order = $this->data['tracks'][$track] ?? null;
        if ($order === null) return null;
        $order = array_values((array)$order);
        if (empty($order)) return null;

        $userGroups = array_map('strtolower', (array)($this->data['users'][$uuid]['groups'] ?? []));
        // Find the highest index the user currently has on this track
        $pos = -1;
        foreach ($order as $i => $g) {
            if (in_array(strtolower((string)$g), $userGroups, true)) $pos = max($pos, $i);
        }
        if ($pos <= 0) return null; // not on track or already at bottom
        $prev = (string)$order[$pos - 1];
        $this->addUserGroup($uuid, $prev);
        $this->setPrimaryGroup($uuid, $prev);
        return ['next' => $prev];
    }

}
