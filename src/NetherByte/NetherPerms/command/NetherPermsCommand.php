<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\command;

use NetherByte\NetherPerms\NetherPerms;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;

final class NetherPermsCommand extends Command
{
    public function __construct(private NetherPerms $plugin)
    {
        parent::__construct('netherperms', 'Manage NetherPerms', '/netherperms <...>', ['np', 'perms']);
        $this->setPermission('netherperms.command');
    }

    private function stripQuotes(string $name) : string
    {
        if ($name === '') return $name;
        $first = $name[0];
        $last = $name[strlen($name) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($name, 1, -1);
        }
        return $name;
    }

    /**
     * Output consistent user info including groups, primary group, meta and permissions sample.
     * Centralized to keep maintenance simple and avoid duplication.
     */
    private function outputUserInfo(CommandSender $sender, $pm, string $uuid, string $fallbackName) : void
    {
        $user = $pm->getUser($uuid);
        if ($user === null) { $sender->sendMessage(TF::RED . 'No data for player'); return; }
        $groups = (array)($user['groups'] ?? []);
        $computedPrimary = $pm->getComputedPrimaryGroup($uuid) ?? '(none)';
        $prefix = $pm->getResolvedPrefix($uuid) ?? '';
        $suffix = $pm->getResolvedSuffix($uuid) ?? '';
        $effective = $pm->getEffectivePermissionsForUser($uuid, []);
        $permCount = count($effective);
        $sample = array_slice(array_keys($effective), 0, 10);
        $sender->sendMessage(TF::YELLOW . 'User: ' . ($user['name'] ?? $fallbackName));
        $sender->sendMessage('Groups: ' . (empty($groups) ? '(none)' : implode(', ', $groups)));
        $sender->sendMessage('Primary: ' . $computedPrimary);
        if ($prefix !== '' || $suffix !== '') {
            $sender->sendMessage('Meta: ' . ($prefix !== '' ? ("prefix=\"$prefix\"") : '') . (($prefix !== '' && $suffix !== '') ? ', ' : '') . ($suffix !== '' ? ("suffix=\"$suffix\"") : ''));
        }
        $sender->sendMessage('Permissions (' . $permCount . '): ' . (empty($sample) ? '(none)' : implode(', ', $sample) . ($permCount > 10 ? ' ...' : '')));
    }

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
            case 'creategroup': {
                if (!$sender->hasPermission('netherperms.creategroup')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.creategroup'); return; }
                if (count($args) < 2) { $sender->sendMessage(TF::RED . 'Usage: /np creategroup <group> [weight] [displayname]'); return; }
                $group = $args[1];
                if ($pm->getGroup($group) !== null) { $sender->sendMessage(TF::RED . 'Group already exists'); return; }
                $pm->createGroup($group);
                if (isset($args[2])) {
                    $weight = filter_var($args[2], FILTER_VALIDATE_INT);
                    if ($weight !== false) { $pm->setGroupWeight($group, (int)$weight); }
                }
                if (isset($args[3])) {
                    $pm->setGroupMeta($group, 'displayname', implode(' ', array_slice($args, 3)));
                }
                $pm->save();
                $sender->sendMessage(TF::GREEN . "Group '$group' created.");
                return;
            }
            case 'deletegroup': {
                if (!$sender->hasPermission('netherperms.deletegroup')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.deletegroup'); return; }
                if (count($args) < 2) { $sender->sendMessage(TF::RED . 'Usage: /np deletegroup <group>'); return; }
                $group = $args[1];
                if ($pm->getGroup($group) === null) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
                $pm->deleteGroup($group); $pm->save();
                $sender->sendMessage(TF::GREEN . "Group '$group' deleted.");
                return;
            }
            case 'listgroups': {
                if (!$sender->hasPermission('netherperms.group.list')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.list'); return; }
                $groups = array_keys($pm->getAllGroups()); sort($groups);
                $sender->sendMessage(TF::YELLOW . 'Groups: ' . (empty($groups) ? '(none)' : implode(', ', $groups)));
                return;
            }
            case 'createtrack': {
                if (!$sender->hasPermission('netherperms.track.create')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.create'); return; }
                if (count($args) < 2) { $sender->sendMessage(TF::RED . 'Usage: /np createtrack <track>'); return; }
                $track = strtolower($args[1]);
                $tracks = $pm->getTracks();
                if (isset($tracks[$track])) { $sender->sendMessage(TF::RED . 'Track already exists'); return; }
                $pm->createTrack($track); $pm->save();
                $sender->sendMessage(TF::GREEN . "Track '$track' created.");
                return;
            }
            case 'deletetrack': {
                if (!$sender->hasPermission('netherperms.track.delete')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.delete'); return; }
                if (count($args) < 2) { $sender->sendMessage(TF::RED . 'Usage: /np deletetrack <track>'); return; }
                $track = strtolower($args[1]);
                $tracks = $pm->getTracks();
                if (!isset($tracks[$track])) { $sender->sendMessage(TF::RED . 'Track not found'); return; }
                $pm->deleteTrack($track); $pm->save();
                $sender->sendMessage(TF::GREEN . "Track '$track' deleted.");
                return;
            }
            case 'listtracks': {
                if (!$sender->hasPermission('netherperms.track.list')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.list'); return; }
                $tracks = array_keys($pm->getTracks()); sort($tracks);
                $sender->sendMessage(TF::YELLOW . 'Tracks: ' . (empty($tracks) ? '(none)' : implode(', ', $tracks)));
                return;
            }
            case 'group':
                if (count($args) < 3) {
                    $sender->sendMessage(TF::RED . 'Usage: /np group <group> <info|permission|parent|setweight|setdisplayname|meta|listmembers|showtracks|rename|clone> ...');
                    $sender->sendMessage(TF::YELLOW . 'Tip: use /np creategroup, /np deletegroup, /np listgroups for management.');
                    return;
                }
                $first = strtolower($args[1]);
                // LP-style: /np group <group> <sub> ...
                if (!in_array($first, ['list','create','delete','permission','parent','setweight','setdisplayname','meta'], true)) {
                    $group = $args[1];
                    $sub = strtolower($args[2] ?? '');
                    if ($pm->getGroup($group) === null) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
                    switch ($sub) {
                        case 'info': {
                            if (!$sender->hasPermission('netherperms.group.info')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.info'); return; }
                            $g = $pm->getGroup($group);
                            $parents = (array)($g['parents'] ?? []);
                            $weight = (string)($g['weight'] ?? '0');
                            $perms = array_keys((array)($g['permissions'] ?? []));
                            $meta = (array)($g['meta'] ?? []);
                            $sender->sendMessage(TF::YELLOW . "Group: $group");
                            $sender->sendMessage('Weight: ' . $weight);
                            $sender->sendMessage('Parents: ' . (empty($parents) ? '(none)' : implode(', ', $parents)));
                            $sender->sendMessage('Permissions: ' . (empty($perms) ? '(none)' : implode(', ', $perms)));
                            if (!empty($meta)) {
                                foreach ($meta as $k => $v) {
                                    $sender->sendMessage("Meta $k: $v");
                                }
                            }
                            return;
                        }
                        case 'permission': {
                            // Permission checks per action
                            $action = strtolower($args[3] ?? '');
                            if ($action === 'set') {
                                if (!$sender->hasPermission('netherperms.group.permission.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.permission.set'); return; }
                                if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' permission set <node> [true|false] [context...]'); return; }
                                $node = $args[4];
                                $next = $args[5] ?? 'true';
                                $bool = filter_var($next, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                                if ($bool !== null) {
                                    $value = (bool)$bool;
                                    $ctxStart = 6;
                                } else {
                                    $value = true;
                                    $ctxStart = 5;
                                }
                                $ctx = $this->parseContextArgs($args, $ctxStart);
                                $pm->setGroupPermission($group, $node, $value, $ctx); $pm->save();
                                $this->applyOnlineAffectedByGroup($group);
                                $sender->sendMessage(TF::GREEN . "Set $node=" . (((bool)$value)?'true':'false') . " on group '$group'.");
                                return;
                            } elseif ($action === 'unset') {
                                if (!$sender->hasPermission('netherperms.group.permission.unset')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.permission.unset'); return; }
                                if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' permission unset <node>'); return; }
                                $node = $args[4];
                                $pm->unsetGroupPermission($group, $node); $pm->save();
                                $this->applyOnlineAffectedByGroup($group);
                                $sender->sendMessage(TF::GREEN . "Unset $node on group '$group'.");
                                return;
                            }
                            $sender->sendMessage(TF::RED . 'Unknown action (use set|unset)');
                            return;
                        }
                        case 'parent': {
                            $action = strtolower($args[3] ?? '');
                            $parent = $args[4] ?? null;
                            if ($action === 'add') {
                                if (!$sender->hasPermission('netherperms.group.parent.add')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.add'); return; }
                                if ($parent === null) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' parent add <parent>'); return; }
                                if (!$pm->addParent($group, $parent)) { $sender->sendMessage(TF::RED . 'Parent group not found'); return; }
                                $pm->save(); $this->applyOnlineAffectedByGroup($group);
                                $sender->sendMessage(TF::GREEN . "Added parent '$parent' to group '$group'.");
                                return;
                            } elseif ($action === 'set') {
                                if (!$sender->hasPermission('netherperms.group.parent.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.set'); return; }
                                if ($parent === null) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' parent set <parent>'); return; }
                                $g = $pm->getGroup($group);
                                if ($g !== null) { foreach (($g['parents'] ?? []) as $p) { $pm->removeParent($group, (string)$p); } }
                                if (!$pm->addParent($group, $parent)) { $sender->sendMessage(TF::RED . 'Parent group not found'); return; }
                                $pm->save(); $this->applyOnlineAffectedByGroup($group);
                                $sender->sendMessage(TF::GREEN . "Set parent of group '$group' to '$parent'.");
                                return;
                            } elseif ($action === 'remove') {
                                if (!$sender->hasPermission('netherperms.group.parent.remove')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.remove'); return; }
                                if ($parent === null) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' parent remove <parent>'); return; }
                                $pm->removeParent($group, $parent); $pm->save(); $this->applyOnlineAffectedByGroup($group);
                                $sender->sendMessage(TF::GREEN . "Removed parent '$parent' from group '$group'.");
                                return;
                            } elseif ($action === 'list') {
                                if (!$sender->hasPermission('netherperms.group.parent.list')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.parent.list'); return; }
                                $g = $pm->getGroup($group); $parents = $g !== null ? ($g['parents'] ?? []) : [];
                                $sender->sendMessage(TF::YELLOW . "Parents of '$group': " . (empty($parents) ? '(none)' : implode(', ', $parents)));
                                return;
                            }
                            $sender->sendMessage(TF::RED . 'Unknown parent subcommand (use add/set/remove/list)');
                            return;
                        }
                        case 'setweight': {
                            if (!$sender->hasPermission('netherperms.group.setweight')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.setweight'); return; }
                            if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' setweight <int>'); return; }
                            $weight = filter_var($args[3], FILTER_VALIDATE_INT);
                            if ($weight === false) { $sender->sendMessage(TF::RED . 'Weight must be an integer'); return; }
                            $pm->setGroupWeight($group, (int)$weight); $pm->save();
                            $sender->sendMessage(TF::GREEN . "Set weight of '$group' to $weight.");
                            return;
                        }
                        case 'setdisplayname': {
                            if (!$sender->hasPermission('netherperms.group.setdisplayname')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.setdisplayname'); return; }
                            if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' setdisplayname <name>'); return; }
                            $value = implode(' ', array_slice($args, 3));
                            $pm->setGroupMeta($group, 'displayname', $value); $pm->save();
                            $this->applyOnlineAffectedByGroup($group);
                            $sender->sendMessage(TF::GREEN . "Set displayname for group '$group' to '$value'.");
                            return;
                        }
                        case 'meta': {
                            if (!$sender->hasPermission('netherperms.group.meta')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.meta'); return; }
                            if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' meta <set|unset> <prefix|suffix> [value]'); return; }
                            $action = strtolower($args[3]);
                            $key = strtolower($args[4]);
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
                        }
                        case 'listmembers': {
                            if (!$sender->hasPermission('netherperms.group.listmembers')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.listmembers'); return; }
                            // page optional at args[3]
                            $pageToken = $args[3] ?? '1';
                            $page = filter_var($pageToken, FILTER_VALIDATE_INT);
                            if ($page === false || $page === null || $page < 1) { $page = 1; }
                            $allUsers = $pm->getAllUsers();
                            $members = [];
                            foreach ($allUsers as $u) {
                                $ugs = (array)($u['groups'] ?? []);
                                if (in_array(strtolower($group), array_map('strtolower', $ugs), true)) {
                                    $members[] = (string)($u['name'] ?? 'unknown');
                                }
                            }
                            sort($members, SORT_NATURAL | SORT_FLAG_CASE);
                            $total = count($members);
                            $perPage = 10;
                            $maxPage = max(1, (int)ceil($total / $perPage));
                            if ($page > $maxPage) { $page = $maxPage; }
                            $offset = ($page - 1) * $perPage;
                            $slice = array_slice($members, $offset, $perPage);
                            $sender->sendMessage(TF::YELLOW . "Members of '$group' (page $page/$maxPage, total $total):");
                            if (empty($slice)) {
                                $sender->sendMessage('(none)');
                            } else {
                                foreach ($slice as $name) { $sender->sendMessage(' - ' . $name); }
                            }
                            return;
                        }
                        case 'showtracks': {
                            if (!$sender->hasPermission('netherperms.group.showtracks')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.showtracks'); return; }
                            $tracks = $pm->getTracks();
                            $found = false;
                            $sender->sendMessage(TF::YELLOW . "[NP] $group's Tracks:");
                            foreach ($tracks as $tName => $order) {
                                $order = array_map('strtolower', (array)$order);
                                if (in_array(strtolower($group), $order, true)) {
                                    $found = true;
                                    // Build pretty string e.g., (a ---> b ---> c)
                                    $pretty = '(' . implode(' ---> ', $order) . ')';
                                    $sender->sendMessage('> ' . $tName . ': ');
                                    $sender->sendMessage('  ' . $pretty);
                                }
                            }
                            if (!$found) { $sender->sendMessage('(none)'); }
                            return;
                        }
                        case 'rename': {
                            if (!$sender->hasPermission('netherperms.group.rename')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.rename'); return; }
                            if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' rename <newName>'); return; }
                            $newName = (string)$args[3];
                            if ($pm->getGroup($newName) !== null) { $sender->sendMessage(TF::RED . "Group '$newName' already exists"); return; }
                            if (!$pm->renameGroup($group, $newName)) { $sender->sendMessage(TF::RED . 'Rename failed (group not found or name exists)'); return; }
                            $pm->save();
                            foreach ($this->plugin->getServer()->getOnlinePlayers() as $op) { $this->plugin->applyPermissions($op); }
                            $sender->sendMessage(TF::GREEN . "Renamed group '$group' to '$newName'.");
                            return;
                        }
                        case 'clone': {
                            if (!$sender->hasPermission('netherperms.group.clone')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.group.clone'); return; }
                            if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np group ' . $group . ' clone <cloneName>'); return; }
                            $cloneName = (string)$args[3];
                            if ($pm->getGroup($cloneName) !== null) { $sender->sendMessage(TF::RED . "Group '$cloneName' already exists"); return; }
                            if (!$pm->cloneGroup($group, $cloneName)) { $sender->sendMessage(TF::RED . 'Clone failed (source not found or target exists)'); return; }
                            $pm->save();
                            $sender->sendMessage(TF::GREEN . "Cloned group '$group' to '$cloneName'.");
                            return;
                        }
                    }
                    $sender->sendMessage(TF::RED . 'Unknown group subcommand');
                    return;
                }
                $sender->sendMessage(TF::RED . 'Unknown group subcommand');
                return;
            case 'user':
                if (!$sender->hasPermission('netherperms.user')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user'); return; }
                if (count($args) < 3) { $sender->sendMessage(TF::RED . 'Usage: /np user <user> <info|parent|permission|meta> ...'); return; }
                // LuckPerms-style: /np user <user> <sub> ...
                // Support usernames with spaces by consuming tokens until a known subcommand.
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
                    case 'info': {
                        if (!$sender->hasPermission('netherperms.user.info')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.info'); return; }
                        $this->outputUserInfo($sender, $pm, $uuid, $playerName);
                        return;
                    }
                    case 'primary': {
                        if (!$sender->hasPermission('netherperms.user.primary')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.primary'); return; }
                        $action = strtolower((string)($args[$subIndex + 1] ?? ''));
                        if ($action === 'show') {
                            if (!$sender->hasPermission('netherperms.user.primary.show')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.primary.show'); return; }
                            $pg = $pm->getComputedPrimaryGroup($uuid) ?? '(none)';
                            $sender->sendMessage(TF::YELLOW . "$playerName primary group: $pg");
                            return;
                        } elseif ($action === 'set') {
                            if (!$sender->hasPermission('netherperms.user.primary.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.primary.set'); return; }
                            $group = (string)($args[$subIndex + 2] ?? '');
                            if ($group === '') { $sender->sendMessage(TF::RED . 'Usage: /np user <user> primary set <group>'); return; }
                            if ($pm->getGroup($group) === null) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
                            $pm->setPrimaryGroup($uuid, $group);
                            // ensure the user is member of the primary group
                            $pm->addUserGroup($uuid, $group);
                            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Set $playerName primary group to '$group'.");
                            return;
                        } elseif ($action === 'unset') {
                            if (!$sender->hasPermission('netherperms.user.primary.unset')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.primary.unset'); return; }
                            $pm->unsetPrimaryGroup($uuid);
                            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Unset $playerName primary group.");
                            return;
                        }
                        $sender->sendMessage(TF::RED . 'Unknown action (use show|set|unset)');
                        return;
                    }
                    // LuckPerms-style: /np user <user> parent add|set|remove <group> [context...]
                    case 'parent':
                        if (!$sender->hasPermission('netherperms.user.parent')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.parent'); return; }
                        if (count($args) < $subIndex + 3) { $sender->sendMessage(TF::RED . 'Usage: /np user <user> parent <add|set|remove|switchprimarygroup> <group> [context...]'); return; }
                        $action = strtolower((string)$args[$subIndex + 1]);
                        $group = (string)$args[$subIndex + 2];
                        if ($action === 'add') {
                            if (!$sender->hasPermission('netherperms.user.parent.add')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.parent.add'); return; }
                            // Contexts not yet supported for parents; warn if present
                            $ctx = $this->parseContextArgs($args, $subIndex + 3);
                            if (!empty($ctx)) { $sender->sendMessage(TF::YELLOW . 'Context-aware parents are not supported yet; applying globally.'); }
                            if (!$pm->addUserGroup($uuid, $group)) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
                            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Added $playerName to group '$group'.");
                            return;
                        } elseif ($action === 'set') {
                            if (!$sender->hasPermission('netherperms.user.parent.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.parent.set'); return; }
                            // Clear all existing groups, then set the given one; also update primary group since no context
                            $ctx = $this->parseContextArgs($args, $subIndex + 3);
                            if (!empty($ctx)) { $sender->sendMessage(TF::RED . 'Context-aware parent set is not supported yet. Omit contexts.'); return; }
                            foreach ($pm->getUserGroups($uuid) as $g) { $pm->removeUserGroup($uuid, $g); }
                            if (!$pm->addUserGroup($uuid, $group)) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
                            $pm->setPrimaryGroup($uuid, $group);
                            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Set $playerName's parent to '$group' and updated primary group.");
                            return;
                        } elseif ($action === 'remove') {
                            if (!$sender->hasPermission('netherperms.user.parent.remove')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.parent.remove'); return; }
                            $ctx = $this->parseContextArgs($args, $subIndex + 3);
                            if (!empty($ctx)) { $sender->sendMessage(TF::YELLOW . 'Context-aware parents are not supported yet; applying globally.'); }
                            $pm->removeUserGroup($uuid, $group); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Removed $playerName from group '$group'.");
                            return;
                        } elseif ($action === 'switchprimarygroup') {
                            if (!$sender->hasPermission('netherperms.user.parent.switchprimarygroup')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.parent.switchprimarygroup'); return; }
                            if ($pm->getGroup($group) === null) { $sender->sendMessage(TF::RED . 'Group not found'); return; }
                            // Set stored primary group; ensure membership but do not modify other groups
                            $pm->setPrimaryGroup($uuid, $group);
                            $pm->addUserGroup($uuid, $group);
                            $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Switched stored primary group for $playerName to '$group'.");
                            return;
                        }
                        $sender->sendMessage(TF::RED . 'Unknown action (use set|unset)');
                        return;
                    // LuckPerms-style: /np user <user> permission set/unset ...
                    case 'permission':
                        if (!$sender->hasPermission('netherperms.user.permission')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.permission'); return; }
                        if (count($args) < $subIndex + 2) { $sender->sendMessage(TF::RED . 'Usage: /np user <user> permission <set|unset|settemp|unsettemp> <node> ...'); return; }
                        $action = strtolower((string)$args[$subIndex + 1]);
                        $node = (string)($args[$subIndex + 2] ?? '');
                        if ($node === '') { $sender->sendMessage(TF::RED . 'Missing permission node'); return; }
                        if ($action === 'set') {
                            if (!$sender->hasPermission('netherperms.user.permission.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.permission.set'); return; }
                            $value = isset($args[$subIndex + 3]) ? filter_var($args[$subIndex + 3], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : true;
                            if ($value === null) { $sender->sendMessage(TF::RED . 'Value must be true or false'); return; }
                            $ctx = $this->parseContextArgs($args, $subIndex + 4);
                            $pm->setUserPermission($uuid, $node, (bool)$value, $ctx); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Set $node=" . (((bool)$value)?'true':'false') . " for $playerName.");
                            return;
                        } elseif ($action === 'unset') {
                            if (!$sender->hasPermission('netherperms.user.permission.unset')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.permission.unset'); return; }
                            $pm->unsetUserPermission($uuid, $node); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Unset $node for $playerName.");
                            return;
                        } elseif ($action === 'settemp') {
                            if (!$sender->hasPermission('netherperms.user.permission.settemp')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.permission.settemp'); return; }
                            // /np user <user> permission settemp <node> [true|false] <duration> [context]
                            if (count($args) < $subIndex + 3) { $sender->sendMessage(TF::RED . 'Usage: /np user <user> permission settemp <node> [true|false] <duration> [world=.. gamemode=.. dimension=..]'); return; }
                            $next = $args[$subIndex + 3] ?? 'true';
                            $hasBool = filter_var($next, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                            if ($hasBool !== null) {
                                $value = (bool)$hasBool;
                                $durationTokenIndex = $subIndex + 4;
                            } else {
                                $value = true;
                                $durationTokenIndex = $subIndex + 3;
                            }
                            $durationToken = (string)($args[$durationTokenIndex] ?? '');
                            $seconds = $this->parseDurationSeconds($durationToken);
                            if ($seconds === null || $seconds <= 0) { $sender->sendMessage(TF::RED . 'Invalid duration. Examples: 10m, 1h30m, 2d'); return; }
                            $ctx = $this->parseContextArgs($args, $durationTokenIndex + 1);
                            $pm->addUserTempPermission($uuid, $node, $value, $seconds, $ctx); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Temporarily set $node=" . ($value ? 'true' : 'false') . " for $playerName for $durationToken.");
                            return;
                        } elseif ($action === 'unsettemp') {
                            if (!$sender->hasPermission('netherperms.user.permission.unsettemp')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.permission.unsettemp'); return; }
                            // /np user <user> permission unsettemp <node> [context]
                            $ctx = $this->parseContextArgs($args, $subIndex + 3);
                            $pm->unsetUserTempPermission($uuid, $node, $ctx); $pm->save(); if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Removed temporary $node for $playerName.");
                            return;
                        }
                        $sender->sendMessage(TF::RED . 'Unknown permission action (use set|unset|settemp|unsettemp)');
                        return;
                    // legacy subcommands removed intentionally to match LuckPerms
                    case 'meta':
                        if (!$sender->hasPermission('netherperms.user.meta')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.meta'); return; }
                        if (count($args) < $subIndex + 3) { $sender->sendMessage(TF::RED . 'Usage: /np user <user> meta <set|unset> <prefix|suffix> [value]'); return; }
                        $action = strtolower((string)$args[$subIndex + 1]);
                        $key = strtolower((string)$args[$subIndex + 2]);
                        if (!in_array($key, ['prefix','suffix'], true)) { $sender->sendMessage(TF::RED . 'Key must be prefix or suffix'); return; }
                        if ($action === 'set') {
                            if (!$sender->hasPermission('netherperms.user.meta.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.meta.set'); return; }
                            $value = (string)($args[$subIndex + 3] ?? '');
                            $pm->setUserMeta($uuid, $key, $value); $pm->save();
                            if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Set $key for $playerName to '$value'.");
                            return;
                        } elseif ($action === 'unset') {
                            if (!$sender->hasPermission('netherperms.user.meta.unset')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.user.meta.unset'); return; }
                            $pm->unsetUserMeta($uuid, $key); $pm->save();
                            if ($player !== null) { $this->plugin->applyPermissions($player); }
                            $sender->sendMessage(TF::GREEN . "Unset $key for $playerName.");
                            return;
                        }
                        $sender->sendMessage(TF::RED . 'Unknown action (use set|unset)');
                        return;
                }
                $sender->sendMessage(TF::RED . 'Unknown user subcommand');
                return;
            case 'track':
                if (!$sender->hasPermission('netherperms.track')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track'); return; }
                if (count($args) < 3) {
                    $sender->sendMessage(TF::RED . 'Usage: /np track <set|show> <track> [...] OR /np track <track> <info|rename|clone|insert|remove|append> [...]');
                    return;
                }
                // Support both styles:
                // 1) Legacy: /np track <set|show> <track> [...]
                // 2) Preferred: /np track <track> <rename|clone|insert|remove|append> [...]
                $first = strtolower($args[1]);
                $knownLegacy = ['set','show'];
                if (in_array($first, $knownLegacy, true)) {
                    $action = $first;
                    $track = strtolower($args[2] ?? '');
                    if ($track === '') { $sender->sendMessage(TF::RED . 'Missing track name.'); return; }
                    if ($action === 'set') {
                        if (!$sender->hasPermission('netherperms.track.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.set'); return; }
                        if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np track set <track> <g1> <g2> ...'); return; }
                        $groups = array_slice($args, 3);
                        $unknown = $pm->setTrack($track, $groups);
                        if (!empty($unknown)) {
                            $sender->sendMessage(TF::RED . 'Unknown groups: ' . implode(', ', $unknown) . '. Create them first.');
                            return;
                        }
                        $pm->save();
                        $sender->sendMessage(TF::GREEN . "Track '$track' set to: " . implode(' -> ', array_map('strtolower', $groups)));
                        return;
                    } elseif ($action === 'show') {
                        if (!$sender->hasPermission('netherperms.track.show')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.show'); return; }
                        $tracks = $pm->getTracks();
                        $order = $tracks[$track] ?? [];
                        $sender->sendMessage(TF::YELLOW . "Track '$track': " . (empty($order) ? '(empty)' : implode(' -> ', $order)));
                        return;
                    }
                    $sender->sendMessage(TF::RED . 'Unknown track action (use set|show)');
                    return;
                } else {
                    // Preferred syntax: /np track <track> <action> [...]
                    $track = strtolower($args[1]);
                    $action = strtolower($args[2] ?? '');
                    if ($action === 'info') {
                        if (!$sender->hasPermission('netherperms.track.show')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.show'); return; }
                        $order = $pm->getTracks()[$track] ?? [];
                        $sender->sendMessage(TF::YELLOW . '[NP] > Showing Track: ' . $track);
                        $sender->sendMessage(TF::YELLOW . '[NP] - Path: ' . (empty($order) ? '(empty)' : implode(' ---> ', array_map('strtolower', $order))));
                        return;
                    } elseif ($action === 'set') {
                        if (!$sender->hasPermission('netherperms.track.set')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.set'); return; }
                        if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np track ' . $track . ' set <g1> <g2> ...'); return; }
                        $groups = array_slice($args, 3);
                        $unknown = $pm->setTrack($track, $groups);
                        if (!empty($unknown)) {
                            $sender->sendMessage(TF::RED . 'Unknown groups: ' . implode(', ', $unknown) . '. Create them first.');
                            return;
                        }
                        $pm->save();
                        $sender->sendMessage(TF::GREEN . "Track '$track' set to: " . implode(' -> ', array_map('strtolower', $groups)));
                        return;
                    } elseif ($action === 'rename') {
                        if (!$sender->hasPermission('netherperms.track.rename')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.rename'); return; }
                        $newName = strtolower((string)($args[3] ?? ''));
                        if ($newName === '') { $sender->sendMessage(TF::RED . 'Usage: /np track ' . $track . ' rename <newName>'); return; }
                        if (!$pm->renameTrack($track, $newName)) { $sender->sendMessage(TF::RED . 'Rename failed (track not found or name exists)'); return; }
                        $pm->save();
                        $sender->sendMessage(TF::GREEN . "Renamed track '$track' to '$newName'.");
                        return;
                    } elseif ($action === 'clone') {
                        if (!$sender->hasPermission('netherperms.track.clone')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.clone'); return; }
                        $cloneName = strtolower((string)($args[3] ?? ''));
                        if ($cloneName === '') { $sender->sendMessage(TF::RED . 'Usage: /np track ' . $track . ' clone <cloneName>'); return; }
                        if (!$pm->cloneTrack($track, $cloneName)) { $sender->sendMessage(TF::RED . 'Clone failed (source not found or target exists)'); return; }
                        $pm->save();
                        $sender->sendMessage(TF::GREEN . "Cloned track '$track' to '$cloneName'.");
                        return;
                    } elseif ($action === 'insert') {
                        if (!$sender->hasPermission('netherperms.track.insert')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.insert'); return; }
                        $groupName = strtolower((string)($args[3] ?? ''));
                        $pos = isset($args[4]) ? filter_var($args[4], FILTER_VALIDATE_INT) : null;
                        if ($groupName === '' || $pos === null || $pos < 1) { $sender->sendMessage(TF::RED . 'Usage: /np track ' . $track . ' insert <group> <position>'); return; }
                        $err = $pm->insertGroupIntoTrack($track, $groupName, (int)$pos);
                        if ($err === 'Track not found') { $sender->sendMessage(TF::RED . "Track $track not found."); return; }
                        if ($err === 'Group not found') { $sender->sendMessage(TF::RED . "Group $groupName not found."); return; }
                        if ($err === 'already-contains') { $sender->sendMessage(TF::YELLOW . "$track already contains $groupName."); return; }
                        $pm->save();
                        $sender->sendMessage(TF::GREEN . "Group $groupName was inserted into track $track at position $pos.");
                        $order = $pm->getTracks()[$track] ?? [];
                        $sender->sendMessage(TF::YELLOW . (empty($order) ? '(empty)' : implode(' ---> ', $order)));
                        return;
                    } elseif ($action === 'remove') {
                        if (!$sender->hasPermission('netherperms.track.remove')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.remove'); return; }
                        $groupName = strtolower((string)($args[3] ?? ''));
                        if ($groupName === '') { $sender->sendMessage(TF::RED . 'Usage: /np track ' . $track . ' remove <group>'); return; }
                        $err = $pm->removeGroupFromTrack($track, $groupName);
                        if ($err === 'Track not found') { $sender->sendMessage(TF::RED . "Track $track not found."); return; }
                        if ($err === 'not-present') { $sender->sendMessage(TF::YELLOW . "$groupName is not in $track."); return; }
                        $pm->save();
                        $sender->sendMessage(TF::GREEN . "Removed $groupName from track $track.");
                        $order = $pm->getTracks()[$track] ?? [];
                        $sender->sendMessage(TF::YELLOW . (empty($order) ? '(empty)' : implode(' ---> ', $order)));
                        return;
                    } elseif ($action === 'append') {
                        if (!$sender->hasPermission('netherperms.track.append')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.append'); return; }
                        $groupName = strtolower((string)($args[3] ?? ''));
                        if ($groupName === '') { $sender->sendMessage(TF::RED . 'Usage: /np track ' . $track . ' append <group>'); return; }
                        $err = $pm->appendGroupToTrack($track, $groupName);
                        if ($err === 'Track not found') { $sender->sendMessage(TF::RED . "Track $track not found."); return; }
                        if ($err === 'Group not found') { $sender->sendMessage(TF::RED . "Group $groupName not found."); return; }
                        if ($err === 'already-contains') { $sender->sendMessage(TF::YELLOW . "$track already contains $groupName."); return; }
                        $pm->save();
                        $sender->sendMessage(TF::GREEN . "Appended $groupName to track $track.");
                        $order = $pm->getTracks()[$track] ?? [];
                        $sender->sendMessage(TF::YELLOW . (empty($order) ? '(empty)' : implode(' ---> ', $order)));
                        return;
                    }
                    $sender->sendMessage(TF::RED . 'Unknown track action (use rename|clone|insert|remove|append)');
                    return;
                }
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

    /**
     * Re-apply permissions to online players after a group change.
     * For correctness and simplicity, we update all online players.
     */
    private function applyOnlineAffectedByGroup(string $group) : void
    {
        foreach ($this->plugin->getServer()->getOnlinePlayers() as $op) {
            $this->plugin->applyPermissions($op);
        }
    }

    /**
     * Parse key=value context arguments from a specific start offset.
     * Allowed keys: world, gamemode, dimension
     * @return array<string,string>
     */
    private function parseContextArgs(array $args, int $start) : array
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
     * Parse durations like "90", "10m", "1h30m", "2d3h", "1d2h30m15s" to seconds.
     * Returns null on invalid format.
     */
    private function parseDurationSeconds(string $token) : ?int
    {
        $token = trim($token);
        if ($token === '') return null;
        // pure integer seconds
        if (ctype_digit($token)) {
            $val = (int)$token;
            return $val > 0 ? $val : null;
        }
        // match sequences of <int><unit>
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
