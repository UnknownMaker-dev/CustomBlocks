<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\block;

use Unknown\CustomBlocks\libs\customiesdevs\customies\block\BlockComponents;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\permutations\Permutable;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\permutations\RotatableTrait;
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
