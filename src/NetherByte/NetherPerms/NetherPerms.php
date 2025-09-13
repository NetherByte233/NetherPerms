<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms;

use NetherByte\NetherPerms\permission\PermissionManager;
use NetherByte\NetherPerms\ui\UiController;
use NetherByte\NetherPerms\storage\YamlStorage;
use NetherByte\NetherPerms\storage\DbStorage;
use NetherByte\NetherPerms\command\NetherPermsCommand;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\player\PlayerGameModeChangeEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\permission\PermissionAttachment;
use pocketmine\utils\TextFormat as TF;
use pocketmine\scheduler\ClosureTask;
use NetherByte\NetherPerms\integration\NetherPermsProvider;

final class NetherPerms extends PluginBase implements Listener
{
    private PermissionManager $permManager;
    private UiController $ui;

    /** @var array<string, PermissionAttachment> */
    private array $attachments = [];
    /** @var array<string,string> md5 signatures of last applied effective permissions per uuid */
    private array $lastPermSignature = [];

    protected function onEnable() : void
    {
        $this->saveResource("config.yml");

        $dataPath = $this->getDataFolder();
        @mkdir($dataPath);

        // Migrate any legacy lowercase folder plugin_data/netherperms -> plugin_data/NetherPerms
        $serverData = rtrim($this->getServer()->getDataPath(), "\\/\n\r") . DIRECTORY_SEPARATOR;
        $legacyDir = $serverData . 'plugin_data' . DIRECTORY_SEPARATOR . 'netherperms';
        $properDir = $serverData . 'plugin_data' . DIRECTORY_SEPARATOR . 'NetherPerms';
        if (is_dir($legacyDir)) {
            @mkdir($properDir, 0777, true);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($legacyDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $item) {
                $dest = $properDir . DIRECTORY_SEPARATOR . $it->getSubPathName();
                if ($item->isDir()) {
                    if (!is_dir($dest)) @mkdir($dest, 0777, true);
                } else {
                    @copy($item->getPathname(), $dest);
                }
            }
            // Optional: we don't delete legacy to avoid accidental data loss
        }

        // Choose storage backend
        $storageType = strtolower((string)$this->getConfig()->get('storage', 'yaml'));
        if ($storageType === 'sqlite') {
            // Do not save YAML resources in DB mode
            // Resolve DB path (default: store inside this plugin's data folder)
            $dbFileCfg = (string)$this->getConfig()->get('sqlite-file', 'netherperms.sqlite');
            if (str_starts_with($dbFileCfg, 'plugin_data/')) {
                // Normalize to proper case folder name
                $normalized = preg_replace('#^plugin_data/netherperms#i', 'plugin_data/NetherPerms', $dbFileCfg) ?? $dbFileCfg;
                $dbFile = $this->getServer()->getDataPath() . ltrim($normalized, "/\\");
            } elseif (!preg_match('#^([A-Za-z]:\\|/)#', $dbFileCfg)) {
                // relative: place under this plugin's data folder
                $dbFile = $dataPath . ltrim($dbFileCfg, "/\\");
            } else {
                // absolute path
                $dbFile = $dbFileCfg;
            }
            @mkdir(dirname($dbFile), 0777, true);
            $storage = new DbStorage($dbFile);
        } else {
            // YAML mode: ensure example YAMLs exist
            $this->saveResource("groups.yml");
            $this->saveResource("users.yml");
            $storage = new YamlStorage($dataPath . 'users.yml', $dataPath . 'groups.yml');
        }
        $denyPrecedence = (bool)$this->getConfig()->get('deny-precedence', true);
        $primaryMode = (string)$this->getConfig()->get('primary-group-calculation', 'parents-by-weight');
        $this->permManager = new PermissionManager($storage, $this->getConfig()->get('default-group', 'default'), $denyPrecedence, $primaryMode);
        $this->permManager->load();
        $this->ui = new UiController($this, $this->permManager);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getCommandMap()->register('netherperms', new NetherPermsCommand($this));

        // Apply to already online players on reload
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $this->applyPermissions($player);
        }

        // Register PlaceholderAPI provider if available
        if (class_exists(\NetherByte\PlaceholderAPI\PlaceholderAPI::class)) {
            try {
                \NetherByte\PlaceholderAPI\PlaceholderAPI::registerProvider(new NetherPermsProvider($this));
                $this->getLogger()->info(TF::GREEN . "PlaceholderAPI provider registered: NetherPerms");
            } catch (\Throwable $e) {
                $this->getLogger()->warning("Failed to register PlaceholderAPI provider: " . $e->getMessage());
            }
        }

