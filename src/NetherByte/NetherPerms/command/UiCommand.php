<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\command;

use NetherByte\NetherPerms\NetherPerms;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;

final class UiCommand extends Command
{
    public function __construct(private NetherPerms $plugin)
    {
        parent::__construct('npui', 'Open NetherPerms editor UI', '/npui', []);
        $this->setPermission('netherperms.ui');
    }

    public function execute(CommandSender $sender, string $label, array $args) : void
    {
        if (!$this->testPermission($sender)) return;
        if (!$sender instanceof Player) { $sender->sendMessage(TF::RED . 'Run this in-game.'); return; }
        $this->plugin->getUi()->openMain($sender);
    }
}
