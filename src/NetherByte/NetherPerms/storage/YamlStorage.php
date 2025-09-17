<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\storage;

use pocketmine\utils\Config;

final class YamlStorage implements StorageInterface
{
    private ?Config $usersCfg = null; // legacy, only if file exists
    private ?Config $groupsCfg = null; // legacy, only if file exists

    // New layout
    private string $baseDir;
    private string $groupsDir;
    private string $tracksDir;
    private string $usersDir;

    // In-memory data buffers
    /** @var array<string,mixed> */
    private array $usersData = [];
    /** @var array<string,mixed> */
    private array $groupsData = [];
    /** @var array<string,mixed> */
    private array $tracksData = [];

    public function __construct(private string $usersPath, private string $groupsPath)
    {
        // Determine directories from the groups file location
        $this->baseDir = \dirname($groupsPath);
        $this->groupsDir = $this->baseDir . DIRECTORY_SEPARATOR . 'groups';
        $this->tracksDir = $this->baseDir . DIRECTORY_SEPARATOR . 'tracks';
        $this->usersDir  = $this->baseDir . DIRECTORY_SEPARATOR . 'users';

        // Ensure directories exist (adopt new layout by default)
        if (!is_dir($this->groupsDir)) @mkdir($this->groupsDir, 0777, true);
        if (!is_dir($this->tracksDir)) @mkdir($this->tracksDir, 0777, true);
        if (!is_dir($this->usersDir))  @mkdir($this->usersDir, 0777, true);

        $this->reload();
    }

    /**
     * Normalize meta_context from YAML to strings
     * @param array<string,mixed> $map
     * @return array<string,array<string,string>>
     */
    private function normalizeMetaContextRead(array $map) : array
    {
        $out = [];
        foreach ($map as $key => $ctxMap) {
            if (!is_array($ctxMap)) continue; $key = (string)$key; $out[$key] = [];
            foreach ($ctxMap as $ck => $val) { $out[$key][(string)$ck] = (string)$val; }
        }
        return $out;
    }

