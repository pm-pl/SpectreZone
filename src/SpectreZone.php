<?php
declare(strict_types=1);
namespace jasonwynn10\SpectreZone;

use jasonwynn10\SpectreZone\item\CustomReleasableItem;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemUseResult;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ItemComponentPacket;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\ItemComponentPacketEntry;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\resourcepacks\ZippedResourcePack;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use Webmozart\PathUtil\Path;

final class SpectreZone extends PluginBase {
	private array $savedPositions = [];
	private int $defaultHeight = 4;
	private int $chunkOffset = 1;

	public function onEnable() : void {
		$server = $this->getServer();

		// register custom items
		$this->registerCustomItem($ectoplasm = new Item(new ItemIdentifier(400, 0), "Ectoplasm"), $this->getName());
		$this->registerCustomItem($spectreIngot = new Item(new ItemIdentifier(401, 0), "Spectre Ingot"), $this->getName());
		$this->registerCustomItem($spectreKey = new CustomReleasableItem(new ItemIdentifier(402, 0), "Spectre Key",
			function(Player $player) {
				if($player->getWorld() === $this->getServer()->getWorldManager()->getWorldByName('SpectreZone')) {
					$position = $this->getSavedPosition($player);
				}else{
					$this->savePlayerPosition($player);
					$position = $this->getSpectreSpawn($player);
				}
				$player->teleport($position);

				return ItemUseResult::NONE(); // TODO: test return ItemUseResult::SUCCESS()
			}),
			$this->getName(),
			CompoundTag::create()
				->setByte("allow_off_hand", 0)
				->setByte("hand_equipped", 1)
				->setInt("max_stack_size", 1)
				->setInt("use_animation", 0) // TODO: find throw animation
				->setTag('minecraft:throwable', CompoundTag::create()
					->setFloat('min_draw_duration', 5.0) // Only activate key after 5 seconds
					->setFloat('max_draw_duration', 15.0) // Force key to release after 15 seconds
				)
		);

		// register custom item recipes
		$craftManager = $server->getCraftingManager();
		$craftManager->registerShapedRecipe(new ShapedRecipe(
			[
				' A ',
				' B ',
				' C '
			],
			[
				'A' => VanillaItems::LAPIS_LAZULI(),
				'B' => VanillaItems::GOLD_INGOT(),
				'C' => $ectoplasm
			],
			[
				$spectreIngot
			]
		));
		$countedSpectreIngot = (clone $spectreIngot)->setCount(9);
		$craftManager->registerShapedRecipe(new ShapedRecipe(
			[
				'CAC',
				'CBC',
				'CCC'
			],
			[
				'A' => VanillaItems::LAPIS_LAZULI(),
				'B' => VanillaItems::GOLD_INGOT(),
				'C' => $ectoplasm
			],
			[
				$countedSpectreIngot
			]
		));
		$craftManager->registerShapedRecipe(new ShapedRecipe(
			[
				'A  ',
				'AB ',
				'  A'
			],
			[
				'A' => $spectreIngot,
				'B' => VanillaItems::ENDER_PEARL()
			],
			[
				$spectreKey
			]
		));

		// TODO: register custom blocks

		// Register world generator

		GeneratorManager::getInstance()->addGenerator(
			SpectreZoneGenerator::class,
			'SpectreZone',
			\Closure::fromCallable(
				function(string $generatorOptions) {
					$parsedOptions = \json_decode($generatorOptions, true, flags: JSON_THROW_ON_ERROR);
					if(!is_int($parsedOptions['Chunk Offset']) or $parsedOptions['Chunk Offset'] < 0) {
						return new InvalidGeneratorOptionsException();
					}elseif(!is_int($parsedOptions['Default Height']) or $parsedOptions['Default Height'] < 2) {
						return new InvalidGeneratorOptionsException();
					}
					return null;
				}
			)
		);

		// Load or generate the SpectreZone dimension
		$worldManager = $server->getWorldManager();
		if(!$worldManager->loadWorld('SpectreZone')) {
			$server->getWorldManager()->generateWorld(
				'SpectreZone',
				WorldCreationOptions::create()
					->setGeneratorClass(SpectreZoneGenerator::class)
					->setDifficulty(World::DIFFICULTY_PEACEFUL)
					->setSpawnPosition(new Vector3(0.5, 1, 0.5))
					->setGeneratorOptions(\json_encode($this->getConfig()->getAll(), JSON_THROW_ON_ERROR)),
				true,
				false // keep this for NativeDimensions compatibility
			);
		}

		$spectreZone = $worldManager->getWorldByName('SpectreZone');
		$options = \json_decode($spectreZone->getProvider()->getWorldData()->getGeneratorOptions(), true, flags: JSON_THROW_ON_ERROR);

		$this->defaultHeight = (int) \abs($options["Default Height"] ?? 4);
		$this->chunkOffset = (int) \abs($options["Chunk Offset"] ?? 1);

		// register events
		$server->getPluginManager()->registerEvent(
			PlayerQuitEvent::class,
			\Closure::fromCallable(
				function(PlayerQuitEvent $event) {
					$player = $event->getPlayer();
					if(isset($this->savedPositions[$player->getUniqueId()->toString()])) { // if set, the player is in the SpectreZone world
						$position = $this->savedPositions[$player->getUniqueId()->toString()];
						unset($this->savedPositions[$player->getUniqueId()->toString()]);
						$player->teleport($position); // teleport the player back to their last position
					}
				}
			),
			EventPriority::MONITOR,
			$this,
			true // doesn't really matter because event cannot be cancelled
		);
	}

