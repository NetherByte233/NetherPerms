<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\command;

use NetherByte\NetherPerms\NetherPerms;
use NetherByte\NetherPerms\util\ContextUtil;
use NetherByte\NetherPerms\util\DurationParser;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;

final class UserCommand
{
    public function __construct(private NetherPerms $plugin) {}

    public function handleUser(CommandSender $sender, array $args) : void
    {
        $pm = $this->plugin->getPermissionManager();
        if (!$sender->hasPermission('netherperms.user')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user'); return; }
        if (count($args) < 3) { $sender->sendMessage(TF::RED . 'Usage: /np user <user> <info|parent|permission|meta> ...'); return; }

        $knownSubs = ['info','parent','permission','meta'];
        $subIndex = -1;
        for ($i = 2; $i < count($args); $i++) {
            $t = strtolower((string)$args[$i]);
            if (in_array($t, $knownSubs, true)) { $subIndex = $i; break; }
        }
        if ($subIndex === -1) { $sender->sendMessage(TF::RED . 'Missing user subcommand. Use one of: ' . implode('|', $knownSubs)); return; }
        $playerName = implode(' ', array_slice($args, 1, $subIndex - 1));
        $sub = strtolower((string)$args[$subIndex]);

        // Resolve player/uuid (online or stored offline)
        $player = $this->plugin->getServer()->getPlayerExact($playerName);
        $uuid = null;
        if ($player !== null) {
            $uuid = $player->getUniqueId()->toString();
            if (!$pm->userExists($uuid)) { $pm->createUser($uuid, $player->getName()); }
        } else {
            $uuid = $pm->findUserUuidByName($playerName);
            if ($uuid === null) { $sender->sendMessage(TF::RED . 'Player not online and no stored data found. Ask them to join once.'); return; }
        }

        switch ($sub) {
            case 'info':
                if (!$sender->hasPermission('netherperms.user.info')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.info'); return; }
                $this->outputUserInfo($sender, $pm, $uuid, $playerName);
                return;
            case 'parent':
                $this->handleParent($sender, $uuid, array_slice($args, $subIndex + 1), $playerName, $player);
                return;
            case 'permission':
                $this->handlePermission($sender, $uuid, array_slice($args, $subIndex + 1), $playerName, $player);
                return;
            case 'meta':
                $this->handleMeta($sender, $uuid, array_slice($args, $subIndex + 1), $playerName, $player);
                return;
        }
        $sender->sendMessage(TF::RED . 'Unknown user subcommand');
    }

    private function outputUserInfo(CommandSender $sender, $pm, string $uuid, string $fallbackName) : void
    {
        $user = $pm->getUser($uuid);
        if ($user === null) { $sender->sendMessage(TF::RED . 'No data for player'); return; }
        $groups = (array)($user['groups'] ?? []);
        $computedPrimary = $pm->getComputedPrimaryGroup($uuid) ?? '(none)';
        $prefix = $pm->getResolvedPrefix($uuid) ?? '';
        $suffix = $pm->getResolvedSuffix($uuid) ?? '';
        $sender->sendMessage(TF::YELLOW . 'User: ' . ($user['name'] ?? $fallbackName));
        $sender->sendMessage('Groups: ' . (empty($groups) ? '(none)' : implode(', ', $groups)));
        // Show temporary parents if any
        $tp = (array)$pm->getUserTempParents($uuid);
        if (!empty($tp)) {
            $sender->sendMessage('Temp Parents:');
            $now = time();
            foreach ($tp as $ent) {
                if (!is_array($ent)) continue; $g = (string)($ent['group'] ?? ''); $exp = (int)($ent['expires'] ?? 0); $ctx = (string)($ent['context'] ?? '');
                if ($g === '' || $exp <= $now) continue;
                $remain = $exp - $now; $ctxDisp = $ctx !== '' ? " ($ctx)" : '';
                $sender->sendMessage(" - $g: " . $this->formatRemaining($remain) . $ctxDisp);
            }
        }
        $sender->sendMessage('Primary: ' . $computedPrimary);
        if ($prefix !== '' || $suffix !== '') {
            $sender->sendMessage('Meta: ' . ($prefix !== '' ? ("prefix=\"$prefix\"") : '') . (($prefix !== '' && $suffix !== '') ? ', ' : '') . ($suffix !== '' ? ("suffix=\"$suffix\"") : ''));
        }
    }

    // Removed primary subcommand handler

    private function handleParent(CommandSender $sender, string $uuid, array $args, string $playerName, ?Player $player) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $action = strtolower($args[0] ?? ''); $group = $args[1] ?? '';
        if ($action === 'add') {
            if ($group === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> parent add <group> [context...]'); return; }
            if (!$pm->addUserGroup($uuid, $group)) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Added $playerName to group $group.");
            return;
        } elseif ($action === 'set') {
            if ($group === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> parent set <group> [context...]'); return; }
            // Replace all membership with just this group
            foreach ((array)$pm->getUserGroups($uuid) as $g) { $pm->removeUserGroup($uuid, (string)$g); }
            if (!$pm->addUserGroup($uuid, $group)) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Set groups of $playerName to [$group].");
            return;
        } elseif ($action === 'remove') {
            if ($group === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> parent remove <group> [context...]'); return; }
            $pm->removeUserGroup($uuid, $group); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Removed $playerName from group $group.");
            return;
        } elseif ($action === 'switchprimarygroup') {
            if ($group === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> parent switchprimarygroup <group>'); return; }
            if (!$pm->setPrimaryGroup($uuid, $group)) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Switched primary group of $playerName to $group.");
            return;
        } elseif ($action === 'info') {
            // Permission intentionally as requested: netherperms.group.parent.info
            if (!$sender->hasPermission('netherperms.group.parent.info')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.info'); return; }
            $groups = (array)$pm->getUserGroups($uuid);
            $sender->sendMessage(TF::YELLOW . "Parents (groups) of $playerName: " . (empty($groups) ? '(none)' : implode(', ', $groups)));
            return;
        } elseif ($action === 'addtemp') {
            // /np user <user> parent addtemp <group> <duration> [context]
            if ($group === '' || !isset($args[2])) { $sender->sendMessage(TF::RED . 'Usage: /np user <user> parent addtemp <group> <duration> [context]'); return; }
            $durationToken = (string)$args[2];
            $seconds = DurationParser::parse($durationToken);
            if ($seconds === null || $seconds <= 0) { $sender->sendMessage(TF::RED . 'Invalid duration. Examples: 10m, 1h30m, 2d'); return; }
            $ctx = ContextUtil::parseContextArgs($args, 3);
            $ok = $pm->addUserTempParent($uuid, $group, $seconds, $ctx);
            if (!$ok) { $sender->sendMessage(TF::RED . 'Failed to add temporary parent (check group exists or temporary-add-behaviour).'); return; }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Temporarily added parent $group to $playerName for $durationToken.");
            return;
        } elseif ($action === 'removetemp') {
            // /np user <user> parent removetemp <group> [context]
            if ($group === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> parent removetemp <group> [context]'); return; }
            $ctx = ContextUtil::parseContextArgs($args, 2);
            $pm->unsetUserTempParent($uuid, $group, $ctx); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Removed temporary parent $group from $playerName.");
            return;
        }
        $sender->sendMessage(TF::RED . 'Unknown user parent subcommand (use add/set/remove/switchprimarygroup/info/addtemp/removetemp)');
    }

    private function handlePermission(CommandSender $sender, string $uuid, array $args, string $playerName, ?Player $player) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $action = strtolower($args[0] ?? '');
        $node = $args[1] ?? '';
        if ($action === 'info') {
            if (!$sender->hasPermission('netherperms.user.permission.info')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.permission.info'); return; }
            $user = $pm->getUser($uuid);
            $list = (array)($user['permissions'] ?? []);
            $count = count($list);
            $sender->sendMessage(TF::YELLOW . "Permissions for $playerName ($count):");
            if ($count === 0) { $sender->sendMessage('(none)'); }
            else {
                ksort($list, SORT_NATURAL | SORT_FLAG_CASE);
                foreach ($list as $n => $val) {
                    if (is_bool($val)) {
                        $sender->sendMessage($n . '=' . ($val ? 'true' : 'false'));
                    } elseif (is_array($val)) {
                        if (array_key_exists('__global__', $val)) { $sender->sendMessage($n . '=' . ($val['__global__'] ? 'true' : 'false')); }
                        foreach ($val as $ckey => $cv) {
                            if ($ckey === '__global__') continue;
                            $parts = $ckey !== '' ? explode(';', $ckey) : [];
                            $ctxStr = '';
                            foreach ($parts as $p) { if ($p !== '') { $ctxStr .= ' (' . $p . ')'; } }
                            $sender->sendMessage($n . '=' . ($cv ? 'true' : 'false') . $ctxStr);
                        }
                    }
                }
            }
            // Temp permissions
            $temps = (array)($user['temp_permissions'] ?? []);
            if (!empty($temps)) {
                $sender->sendMessage(TF::YELLOW . 'Temporary permissions:');
                $now = time();
                foreach ($temps as $ent) {
                    if (!is_array($ent)) continue; $n = (string)($ent['node'] ?? ''); $val = isset($ent['value']) ? (bool)$ent['value'] : null; $exp = (int)($ent['expires'] ?? 0); $ctx = (string)($ent['context'] ?? '');
                    if ($n === '' || $val === null || $exp <= $now) continue;
                    $remain = $exp - $now; $ctxDisp = $ctx !== '' ? " ($ctx)" : '';
                    $sender->sendMessage(" - $n=" . ($val?'true':'false') . ' ' . $this->formatRemaining($remain) . $ctxDisp);
                }
            }
            return;
        }
        if ($action === 'set' || $action === 'unset') {
            if ($node === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> permission set|unset <node> [true|false] [context...]'); return; }
            if ($action === 'set') {
                $next = $args[2] ?? 'true';
                $bool = filter_var($next, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool !== null) { $value = (bool)$bool; $ctxStart = 3; } else { $value = true; $ctxStart = 2; }
                $ctx = ContextUtil::parseContextArgs($args, $ctxStart);
                $pm->setUserPermission($uuid, $node, $value, $ctx);
                $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                $sender->sendMessage(TF::GREEN . "Set $node=" . ($value?'true':'false') . " for $playerName.");
                return;
            } else {
                $pm->unsetUserPermission($uuid, $node); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                $sender->sendMessage(TF::GREEN . "Unset $node for $playerName.");
                return;
            }
        } elseif ($action === 'settemp') {
            if ($node === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> permission settemp <node> [true|false] <duration> [context]'); return; }
            $next = $args[2] ?? 'true';
            $hasBool = filter_var($next, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($hasBool !== null) { $value = (bool)$hasBool; $durationTokenIndex = 3; } else { $value = true; $durationTokenIndex = 2; }
            $durationToken = (string)($args[$durationTokenIndex] ?? '');
            $seconds = DurationParser::parse($durationToken);
            if ($seconds === null || $seconds <= 0) { $sender->sendMessage(TF::RED . 'Invalid duration. Examples: 10m, 1h30m, 2d'); return; }
            $ctx = ContextUtil::parseContextArgs($args, $durationTokenIndex + 1);
            $ok = $pm->addUserTempPermission($uuid, $node, $value, $seconds, $ctx);
            if (!$ok) { $sender->sendMessage(TF::RED . 'Failed to add temporary node due to temporary-add-behaviour=deny.'); return; }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Temporarily set $node=" . ($value ? 'true' : 'false') . " for $playerName for $durationToken.");
            return;
        } elseif ($action === 'unsettemp') {
            if ($node === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> permission unsettemp <node> [context]'); return; }
            $ctx = ContextUtil::parseContextArgs($args, 2);
            $pm->unsetUserTempPermission($uuid, $node, $ctx); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Removed temporary $node for $playerName.");
            return;
        }
        $sender->sendMessage(TF::RED . 'Unknown user permission subcommand (use set|unset|settemp|unsettemp)');
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
        if ($s > 0 && empty($parts)) $parts[] = $s . 's';
        if ($s > 0 && !empty($parts)) $parts[] = $s . 's';
        return implode(' ', $parts);
    }

    private function handleMeta(CommandSender $sender, string $uuid, array $args, string $playerName, ?Player $player) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $action = strtolower($args[0] ?? '');
        if ($action === 'info') {
            $user = $pm->getUser($uuid) ?? [];
            $sender->sendMessage(TF::YELLOW . "Meta for $playerName:");
            $base = (array)($user['meta'] ?? []);
            if (empty($base)) { $sender->sendMessage('Base: (none)'); } else { $sender->sendMessage('Base:'); foreach ($base as $k=>$v) { $sender->sendMessage(" - $k=\"$v\""); } }
            $mctx = (array)($user['meta_context'] ?? []);
            if (!empty($mctx)) { $sender->sendMessage('Contextual:'); foreach ($mctx as $k=>$map) { foreach ((array)$map as $ck=>$v) { $sender->sendMessage(" - $k=\"$v\" ($ck)"); } } }
            $tmeta = (array)($user['temp_meta'] ?? []); $now = time();
            if (!empty($tmeta)) { $sender->sendMessage('Temporary:'); foreach ($tmeta as $e) { if (!is_array($e)) continue; $k=(string)($e['key']??''); $v=(string)($e['value']??''); $ck=(string)($e['context']??''); $exp=(int)($e['expires']??0); if ($k===''||$v===''||$exp<=$now) continue; $sender->sendMessage(" - $k=\"$v\" " . $this->formatRemaining($exp-$now) . ($ck!==''?" ($ck)":"")); } }
            return;
        }
        $key = strtolower($args[1] ?? '');
        if ($key === '') { $sender->sendMessage(TF::RED . 'Usage: /np user ' . $playerName . ' meta <info|set|unset|settemp|unsettemp> <key> [value] [context...]'); return; }
        if ($action === 'set') {
            $value = (string)($args[2] ?? '');
            $ctx = ContextUtil::parseContextArgs($args, 3);
            if (empty($ctx)) { $pm->setUserMeta($uuid, $key, $value); }
            else { $pm->setUserMetaContext($uuid, $key, $value, $ctx); }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Set $key=\"$value\" for $playerName" . (empty($ctx)?'':(' in context')) . '.');
            return;
        } elseif ($action === 'unset') {
            $ctx = ContextUtil::parseContextArgs($args, 2);
            if (empty($ctx)) { $pm->unsetUserMeta($uuid, $key); }
            else { $pm->unsetUserMetaContext($uuid, $key, $ctx); }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Unset $key for $playerName" . (empty($ctx)?'':(' in context')) . '.');
            return;
        } elseif ($action === 'settemp') {
            $value = (string)($args[2] ?? ''); $durationToken = (string)($args[3] ?? '');
            $seconds = DurationParser::parse($durationToken); if ($seconds === null || $seconds <= 0) { $sender->sendMessage(TF::RED . 'Invalid duration.'); return; }
            $ctx = ContextUtil::parseContextArgs($args, 4);
            $ok = $pm->addUserTempMeta($uuid, $key, $value, $seconds, $ctx);
            if (!$ok) { $sender->sendMessage(TF::RED . 'Failed to add temporary meta.'); return; }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Temporarily set $key=\"$value\" for $playerName $durationToken.");
            return;
        } elseif ($action === 'unsettemp') {
            $ctx = ContextUtil::parseContextArgs($args, 2);
            $pm->unsetUserTempMeta($uuid, $key, $ctx); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Removed temporary meta $key for $playerName.");
            return;
        }
        $sender->sendMessage(TF::RED . 'Unknown user meta subcommand (use info|set|unset|settemp|unsettemp)');
    }
}
