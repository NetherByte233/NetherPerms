<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\ui;

use NetherByte\NetherPerms\NetherPerms;
use NetherByte\NetherPerms\permission\PermissionManager;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use NetherByte\NetherPerms\libs\jojoe77777\FormAPI\SimpleForm;
use NetherByte\NetherPerms\libs\jojoe77777\FormAPI\CustomForm;
use pocketmine\scheduler\ClosureTask;


final class UiController
{
    public function __construct(private NetherPerms $plugin, private PermissionManager $pm) {}

    public function openMain(Player $player) : void
    {
        $form = new SimpleForm(function(Player $p, ?int $data) : void {
            if ($data === null) return;
            switch ($data) {
                case 0: $this->openGroups($p); break;
                case 1: $this->openUsers($p); break;
                case 2: $this->openTracks($p); break;
                case 3: $this->plugin->reloadAll(); $p->sendMessage(TF::GREEN . 'Reloaded NetherPerms.'); break;
            }
        });
        $form->setTitle("NetherPerms");
        $form->setContent("Choose a category");
        $form->addButton("Groups");
        $form->addButton("Users");
        $form->addButton("Tracks");
        $form->addButton("Reload");
        $player->sendForm($form);
    }

    private function openGroups(Player $player) : void
    {
        $groups = array_values(array_keys($this->pm->getAllGroups()));
        sort($groups);
        $labels = $groups;
        $labels[] = "+ Add New Group";
        $form = new SimpleForm(function(Player $p, ?int $data) use ($groups) : void {
            if ($data === null) return;
            if ($data === count($groups)) { $this->openCreateGroup($p); return; }
            if (!isset($groups[$data])) return;
            $this->openGroupView($p, $groups[$data]);
        });
        $form->setTitle("Groups");
        $form->setContent("Select a group or add a new one");
        foreach ($labels as $label) $form->addButton($label);
        $player->sendForm($form);
    }

    private function openCreateGroup(Player $player) : void
    {
        $allGroups = array_values(array_keys($this->pm->getAllGroups()));
        sort($allGroups);
        $dropdown = array_merge(['(none)'], $allGroups);
        $form = new CustomForm(function(Player $p, ?array $data) use ($dropdown, $allGroups) : void {
            if ($data === null) return;
            $name = strtolower(trim((string)($data[0] ?? '')));
            $display = trim((string)($data[1] ?? ''));
            $weightStr = trim((string)($data[2] ?? ''));
            $prefix = trim((string)($data[3] ?? ''));
            $suffix = trim((string)($data[4] ?? ''));
            $parentIdx = (int)($data[5] ?? 0);
            if ($name === '') { $p->sendMessage(TF::RED . 'Group name is required'); return; }
            if ($this->pm->getGroup($name) !== null) { $p->sendMessage(TF::RED . "Group '$name' already exists"); return; }
            $this->pm->createGroup($name);
            if ($display !== '') $this->pm->setGroupMeta($name, 'display', $display);
            if ($weightStr !== '' && is_numeric($weightStr)) $this->pm->setGroupWeight($name, (int)$weightStr);
            if ($prefix !== '') $this->pm->setGroupMeta($name, 'prefix', $prefix);
            if ($suffix !== '') $this->pm->setGroupMeta($name, 'suffix', $suffix);
            if ($parentIdx > 0) { // index 0 is (none)
                $parent = $dropdown[$parentIdx] ?? null;
                if ($parent !== null && $parent !== '(none)') {
                    $this->pm->addGroupParent($name, $parent);
                }
            }
            $this->pm->save();
            $p->sendMessage(TF::GREEN . "Created group '$name'.");
            $this->openGroupView($p, $name);
        });
        $form->setTitle("Create New Group");
        $form->addInput("Name (required)");
        $form->addInput("Display name (optional)");
        $form->addInput("Weight (optional integer)");
        $form->addInput("Prefix (optional)");
        $form->addInput("Suffix (optional)");
        $form->addDropdown("Parent (optional)", $dropdown, 0);
        $player->sendForm($form);
    }

    private function openGroupView(Player $player, string $group) : void
    {
        $g = $this->pm->getGroup($group);
        if ($g === null) { $player->sendMessage(TF::RED . 'Group not found'); return; }
        $perms = array_keys($g['permissions'] ?? []);
        sort($perms);
        $parents = array_map(fn($x) => (string)$x, $g['parents'] ?? []);
        sort($parents);

        $form = new SimpleForm(function(Player $p, ?int $data) use ($group) : void {
            if ($data === null) return;
            switch ($data) {
                case 0: $this->openGroupPerms($p, $group); break;
                case 1: $this->openGroupParents($p, $group); break;
                case 2: $this->openGroups($p); break;
            }
        });
        $form->setTitle("Group: $group");
        $form->setContent("Manage group settings");
        $form->addButton("Permissions");
        $form->addButton("Parents");
        $form->addButton("Back");
        $player->sendForm($form);
    }

