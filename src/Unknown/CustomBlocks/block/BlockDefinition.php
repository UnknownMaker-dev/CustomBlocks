<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\block;

use Unknown\CustomBlocks\libs\customiesdevs\customies\block\Material;
use Unknown\CustomBlocks\libs\customiesdevs\customies\item\CreativeInventoryInfo;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockToolType;
use pocketmine\item\ToolTier;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function preg_match;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;
use function ucwords;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/**
 * Representação imutável de um bloco customizado descrito no blocks.yml.
 *
 * Os dados ficam guardados como um array puro de escalares para que a definição possa atravessar
 * as threads dos AsyncWorkers do Customies em forma de JSON — objetos não sobrevivem a essa viagem.
 */
final class BlockDefinition {

	/** Caixa que cobre o bloco inteiro, no sistema de coordenadas do Bedrock (origem -8..8, tamanho 0..16). */
	public const FULL_BOX = [-8.0, 0.0, -8.0, 16.0, 16.0, 16.0];

	private const RENDER_METHODS = [
		"opaque" => Material::RENDER_METHOD_OPAQUE,
		"alpha_test" => Material::RENDER_METHOD_ALPHA_TEST,
		"blend" => Material::RENDER_METHOD_BLEND,
	];

	private const TEXTURE_TARGETS = [
		"*" => Material::TARGET_ALL,
		"all" => Material::TARGET_ALL,
		"sides" => Material::TARGET_SIDES,
		"up" => Material::TARGET_UP,
		"down" => Material::TARGET_DOWN,
		"north" => Material::TARGET_NORTH,
		"east" => Material::TARGET_EAST,
		"south" => Material::TARGET_SOUTH,
		"west" => Material::TARGET_WEST,
	];

	private const TOOL_TYPES = [
		"none" => BlockToolType::NONE,
		"sword" => BlockToolType::SWORD,
		"shovel" => BlockToolType::SHOVEL,
		"pickaxe" => BlockToolType::PICKAXE,
		"axe" => BlockToolType::AXE,
		"shears" => BlockToolType::SHEARS,
		"hoe" => BlockToolType::HOE,
	];

	private const TOOL_TIERS = [
		"wood" => ToolTier::WOOD,
		"gold" => ToolTier::GOLD,
		"stone" => ToolTier::STONE,
		"iron" => ToolTier::IRON,
		"diamond" => ToolTier::DIAMOND,
		"netherite" => ToolTier::NETHERITE,
	];

	private const CREATIVE_CATEGORIES = [
		"all" => CreativeInventoryInfo::CATEGORY_ALL,
		"commands" => CreativeInventoryInfo::CATEGORY_COMMANDS,
		"construction" => CreativeInventoryInfo::CATEGORY_CONSTRUCTION,
		"equipment" => CreativeInventoryInfo::CATEGORY_EQUIPMENT,
		"items" => CreativeInventoryInfo::CATEGORY_ITEMS,
		"nature" => CreativeInventoryInfo::CATEGORY_NATURE,
	];

	public const DROPS_SELF = "self";
	public const DROPS_NONE = "none";

	/** @param mixed[] $data já normalizado por {@link BlockDefinition::parse()} */
	private function __construct(private readonly array $data) { }

