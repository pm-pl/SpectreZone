<?php
declare(strict_types=1);
namespace jasonwynn10\SpectreZone;

use pocketmine\block\Block;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\generator\Generator;
use twistedasylummc\customies\block\CustomiesBlockFactory;

final class SpectreZoneGenerator extends Generator{

	protected int $height = 4;
	protected int $multiplier = 0;

	public function __construct(int $seed, string $preset){
		parent::__construct($seed, $preset);
		$parsedData = json_decode($preset, true, JSON_THROW_ON_ERROR);
		$this->height = (int) \abs($parsedData["Default Height"] ?? 4);
		$this->multiplier = (int) \abs($parsedData["Chunk Offset"] ?? 1);
	}

	public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ);

		$block = CustomiesBlockFactory::getInstance()->get('spectrezone:spectre_block');
		$filledSubChunk = new PalettedBlockArray($block->getFullId());

		for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; ++$y){
			$chunk->setSubChunk($y, new SubChunk(BlockLegacyIds::AIR << Block::INTERNAL_METADATA_BITS, [$filledSubChunk]));
		}

		if($this->isChunkValid($chunkX, $chunkZ)) {
			$block = CustomiesBlockFactory::getInstance()->get('spectrezone:spectre_core');

			$center = Chunk::EDGE_LENGTH / 2;

			for($x = 0; $x <= Chunk::EDGE_LENGTH; ++$x){
				for($z = 0; $z <= Chunk::EDGE_LENGTH; ++$z){
					for($y = 0; $y < $world->getMaxY(); ++$y){
						if($y > 0 and $y <= $this->height) {
							$chunk->setFullBlock($x, $y, $z, VanillaBlocks::AIR()->getFullId());
						}elseif($x === $center or
							$x === $center + 1 or
							$z === $center or
							$z === $center + 1
						) {
							$chunk->setFullBlock($x, $y, $z, $block->getFullId());
						}
					}
				}
			}
		}
	}

	public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void{}

	protected function isChunkValid(int $chunkX, int $chunkZ) : bool{
		return $chunkX % (3 + $this->multiplier) === 0 and $chunkZ % (3 + $this->multiplier) === 0;
	}
}