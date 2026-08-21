<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\resourcepack;

use Unknown\CustomBlocks\block\BlockDefinition;
use Logger;
use pocketmine\utils\Filesystem;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use ZipArchive;
use function array_diff;
use function array_keys;
use function basename;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function ksort;
use function mkdir;
use function pathinfo;
use function sha1;
use function sort;
use function strtolower;
use function unlink;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PATHINFO_FILENAME;

/**
 * Monta o resource pack que dá textura aos blocos customizados.
 *
 * O administrador só precisa jogar os PNGs em `plugin_data/CustomBlocks/textures/`; o nome do
 * arquivo (sem extensão) é o mesmo que se usa no campo `texture` do blocks.yml.
 */
final class ResourcePackGenerator {

	private const PACK_TEXTURE_DIR = "textures/blocks";

	private readonly string $sourceTexturesPath;
	private readonly string $buildPath;
	private readonly string $zipPath;
	private readonly string $statePath;
	private readonly string $iconPath;

	public function __construct(private readonly Logger $logger, string $dataFolder) {
		$this->sourceTexturesPath = Path::join($dataFolder, "textures");
		$this->buildPath = Path::join($dataFolder, "resource_pack");
		$this->zipPath = Path::join($dataFolder, "CustomBlocks.mcpack");
		$this->statePath = Path::join($dataFolder, "pack-version.json");
		$this->iconPath = Path::join($dataFolder, "pack_icon.png");
	}

	public function getZipPath(): string {
		return $this->zipPath;
	}

	public function getSourceTexturesPath(): string {
		return $this->sourceTexturesPath;
	}

	/**
	 * Gera o pacote e devolve o caminho do .mcpack, ou null se não houver nada para empacotar.
	 *
	 * @param BlockDefinition[] $definitions
	 * @param int[] $minEngineVersion
	 */
	public function generate(array $definitions, string $name, string $description, string $uuid, string $moduleUuid, array $minEngineVersion): ?string {
		if(!is_dir($this->sourceTexturesPath) && !mkdir($this->sourceTexturesPath, 0777, true) && !is_dir($this->sourceTexturesPath)) {
			throw new RuntimeException("Não foi possível criar a pasta de texturas em {$this->sourceTexturesPath}");
		}

		$textures = $this->collectTextures();
		$this->reportMissingTextures($definitions, array_keys($textures));
		if($textures === []) {
			$this->logger->warning("Nenhuma textura encontrada em {$this->sourceTexturesPath}; o resource pack não foi gerado. Coloque os PNGs lá e reinicie o servidor.");
			return null;
		}

		/** @var array<string, string> $files caminho dentro do pack => conteúdo */
		$files = [];
		foreach($textures as $textureName => $path){
			$contents = file_get_contents($path);
			if($contents === false) {
				throw new RuntimeException("Não foi possível ler a textura $path");
			}
			$files[self::PACK_TEXTURE_DIR . "/$textureName.png"] = $contents;
		}
		$files["textures/terrain_texture.json"] = $this->buildTerrainTexture(array_keys($textures));

		if(file_exists($this->iconPath)) {
			$icon = file_get_contents($this->iconPath);
			if($icon !== false) {
				$files["pack_icon.png"] = $icon;
			}
		}

		// O cliente guarda o pack em cache por versão. Só subimos a versão quando o conteúdo muda,
		// para não forçar um download novo a cada boot nem servir um pack desatualizado.
		$version = $this->resolveVersion($files);
		$files["manifest.json"] = $this->buildManifest($name, $description, $uuid, $moduleUuid, $version, $minEngineVersion);

		$this->writeBuildDirectory($files);
		$this->writeZip($files);

		$this->logger->info("Resource pack gerado com " . count($textures) . " textura(s), versão " . implode(".", $version) . ".");
		return $this->zipPath;
	}

	/** @return array<string, string> nome da textura => caminho do PNG de origem */
	private function collectTextures(): array {
		$textures = [];
		foreach(glob(Path::join($this->sourceTexturesPath, "*.png")) ?: [] as $path){
			$textureName = strtolower(pathinfo($path, PATHINFO_FILENAME));
			if(isset($textures[$textureName])) {
				$this->logger->warning("Há mais de uma textura chamada '$textureName'; " . basename($path) . " foi ignorada.");
				continue;
			}
			$textures[$textureName] = $path;
		}
		ksort($textures);
		return $textures;
	}

