<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\block;

use Unknown\CustomBlocks\libs\customiesdevs\customies\block\BlockComponentsTrait;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\BreathabilityComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\CollisionBoxComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\DestructibleByExplosionComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\DestructibleByMiningComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\DisplayNameComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\FlammableComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\FrictionComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\GeometryComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\LightDampeningComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\LightEmissionComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\MaterialInstancesComponent;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\component\SelectionBoxComponent;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\utils\SupportType;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;

/**
 * Liga uma {@link BlockDefinition} ao bloco do servidor e aos componentes enviados ao cliente.
 *
 * Fica em um trait porque o PHP não tem herança múltipla: os blocos rotacionáveis precisam
 * herdar também do RotatableTrait do Customies.
 */
trait CustomBlockTrait {
	use BlockComponentsTrait;

	private BlockDefinition $definition;

	public function __construct(BlockDefinition $definition) {
		$this->definition = $definition;
		parent::__construct(
			new BlockIdentifier(BlockTypeIds::newId()),
			$definition->getDisplayName(),
			new BlockTypeInfo($definition->createBreakInfo())
		);
		$this->initCustomComponents();
	}

	public function getDefinition(): BlockDefinition {
		return $this->definition;
	}

	/** Monta os componentes que o cliente recebe no bloco de palette do Customies. */
	private function initCustomComponents(): void {
		$definition = $this->definition;

		$this->addComponent(new BreathabilityComponent($definition->isBreathable() ? BreathabilityComponent::AIR : BreathabilityComponent::SOLID));
		$this->addComponent(new DestructibleByMiningComponent($definition->getMiningSeconds()));
		$this->addComponent(new DestructibleByExplosionComponent($definition->getExplosionResistance()));
		$this->addComponent(new LightEmissionComponent($definition->getLightEmission()));
		$this->addComponent(new LightDampeningComponent($definition->getLightFilter()));
		$this->addComponent(new FrictionComponent($definition->getFriction()));
		$this->addComponent(new GeometryComponent($definition->getGeometry()));
		$this->addComponent(new DisplayNameComponent($definition->getDisplayName()));
		$this->addComponent(new MaterialInstancesComponent($definition->createMaterials()));

		$selection = $definition->getSelectionBox();
		$this->addComponent($selection === null
			? new SelectionBoxComponent(false)
			: new SelectionBoxComponent(true, BlockDefinition::boxOrigin($selection), BlockDefinition::boxSize($selection)));

		$collision = $definition->getCollisionBox();
		$this->addComponent($collision === null
			? new CollisionBoxComponent(false)
			: new CollisionBoxComponent(true, BlockDefinition::boxOrigin($collision), BlockDefinition::boxSize($collision)));

		$flammable = $definition->getFlammable();
		if($flammable !== null) {
			$this->addComponent(new FlammableComponent($flammable[0], $flammable[1]));
		}
	}

	public function getLightLevel(): int {
		return $this->definition->getLightEmission();
	}

	public function getLightFilter(): int {
		return $this->definition->getLightFilter();
	}

	public function getFrictionFactor(): float {
		return $this->definition->getFriction();
	}

	public function isSolid(): bool {
		return $this->definition->isSolid();
	}

	public function isTransparent(): bool {
		return $this->definition->isTransparent();
	}

	public function getFlammability(): int {
		return $this->definition->getFlammable()[1] ?? 0;
	}

	public function getFlameEncouragement(): int {
		return $this->definition->getFlammable()[0] ?? 0;
	}

	public function getSupportType(int $facing): SupportType {
		return $this->definition->isSolid() && $this->definition->isFullCube() ? SupportType::FULL : SupportType::NONE;
	}

	protected function recalculateCollisionBoxes(): array {
		$collision = $this->definition->getCollisionBox();
		if($collision === null) {
			return [];
		}
		return [BlockDefinition::boxToAABB($collision)];
	}

	public function getDropsForCompatibleTool(Item $item): array {
		$drops = $this->definition->getDrops();
		if($drops === BlockDefinition::DROPS_NONE) {
			return [];
		}
		if($drops === BlockDefinition::DROPS_SELF) {
			return [$this->asItem()];
		}

		$items = [];
		foreach($drops as $drop){
			// Ids inválidos já foram reportados no boot pelo BlockRegistry, aqui apenas ignoramos.
			$parsed = StringToItemParser::getInstance()->parse($drop["id"]);
			if($parsed !== null) {
				$items[] = $parsed->setCount($drop["amount"]);
			}
		}
		return $items;
	}

	public function getXpDropForTool(Item $item): int {
		return $this->definition->getXpDrop();
	}
}