	/**
	 * Lê e valida a entrada crua vinda do blocks.yml.
	 *
	 * @param mixed[] $raw
	 * @throws BlockDefinitionException se qualquer campo estiver fora do formato esperado
	 */
	public static function parse(string $identifier, array $raw): self {
		$identifier = strtolower(trim($identifier));
		if(preg_match('/^[a-z0-9_]+:[a-z0-9_]+$/', $identifier) !== 1) {
			throw new BlockDefinitionException($identifier, "o identificador precisa estar no formato 'namespace:nome' usando apenas [a-z0-9_]");
		}
		if(str_starts_with($identifier, "minecraft:")) {
			throw new BlockDefinitionException($identifier, "o namespace 'minecraft' é reservado pelo jogo");
		}

		$name = self::readString($identifier, $raw, "name", self::humanize($identifier));
		$textures = self::readTextures($identifier, $raw);
		$renderMethod = self::readEnum($identifier, $raw, "render_method", self::RENDER_METHODS, "opaque");

		$hardness = max(0.0, self::readFloat($identifier, $raw, "hardness", 1.0));
		$unbreakable = self::readBool($identifier, $raw, "unbreakable", false);
		$blastResistance = self::readFloat($identifier, $raw, "blast_resistance", $hardness * 5.0);

		$toolType = self::readEnum($identifier, $raw, "tool", self::TOOL_TYPES, "none");
		$tierName = self::readString($identifier, $raw, "tool_tier", "");
		if($tierName === "") {
			$harvestLevel = 0;
		} else {
			$tier = self::TOOL_TIERS[strtolower($tierName)] ?? null;
			if($tier === null) {
				throw new BlockDefinitionException($identifier, "tool_tier '$tierName' é inválido (use: " . implode(", ", array_keys(self::TOOL_TIERS)) . ")");
			}
			$harvestLevel = $tier->getHarvestLevel();
		}

		$transparent = self::readBool($identifier, $raw, "transparent", $renderMethod !== Material::RENDER_METHOD_OPAQUE);
		$solid = self::readBool($identifier, $raw, "solid", true);

		$collisionBox = self::readBox($identifier, $raw, "collision_box");
		$selectionBox = self::readBox($identifier, $raw, "selection_box");

		$flammable = null;
		$flammableRaw = $raw["flammable"] ?? false;
		if($flammableRaw !== false && $flammableRaw !== null) {
			$flammableRaw = is_array($flammableRaw) ? $flammableRaw : [];
			$flammable = [
				max(0, self::readInt($identifier, $flammableRaw, "catch_chance", 5)),
				max(0, self::readInt($identifier, $flammableRaw, "destroy_chance", 20)),
			];
		}

		$creativeRaw = is_array($raw["creative"] ?? null) ? $raw["creative"] : [];
		$category = self::readEnum($identifier, $creativeRaw, "category", self::CREATIVE_CATEGORIES, "construction");
		$group = self::readString($identifier, $creativeRaw, "group", CreativeInventoryInfo::NONE);

		return new self([
			"identifier" => $identifier,
			"name" => $name,
			"textures" => $textures,
			"render_method" => $renderMethod,
			"face_dimming" => self::readBool($identifier, $raw, "face_dimming", true),
			"ambient_occlusion" => self::readBool($identifier, $raw, "ambient_occlusion", true),
			"geometry" => self::readString($identifier, $raw, "geometry", "minecraft:geometry.full_block"),
			"rotatable" => self::readBool($identifier, $raw, "rotatable", false),

			"hardness" => $hardness,
			"blast_resistance" => $blastResistance,
			"unbreakable" => $unbreakable,
			"tool_type" => $toolType,
			"harvest_level" => $harvestLevel,
			"mining_seconds" => self::readFloat($identifier, $raw, "mining_seconds", $hardness),
			"explosion_resistance" => self::readFloat($identifier, $raw, "explosion_resistance", $blastResistance),

			"light_emission" => min(15, max(0, self::readInt($identifier, $raw, "light_emission", 0))),
			"light_filter" => min(15, max(0, self::readInt($identifier, $raw, "light_filter", $transparent ? 0 : 15))),
			"friction" => min(0.9, max(0.0, self::readFloat($identifier, $raw, "friction", 0.6))),
			"solid" => $solid,
			"transparent" => $transparent,
			"breathable" => self::readBool($identifier, $raw, "breathable", false),

			"flammable" => $flammable,
			"collision_box" => $collisionBox,
			"selection_box" => $selectionBox,

			"creative_category" => $category,
			"creative_group" => $group,

			"drops" => self::readDrops($identifier, $raw),
			"xp_drop" => max(0, self::readInt($identifier, $raw, "xp_drop", 0)),
		]);
	}