	/**
	 * @param BlockDefinition[] $definitions
	 * @param string[] $available
	 */
	private function reportMissingTextures(array $definitions, array $available): void {
		$referenced = [];
		foreach($definitions as $definition){
			foreach($definition->getTextures() as $texture){
				$referenced[strtolower($texture)] = $definition->getIdentifier();
			}
		}

		$missing = array_diff(array_keys($referenced), $available);
		foreach($missing as $texture){
			$this->logger->warning("A textura '$texture' (usada por '{$referenced[$texture]}') não existe em {$this->sourceTexturesPath}/$texture.png — o bloco vai aparecer sem textura.");
		}
	}

	/** @param string[] $textureNames */
	private function buildTerrainTexture(array $textureNames): string {
		$textureData = [];
		foreach($textureNames as $textureName){
			$textureData[$textureName] = ["textures" => self::PACK_TEXTURE_DIR . "/$textureName"];
		}

		return json_encode([
			"resource_pack_name" => "customblocks",
			"texture_name" => "atlas.terrain",
			"padding" => 8,
			"num_mip_levels" => 4,
			"texture_data" => $textureData,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/**
	 * @param int[] $version
	 * @param int[] $minEngineVersion
	 */
	private function buildManifest(string $name, string $description, string $uuid, string $moduleUuid, array $version, array $minEngineVersion): string {
		return json_encode([
			"format_version" => 2,
			"header" => [
				"name" => $name,
				"description" => $description,
				"uuid" => $uuid,
				"version" => $version,
				"min_engine_version" => $minEngineVersion,
			],
			"modules" => [
				[
					"description" => $description,
					"type" => "resources",
					"uuid" => $moduleUuid,
					"version" => $version,
				],
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/**
	 * @param array<string, string> $files
	 * @return int[] [major, minor, patch]
	 */
	private function resolveVersion(array $files): array {
		$names = array_keys($files);
		sort($names);
		$fingerprint = "";
		foreach($names as $fileName){
			$fingerprint .= $fileName . ":" . sha1($files[$fileName]) . "\n";
		}
		$hash = sha1($fingerprint);

		$state = file_exists($this->statePath) ? json_decode((string) file_get_contents($this->statePath), true) : null;
		if(is_array($state) && ($state["hash"] ?? null) === $hash && is_array($state["version"] ?? null) && count($state["version"]) === 3) {
			return [(int) $state["version"][0], (int) $state["version"][1], (int) $state["version"][2]];
		}

		$patch = is_array($state) && is_array($state["version"] ?? null) ? ((int) ($state["version"][2] ?? 0)) + 1 : 0;
		$version = [1, 0, $patch];
		file_put_contents($this->statePath, json_encode(["hash" => $hash, "version" => $version], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
		return $version;
	}

	/**
	 * Escreve o pack descompactado para quem quiser editar à mão ou copiar para um cliente.
	 *
	 * @param array<string, string> $files
	 */
	private function writeBuildDirectory(array $files): void {
		if(is_dir($this->buildPath)) {
			Filesystem::recursiveUnlink($this->buildPath);
		}
		foreach($files as $fileName => $contents){
			$target = Path::join($this->buildPath, $fileName);
			$directory = Path::getDirectory($target);
			if(!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
				throw new RuntimeException("Não foi possível criar a pasta $directory");
			}
			Filesystem::safeFilePutContents($target, $contents);
		}
	}

	/** @param array<string, string> $files */
	private function writeZip(array $files): void {
		if(file_exists($this->zipPath)) {
			unlink($this->zipPath);
		}

		$archive = new ZipArchive();
		$result = $archive->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		if($result !== true) {
			throw new RuntimeException("Não foi possível criar {$this->zipPath} (código $result do ZipArchive)");
		}
		foreach($files as $fileName => $contents){
			$archive->addFromString($fileName, $contents);
		}
		$archive->close();
	}
}
