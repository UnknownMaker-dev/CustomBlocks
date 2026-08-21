<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\block;

use Unknown\CustomBlocks\libs\customiesdevs\customies\block\CustomiesBlockFactory;
use Logger;
use LogicException;
use pocketmine\block\Block;
use pocketmine\item\StringToItemParser;
use function array_key_exists;
use function count;
use function is_array;

/**
 * Guarda as definições registradas e entrega cada bloco ao Customies.
 *
 * O registro precisa acontecer inteiro dentro do onEnable: o Customies fecha a lista assim que o
 * servidor termina de subir, para poder replicá-la nos AsyncWorkers.
 */
final class BlockRegistry {

	/** @var array<string, BlockDefinition> */
	private array $definitions = [];
	private bool $locked = false;

	public function __construct(private readonly Logger $logger) { }

	/**
	 * Registra um bloco no Customies.
	 *
	 * @throws LogicException se o registro já estiver fechado ou o identificador estiver em uso
	 */
	public function register(BlockDefinition $definition): void {
		$identifier = $definition->getIdentifier();
		if($this->locked) {
			throw new LogicException("Blocos customizados só podem ser registrados durante o onEnable (tentativa com '$identifier')");
		}
		if(array_key_exists($identifier, $this->definitions)) {
			throw new LogicException("O bloco '$identifier' já está registrado");
		}

		$this->warnAboutUnknownDrops($definition);

		// A closure é executada também nos AsyncWorkers do Customies, onde objetos não chegam
		// intactos. Por isso ela carrega apenas escalares e reconstrói a definição do zero.
		$json = $definition->toJson();
		$rotatable = $definition->isRotatable();
		$blockFunc = static function () use ($json, $rotatable): Block {
			$definition = BlockDefinition::fromJson($json);
			return $rotatable ? new CustomRotatableBlock($definition) : new CustomBlock($definition);
		};

		CustomiesBlockFactory::getInstance()->registerBlock($blockFunc, $identifier, $definition->createCreativeInfo());
		$this->definitions[$identifier] = $definition;
	}

	/** Fecha o registro. Chamado quando o servidor termina de subir. */
	public function lock(): void {
		$this->locked = true;
	}

	public function isLocked(): bool {
		return $this->locked;
	}

	public function has(string $identifier): bool {
		return array_key_exists($identifier, $this->definitions);
	}

	public function get(string $identifier): ?BlockDefinition {
		return $this->definitions[$identifier] ?? null;
	}

	/** @return array<string, BlockDefinition> */
	public function getAll(): array {
		return $this->definitions;
	}

	public function count(): int {
		return count($this->definitions);
	}

	/**
	 * Os drops são resolvidos só na hora de quebrar o bloco, então um id errado passaria
	 * despercebido até alguém minerar. Avisamos aqui, no boot, enquanto ainda dá para corrigir.
	 */
	private function warnAboutUnknownDrops(BlockDefinition $definition): void {
		$drops = $definition->getDrops();
		if(!is_array($drops)) {
			return;
		}
		foreach($drops as $drop){
			if(StringToItemParser::getInstance()->parse($drop["id"]) === null) {
				$this->logger->warning("O bloco '{$definition->getIdentifier()}' tem o drop '{$drop["id"]}', que não corresponde a nenhum item conhecido e será ignorado.");
			}
		}
	}
}
