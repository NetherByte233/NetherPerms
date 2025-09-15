<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\command;

use NetherByte\NetherPerms\NetherPerms;
use NetherByte\NetherPerms\util\ContextUtil;
use NetherByte\NetherPerms\util\DurationParser;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat as TF;

final class GroupCommand
{
    public function __construct(private NetherPerms $plugin) {}

    public function handleRoot(CommandSender $sender, array $args) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $sub = strtolower($args[0] ?? '');
        switch ($sub) {
            case 'listgroups':
                if (!$sender->hasPermission('netherperms.group.list')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.list'); return; }
                $groups = array_keys($pm->getAllGroups()); sort($groups);
                $sender->sendMessage(TF::YELLOW . 'Groups: ' . (empty($groups) ? '(none)' : implode(', ', $groups)));
                return;
            case 'creategroup':
                if (!$sender->hasPermission('netherperms.creategroup')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.creategroup'); return; }
                if (count($args) < 2) { $sender->sendMessage(TF::RED . 'Usage: /np creategroup <group> [weight] [displayname]'); return; }
                $group = $args[1];
                if ($pm->getGroup($group) !== null) { $sender->sendMessage(TF::RED . 'Group already exists'); return; }
                $pm->createGroup($group);
                if (isset($args[2])) { $w = filter_var($args[2], FILTER_VALIDATE_INT); if ($w !== false) { $pm->setGroupWeight($group, (int)$w); } }
                if (isset($args[3])) { $pm->setGroupMeta($group, 'displayname', implode(' ', array_slice($args, 3))); }
                $pm->save();
                $sender->sendMessage(TF::GREEN . "Group '$group' created.");
                return;
            case 'deletegroup':
                if (!$sender->hasPermission('netherperms.deletegroup')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.deletegroup'); return; }
                if (count($args) < 2) { $sender->sendMessage(TF::RED . 'Usage: /np deletegroup <group>'); return; }
                $group = $args[1];
                if ($pm->getGroup($group) === null) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
                $pm->deleteGroup($group); $pm->save();
                $sender->sendMessage(TF::GREEN . "Group '$group' deleted.");
                return;
        }
        $sender->sendMessage(TF::RED . 'Unknown command');
    }

    public function handleGroup(CommandSender $sender, array $args) : void
    {
        $pm = $this->plugin->getPermissionManager();
        if (count($args) < 3) {
            $sender->sendMessage(TF::RED . 'Usage: /np group <group> <info|permission|parent|setweight|setdisplayname|meta|listmembers|showtracks|rename|clone> ...');
            return;
        }
        $group = $args[1];
        $sub = strtolower($args[2] ?? '');
        if ($pm->getGroup($group) === null) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
        switch ($sub) {
            case 'info':
                if (!$sender->hasPermission('netherperms.group.info')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.info'); return; }
                $g = $pm->getGroup($group);
                $parents = (array)($g['parents'] ?? []);
                $weight = (string)($g['weight'] ?? '0');
                $meta = (array)($g['meta'] ?? []);
                $sender->sendMessage(TF::YELLOW . "Group: $group");
                $sender->sendMessage('Weight: ' . $weight);
                $sender->sendMessage('Parents: ' . (empty($parents) ? '(none)' : implode(', ', $parents)));
                if (!empty($meta)) { foreach ($meta as $k => $v) { $sender->sendMessage("Meta $k: $v"); } }
                return;
            case 'permission':
                $this->handlePermission($sender, $group, $args);
                return;
            case 'parent':
                $this->handleParent($sender, $group, $args);
                return;
            case 'setweight':
                if (!$sender->hasPermission('netherperms.group.setweight')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.setweight'); return; }
                if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' setweight <int>'); return; }
                $weight = filter_var($args[3], FILTER_VALIDATE_INT);
                if ($weight === false) { $sender->sendMessage(TF::RED . 'Weight must be an integer'); return; }
                $pm->setGroupWeight($group, (int)$weight); $pm->save();
                $sender->sendMessage(TF::GREEN . "Set weight of '$group' to $weight.");
                return;
            case 'setdisplayname':
                if (!$sender->hasPermission('netherperms.group.setdisplayname')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.setdisplayname'); return; }
                if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' setdisplayname <name>'); return; }
                $value = implode(' ', array_slice($args, 3));
                $pm->setGroupMeta($group, 'displayname', $value); $pm->save();
                $this->applyOnlineAffectedByGroup($group);
                $sender->sendMessage(TF::GREEN . "Set displayname for group '$group' to '$value'.");
                return;
            case 'meta':
                if (!$sender->hasPermission('netherperms.group.meta')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.meta'); return; }
                if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' meta <set|unset> <prefix|suffix> [value]'); return; }
                $action = strtolower($args[3]); $key = strtolower($args[4]);
                if (!in_array($key, ['prefix','suffix'], true)) { $sender->sendMessage(TF::RED . 'Key must be prefix or suffix'); return; }
                if ($action === 'set') {
                    if (!$sender->hasPermission('netherperms.group.meta.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.meta.set'); return; }
                    $value = $args[5] ?? '';
                    $pm->setGroupMeta($group, $key, $value); $pm->save();
                    $this->applyOnlineAffectedByGroup($group);
                    $sender->sendMessage(TF::GREEN . "Set $key for group '$group' to '$value'.");
                    return;
                } elseif ($action === 'unset') {
                    if (!$sender->hasPermission('netherperms.group.meta.unset')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.meta.unset'); return; }
                    $pm->unsetGroupMeta($group, $key); $pm->save();
                    $this->applyOnlineAffectedByGroup($group);
                    $sender->sendMessage(TF::GREEN . "Unset $key for group '$group'.");
                    return;
                }
                $sender->sendMessage(TF::RED . 'Unknown action (use set|unset)');
                return;
            case 'listmembers':
                if (!$sender->hasPermission('netherperms.group.listmembers')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.listmembers'); return; }
                $pageToken = $args[3] ?? '1';
                $page = filter_var($pageToken, FILTER_VALIDATE_INT); if ($page === false || $page === null || $page < 1) { $page = 1; }
                $allUsers = $pm->getAllUsers();
                $members = [];
                foreach ($allUsers as $u) {
                    $ugs = (array)($u['groups'] ?? []);
                    if (in_array(strtolower($group), array_map('strtolower', $ugs), true)) { $members[] = (string)($u['name'] ?? 'unknown'); }
                }
                sort($members, SORT_NATURAL | SORT_FLAG_CASE);
                $total = count($members); $perPage = 10; $maxPage = max(1, (int)ceil($total / $perPage)); if ($page > $maxPage) { $page = $maxPage; }
                $offset = ($page - 1) * $perPage; $slice = array_slice($members, $offset, $perPage);
                $sender->sendMessage(TF::YELLOW . "Members of '$group' (page $page/$maxPage, total $total):");
                if (empty($slice)) { $sender->sendMessage('(none)'); } else { foreach ($slice as $name) { $sender->sendMessage(' - ' . $name); } }
                return;
            case 'showtracks':
                if (!$sender->hasPermission('netherperms.group.showtracks')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.showtracks'); return; }
                $tracks = $pm->getTracks(); $found = false; $sender->sendMessage(TF::YELLOW . "[NP] $group's Tracks:");
                foreach ($tracks as $tName => $order) {
                    $order = array_map('strtolower', (array)$order);
                    if (in_array(strtolower($group), $order, true)) {
                        $found = true; $pretty = '(' . implode(' ---> ', $order) . ')';
                        $sender->sendMessage('> ' . $tName . ': ');
                        $sender->sendMessage('  ' . $pretty);
                    }
                }
                if (!$found) { $sender->sendMessage('(none)'); }
                return;
            case 'rename':
                if (!$sender->hasPermission('netherperms.group.rename')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.rename'); return; }
                if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' rename <newName>'); return; }
                $newName = (string)$args[3];
                if ($pm->getGroup($newName) !== null) { $sender->sendMessage(TF::RED . "Group '$newName' already exists"); return; }
                if (!$pm->renameGroup($group, $newName)) { $sender->sendMessage(TF::RED . 'Rename failed (group not found or name exists)'); return; }
                $pm->save(); foreach ($this->plugin->getServer()->getOnlinePlayers() as $op) { $this->plugin->applyPermissions($op); }
                $sender->sendMessage(TF::GREEN . "Renamed group '$group' to '$newName'.");
                return;
            case 'clone':
                if (!$sender->hasPermission('netherperms.group.clone')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.clone'); return; }
                if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' clone <cloneName>'); return; }
                $cloneName = (string)$args[3];
                if ($pm->getGroup($cloneName) !== null) { $sender->sendMessage(TF::RED . "Group '$cloneName' already exists"); return; }
                if (!$pm->cloneGroup($group, $cloneName)) { $sender->sendMessage(TF::RED . 'Clone failed (source not found or target exists)'); return; }
                $pm->save(); $sender->sendMessage(TF::GREEN . "Cloned group '$group' to '$cloneName'.");
                return;
        }
        $sender->sendMessage(TF::RED . 'Unknown group subcommand');
    }

    private function handlePermission(CommandSender $sender, string $group, array $args) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $action = strtolower($args[3] ?? '');
        if ($action === 'info') {
            if (!$sender->hasPermission('netherperms.group.permission.info')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.permission.info'); return; }
            $g = $pm->getGroup($group);
            $list = (array)($g['permissions'] ?? []);
            $count = count($list);
            $sender->sendMessage(TF::YELLOW . "Permission for group '$group' ($count):");
            if ($count === 0) { $sender->sendMessage('(none)'); return; }
            ksort($list, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($list as $node => $val) {
                if (is_bool($val)) {
                    $sender->sendMessage($node . '=' . ($val ? 'true' : 'false'));
                } elseif (is_array($val)) {
                    if (array_key_exists('__global__', $val)) { $sender->sendMessage($node . '=' . ($val['__global__'] ? 'true' : 'false')); }
                    foreach ($val as $ckey => $cv) {
                        if ($ckey === '__global__') continue;
                        $parts = $ckey !== '' ? explode(';', $ckey) : [];
                        $ctxStr = '';
                        foreach ($parts as $p) { if ($p !== '') { $ctxStr .= ' (' . $p . ')'; } }
                        $sender->sendMessage($node . '=' . ($cv ? 'true' : 'false') . $ctxStr);
                    }
                }
            }
            return;
        }
        if ($action === 'set') {
            if (!$sender->hasPermission('netherperms.group.permission.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.permission.set'); return; }
            if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' permission set <node> [true|false] [context...]'); return; }
            $node = $args[4]; $next = $args[5] ?? 'true'; $bool = filter_var($next, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) { $value = (bool)$bool; $ctxStart = 6; } else { $value = true; $ctxStart = 5; }
            $multi = ContextUtil::parseMultiContextArgs($args, $ctxStart); $variants = ContextUtil::expandContextVariants($multi);
            if (empty($variants)) { $pm->setGroupPermission($group, $node, $value, []); }
            else { foreach ($variants as $ctx) { $pm->setGroupPermission($group, $node, $value, $ctx); } }
            $pm->save(); $this->applyOnlineAffectedByGroup($group);
            $sender->sendMessage(TF::GREEN . "Set $node=" . ($value?'true':'false') . " on group '$group'" . (empty($variants) ? '' : (' for ' . count($variants) . ' context(s)')) . '.');
            return;
        } elseif ($action === 'unset') {
            if (!$sender->hasPermission('netherperms.group.permission.unset')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.permission.unset'); return; }
            if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' permission unset <node>'); return; }
            $node = $args[4]; $multi = ContextUtil::parseMultiContextArgs($args, 5); $variants = ContextUtil::expandContextVariants($multi);
            if (empty($variants)) { $pm->unsetGroupPermission($group, $node); }
            else { foreach ($variants as $ctx) { $pm->unsetGroupPermissionContext($group, $node, $ctx); } }
            $pm->save(); $this->applyOnlineAffectedByGroup($group);
            $sender->sendMessage(TF::GREEN . "Unset $node on group '$group'" . (empty($variants) ? '' : (' for ' . count($variants) . ' context(s)')) . '.');
            return;
        } elseif ($action === 'settemp') {
            if (!$sender->hasPermission('netherperms.group.permission.settemp')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.permission.settemp'); return; }
            if (count($args) < 6) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' permission settemp <node> [true|false] <duration> [context...]'); return; }
            $node = $args[4]; $next = $args[5] ?? 'true'; $hasBool = filter_var($next, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($hasBool !== null) { $value = (bool)$hasBool; $durationTokenIndex = 6; } else { $value = true; $durationTokenIndex = 5; }
            $durationToken = (string)($args[$durationTokenIndex] ?? ''); $seconds = DurationParser::parse($durationToken);
            if ($seconds === null || $seconds <= 0) { $sender->sendMessage(TF::RED . 'Invalid duration. Examples: 10m, 1h30m, 2d'); return; }
            $multi = ContextUtil::parseMultiContextArgs($args, $durationTokenIndex + 1); $variants = ContextUtil::expandContextVariants($multi);
            $added = 0; $denied = 0;
            if (empty($variants)) { $added += $pm->addGroupTempPermission($group, $node, $value, $seconds, []) ? 1 : 0; $denied += $added ? 0 : 1; }
            else { foreach ($variants as $ctx) { $ok = $pm->addGroupTempPermission($group, $node, $value, $seconds, $ctx); if ($ok) $added++; else $denied++; } }
            $pm->save(); $this->applyOnlineAffectedByGroup($group);
            if ($denied > 0 && $added === 0) { $sender->sendMessage(TF::RED . 'Failed to add any temporary node due to temporary-add-behaviour=deny.'); return; }
            $msg = TF::GREEN . "Temporarily set $node=" . ($value ? 'true' : 'false') . " on group '$group' for $durationToken";
            if (!empty($variants)) { $msg .= " across $added context(s)"; if ($denied > 0) { $msg .= ", $denied denied"; } }
            $sender->sendMessage($msg . '.');
            return;
        } elseif ($action === 'unsettemp') {
            if (!$sender->hasPermission('netherperms.group.permission.unsettemp')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.permission.unsettemp'); return; }
            if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' permission unsettemp <node> [context...]'); return; }
            $node = $args[4]; $multi = ContextUtil::parseMultiContextArgs($args, 5); $variants = ContextUtil::expandContextVariants($multi);
            if (empty($variants)) { $pm->unsetGroupTempPermission($group, $node, []); }
            else { foreach ($variants as $ctx) { $pm->unsetGroupTempPermission($group, $node, $ctx); } }
            $pm->save(); $this->applyOnlineAffectedByGroup($group);
            $sender->sendMessage(TF::GREEN . "Removed temporary $node on group '$group'" . (empty($variants) ? '' : (' across ' . count($variants) . ' context(s)')) . '.');
            return;
        }
        $sender->sendMessage(TF::RED . 'Unknown action (use set|unset)');
    }

    private function handleParent(CommandSender $sender, string $group, array $args) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $action = strtolower($args[3] ?? ''); $parent = $args[4] ?? null;
        if ($action === 'add') {
            if (!$sender->hasPermission('netherperms.group.parent.add')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.add'); return; }
            if ($parent === null) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' parent add <parent>'); return; }
            if (!$pm->addParent($group, $parent)) { $sender->sendMessage(TF::RED . 'Parent group not found'); return; }
            $pm->save(); $this->applyOnlineAffectedByGroup($group); $sender->sendMessage(TF::GREEN . "Added parent '$parent' to group '$group'."); return;
        } elseif ($action === 'set') {
            if (!$sender->hasPermission('netherperms.group.parent.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.set'); return; }
            if ($parent === null) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' parent set <parent>'); return; }
            $g = $pm->getGroup($group); if ($g !== null) { foreach (($g['parents'] ?? []) as $p) { $pm->removeParent($group, (string)$p); } }
            if (!$pm->addParent($group, $parent)) { $sender->sendMessage(TF::RED . 'Parent group not found'); return; }
            $pm->save(); $this->applyOnlineAffectedByGroup($group); $sender->sendMessage(TF::GREEN . "Set parent of group '$group' to '$parent'."); return;
        } elseif ($action === 'remove') {
            if (!$sender->hasPermission('netherperms.group.parent.remove')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.remove'); return; }
            if ($parent === null) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' parent remove <parent>'); return; }
            $pm->removeParent($group, $parent); $pm->save(); $this->applyOnlineAffectedByGroup($group); $sender->sendMessage(TF::GREEN . "Removed parent '$parent' from group '$group'."); return;
        } elseif ($action === 'list') {
            if (!$sender->hasPermission('netherperms.group.parent.list')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.list'); return; }
            $g = $pm->getGroup($group); $parents = $g !== null ? ($g['parents'] ?? []) : [];
            $sender->sendMessage(TF::YELLOW . "Parents of '$group': " . (empty($parents) ? '(none)' : implode(', ', $parents)));
            return;
        }
        $sender->sendMessage(TF::RED . 'Unknown parent subcommand (use add/set/remove/list)');
    }

    private function applyOnlineAffectedByGroup(string $group) : void
    {
        foreach ($this->plugin->getServer()->getOnlinePlayers() as $op) {
            $this->plugin->applyPermissions($op);
        }
    }
}
