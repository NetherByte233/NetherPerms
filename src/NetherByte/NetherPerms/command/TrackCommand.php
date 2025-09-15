<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\command;

use NetherByte\NetherPerms\NetherPerms;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat as TF;

final class TrackCommand
{
    public function __construct(private NetherPerms $plugin) {}

    public function handleRoot(CommandSender $sender, array $args) : void
    {
        $pm = $this->plugin->getPermissionManager();
        $sub = strtolower($args[0] ?? '');
        switch ($sub) {
            case 'createtrack':
                if (!$sender->hasPermission('netherperms.track.create')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.create'); return; }
                if (count($args) < 2) { $sender->sendMessage(TF::RED . 'Usage: /np createtrack <track>'); return; }
                $track = strtolower($args[1]);
                $tracks = $pm->getTracks();
                if (isset($tracks[$track])) { $sender->sendMessage(TF::RED . 'Track already exists'); return; }
                $pm->createTrack($track); $pm->save();
                $sender->sendMessage(TF::GREEN . "Track '$track' created.");
                return;
            case 'deletetrack':
                if (!$sender->hasPermission('netherperms.track.delete')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.delete'); return; }
                if (count($args) < 2) { $sender->sendMessage(TF::RED . 'Usage: /np deletetrack <track>'); return; }
                $track = strtolower($args[1]);
                $tracks = $pm->getTracks();
                if (!isset($tracks[$track])) { $sender->sendMessage(TF::RED . 'Track not found'); return; }
                $pm->deleteTrack($track); $pm->save();
                $sender->sendMessage(TF::GREEN . "Track '$track' deleted.");
                return;
            case 'listtracks':
                if (!$sender->hasPermission('netherperms.track.list')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track.list'); return; }
                $tracks = array_keys($pm->getTracks()); sort($tracks);
                $sender->sendMessage(TF::YELLOW . 'Tracks: ' . (empty($tracks) ? '(none)' : implode(', ', $tracks)));
                return;
        }
        $sender->sendMessage(TF::RED . 'Unknown command');
    }

    public function handleTrack(CommandSender $sender, array $args) : void
    {
        $pm = $this->plugin->getPermissionManager();
        if (!$sender->hasPermission('netherperms.track')) { $sender->sendMessage(TF::RED . 'You lack permission netherperms.track'); return; }
        if (count($args) < 3) {
            $sender->sendMessage(TF::RED . 'Usage: /np track <track> <info|rename|clone|insert|remove|append> [...]');
            return;
        }
        $track = strtolower($args[1]);
        $sub = strtolower($args[2] ?? '');
        $tracks = $pm->getTracks();
        if (!isset($tracks[$track])) { $sender->sendMessage(TF::RED . 'Track not found'); return; }
        switch ($sub) {
            case 'info':
                $order = $tracks[$track] ?? [];
                $pretty = '(' . implode(' ---> ', $order) . ')';
                $sender->sendMessage(TF::YELLOW . "Track $track: ");
                $sender->sendMessage('  ' . $pretty);
                return;
            case 'rename':
                if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np track <track> rename <newName>'); return; }
                $new = strtolower((string)$args[3]);
                if (isset($tracks[$new])) { $sender->sendMessage(TF::RED . 'Target track already exists'); return; }
                if (!$pm->renameTrack($track, $new)) { $sender->sendMessage(TF::RED . 'Rename failed'); return; }
                $pm->save(); $sender->sendMessage(TF::GREEN . "Renamed track '$track' to '$new'.");
                return;
            case 'clone':
                if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np track <track> clone <cloneName>'); return; }
                $clone = strtolower((string)$args[3]);
                if (isset($tracks[$clone])) { $sender->sendMessage(TF::RED . 'Target track already exists'); return; }
                if (!$pm->cloneTrack($track, $clone)) { $sender->sendMessage(TF::RED . 'Clone failed'); return; }
                $pm->save(); $sender->sendMessage(TF::GREEN . "Cloned track '$track' to '$clone'.");
                return;
            case 'insert':
                if (count($args) < 5) { $sender->sendMessage(TF::RED . 'Usage: /np track <track> insert <group> <position>'); return; }
                $group = (string)$args[3]; $pos = filter_var($args[4], FILTER_VALIDATE_INT);
                if ($pos === false || $pos === null) { $sender->sendMessage(TF::RED . 'Position must be an integer'); return; }
                $err = $pm->insertGroupIntoTrack($track, $group, (int)$pos);
                if ($err !== null) { $sender->sendMessage(TF::RED . 'Insert failed: ' . $err); return; }
                $pm->save(); $sender->sendMessage(TF::GREEN . "Inserted $group at position $pos on track '$track'.");
                return;
            case 'remove':
                if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np track <track> remove <group>'); return; }
                $group = (string)$args[3];
                $err = $pm->removeGroupFromTrack($track, $group);
                if ($err !== null) { $sender->sendMessage(TF::RED . 'Remove failed: ' . $err); return; }
                $pm->save(); $sender->sendMessage(TF::GREEN . "Removed $group from track '$track'.");
                return;
            case 'append':
                if (count($args) < 4) { $sender->sendMessage(TF::RED . 'Usage: /np track <track> append <group>'); return; }
                $group = (string)$args[3];
                $err = $pm->appendGroupToTrack($track, $group);
                if ($err !== null) { $sender->sendMessage(TF::RED . 'Append failed: ' . $err); return; }
                $pm->save(); $sender->sendMessage(TF::GREEN . "Appended $group to track '$track'.");
                return;
        }
        $sender->sendMessage(TF::RED . 'Unknown track subcommand');
    }
}
