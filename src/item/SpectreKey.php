<?php
declare(strict_types=1);
namespace jasonwynn10\SpectreZone\item;

use customiesdevs\customies\item\ItemComponents;
use customiesdevs\customies\item\ItemComponentsTrait;
use jasonwynn10\SpectreZone\SpectreZone;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemUseResult;
use pocketmine\item\Releasable;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\Server;

final class SpectreKey extends Item implements Releasable, ItemComponents{
	use ItemComponentsTrait;

	public function __construct(ItemIdentifier $identifier, string $name = 'Spectre Key'){
		parent::__construct($identifier, $name);
		$this->initComponent('spectre_key', 1);
		$this->addProperty('creative_group', 'Items');
		$this->addProperty('creative_category', 4);
//		$this->addComponent('minecraft:projectile', CompoundTag::create());
		$this->addComponent('minecraft:throwable', CompoundTag::create()
			->setByte('do_swing_animation', 0)
			->setFloat('launch_power_scale', 1.0)
			->setFloat('max_draw_duration', 15.0)
			->setFloat('max_launch_power', 30.0)
			->setFloat('min_draw_duration', 5.0)
			->setByte('scale_power_by_draw_duration', 1)
		);
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function onReleaseUsing(Player $player) : ItemUseResult{
		/** @var SpectreZone $plugin */
		$plugin = Server::getInstance()->getPluginManager()->getPlugin('SpectreZone');
		if($player->getWorld() === Server::getInstance()->getWorldManager()->getWorldByName('SpectreZone')) {
			[$position, $viewDistance] = $plugin->getSavedInfo($player);
			$player->setViewDistance($viewDistance);
		}else{
			$plugin->savePlayerInfo($player);
			$position = $plugin->getSpectreSpawn($player);
			$player->setViewDistance(3);
		}
		$player->teleport($position);
		return ItemUseResult::NONE();
	}

	/**
	 * Returns the number of ticks a player must wait before activating this item again.
	 */
	public function getCooldownTicks() : int{
		return 20 * 2; // 2 second hold for particles
	}

	public function canStartUsingItem(Player $player) : bool{
		return true;
	}
}