        // Periodically check for temp permission expiry and reapply if changed
        // Run every 1 second (20 ticks)
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void {
            foreach ($this->getServer()->getOnlinePlayers() as $player) {
                $uuid = $player->getUniqueId()->toString();
                $context = $this->collectContext($player);
                // Resolve current effective (also purges expired temp nodes internally)
                $perms = $this->permManager->getEffectivePermissionsForUser($uuid, $context);
                $sig = md5(json_encode($perms));
                if (!isset($this->lastPermSignature[$uuid]) || $this->lastPermSignature[$uuid] !== $sig) {
                    $this->applyPermissions($player);
                    $this->lastPermSignature[$uuid] = $sig;
                }
            }
        }), 20);
    }

    protected function onDisable() : void
    {
        $this->permManager->save();
        // Remove attachments for online players; others will be GC'd on shutdown
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $uuid = $player->getUniqueId()->toString();
            if (isset($this->attachments[$uuid])) {
                $player->removeAttachment($this->attachments[$uuid]);
                unset($this->attachments[$uuid]);
            }
        }
        $this->attachments = [];
    }

    public function onPlayerJoin(PlayerJoinEvent $event) : void
    {
        $player = $event->getPlayer();
        $this->ensureDefaultAssigned($player);
        $this->applyPermissions($player);
    }

    public function onPlayerQuit(PlayerQuitEvent $event) : void
    {
        $player = $event->getPlayer();
        $uuid = $player->getUniqueId()->toString();
        if (isset($this->attachments[$uuid])) {
            $player->removeAttachment($this->attachments[$uuid]);
            unset($this->attachments[$uuid]);
        }
    }

    private function ensureDefaultAssigned(Player $player) : void
    {
        $uuid = $player->getUniqueId()->toString();
        if (!$this->permManager->userExists($uuid)) {
            $this->permManager->createUser($uuid, $player->getName());
            $this->permManager->addUserGroup($uuid, $this->permManager->getDefaultGroup());
            $this->permManager->save();
        }
    }

    public function applyPermissions(Player $player) : void
    {
        $uuid = $player->getUniqueId()->toString();
        // Note: PMMP API 5 removed Player::isOp()/setOp().
        // If a player is OP, they bypass permission checks. Denies will not apply to OP users.
        // To test denies, remove the player from ops (ops.txt) or avoid granting OP.
        // Clear old attachment
        if (isset($this->attachments[$uuid])) {
            $player->removeAttachment($this->attachments[$uuid]);
            unset($this->attachments[$uuid]);
        }
        $attachment = $player->addAttachment($this);
        $context = $this->collectContext($player);
        $perms = $this->permManager->getEffectivePermissionsForUser($uuid, $context);
        foreach ($perms as $node => $value) {
            $attachment->setPermission($node, $value);
        }
        $this->attachments[$uuid] = $attachment;
        // Update signature cache to avoid repeated reapplications
        $this->lastPermSignature[$uuid] = md5(json_encode($perms));
    }

    public function getPermissionManager() : PermissionManager
    {
        return $this->permManager;
    }

    public function getUi() : UiController
    {
        return $this->ui;
    }

    public function reloadAll() : void
    {
        // Reload config so calculation mode and other settings can update
        $this->reloadConfig();
        $mode = (string)$this->getConfig()->get('primary-group-calculation', 'parents-by-weight');
        $this->permManager->setPrimaryGroupCalcMode($mode);
        $this->permManager->load();
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $this->applyPermissions($player);
        }
    }

    private function collectContext(Player $player) : array
    {
        $world = $player->getWorld()->getFolderName();
        $gm = $player->getGamemode();
        $gmName = null;
        if (method_exists($gm, 'getEnglishName')) {
            $gmName = strtolower($gm->getEnglishName());
        } elseif (method_exists($gm, 'name')) {
            $gmName = strtolower($gm->name());
        } else {
            $gmName = (string)$gm; // fallback
        }
        return [
            'world' => strtolower($world),
            'gamemode' => $gmName,
            // 'dimension' can be provided in commands; runtime is optional for PMMP
        ];
    }

    /** @priority MONITOR */
    public function onMove(PlayerMoveEvent $event) : void
    {
        $from = $event->getFrom();
        $to = $event->getTo();
        if ($from->getWorld()->getFolderName() !== $to->getWorld()->getFolderName()) {
            // Defer one tick to ensure world switch is finalized
            $player = $event->getPlayer();
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void {
                if ($player->isOnline()) {
                    $this->applyPermissions($player);
                }
            }), 1);
        }
    }

    /** @priority MONITOR */
    public function onGameModeChange(PlayerGameModeChangeEvent $event) : void
    {
        // Defer one tick to ensure player's gamemode is updated before collecting context
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void {
            if ($player->isOnline()) {
                $this->applyPermissions($player);
            }
        }), 1);
    }

    /** @priority MONITOR */
    public function onTeleport(EntityTeleportEvent $event) : void
    {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) return;
        $from = $event->getFrom();
        $to = $event->getTo();
        if ($from->getWorld()->getFolderName() !== $to->getWorld()->getFolderName()) {
            $player = $entity;
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void {
                if ($player->isOnline()) {
                    $this->applyPermissions($player);
                }
            }), 1);
        }
    }
}
