<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\command;

use Unknown\CustomBlocks\block\BlockDefinition;
use Unknown\CustomBlocks\CustomBlocks;
use Unknown\CustomBlocks\libs\customiesdevs\customies\block\CustomiesBlockFactory;
use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use Throwable;
use function array_shift;
use function array_slice;
use function count;
use function implode;
use function is_array;
use function is_numeric;
use function max;
use function min;
use function strtolower;

/** Comando administrativo: listar, inspecionar e obter os blocos customizados. */
final class CustomBlocksCommand implements CommandExecutor {

	private const MAX_GIVE_AMOUNT = 1024;

	public function __construct(private readonly CustomBlocks $plugin) { }

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		$subCommand = strtolower((string) array_shift($args));

		switch($subCommand){
			case "list":
				$this->sendList($sender);
				return true;
			case "info":
				$this->sendInfo($sender, $args);
				return true;
			case "give":
				$this->give($sender, $args);
				return true;
			case "pack":
				$this->rebuildPack($sender);
				return true;
		}

		$sender->sendMessage(TextFormat::YELLOW . "Uso: /$label <list|info|give|pack>");
		$sender->sendMessage(TextFormat::GRAY . " /$label list " . TextFormat::WHITE . "- lista os blocos registrados");
		$sender->sendMessage(TextFormat::GRAY . " /$label info <bloco> " . TextFormat::WHITE . "- mostra as propriedades de um bloco");
		$sender->sendMessage(TextFormat::GRAY . " /$label give [jogador] <bloco> [quantidade] " . TextFormat::WHITE . "- entrega o bloco");
		$sender->sendMessage(TextFormat::GRAY . " /$label pack " . TextFormat::WHITE . "- regera o resource pack");
		return true;
	}

	private function sendList(CommandSender $sender): void {
		$definitions = $this->plugin->getRegistry()->getAll();
		if($definitions === []) {
			$sender->sendMessage(TextFormat::RED . "Nenhum bloco customizado está registrado. Confira o blocks.yml.");
			return;
		}

		$sender->sendMessage(TextFormat::GREEN . count($definitions) . " bloco(s) customizado(s):");
		foreach($definitions as $identifier => $definition){
			$sender->sendMessage(TextFormat::GRAY . " - " . TextFormat::WHITE . $identifier . TextFormat::GRAY . " (" . $definition->getDisplayName() . ")");
		}
	}

	/** @param string[] $args */
	private function sendInfo(CommandSender $sender, array $args): void {
		$definition = $this->findDefinition($sender, $args[0] ?? null);
		if($definition === null) {
			return;
		}

		$drops = $definition->getDrops();
		$flammable = $definition->getFlammable();

		$sender->sendMessage(TextFormat::GREEN . $definition->getDisplayName() . TextFormat::GRAY . " (" . $definition->getIdentifier() . ")");
		$this->sendField($sender, "Texturas", implode(", ", $this->describeTextures($definition)));
		$this->sendField($sender, "Geometria", $definition->getGeometry());
		$this->sendField($sender, "Rotacionável", $definition->isRotatable() ? "sim" : "não");
		$this->sendField($sender, "Dureza / tempo de quebra", $definition->createBreakInfo()->getHardness() . " / " . $definition->getMiningSeconds() . "s");
		$this->sendField($sender, "Resistência a explosão", (string) $definition->getExplosionResistance());
		$this->sendField($sender, "Luz emitida / filtrada", $definition->getLightEmission() . " / " . $definition->getLightFilter());
		$this->sendField($sender, "Atrito", (string) $definition->getFriction());
		$this->sendField($sender, "Sólido / transparente", ($definition->isSolid() ? "sim" : "não") . " / " . ($definition->isTransparent() ? "sim" : "não"));
		$this->sendField($sender, "Colisão", $definition->getCollisionBox() === null ? "desativada" : ($definition->isFullCube() ? "cubo inteiro" : "personalizada"));
		$this->sendField($sender, "Inflamável", $flammable === null ? "não" : "pega {$flammable[0]} / queima {$flammable[1]}");
		$this->sendField($sender, "Drops", is_array($drops) ? $this->describeDrops($drops) : $drops);
		$this->sendField($sender, "XP ao quebrar", (string) $definition->getXpDrop());
		$this->sendField($sender, "Criativo", $definition->getCreativeCategory() . " / " . $definition->getCreativeGroup());
	}

	/** @param string[] $args */
	private function give(CommandSender $sender, array $args): void {
		if(count($args) === 0) {
			$sender->sendMessage(TextFormat::RED . "Uso: /customblocks give [jogador] <bloco> [quantidade]");
			return;
		}

		// O nome do jogador é opcional quando quem digita já está em jogo. Um identificador de
		// bloco conhecido nunca é lido como nome, para não confundir os dois argumentos.
		if($this->plugin->getRegistry()->has(strtolower($args[0]))) {
			if(!$sender instanceof Player) {
				$sender->sendMessage(TextFormat::RED . "Rode do console informando o jogador: /customblocks give <jogador> <bloco> [quantidade]");
				return;
			}
			$target = $sender;
		} else {
			$target = $this->plugin->getServer()->getPlayerByPrefix($args[0]);
			if($target === null) {
				$sender->sendMessage(TextFormat::RED . "Não existe nenhum jogador online chamado '{$args[0]}' nem um bloco com esse identificador.");
				return;
			}
			$args = array_slice($args, 1);
		}

		$definition = $this->findDefinition($sender, $args[0] ?? null);
		if($definition === null) {
			return;
		}

		$amount = 1;
		if(isset($args[1])) {
			if(!is_numeric($args[1])) {
				$sender->sendMessage(TextFormat::RED . "A quantidade precisa ser um número.");
				return;
			}
			$amount = min(self::MAX_GIVE_AMOUNT, max(1, (int) $args[1]));
		}

		try{
			$item = CustomiesBlockFactory::getInstance()->get($definition->getIdentifier())->asItem()->setCount($amount);
		}catch(Throwable $e){
			$sender->sendMessage(TextFormat::RED . "Não foi possível criar o item de '{$definition->getIdentifier()}': " . $e->getMessage());
			return;
		}

		// O que não couber no inventário cai no chão, como no /give do próprio jogo.
		foreach($target->getInventory()->addItem($item) as $leftover){
			$target->getWorld()->dropItem($target->getPosition(), $leftover);
		}

		$sender->sendMessage(TextFormat::GREEN . "{$amount}x {$definition->getDisplayName()} entregue para {$target->getName()}.");
		if($target !== $sender) {
			$target->sendMessage(TextFormat::GREEN . "Você recebeu {$amount}x {$definition->getDisplayName()}.");
		}
	}

	private function rebuildPack(CommandSender $sender): void {
		if($this->plugin->getPackGenerator() === null) {
			$sender->sendMessage(TextFormat::RED . "A geração do resource pack está desligada no config.yml.");
			return;
		}

		try{
			$zipPath = $this->plugin->buildResourcePack();
		}catch(Throwable $e){
			$sender->sendMessage(TextFormat::RED . "Falha ao gerar o resource pack: " . $e->getMessage());
			return;
		}
		if($zipPath === null) {
			$sender->sendMessage(TextFormat::RED . "Nenhuma textura encontrada em " . $this->plugin->getPackGenerator()->getSourceTexturesPath() . ".");
			return;
		}

		$this->plugin->registerResourcePack($zipPath);
		$sender->sendMessage(TextFormat::GREEN . "Resource pack regerado em $zipPath.");
		$sender->sendMessage(TextFormat::YELLOW . "Quem já está conectado precisa entrar de novo para baixar a versão nova.");
	}

	private function findDefinition(CommandSender $sender, ?string $identifier): ?BlockDefinition {
		if($identifier === null) {
			$sender->sendMessage(TextFormat::RED . "Informe o identificador do bloco. Use /customblocks list para ver os disponíveis.");
			return null;
		}

		$definition = $this->plugin->getRegistry()->get(strtolower($identifier));
		if($definition === null) {
			$sender->sendMessage(TextFormat::RED . "O bloco '$identifier' não está registrado. Use /customblocks list para ver os disponíveis.");
			return null;
		}
		return $definition;
	}

	/** @return string[] */
	private function describeTextures(BlockDefinition $definition): array {
		$textures = [];
		foreach($definition->getTextures() as $target => $texture){
			$textures[] = ($target === "*" ? "todas" : $target) . ": " . $texture;
		}
		return $textures;
	}

	/** @param array<int, array{id: string, amount: int}> $drops */
	private function describeDrops(array $drops): string {
		$parts = [];
		foreach($drops as $drop){
			$parts[] = $drop["amount"] . "x " . $drop["id"];
		}
		return $parts === [] ? "nenhum" : implode(", ", $parts);
	}

	private function sendField(CommandSender $sender, string $label, string $value): void {
		$sender->sendMessage(TextFormat::GRAY . " $label: " . TextFormat::WHITE . $value);
	}
}