    private function openGroupPerms(Player $player, string $group) : void
    {
        $g = $this->pm->getGroup($group);
        if ($g === null) { $player->sendMessage(TF::RED . 'Group not found'); return; }
        // Build flattened entries of [node, contextArray|null, value]
        $entries = [];
        foreach (($g['permissions'] ?? []) as $node => $val) {
            if (is_bool($val)) {
                $entries[] = ['node' => (string)$node, 'ctx' => null, 'value' => (bool)$val];
            } elseif (is_array($val)) {
                // global if present
                if (array_key_exists('__global__', $val)) {
                    $entries[] = ['node' => (string)$node, 'ctx' => null, 'value' => (bool)$val['__global__']];
                }
                foreach ($val as $k => $v) {
                    if ($k === '__global__') continue;
                    $entries[] = ['node' => (string)$node, 'ctx' => $this->parseContextKeyForDisplay((string)$k), 'value' => (bool)$v];
                }
            }
        }
        // Sort by node then by context string
        usort($entries, function(array $a, array $b) : int {
            $cmp = strcmp($a['node'], $b['node']);
            if ($cmp !== 0) return $cmp;
            $as = $a['ctx'] === null ? '' : $this->ctxToShort($a['ctx']);
            $bs = $b['ctx'] === null ? '' : $this->ctxToShort($b['ctx']);
            return strcmp($as, $bs);
        });
        // Labels
        $labels = [];
        foreach ($entries as $e) {
            $labels[] = ($e['value'] ? TF::GREEN.'true' : TF::RED.'false') . TF::RESET . ' • ' . $e['node'] . ' ' . ($e['ctx'] ? TF::AQUA.'['.$this->ctxToShort($e['ctx']).']' : TF::AQUA.'[global]');
        }
        $labels[] = "+ Add new";

        $form = new SimpleForm(function(Player $p, ?int $data) use ($group, $entries) : void {
            if ($data === null) return;
            if ($data === count($entries)) { $this->openAddGroupPerm($p, $group); return; }
            if (!isset($entries[$data])) return;
            $ent = $entries[$data];
            $this->openEditGroupPerm($p, $group, $ent['node'], $ent['ctx']);
        });
        $form->setTitle("$group • Permissions");
        $form->setContent("Select an entry to edit/delete");
        foreach ($labels as $label) $form->addButton($label);
        $player->sendForm($form);
    }