	/** Reconstrói a definição a partir do JSON produzido por {@link BlockDefinition::toJson()}. */
	public static function fromJson(string $json): self {
		return new self(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
	}

	public function toJson(): string {
		return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
	}

	/** @return mixed[] */
	public function toArray(): array {
		return $this->data;
	}

	public function getIdentifier(): string {
		return $this->data["identifier"];
	}

	public function getDisplayName(): string {
		return $this->data["name"];
	}

	/** @return array<string, string> alvo da face => nome da textura no terrain_texture.json */
	public function getTextures(): array {
		return $this->data["textures"];
	}

	public function getGeometry(): string {
		return $this->data["geometry"];
	}

	public function isRotatable(): bool {
		return $this->data["rotatable"];
	}

	public function getLightEmission(): int {
		return $this->data["light_emission"];
	}

	public function getLightFilter(): int {
		return $this->data["light_filter"];
	}

	public function getFriction(): float {
		return $this->data["friction"];
	}

	public function isSolid(): bool {
		return $this->data["solid"];
	}

	public function isTransparent(): bool {
		return $this->data["transparent"];
	}

	public function isBreathable(): bool {
		return $this->data["breathable"];
	}

	public function getMiningSeconds(): float {
		return $this->data["mining_seconds"];
	}

	public function getExplosionResistance(): float {
		return $this->data["explosion_resistance"];
	}

	/** @return null|array{int, int} [catch_chance, destroy_chance], ou null se o bloco não pega fogo */
	public function getFlammable(): ?array {
		return $this->data["flammable"];
	}

	/** @return null|float[] null quando a caixa está desativada */
	public function getCollisionBox(): ?array {
		return $this->data["collision_box"];
	}

	/** @return null|float[] null quando a caixa está desativada */
	public function getSelectionBox(): ?array {
		return $this->data["selection_box"];
	}

	public function getXpDrop(): int {
		return $this->data["xp_drop"];
	}

	/** @return string|array<int, array{id: string, amount: int}> "self", "none" ou a lista de drops */
	public function getDrops(): string|array {
		return $this->data["drops"];
	}

	public function getCreativeCategory(): string {
		return $this->data["creative_category"];
	}

	public function getCreativeGroup(): string {
		return $this->data["creative_group"];
	}

	public function createBreakInfo(): BlockBreakInfo {
		if($this->data["unbreakable"]) {
			return BlockBreakInfo::indestructible($this->data["explosion_resistance"]);
		}
		return new BlockBreakInfo(
			$this->data["hardness"],
			$this->data["tool_type"],
			$this->data["harvest_level"],
			$this->data["blast_resistance"]
		);
	}

	public function createCreativeInfo(): CreativeInventoryInfo {
		return new CreativeInventoryInfo($this->data["creative_category"], $this->data["creative_group"]);
	}

	/** @return Material[] */
	public function createMaterials(): array {
		$materials = [];
		foreach($this->data["textures"] as $target => $texture){
			$materials[] = new Material(
				$target,
				$texture,
				$this->data["render_method"],
				$this->data["face_dimming"],
				$this->data["ambient_occlusion"]
			);
		}
		return $materials;
	}

	/** O bloco ocupa o cubo inteiro? Usado para decidir se ele serve de apoio para outros blocos. */
	public function isFullCube(): bool {
		$box = $this->data["collision_box"];
		// O JSON que atravessa as threads pode devolver 16 no lugar de 16.0, então a comparação é numérica.
		return $box !== null && array_map(static fn($value) => (float) $value, $box) === self::FULL_BOX;
	}

	/** Converte uma caixa do sistema do Bedrock para o AABB usado pelo servidor (0..1 em cada eixo). */
	public static function boxToAABB(array $box): AxisAlignedBB {
		[$originX, $originY, $originZ, $sizeX, $sizeY, $sizeZ] = $box;
		$minX = ($originX + 8.0) / 16.0;
		$minY = $originY / 16.0;
		$minZ = ($originZ + 8.0) / 16.0;
		return new AxisAlignedBB($minX, $minY, $minZ, $minX + $sizeX / 16.0, $minY + $sizeY / 16.0, $minZ + $sizeZ / 16.0);
	}

	/** @param float[] $box */
	public static function boxOrigin(array $box): Vector3 {
		return new Vector3($box[0], $box[1], $box[2]);
	}

	/** @param float[] $box */
	public static function boxSize(array $box): Vector3 {
		return new Vector3($box[3], $box[4], $box[5]);
	}

	/**
	 * Aceita `texture: nome`, ou `textures: {up: a, down: b, sides: c}`.
	 *
	 * @param mixed[] $raw
	 * @return array<string, string>
	 */
	private static function readTextures(string $identifier, array $raw): array {
		// Sempre em minúsculas: o gerador do resource pack registra as texturas pelo nome do PNG
		// já rebaixado, e uma diferença de caixa aqui faria o cliente procurar uma chave que não
		// existe no terrain_texture.json — o bloco apareceria sem textura, sem nenhum aviso.
		$single = $raw["texture"] ?? null;
		if(is_string($single) && trim($single) !== "") {
			return [Material::TARGET_ALL => strtolower(trim($single))];
		}

		$textures = $raw["textures"] ?? null;
		if(!is_array($textures) || $textures === []) {
			throw new BlockDefinitionException($identifier, "defina 'texture: <nome>' ou um mapa 'textures:' com pelo menos uma face");
		}

		$result = [];
		foreach($textures as $face => $texture){
			$target = self::TEXTURE_TARGETS[strtolower((string) $face)] ?? null;
			if($target === null) {
				throw new BlockDefinitionException($identifier, "a face '$face' é inválida (use: " . implode(", ", array_keys(self::TEXTURE_TARGETS)) . ")");
			}
			if(!is_string($texture) || trim($texture) === "") {
				throw new BlockDefinitionException($identifier, "a textura da face '$face' precisa ser um nome não vazio");
			}
			$result[$target] = strtolower(trim($texture));
		}
		return $result;
	}

	/**
	 * @param mixed[] $raw
	 * @return null|float[] null quando a caixa foi desativada com `false`
	 */
	private static function readBox(string $identifier, array $raw, string $key): ?array {
		$value = $raw[$key] ?? null;
		if($value === null) {
			return self::FULL_BOX;
		}
		if($value === false) {
			return null;
		}
		if($value === true) {
			return self::FULL_BOX;
		}
		if(!is_array($value) || !is_array($value["origin"] ?? null) || !is_array($value["size"] ?? null)) {
			throw new BlockDefinitionException($identifier, "$key precisa ser 'false' ou conter 'origin: [x, y, z]' e 'size: [x, y, z]'");
		}

		$origin = self::readVector($identifier, "$key.origin", $value["origin"]);
		$size = self::readVector($identifier, "$key.size", $value["size"]);
		foreach([0, 1, 2] as $axis){
			$min = $axis === 1 ? 0.0 : -8.0;
			$maxValue = $axis === 1 ? 16.0 : 8.0;
			if($size[$axis] < 0.0) {
				throw new BlockDefinitionException($identifier, "$key.size não pode ser negativo");
			}
			if($origin[$axis] < $min || $origin[$axis] > $maxValue || $origin[$axis] + $size[$axis] > $maxValue) {
				throw new BlockDefinitionException($identifier, sprintf("$key precisa caber entre (-8, 0, -8) e (8, 16, 8); o eixo %d saiu do limite", $axis));
			}
		}
		return [$origin[0], $origin[1], $origin[2], $size[0], $size[1], $size[2]];
	}

	/**
	 * @param mixed[] $value
	 * @return float[]
	 */
	private static function readVector(string $identifier, string $path, array $value): array {
		if(count($value) !== 3) {
			throw new BlockDefinitionException($identifier, "$path precisa ter exatamente 3 números [x, y, z]");
		}
		$result = [];
		foreach(array_values($value) as $component){
			if(!is_numeric($component)) {
				throw new BlockDefinitionException($identifier, "$path só aceita números");
			}
			$result[] = (float) $component;
		}
		return $result;
	}

	/**
	 * @param mixed[] $raw
	 * @return string|array<int, array{id: string, amount: int}>
	 */
	private static function readDrops(string $identifier, array $raw): string|array {
		$drops = $raw["drops"] ?? self::DROPS_SELF;
		if(is_string($drops)) {
			$drops = strtolower(trim($drops));
			if($drops !== self::DROPS_SELF && $drops !== self::DROPS_NONE) {
				throw new BlockDefinitionException($identifier, "drops só aceita 'self', 'none' ou uma lista de itens");
			}
			return $drops;
		}
		if(!is_array($drops) || !array_is_list($drops)) {
			throw new BlockDefinitionException($identifier, "drops só aceita 'self', 'none' ou uma lista de itens");
		}

		$result = [];
		foreach($drops as $index => $drop){
			if(is_string($drop)) {
				$drop = ["id" => $drop];
			}
			if(!is_array($drop) || !is_string($drop["id"] ?? null) || trim($drop["id"]) === "") {
				throw new BlockDefinitionException($identifier, "o drop #$index precisa ter um 'id' de item");
			}
			$result[] = [
				"id" => trim($drop["id"]),
				"amount" => max(1, self::readInt($identifier, $drop, "amount", 1)),
			];
		}
		return $result;
	}

	/** @param mixed[] $raw */
	private static function readString(string $identifier, array $raw, string $key, string $default): string {
		if(!array_key_exists($key, $raw) || $raw[$key] === null) {
			return $default;
		}
		if(!is_string($raw[$key])) {
			throw new BlockDefinitionException($identifier, "$key precisa ser um texto");
		}
		return trim($raw[$key]);
	}

	/** @param mixed[] $raw */
	private static function readBool(string $identifier, array $raw, string $key, bool $default): bool {
		if(!array_key_exists($key, $raw) || $raw[$key] === null) {
			return $default;
		}
		if(!is_bool($raw[$key])) {
			throw new BlockDefinitionException($identifier, "$key precisa ser true ou false");
		}
		return $raw[$key];
	}

	/** @param mixed[] $raw */
	private static function readInt(string $identifier, array $raw, string $key, int $default): int {
		if(!array_key_exists($key, $raw) || $raw[$key] === null) {
			return $default;
		}
		if(!is_numeric($raw[$key])) {
			throw new BlockDefinitionException($identifier, "$key precisa ser um número inteiro");
		}
		return (int) $raw[$key];
	}

	/** @param mixed[] $raw */
	private static function readFloat(string $identifier, array $raw, string $key, float $default): float {
		if(!array_key_exists($key, $raw) || $raw[$key] === null) {
			return $default;
		}
		if(!is_numeric($raw[$key])) {
			throw new BlockDefinitionException($identifier, "$key precisa ser um número");
		}
		return (float) $raw[$key];
	}

	/**
	 * @param mixed[] $raw
	 * @param array<string, mixed> $values
	 */
	private static function readEnum(string $identifier, array $raw, string $key, array $values, string $default): mixed {
		$name = strtolower(self::readString($identifier, $raw, $key, $default));
		if(!array_key_exists($name, $values)) {
			throw new BlockDefinitionException($identifier, "$key '$name' é inválido (use: " . implode(", ", array_keys($values)) . ")");
		}
		return $values[$name];
	}

	/** "customblocks:ruby_block" => "Ruby Block" */
	private static function humanize(string $identifier): string {
		[, $name] = explode(":", $identifier, 2);
		return ucwords(str_replace("_", " ", $name));
	}
}
