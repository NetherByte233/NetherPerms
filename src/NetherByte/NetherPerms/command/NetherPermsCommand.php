<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\command;

use NetherByte\NetherPerms\NetherPerms;
use NetherByte\NetherPerms\command\GroupCommand;
use NetherByte\NetherPerms\command\UserCommand;
use NetherByte\NetherPerms\command\TrackCommand;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;

final class NetherPermsCommand extends Command
{
    private GroupCommand $groupCmd;
    private UserCommand $userCmd;
    private TrackCommand $trackCmd;

    public function __construct(private NetherPerms $plugin)
    {
        parent::__construct('netherperms', 'Manage NetherPerms', '/netherperms <...>', ['np', 'perms']);
        $this->setPermission('netherperms.command');
        $this->groupCmd = new GroupCommand($this->plugin);
        $this->userCmd = new UserCommand($this->plugin);
        $this->trackCmd = new TrackCommand($this->plugin);
    }

    // All detailed handling is delegated to GroupCommand/UserCommand/TrackCommand

    public function execute(CommandSender $sender, string $commandLabel, array $args) : void
    {
        if (!$this->testPermission($sender)) return;
        if (count($args) === 0) {
            $sender->sendMessage(TF::YELLOW . 'NetherPerms commands:' . "\n" .
                '/np editor' . "\n" .
                '/np reload' . "\n" .
                '/np listgroups' . "\n" .
                '/np creategroup <group> [weight] [displayname]' . "\n" .
                '/np deletegroup <group>' . "\n" .
                '/np group <group> info' . "\n" .
                '/np group <group> permission <set|unset> <node> [true|false] [context...]' . "\n" .
                '/np group <group> parent <add|set|remove|list> [parent]' . "\n" .
                '/np group <group> setweight <int>' . "\n" .
                '/np group <group> setdisplayname <name>' . "\n" .
                '/np group <group> meta <set|unset> <prefix|suffix> [value]' . "\n" .
                '/np group <group> listmembers [page]' . "\n" .
                '/np group <group> showtracks' . "\n" .
                '/np group <group> rename <newName>' . "\n" .
                '/np group <group> clone <cloneName>' . "\n" .
                '/np user <user> info' . "\n" .
                '/np user <user> parent <add|set|remove|switchprimarygroup> <group> [context...]' . "\n" .
                '/np user <user> permission <set|unset> <node> [true|false] [context...]' . "\n" .
                '/np user <user> permission <settemp|unsettemp> <node> [true|false] <duration> [context...]' . "\n" .
                '/np user <user> meta <set|unset> <prefix|suffix> [value]' . "\n" .
                '/np user <user> primary show|set <group>|unset' . "\n" .
                '/np track <track> set <g1> <g2> ...' . "\n" .
                '/np track <track> info' . "\n" .
                '/np track <track> rename <newName>' . "\n" .
                '/np track <track> clone <cloneName>' . "\n" .
                '/np track <track> insert <group> <position>' . "\n" .
                '/np track <track> remove <group>' . "\n" .
                '/np track <track> append <group>' . "\n" .
                '/np createtrack <track>' . "\n" .
                '/np deletetrack <track>' . "\n" .
                '/np listtracks' . "\n" .
                '/np promote <player> <track>' . "\n" .
                '/np demote <player> <track>' . "\n" .
                '/np info [player]'
            );
            return;
        }

        $pm = $this->plugin->getPermissionManager();
        switch (strtolower($args[0])) {
            case 'editor':
                if (!$sender->hasPermission('netherperms.ui')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.ui'); return; }
                if (!$sender instanceof Player) { $sender->sendMessage(TF::RED . 'Run this in-game.'); return; }
                $this->plugin->getUi()->openMain($sender);
                return;
            case 'reload':
                if (!$sender->hasPermission('netherperms.reload')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.reload'); return; }
                $this->plugin->reloadAll();
                $sender->sendMessage(TF::GREEN . 'NetherPerms reloaded.');
                return;
            case 'creategroup':
            case 'deletegroup':
            case 'listgroups':
                $this->groupCmd->handleRoot($sender, $args);
                return;
            case 'createtrack':
            case 'deletetrack':
            case 'listtracks':
                $this->trackCmd->handleRoot($sender, $args);
                return;
            case 'group':
                $this->groupCmd->handleGroup($sender, $args);
                return;
            case 'user':
                $this->userCmd->handleUser($sender, $args);
                return;
            case 'track':
                $this->trackCmd->handleTrack($sender, $args);
                return;
            case 'promote': {
                if (!$sender->hasPermission('netherperms.promote')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.promote'); return; }
                if (count($args) < 3) { $sender->sendMessage(TF::RED . 'Usage: /np promote <player> <track>'); return; }
                $playerName = $args[1];
                $track = strtolower((string)$args[2]);
                $player = $this->plugin->getServer()->getPlayerExact($playerName);
                if ($player !== null) {
                    $uuid = $player->getUniqueId()->toString();
                    if (!$pm->userExists($uuid)) { $pm->createUser($uuid, $player->getName()); }
                } else {
                    $uuid = $pm->findUserUuidByName($playerName);
                    if ($uuid === null) { $sender->sendMessage(TF::RED . 'Player not online and no stored data found. Ask them to join once.'); return; }
                }
                if (!isset($pm->getTracks()[$track])) { $sender->sendMessage(TF::RED . 'Track not found'); return; }
                if (!method_exists($pm, 'promote')) { $sender->sendMessage(TF::RED . 'Promote not available in this build.'); return; }
                $res = $pm->promote($uuid, $track);
                if ($res === null) { $sender->sendMessage(TF::RED . 'Track not found or already at top'); return; }
                $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                $name = $player !== null ? $player->getName() : ($pm->getUser($uuid)['name'] ?? $playerName);
                $sender->sendMessage(TF::GREEN . "Promoted $name to '{$res['next']}'.");
                return;
            }
                case 'demote': {
                    if (!$sender->hasPermission('netherperms.demote')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.demote'); return; }
                    if (count($args) < 3) { $sender->sendMessage(TF::RED . 'Usage: /np demote <player> <track>'); return; }
                    $playerName = $args[1];
                    $track = strtolower((string)$args[2]);
                    $player = $this->plugin->getServer()->getPlayerExact($playerName);
                    if ($player !== null) {
                        $uuid = $player->getUniqueId()->toString();
                        if (!$pm->userExists($uuid)) { $pm->createUser($uuid, $player->getName()); }
                    } else {
                        $uuid = $pm->findUserUuidByName($playerName);
                        if ($uuid === null) { $sender->sendMessage(TF::RED . 'Player not online and no stored data found. Ask them to join once.'); return; }
                    }
                    if (!isset($pm->getTracks()[$track])) { $sender->sendMessage(TF::RED . 'Track not found'); return; }
                    if (!method_exists($pm, 'demote')) { $sender->sendMessage(TF::RED . 'Demote not available in this build.'); return; }
                    $res = $pm->demote($uuid, $track);
                    if ($res === null) { $sender->sendMessage(TF::RED . 'Track not found or already at bottom'); return; }
                    $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                    $name = $player !== null ? $player->getName() : ($pm->getUser($uuid)['name'] ?? $playerName);
                    $sender->sendMessage(TF::GREEN . "Demoted $name to '{$res['next']}'.");
                    return;
                }
            // end switch
        }
    }

    // This class only routes top-level subcommands now
}
