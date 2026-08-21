<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\block;

use Unknown\CustomBlocks\libs\customiesdevs\customies\block\BlockComponents;
use pocketmine\block\Opaque;

/** Bloco customizado sem estados: um único visual, sempre na mesma orientação. */
final class CustomBlock extends Opaque implements BlockComponents {
	use CustomBlockTrait;
}