    /**
     * Normalize temp_meta entries list to absolute expires
     * @param array<int,mixed> $list
     * @return array<int,array{key:string,value:string,context:string,expires:int}>
     */
    private function normalizeTempMetaRead(array $list) : array
    {
        $now = time(); $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) continue;
            $key = isset($e['key']) ? (string)$e['key'] : '';
            $val = isset($e['value']) ? (string)$e['value'] : '';
            $ctx = isset($e['context']) ? (string)$e['context'] : '';
            if ($key === '' || $val === '') continue;
            $exp = isset($e['remaining']) ? ($now + max(0, (int)$e['remaining'])) : (int)($e['expires'] ?? 0);
            if ($exp <= $now) continue;
            $out[] = ['key'=>$key,'value'=>$val,'context'=>$ctx,'expires'=>$exp];
        }
        return $out;
    }

    /**
     * Prepare meta_context for YAML
     * @param array<string,array<string,string>> $map
     */
    private function prepareMetaContextForWrite(array $map) : array
    {
        $out = [];
        foreach ($map as $key => $ctxMap) {
            $key = (string)$key; $out[$key] = [];
            foreach ((array)$ctxMap as $ck => $val) { $out[$key][(string)$ck] = (string)$val; }
        }
        return $out;
    }

    /**
     * Prepare temp_meta entries for YAML using remaining seconds
     * @param array<int,mixed> $list
     * @return array<int,array{key:string,value:string,context:string,remaining:int}>
     */
    private function prepareTempMetaForWrite(array $list) : array
    {
        $now = time(); $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) continue;
            $key = isset($e['key']) ? (string)$e['key'] : '';
            $val = isset($e['value']) ? (string)$e['value'] : '';
            $ctx = isset($e['context']) ? (string)$e['context'] : '';
            $exp = isset($e['expires']) ? (int)$e['expires'] : 0;
            if ($key === '' || $val === '' || $exp <= $now) continue;
            $out[] = ['key'=>$key,'value'=>$val,'context'=>$ctx,'remaining'=>max(0, $exp - $now)];
        }
        return $out;
    }

    public function reload() : void
    {
        // If any of the new directories contain data, prefer loading per-entity files.
        $hasNewData = $this->dirHasYaml($this->groupsDir) || $this->dirHasYaml($this->tracksDir) || $this->dirHasYaml($this->usersDir);
        if ($hasNewData) {
            $this->groupsData = $this->readGroupsDir($this->groupsDir);
            $this->tracksData = $this->readTracksDir($this->tracksDir);
            $this->usersData  = $this->readUsersDir($this->usersDir);
            // Ensure default group exists if groups dir is empty
            if (empty($this->groupsData)) {
                $this->groupsData['default'] = [
                    'permissions' => [],
                    'parents' => [],
                    'weight' => 0,
                    'meta' => [],
                ];
                $this->writeGroupsDir($this->groupsDir, $this->groupsData);
            }
            return;
        }

        // Fallback to legacy aggregate files ONLY if they exist
        $usersExists = is_file($this->usersPath);
        $groupsExists = is_file($this->groupsPath);
        if ($usersExists) { $this->usersCfg = new Config($this->usersPath, Config::YAML); }
        if ($groupsExists) { $this->groupsCfg = new Config($this->groupsPath, Config::YAML); }
        if ($this->usersCfg !== null || $this->groupsCfg !== null) {
            if ($this->usersCfg !== null) $this->usersCfg->reload();
            if ($this->groupsCfg !== null) $this->groupsCfg->reload();
            $this->usersData  = (array)($this->usersCfg?->get('users', []) ?? []);
            $this->groupsData = (array)($this->groupsCfg?->get('groups', []) ?? []);
            $this->tracksData = (array)($this->groupsCfg?->get('tracks', []) ?? []);
            // Also ensure default group exists if no groups present
            if (empty($this->groupsData)) {
                $this->groupsData['default'] = [
                    'permissions' => [],
                    'parents' => [],
                    'weight' => 0,
                    'meta' => [],
                ];
                $this->writeGroupsDir($this->groupsDir, $this->groupsData);
            }
            return;
        }

        // No legacy files and no per-entity data: initialize with a default group file
        $this->usersData = [];
        $this->tracksData = [];
        $this->groupsData = [
            'default' => [
                'permissions' => [],
                'parents' => [],
                'weight' => 0,
                'meta' => [],
            ]
        ];
        $this->writeGroupsDir($this->groupsDir, $this->groupsData);
    }

    public function save() : void
    {
        // Always write using new layout; keep legacy files untouched to avoid confusion
        $this->writeGroupsDir($this->groupsDir, $this->groupsData);
        $this->writeTracksDir($this->tracksDir, $this->tracksData);
        $this->writeUsersDir($this->usersDir, $this->usersData);

        // Do NOT write legacy files automatically to avoid recreating users.yml/groups.yml
    }

    public function getUsers() : array { return $this->usersData; }
    public function setUsers(array $users) : void { $this->usersData = $users; }

    public function getGroups() : array { return $this->groupsData; }
    public function setGroups(array $groups) : void { $this->groupsData = $groups; }

    public function getTracks() : array { return $this->tracksData; }
    public function setTracks(array $tracks) : void { $this->tracksData = $tracks; }

    // ---- Directory I/O helpers ----

    private function dirHasYaml(string $dir) : bool
    {
        if (!is_dir($dir)) return false;
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.yml');
        return !empty($files);
    }

    /** @return array<string,mixed> */
    private function readGroupsDir(string $dir) : array
    {
        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.yml') as $file) {
            $name = basename($file, '.yml');
            $cfg = new Config($file, Config::YAML, [
                'permissions' => [],
                'parents' => [],
                'weight' => 0,
                'meta' => [],
                'temp_permissions' => [],
                'temp_parents' => [],
                'meta_context' => [],
                'temp_meta' => []
            ]);
            $gtemps = (array)$cfg->get('temp_permissions', []);
            $gtempParents = (array)$cfg->get('temp_parents', []);
            $gMetaCtx = (array)$cfg->get('meta_context', []);
            $gTempMeta = (array)$cfg->get('temp_meta', []);
            $out[$name] = [
                'permissions' => (array)$cfg->get('permissions', []),
                'parents' => (array)$cfg->get('parents', []),
                'weight' => (int)$cfg->get('weight', 0),
                'meta' => (array)$cfg->get('meta', []),
                'temp_permissions' => $this->normalizeTempRead($gtemps),
                'temp_parents' => $this->normalizeTempParentsRead($gtempParents, 'group'),
                'meta_context' => $this->normalizeMetaContextRead($gMetaCtx),
                'temp_meta' => $this->normalizeTempMetaRead($gTempMeta)
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function readTracksDir(string $dir) : array
    {
        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.yml') as $file) {
            $name = basename($file, '.yml');
            $cfg = new Config($file, Config::YAML, [ 'groups' => [] ]);
            $out[$name] = (array)$cfg->get('groups', []);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function readUsersDir(string $dir) : array
    {
        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.yml') as $file) {
            $uuid = basename($file, '.yml');
            $cfg = new Config($file, Config::YAML, [
                'name' => $uuid,
                'permissions' => [],
                'temp_permissions' => [],
                'temp_parents' => [],
                'groups' => [],
                'primary' => null,
                'meta' => [],
                'meta_context' => [],
                'temp_meta' => []
            ]);
            $utemps = (array)$cfg->get('temp_permissions', []);
            $utempParents = (array)$cfg->get('temp_parents', []);
            $uMetaCtx = (array)$cfg->get('meta_context', []);
            $uTempMeta = (array)$cfg->get('temp_meta', []);
            $out[$uuid] = [
                'name' => (string)$cfg->get('name', $uuid),
                'permissions' => (array)$cfg->get('permissions', []),
                'temp_permissions' => $this->normalizeTempRead($utemps),
                'temp_parents' => $this->normalizeTempParentsRead($utempParents, 'user'),
                'meta_context' => $this->normalizeMetaContextRead($uMetaCtx),
                'temp_meta' => $this->normalizeTempMetaRead($uTempMeta),
                'groups' => (array)$cfg->get('groups', []),
                'primary' => $cfg->get('primary', null),
                'meta' => (array)$cfg->get('meta', []),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $groups */
    private function writeGroupsDir(string $dir, array $groups) : void
    {
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        // Delete stale files that no longer have corresponding groups
        $keep = array_map(fn($k) => strtolower((string)$k), array_keys($groups));
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.yml') as $file) {
            $name = strtolower(basename($file, '.yml'));
            if (!in_array($name, $keep, true)) {
                @unlink($file);
            }
        }
        foreach ($groups as $name => $data) {
            $file = $dir . DIRECTORY_SEPARATOR . strtolower((string)$name) . '.yml';
            $cfg = new Config($file, Config::YAML);
            $cfg->set('permissions', (array)($data['permissions'] ?? []));
            $cfg->set('parents', (array)($data['parents'] ?? []));
            $cfg->set('weight', (int)($data['weight'] ?? 0));
            $cfg->set('meta', (array)($data['meta'] ?? []));
            $cfg->set('temp_permissions', $this->prepareTempForWrite((array)($data['temp_permissions'] ?? [])));
            $cfg->set('temp_parents', $this->prepareTempParentsForWrite((array)($data['temp_parents'] ?? []), 'group'));
            $cfg->set('meta_context', $this->prepareMetaContextForWrite((array)($data['meta_context'] ?? [])));
            $cfg->set('temp_meta', $this->prepareTempMetaForWrite((array)($data['temp_meta'] ?? [])));
            $cfg->save();
        }
    }

    /** @param array<string,mixed> $tracks */
    private function writeTracksDir(string $dir, array $tracks) : void
    {
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        // Delete stale files that no longer have corresponding tracks
        $keep = array_map(fn($k) => strtolower((string)$k), array_keys($tracks));
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.yml') as $file) {
            $name = strtolower(basename($file, '.yml'));
            if (!in_array($name, $keep, true)) {
                @unlink($file);
            }
        }
        foreach ($tracks as $name => $order) {
            $file = $dir . DIRECTORY_SEPARATOR . strtolower((string)$name) . '.yml';
            $cfg = new Config($file, Config::YAML);
            $cfg->set('groups', array_values((array)$order));
            $cfg->save();
        }
    }

    /** @param array<string,mixed> $users */
    private function writeUsersDir(string $dir, array $users) : void
    {
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        foreach ($users as $uuid => $data) {
            $permissions = (array)($data['permissions'] ?? []);
            $temp = (array)($data['temp_permissions'] ?? []);
            $tempParents = (array)($data['temp_parents'] ?? []);
            $meta = (array)($data['meta'] ?? []);
            $groups = (array)($data['groups'] ?? []);
            $primary = $data['primary'] ?? null;

            $shouldCreate = !empty($permissions) || !empty($temp) || !empty($tempParents) || !empty($meta) || !empty($groups) || ($primary !== null && $primary !== '');
            if (!$shouldCreate) {
                // Do not create or overwrite file if user has nothing extra; skip
                continue;
            }

            $file = $dir . DIRECTORY_SEPARATOR . strtolower((string)$uuid) . '.yml';
            $cfg = new Config($file, Config::YAML);
            $cfg->set('name', (string)($data['name'] ?? $uuid));
            $cfg->set('permissions', $permissions);
            $cfg->set('temp_permissions', $this->prepareTempForWrite($temp));
            $cfg->set('temp_parents', $this->prepareTempParentsForWrite($tempParents, 'user'));
            $cfg->set('meta_context', $this->prepareMetaContextForWrite((array)($data['meta_context'] ?? [])));
            $cfg->set('temp_meta', $this->prepareTempMetaForWrite((array)($data['temp_meta'] ?? [])));
            $cfg->set('groups', $groups);
            $cfg->set('primary', $primary);
            $cfg->set('meta', $meta);
            $cfg->save();
        }
    }

    /**
     * Convert YAML-stored temp entries to runtime format with absolute 'expires'.
     * Supports legacy entries storing absolute 'expires' and new entries storing 'remaining'.
     * @param array<int,mixed> $list
     * @return array<int,array{node:string,value:bool,context:string,expires:int}>
     */
    private function normalizeTempRead(array $list) : array
    {
        $now = time();
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) continue;
            $node = isset($e['node']) ? (string)$e['node'] : '';
            $val = isset($e['value']) ? (bool)$e['value'] : null;
            $ctx = isset($e['context']) ? (string)$e['context'] : '';
            if ($node === '' || $val === null) continue;
            if (isset($e['remaining'])) {
                $rem = max(0, (int)$e['remaining']);
                $exp = $now + $rem;
            } else {
                $exp = isset($e['expires']) ? (int)$e['expires'] : 0;
            }
            if ($exp <= $now) continue;
            $out[] = [
                'node' => $node,
                'value' => (bool)$val,
                'context' => $ctx,
                'expires' => $exp,
            ];
        }
        return $out;
    }

    /**
     * Convert runtime temp entries with absolute 'expires' into YAML-storable entries with 'remaining'.
     * @param array<int,mixed> $list
     * @return array<int,array{node:string,value:bool,context:string,remaining:int}>
     */
    private function prepareTempForWrite(array $list) : array
    {
        $now = time();
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) continue;
            $node = isset($e['node']) ? (string)$e['node'] : '';
            $val = isset($e['value']) ? (bool)$e['value'] : null;
            $ctx = isset($e['context']) ? (string)$e['context'] : '';
            $exp = isset($e['expires']) ? (int)$e['expires'] : 0;
            if ($node === '' || $val === null || $exp <= $now) continue;
            $remaining = max(0, $exp - $now);
            $out[] = [
                'node' => $node,
                'value' => (bool)$val,
                'context' => $ctx,
                'remaining' => $remaining,
            ];
        }
        return $out;
    }

    /**
     * Normalize temp parents read from YAML.
     * For users: entries are {group, context, remaining|expires}
     * For groups: entries are {parent, context, remaining|expires}
     * @param array<int,mixed> $list
     * @return array<int,array{group?:string,parent?:string,context:string,expires:int}>
     */
    private function normalizeTempParentsRead(array $list, string $type) : array
    {
        $now = time();
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) continue;
            $nameKey = $type === 'user' ? 'group' : 'parent';
            $nm = isset($e[$nameKey]) ? (string)$e[$nameKey] : '';
            $ctx = isset($e['context']) ? (string)$e['context'] : '';
            if ($nm === '') continue;
            if (isset($e['remaining'])) {
                $rem = max(0, (int)$e['remaining']);
                $exp = $now + $rem;
            } else {
                $exp = isset($e['expires']) ? (int)$e['expires'] : 0;
            }
            if ($exp <= $now) continue;
            $row = [ 'context' => $ctx, 'expires' => $exp ];
            $row[$nameKey] = $nm;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Prepare temp parents for YAML write storing remaining seconds.
     * @param array<int,mixed> $list
     * @return array<int,array{group?:string,parent?:string,context:string,remaining:int}>
     */
    private function prepareTempParentsForWrite(array $list, string $type) : array
    {
        $now = time();
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e)) continue;
            $nameKey = $type === 'user' ? 'group' : 'parent';
            $nm = isset($e[$nameKey]) ? (string)$e[$nameKey] : '';
            $ctx = isset($e['context']) ? (string)$e['context'] : '';
            $exp = isset($e['expires']) ? (int)$e['expires'] : 0;
            if ($nm === '' || $exp <= $now) continue;
            $remaining = max(0, $exp - $now);
            $row = [ 'context' => $ctx, 'remaining' => $remaining ];
            $row[$nameKey] = $nm;
            $out[] = $row;
        }
        return $out;
    }
}