	private function registerCustomItem(Item $item, string $namespace, ?CompoundTag $propertiesTag = null): void{
		// Get the current net id map information from the core
		$ref = new \ReflectionClass(ItemTranslator::class);
		$coreToNetMap = $ref->getProperty("simpleCoreToNetMapping");
		$netToCoreMap = $ref->getProperty("simpleNetToCoreMapping");
		$coreToNetMap->setAccessible(true);
		$netToCoreMap->setAccessible(true);

		$coreMap = $coreToNetMap->getValue(ItemTranslator::getInstance());
		$netMap = $netToCoreMap->getValue(ItemTranslator::getInstance());

		$legacyId = $item->getId();

		// Add the new custom item to the core mapping
		$runtimeId = $legacyId + ($legacyId > 0 ? 5000 : -5000);
		$coreMap[$legacyId] = $runtimeId;
		$netMap[$runtimeId] = $legacyId;

		// Save the new core mapping
		$coreToNetMap->setValue(ItemTranslator::getInstance(), $coreMap);
		$netToCoreMap->setValue(ItemTranslator::getInstance(), $netMap);

		// Get the current item type map information from the core
		$ref_1 = new \ReflectionClass(ItemTypeDictionary::class);
		$itemTypeMap = $ref_1->getProperty("itemTypes");
		$itemTypeMap->setAccessible(true);

		$itemTypeEntries = $itemTypeMap->getValue(GlobalItemTypeDictionary::getInstance()->getDictionary());

		$fullName = mb_strtolower($namespace.':'.str_replace(' ', '_', $item->getVanillaName()));

		// Add the new custom item's type entry to the type map
		$itemTypeEntries[] = new ItemTypeEntry($fullName, $runtimeId, true);

		// Save the new type map
		$itemTypeMap->setValue(GlobalItemTypeDictionary::getInstance()->getDictionary(), $itemTypeEntries);

		self::$packetEntries[] = new ItemComponentPacketEntry($fullName,
			new CacheableNbt(CompoundTag::create()
				->setTag("components", CompoundTag::create()
					->setTag("item_properties", CompoundTag::create()
						->setByte("allow_off_hand", 0)
						->setByte("hand_equipped", 1)
						->setInt("max_stack_size", 64)
						->setByte("creative_category", 4) // // 1 construction 2 nature 3 equipment 4 items
						->setTag("minecraft:icon", CompoundTag::create()
							->setString("texture", mb_strtolower(str_replace(' ', '_', $item->getVanillaName())))
							->setString("legacy_id", $fullName)
						)
						->merge($propertiesTag ?? CompoundTag::create())
					)
					->setShort("minecraft:identifier", $runtimeId)
					->setTag("minecraft:display_name", CompoundTag::create()
						->setString("value", $item->getVanillaName())
					)
				)
			)
		);

		ItemFactory::getInstance()->register($item, true);
		CreativeInventory::getInstance()->add($item);
		StringToItemParser::getInstance()->register($item->getVanillaName(), fn() => $item);
	}

	public function getDefaultHeight() : int{
		return $this->defaultHeight;
	}

	public function getChunkOffset() : int{
		return $this->chunkOffset;
	}

	public function getSpectreSpawn(Player $player) : Position {
		$spectreZone = $this->getServer()->getWorldManager()->getWorldByName('SpectreZone');
		\assert($spectreZone !== null);

		$stream = fopen($this->getDataFolder().'zones.json', 'r');
		$listener = new SimpleObjectQueueListener(fn(array $currentObject) => \var_dump($currentObject));
		try {
			$parser = new Parser($stream, $listener);
			$parser->parse();
		} catch (\Exception $e) {
			$this->getLogger()->logException($e);
		}finally{
			fclose($stream);
		}

		return $player->getPosition(); // TODO: replace placeholder with actual spawn position
	}

	private function isUsableChunk(int $chunkX, int $chunkZ) : bool{
		return $chunkX % (3 + $this->chunkOffset) === 0 and $chunkZ % (3 + $this->chunkOffset) === 0;
	}

	private function savePlayerPosition(Player $player) : void {
		$this->savedPositions[$player->getUniqueId()->toString()] = $player->getPosition();
	}

	private function getSavedPosition(Player $player) : Position {
		if(isset($this->savedPositions[$player->getUniqueId()->toString()])) {
			$position = $this->savedPositions[$player->getUniqueId()->toString()];
			unset($this->savedPositions[$player->getUniqueId()->toString()]);
			return $position;
		}
		return $player->getSpawn(); // return the player's spawn position as a fallback
	}
}