    private function openAddGroupPerm(Player $player, string $group) : void
    {
        $form = new CustomForm(function(Player $p, ?array $data) use ($group) : void {
            if ($data === null) return;
            $node = trim((string)($data[0] ?? ''));
            $val = (bool)($data[1] ?? false);
            $worldsRaw = trim((string)($data[2] ?? ''));
            $gmRaw = trim((string)($data[3] ?? ''));
            $worlds = array_values(array_filter(array_map(function(string $w) : string { return strtolower(trim($w)); }, explode(',', $worldsRaw)), fn(string $w) => $w !== ''));
            $gms = $this->parseGamemodesList($gmRaw);
            if ($node === '') { $p->sendMessage(TF::RED . 'Node cannot be empty'); return; }
            $worlds = empty($worlds) ? [null] : $worlds;
            $gms = empty($gms) ? [null] : $gms;
            foreach ($worlds as $w) {
                foreach ($gms as $gmName) {
                    $ctx = [];
                    if ($w !== null) $ctx['world'] = $w;
                    if ($gmName !== null) $ctx['gamemode'] = $gmName;
                    $this->pm->setGroupPermission($group, $node, $val, $ctx);
                }
            }
            $this->pm->save();
            $this->plugin->getLogger()->info("[UI] Set $node=" . ($val ? 'true' : 'false') . " on group '$group'" . ($ctx ? ' ctx=' . json_encode($ctx) : ''));
            // Reapply for affected online players
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($group) : void {
                foreach ($this->plugin->getServer()->getOnlinePlayers() as $pl) {
                    $uuid = $pl->getUniqueId()->toString();
                    $groups = $this->pm->getUserGroups($uuid);
                    if (in_array(strtolower($group), array_map('strtolower', $groups), true)) {
                        $this->plugin->applyPermissions($pl);
                    }
                }
            }), 1);
            $this->openGroupPerms($p, $group);
        });
        $form->setTitle("Add Permission • $group");
        $form->addInput("Node (e.g. pocketmine.command.gamemode.self)");
        $form->addToggle("Value (true = allow, false = deny)", true);
        $form->addInput("Worlds (optional; comma-separated folder names)");
        $form->addInput("Gamemode (optional; survival/creative/adventure/spectator)");
        $player->sendForm($form);
    }

    private function openEditGroupPerm(Player $player, string $group, string $node, ?array $ctx = null) : void
    {
        $form = new SimpleForm(function(Player $p, ?int $data) use ($group, $node, $ctx) : void {
            if ($data === null) return;
            switch ($data) {
                case 0: // Toggle allow/deny for this exact entry
                    $current = true;
                    $g = $this->pm->getGroup($group);
                    if ($g !== null) {
                        $v = $g['permissions'][$node] ?? null;
                        if (is_bool($v) && $ctx === null) $current = (bool)$v;
                        elseif (is_array($v)) {
                            $key = $ctx === null ? '__global__' : $this->makeContextKey($ctx);
                            if (isset($v[$key])) $current = (bool)$v[$key];
                        }
                    }
                    $newVal = !$current;
                    $this->pm->setGroupPermission($group, $node, $newVal, $ctx ?? []);
                    $this->pm->save();
                    $p->sendMessage(TF::GREEN . "Set $node to ".($newVal ? 'true' : 'false').($ctx ? ' ['.$this->ctxToShort($ctx).']' : ' [global]').".");
                    break;
                case 1: // Edit node/context via form
                    $this->openEditGroupPermForm($p, $group, $node, $ctx);
                    return;
                case 2: // Delete
                    if ($ctx === null) {
                        $this->pm->unsetGroupPermission($group, $node);
                    } else {
                        $this->pm->unsetGroupPermissionContext($group, $node, $ctx);
                    }
                    $this->pm->save();
                    $p->sendMessage(TF::GREEN . "Removed " . ($ctx ? ("context [".$this->ctxToShort($ctx)."] ") : 'global ') . "$node from '$group'.");
                    break;
            }
            $this->openGroupPerms($p, $group);
        });
        $titleCtx = $ctx ? (' ['.$this->ctxToShort($ctx).']') : ' [global]';
        $form->setTitle("$group • $node$titleCtx");
        $form->setContent("Edit or delete this entry");
        $form->addButton("Toggle allow/deny");
        $form->addButton("Edit node/context");
        $form->addButton("Delete entry");
        $form->addButton("Back");
        $player->sendForm($form);
    }

    private function openEditGroupPermForm(Player $player, string $group, string $node, ?array $ctx) : void
    {
        $currentVal = true;
        $g = $this->pm->getGroup($group);
        if ($g !== null) {
            $v = $g['permissions'][$node] ?? null;
            if (is_bool($v) && $ctx === null) $currentVal = (bool)$v;
            elseif (is_array($v)) {
                $key = $ctx === null ? '__global__' : $this->makeContextKey($ctx);
                if (isset($v[$key])) $currentVal = (bool)$v[$key];
            }
        }
        $prefWorld = $ctx['world'] ?? '';
        $prefGm = $ctx['gamemode'] ?? '';
        $form = new CustomForm(function(Player $p, ?array $data) use ($group, $node, $ctx, $currentVal) : void {
            if ($data === null) return;
            $newNode = trim((string)($data[0] ?? $node));
            $newVal = (bool)($data[1] ?? $currentVal);
            $worldsRaw = trim((string)($data[2] ?? ''));
            $gmRaw = trim((string)($data[3] ?? ''));
            if ($newNode === '') { $p->sendMessage(TF::RED . 'Node cannot be empty'); return; }
            // Remove old entry first
            if ($ctx === null) $this->pm->unsetGroupPermission($group, $node); else $this->pm->unsetGroupPermissionContext($group, $node, $ctx);
            // Apply new entries (multi-world x multi-gamemode)
            $worlds = array_values(array_filter(array_map(fn(string $w) => strtolower(trim($w)), explode(',', $worldsRaw)), fn(string $w) => $w !== ''));
            $gms = $this->parseGamemodesList($gmRaw);
            $worlds = empty($worlds) ? [null] : $worlds;
            $gms = empty($gms) ? [null] : $gms;
            foreach ($worlds as $w) {
                foreach ($gms as $gmName) {
                    $newCtx = [];
                    if ($w !== null) $newCtx['world'] = $w;
                    if ($gmName !== null) $newCtx['gamemode'] = $gmName;
                    $this->pm->setGroupPermission($group, $newNode, $newVal, $newCtx);
                }
            }
            $this->pm->save();
            $p->sendMessage(TF::GREEN . "Updated permission.");
            $this->openGroupPerms($p, $group);
        });
        $form->setTitle("Edit Permission • $group");
        $form->addInput("Node", '', $node);
        $form->addToggle("Value (true = allow, false = deny)", $currentVal);
        $form->addInput("Worlds (comma-separated)", '', $prefWorld);
        $form->addInput("Gamemodes (comma-separated)", '', $prefGm);
        $player->sendForm($form);
    }

    private function openGroupParents(Player $player, string $group) : void
    {
        $g = $this->pm->getGroup($group);
        if ($g === null) { $player->sendMessage(TF::RED . 'Group not found'); return; }
        $parents = array_map(fn($x) => (string)$x, $g['parents'] ?? []);
        sort($parents);
        $list = $parents;
        $list[] = "+ Add parent";

        $form = new SimpleForm(function(Player $p, ?int $data) use ($group, $parents) : void {
            if ($data === null) return;
            if ($data === count($parents)) { $this->openAddParent($p, $group); return; }
            if (!isset($parents[$data])) return;
            $parent = $parents[$data];
            $this->pm->removeGroupParent($group, $parent); $this->pm->save();
            $p->sendMessage(TF::GREEN . "Removed parent '$parent'.");
            $this->openGroupParents($p, $group);
        });
        $form->setTitle("$group • Parents");
        $form->setContent("Tap to remove parent or add a new one");
        foreach ($list as $label) $form->addButton($label);
        $player->sendForm($form);
    }

    private function openAddParent(Player $player, string $group) : void
    {
        $form = new CustomForm(function(Player $p, ?array $data) use ($group) : void {
            if ($data === null) return;
            $parent = trim((string)($data[0] ?? ''));
            if ($parent === '') { $p->sendMessage(TF::RED . 'Parent group cannot be empty'); return; }
            $this->pm->addGroupParent($group, $parent); $this->pm->save();
            $p->sendMessage(TF::GREEN . "Added parent '$parent'.");
            $this->openGroupParents($p, $group);
        });
        $form->setTitle("Add Parent • $group");
        $form->addInput("Parent group name");
        $player->sendForm($form);
    }

    // --- Helpers ---
    /** @return array<string,string> */
    private function parseContextKeyForDisplay(string $key) : array
    {
        $out = [];
        $key = str_replace(', ', ',', $key);
        $parts = preg_split('/[;,]/', $key) ?: [];
        foreach ($parts as $pair) {
            if ($pair === '') continue;
            $kv = explode('=', $pair, 2);
            if (count($kv) === 2) { $out[strtolower($kv[0])] = strtolower($kv[1]); }
        }
        return $out;
    }

    /** @param array<string,string> $ctx */
    private function ctxToShort(array $ctx) : string
    {
        $order = ['world','gamemode','dimension'];
        $chunks = [];
        foreach ($order as $k) if (isset($ctx[$k]) && $ctx[$k] !== '') $chunks[] = $k.'='.$ctx[$k];
        // add any extras if present
        foreach ($ctx as $k => $v) if (!in_array($k, $order, true)) $chunks[] = $k.'='.$v;
        return implode(';', $chunks);
    }

    /** @param array<string,string> $context */
    private function makeContextKey(array $context) : string
    {
        $allowed = ['world','gamemode','dimension'];
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
     * @return string[] Normalized gamemode names (survival, creative, adventure, spectator)
     */
    private function parseGamemodesList(string $raw) : array
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') return [];
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($s) => $s !== ''));
        $map = [
            's' => 'survival', '0' => 'survival', 'survival' => 'survival',
            'c' => 'creative', '1' => 'creative', 'creative' => 'creative',
            'a' => 'adventure', '2' => 'adventure', 'adventure' => 'adventure',
            'sp' => 'spectator', '3' => 'spectator', 'spectator' => 'spectator'
        ];
        $out = [];
        foreach ($parts as $p) {
            $out[] = $map[$p] ?? $p; // fall back to user-provided value
        }
        return array_values(array_unique($out));
    }

    // Users: search by name instead of listing all
    private function openUsers(Player $player) : void
    {
        $form = new CustomForm(function(Player $p, ?array $data) : void {
            if ($data === null) return;
            $query = trim((string)($data[0] ?? ''));
            if ($query === '') { $p->sendMessage(TF::RED . 'Please enter a player name'); return; }
            // Try resolve online first
            $target = $this->plugin->getServer()->getPlayerExact($query);
            $uuid = null;
            if ($target !== null) {
                $uuid = $target->getUniqueId()->toString();
                if (!$this->pm->userExists($uuid)) {
                    $this->pm->createUser($uuid, $target->getName());
                    $this->pm->save();
                }
            } else {
                $uuid = $this->pm->findUserUuidByName($query);
            }
            if ($uuid === null) { $p->sendMessage(TF::RED . 'Player not found'); return; }
            $this->openUserView($p, $uuid);
        });
        $form->setTitle("Find User");
        $form->addInput("Enter player name");
        $player->sendForm($form);
    }

    private function openTracks(Player $player) : void
    {
        $tracks = $this->pm->getTracks();
        $names = array_keys($tracks);
        sort($names);
        $labels = $names;
        $labels[] = "+ Add Track";
        $form = new SimpleForm(function(Player $p, ?int $data) use ($names) : void {
            if ($data === null) return;
            if ($data === count($names)) { $this->openCreateTrack($p); return; }
            if (!isset($names[$data])) return;
            $this->openEditTrack($p, $names[$data]);
        });
        $form->setTitle("Tracks");
        $form->setContent("Select a track to edit, or add a new one");
        foreach ($labels as $label) $form->addButton($label);
        $player->sendForm($form);
    }

    private function openUserView(Player $player, string $uuid) : void
    {
        $u = $this->pm->getUser($uuid);
        if ($u === null) { $player->sendMessage(TF::RED . 'User not found'); return; }
        $name = (string)($u['name'] ?? $uuid);
        $form = new SimpleForm(function(Player $p, ?int $data) use ($uuid) : void {
            if ($data === null) return;
            switch ($data) {
                case 0: $this->openUserGroups($p, $uuid); break;
                case 1: $this->openUserPerms($p, $uuid); break;
                case 2: $this->openUserMeta($p, $uuid); break;
                case 3: $this->openUserPrimary($p, $uuid); break;
                case 4: $this->openUsers($p); break;
            }
        });
        $form->setTitle("User: $name");
        $form->setContent("Manage user settings");
        $form->addButton("Groups");
        $form->addButton("Permissions");
        $form->addButton("Meta");
        $form->addButton("Primary Group");
        $form->addButton("Back");
        $player->sendForm($form);
    }

    private function openUserPrimary(Player $player, string $uuid) : void
    {
        $u = $this->pm->getUser($uuid); if ($u === null) { $player->sendMessage(TF::RED.'User not found'); return; }
        $name = (string)($u['name'] ?? $uuid);
        $current = $this->pm->getPrimaryGroup($uuid);
        $allGroups = array_keys($this->pm->getAllGroups()); sort($allGroups);
        $index = 0; $options = ["(none)"]; $valueMap = ["(none)" => null];
        foreach ($allGroups as $g) { $options[] = $g; $valueMap[$g] = $g; }
        $prefill = $current ?? '';
        $form = new CustomForm(function(Player $p, ?array $data) use ($uuid, $valueMap, $options) : void {
            if ($data === null) return;
            $idx = (int)($data[0] ?? 0);
            $label = $options[$idx] ?? '(none)';
            $sel = $valueMap[$label] ?? null;
            if ($sel !== null && !$this->pm->setPrimaryGroup($uuid, $sel)) { $p->sendMessage(TF::RED.'Unknown group'); return; }
            if ($sel === null) { $this->pm->setPrimaryGroup($uuid, null); }
            $this->pm->save();
            $targetName = $this->pm->getUserName($uuid);
            if ($targetName !== null) {
                $target = $this->plugin->getServer()->getPlayerExact($targetName);
                if ($target !== null) {
                    $this->plugin->applyPermissions($target);
                }
            }
            $this->openUserView($p, $uuid);
        });
        $form->setTitle("$name • Primary Group");
        $form->addDropdown("Select primary group", $options, $current === null ? 0 : (array_search($current, $options, true) ?: 0));
        $player->sendForm($form);
    }

    private function openUserGroups(Player $player, string $uuid) : void
    {
        $u = $this->pm->getUser($uuid); if ($u === null) { $player->sendMessage(TF::RED.'User not found'); return; }
        $name = (string)($u['name'] ?? $uuid);
        $groups = array_values($this->pm->getUserGroups($uuid));
        sort($groups);
        $labels = $groups; $labels[] = "+ Add group"; $labels[] = "Back";
        $form = new SimpleForm(function(Player $p, ?int $data) use ($uuid, $groups) : void {
            if ($data === null) return;
            if ($data === count($groups)) { $this->openAddUserGroup($p, $uuid); return; }
            if ($data === count($groups) + 1) { $this->openUserView($p, $uuid); return; }
            if (!isset($groups[$data])) return;
            $this->pm->removeUserGroup($uuid, $groups[$data]); $this->pm->save();
            $p->sendMessage(TF::GREEN . "Removed group '".$groups[$data]."'.");
            $this->plugin->applyPermissions($p);
            $this->openUserGroups($p, $uuid);
        });
        $form->setTitle("$name • Groups");
        $form->setContent("Tap a group to remove, or add new");
        foreach ($labels as $label) $form->addButton($label);
        $player->sendForm($form);
    }

    private function openAddUserGroup(Player $player, string $uuid) : void
    {
        $all = array_keys($this->pm->getAllGroups()); sort($all);
        $form = new CustomForm(function(Player $p, ?array $data) use ($uuid, $all) : void {
            if ($data === null) return;
            $group = trim((string)($data[0] ?? ''));
            if ($group === '') { $p->sendMessage(TF::RED . 'Group cannot be empty'); return; }
            if (!$this->pm->addUserGroup($uuid, $group)) { $p->sendMessage(TF::RED . 'Group not found'); return; }
            $this->pm->save();
            $this->plugin->applyPermissions($p);
            $this->openUserGroups($p, $uuid);
        });
        $form->setTitle("Add Group to User");
        $form->addInput("Group name", '', $all[0] ?? '');
        $player->sendForm($form);
    }

    private function openUserPerms(Player $player, string $uuid) : void
    {
        $u = $this->pm->getUser($uuid); if ($u === null) { $player->sendMessage(TF::RED.'User not found'); return; }
        $name = (string)($u['name'] ?? $uuid);
        $entries = [];
        foreach (($u['permissions'] ?? []) as $node => $val) {
            if (is_bool($val)) {
                $entries[] = ['node' => (string)$node, 'ctx' => null, 'value' => (bool)$val];
            } elseif (is_array($val)) {
                if (array_key_exists('__global__', $val)) {
                    $entries[] = ['node' => (string)$node, 'ctx' => null, 'value' => (bool)$val['__global__']];
                }
                foreach ($val as $k => $v) {
                    if ($k === '__global__') continue;
                    $entries[] = ['node' => (string)$node, 'ctx' => $this->parseContextKeyForDisplay((string)$k), 'value' => (bool)$v];
                }
            }
        }
        usort($entries, function(array $a, array $b) : int {
            $cmp = strcmp($a['node'], $b['node']); if ($cmp !== 0) return $cmp;
            $as = $a['ctx'] === null ? '' : $this->ctxToShort($a['ctx']);
            $bs = $b['ctx'] === null ? '' : $this->ctxToShort($b['ctx']);
            return strcmp($as, $bs);
        });
        $labels = [];
        foreach ($entries as $e) {
            $labels[] = ($e['value'] ? TF::GREEN.'true' : TF::RED.'false') . TF::RESET . ' • ' . $e['node'] . ' ' . ($e['ctx'] ? TF::AQUA.'['.$this->ctxToShort($e['ctx']).']' : TF::AQUA.'[global]');
        }
        $labels[] = "+ Add new"; $labels[] = "Back";
        $form = new SimpleForm(function(Player $p, ?int $data) use ($uuid, $entries) : void {
            if ($data === null) return;
            if ($data === count($entries)) { $this->openAddUserPerm($p, $uuid); return; }
            if ($data === count($entries) + 1) { $this->openUserView($p, $uuid); return; }
            if (!isset($entries[$data])) return;
            $ent = $entries[$data];
            $this->openEditUserPerm($p, $uuid, $ent['node'], $ent['ctx']);
        });
        $form->setTitle("$name • Permissions");
        $form->setContent("Select an entry to edit/delete");
        foreach ($labels as $label) $form->addButton($label);
        $player->sendForm($form);
    }

    private function openAddUserPerm(Player $player, string $uuid) : void
    {
        $form = new CustomForm(function(Player $p, ?array $data) use ($uuid) : void {
            if ($data === null) return;
            $node = trim((string)($data[0] ?? ''));
            $val = (bool)($data[1] ?? false);
            $worldsRaw = trim((string)($data[2] ?? ''));
            $gmRaw = trim((string)($data[3] ?? ''));
            $worlds = array_values(array_filter(array_map(fn(string $w) => strtolower(trim($w)), explode(',', $worldsRaw)), fn(string $w) => $w !== ''));
            $gms = $this->parseGamemodesList($gmRaw);
            if ($node === '') { $p->sendMessage(TF::RED . 'Node cannot be empty'); return; }
            $worlds = empty($worlds) ? [null] : $worlds;
            $gms = empty($gms) ? [null] : $gms;
            foreach ($worlds as $w) {
                foreach ($gms as $gmName) {
                    $ctx = [];
                    if ($w !== null) $ctx['world'] = $w;
                    if ($gmName !== null) $ctx['gamemode'] = $gmName;
                    $this->pm->setUserPermission($uuid, $node, $val, $ctx);
                }
            }
            $this->pm->save();
            $this->plugin->applyPermissions($p);
            $this->openUserPerms($p, $uuid);
        });
        $form->setTitle("Add Permission to User");
        $form->addInput("Node (e.g. pocketmine.command.gamemode.self)");
        $form->addToggle("Value (true = allow, false = deny)", true);
        $form->addInput("Worlds (optional; comma-separated folder names)");
        $form->addInput("Gamemode (optional; survival/creative/adventure/spectator)");
        $player->sendForm($form);
    }

    private function openEditUserPerm(Player $player, string $uuid, string $node, ?array $ctx = null) : void
    {
        $form = new SimpleForm(function(Player $p, ?int $data) use ($uuid, $node, $ctx) : void {
            if ($data === null) return;
            switch ($data) {
                case 0:
                    // Toggle
                    $current = true;
                    $u = $this->pm->getUser($uuid);
                    if ($u !== null) {
                        $v = $u['permissions'][$node] ?? null;
                        if (is_bool($v) && $ctx === null) $current = (bool)$v;
                        elseif (is_array($v)) {
                            $key = $ctx === null ? '__global__' : $this->makeContextKey($ctx);
                            if (isset($v[$key])) $current = (bool)$v[$key];
                        }
                    }
                    $newVal = !$current;
                    $this->pm->setUserPermission($uuid, $node, $newVal, $ctx ?? []);
                    $this->pm->save();
                    $this->plugin->applyPermissions($p);
                    break;
                case 1:
                    $this->openEditUserPermForm($p, $uuid, $node, $ctx); return;
                case 2:
                    $this->pm->unsetUserPermission($uuid, $node); $this->pm->save();
                    $this->plugin->applyPermissions($p);
                    break;
            }
            $this->openUserPerms($p, $uuid);
        });
        $titleCtx = $ctx ? (' ['.$this->ctxToShort($ctx).']') : ' [global]';
        $form->setTitle("User • $node$titleCtx");
        $form->setContent("Edit or delete this entry");
        $form->addButton("Toggle allow/deny");
        $form->addButton("Edit node/context");
        $form->addButton("Delete entry");
        $form->addButton("Back");
        $player->sendForm($form);
    }

    private function openEditUserPermForm(Player $player, string $uuid, string $node, ?array $ctx) : void
    {
        $currentVal = true;
        $u = $this->pm->getUser($uuid);
        if ($u !== null) {
            $v = $u['permissions'][$node] ?? null;
            if (is_bool($v) && $ctx === null) $currentVal = (bool)$v;
            elseif (is_array($v)) {
                $key = $ctx === null ? '__global__' : $this->makeContextKey($ctx);
                if (isset($v[$key])) $currentVal = (bool)$v[$key];
            }
        }
        $prefWorld = $ctx['world'] ?? '';
        $prefGm = $ctx['gamemode'] ?? '';
        $form = new CustomForm(function(Player $p, ?array $data) use ($uuid, $node, $ctx, $currentVal) : void {
            if ($data === null) return;
            $newNode = trim((string)($data[0] ?? $node));
            $newVal = (bool)($data[1] ?? $currentVal);
            $worldsRaw = trim((string)($data[2] ?? ''));
            $gmRaw = trim((string)($data[3] ?? ''));
            if ($newNode === '') { $p->sendMessage(TF::RED . 'Node cannot be empty'); return; }
            // Remove old entry first (full node)
            $this->pm->unsetUserPermission($uuid, $node);
            $worlds = array_values(array_filter(array_map(fn(string $w) => strtolower(trim($w)), explode(',', $worldsRaw)), fn(string $w) => $w !== ''));
            $gms = $this->parseGamemodesList($gmRaw);
            $worlds = empty($worlds) ? [null] : $worlds; $gms = empty($gms) ? [null] : $gms;
            foreach ($worlds as $w) {
                foreach ($gms as $gmName) {
                    $newCtx = [];
                    if ($w !== null) $newCtx['world'] = $w;
                    if ($gmName !== null) $newCtx['gamemode'] = $gmName;
                    $this->pm->setUserPermission($uuid, $newNode, $newVal, $newCtx);
                }
            }
            $this->pm->save();
            $this->plugin->applyPermissions($p);
            $this->openUserPerms($p, $uuid);
        });
        $form->setTitle("Edit Permission • User");
        $form->addInput("Node", '', $node);
        $form->addToggle("Value (true = allow, false = deny)", $currentVal);
        $form->addInput("Worlds (comma-separated)", '', $prefWorld);
        $form->addInput("Gamemodes (comma-separated)", '', $prefGm);
        $player->sendForm($form);
    }

    private function openUserMeta(Player $player, string $uuid) : void
    {
        $u = $this->pm->getUser($uuid); if ($u === null) { $player->sendMessage(TF::RED.'User not found'); return; }
        $name = (string)($u['name'] ?? $uuid);
        $prefix = (string)($u['meta']['prefix'] ?? '');
        $suffix = (string)($u['meta']['suffix'] ?? '');
        $form = new CustomForm(function(Player $p, ?array $data) use ($uuid) : void {
            if ($data === null) return;
            $prefix = trim((string)($data[0] ?? ''));
            $suffix = trim((string)($data[1] ?? ''));
            if ($prefix !== '') $this->pm->setUserMeta($uuid, 'prefix', $prefix); else $this->pm->unsetUserMeta($uuid, 'prefix');
            if ($suffix !== '') $this->pm->setUserMeta($uuid, 'suffix', $suffix); else $this->pm->unsetUserMeta($uuid, 'suffix');
            $this->pm->save();
            $this->plugin->applyPermissions($p);
            $this->openUserView($p, $uuid);
        });
        $form->setTitle("$name • Meta");
        $form->addInput("Prefix", '', $prefix);
        $form->addInput("Suffix", '', $suffix);
        $player->sendForm($form);
    }

    private function openEditTrack(Player $player, string $track) : void
    {
        $order = $this->pm->getTracks()[$track] ?? [];
        $existing = array_keys($this->pm->getAllGroups()); sort($existing);
        $orderStr = implode(',', $order);
        $form = new CustomForm(function(Player $p, ?array $data) use ($track) : void {
            if ($data === null) return;
            $raw = trim((string)($data[0] ?? ''));
            $groups = array_values(array_filter(array_map(fn($s) => strtolower(trim((string)$s)), explode(',', $raw)), fn($s) => $s !== ''));
            $unknown = $this->pm->setTrack($track, $groups);
            if (!empty($unknown)) { $p->sendMessage(TF::RED . 'Unknown groups: ' . implode(', ', $unknown)); return; }
            $this->pm->save();
            $p->sendMessage(TF::GREEN . "Updated track '$track'.");
            $this->openTracks($p);
        });
        $form->setTitle("Edit Track • $track");
        $form->addInput("Group order (comma-separated)", '', $orderStr);
        $player->sendForm($form);
    }

    private function openCreateTrack(Player $player) : void
    {
        $form = new CustomForm(function(Player $p, ?array $data) : void {
            if ($data === null) return;
            $name = strtolower(trim((string)($data[0] ?? '')));
            $orderRaw = trim((string)($data[1] ?? ''));
            if ($name === '') { $p->sendMessage(TF::RED . 'Track name is required'); return; }
            $groups = array_values(array_filter(array_map(fn($s) => strtolower(trim((string)$s)), explode(',', $orderRaw)), fn($s) => $s !== ''));
            $unknown = $this->pm->setTrack($name, $groups);
            if (!empty($unknown)) { $p->sendMessage(TF::RED . 'Unknown groups: ' . implode(', ', $unknown)); return; }
            $this->pm->save();
            $p->sendMessage(TF::GREEN . "Created track '$name'.");
            $this->openTracks($p);
        });
        $form->setTitle("Create Track");
        $form->addInput("Name (required)");
        $form->addInput("Group order (comma-separated)");
        $player->sendForm($form);
    }
}
