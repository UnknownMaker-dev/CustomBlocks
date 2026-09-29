<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\block;

use customiesdevs\customies\block\BlockComponents;
use customiesdevs\customies\block\permutations\Permutable;
use customiesdevs\customies\block\permutations\RotatableTrait;
use pocketmine\block\Opaque;

/**
 * Bloco customizado que gira para acompanhar a direção do jogador ao ser colocado.
 *
 * O RotatableTrait do Customies cuida das quatro permutações horizontais e da serialização
 * do estado no mundo.
 */
final class CustomRotatableBlock extends Opaque implements BlockComponents, Permutable {
	use CustomBlockTrait;
	use RotatableTrait;
}
