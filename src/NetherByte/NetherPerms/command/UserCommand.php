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
        if (count($args) < 3) { $sender->sendMessage(TF::RED . 'Usage: /np user <user> <info|parent|permission|meta|primary> ...'); return; }

        $knownSubs = ['info','parent','permission','meta','primary'];
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
            case 'primary':
                $this->handlePrimary($sender, $uuid, array_slice($args, $subIndex + 1), $playerName, $player);
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
        $sender->sendMessage('Primary: ' . $computedPrimary);
        if ($prefix !== '' || $suffix !== '') {
            $sender->sendMessage('Meta: ' . ($prefix !== '' ? ("prefix=\"$prefix\"") : '') . (($prefix !== '' && $suffix !== '') ? ', ' : '') . ($suffix !== '' ? ("suffix=\"$suffix\"") : ''));
        }
    }

    private function handlePrimary(CommandSender $sender, string $uuid, array $args, string $playerName, ?Player $player) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $action = strtolower($args[0] ?? '');
        if ($action === 'show') {
            $pg = $pm->getPrimaryGroup($uuid) ?? '(none)';
            $sender->sendMessage(TF::YELLOW . "Primary group of $playerName: $pg");
            return;
        } elseif ($action === 'set') {
            $g = $args[1] ?? '';
            if ($g === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> primary set <group>'); return; }
            if (!$pm->setPrimaryGroup($uuid, $g)) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Set primary group of $playerName to $g.");
            return;
        } elseif ($action === 'unset') {
            $pm->unsetPrimaryGroup($uuid); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Unset primary group of $playerName.");
            return;
        }
        $sender->sendMessage(TF::RED . 'Usage: /np user <user> primary show|set <group>|unset');
    }

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
        }
        $sender->sendMessage(TF::RED . 'Unknown user parent subcommand (use add/set/remove/switchprimarygroup)');
    }

    private function handlePermission(CommandSender $sender, string $uuid, array $args, string $playerName, ?Player $player) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $action = strtolower($args[0] ?? '');
        $node = $args[1] ?? '';
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

    private function handleMeta(CommandSender $sender, string $uuid, array $args, string $playerName, ?Player $player) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $action = strtolower($args[0] ?? '');
        $key = strtolower($args[1] ?? '');
        if ($action === 'set') {
            if (!in_array($key, ['prefix','suffix'], true)) { $sender->sendMessage(TF::RED . 'Key must be prefix or suffix'); return; }
            $value = $args[2] ?? '';
            $pm->setUserMeta($uuid, $key, $value); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Set $key for $playerName to '$value'.");
            return;
        } elseif ($action === 'unset') {
            if (!in_array($key, ['prefix','suffix'], true)) { $sender->sendMessage(TF::RED . 'Key must be prefix or suffix'); return; }
            $pm->unsetUserMeta($uuid, $key); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
            $sender->sendMessage(TF::GREEN . "Unset $key for $playerName.");
            return;
        }
        $sender->sendMessage(TF::RED . 'Unknown user meta subcommand (use set|unset)');
    }
}
