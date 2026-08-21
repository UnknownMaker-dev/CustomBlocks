# CustomBlocks

Plugin de PocketMine-MP para criar blocos customizados escrevendo YAML, sem precisar de código.
Toda a parte de rede e de palette é feita pelo [Customies](https://github.com/CustomiesDevs/Customies),
que vem **embutido** — não é preciso instalar nada além deste plugin.

> Esta é a versão **com libs**. Existe também a `CustomBlocks-NoLibs`, que usa o Customies
> instalado no servidor. Use uma ou outra, nunca as duas.

## Instalação

1. Coloque a pasta `CustomBlocks` em `plugins/`.
2. Suba o servidor uma vez para gerar a pasta de dados do plugin.
3. Jogue os PNGs das texturas na subpasta `textures/`.
4. Descreva os blocos no `blocks.yml` e reinicie.

> Se o plugin **Customies** avulso estiver instalado, o CustomBlocks se recusa a ligar — e o
> PocketMine derruba o servidor, como faz com qualquer plugin que falhe ao habilitar. É de
> propósito: duas palettes de blocos concorrendo deixariam os blocos de uma delas invisíveis para o
> cliente, e isso só apareceria em produção. A correção é apagar `plugins/Customies` e usar só este.

O PocketMine-MP 5 não carrega plugins em pasta sozinho — é preciso ter o **DevTools** instalado,
ou empacotar o plugin em `.phar`.

Onde fica a pasta de dados depende de `plugins.legacy-data-dir` no `pocketmine.yml`:
`plugin_data/CustomBlocks/` quando é `false` (o padrão do arquivo de exemplo) ou
`plugins/CustomBlocks/` quando é `true`. O plugin escreve o caminho exato no console a cada boot:

```
[CustomBlocks] Texturas dos blocos: .../plugin_data/CustomBlocks/textures
```

> Blocos customizados só podem ser registrados enquanto o servidor sobe. Qualquer mudança no
> `blocks.yml` exige reiniciar — não existe reload.

## Criando um bloco

```yaml
blocks:
  customblocks:ruby_block:
    name: "Bloco de Rubi"
    texture: ruby_block      # plugin_data/CustomBlocks/textures/ruby_block.png
    hardness: 5.0
    tool: pickaxe
    tool_tier: iron
    creative:
      category: construction
      group: itemGroup.name.ore
```

O identificador é sempre `namespace:nome`. Use um namespace seu — `minecraft` é reservado.
O `blocks.yml` que vem junto documenta todos os campos e traz exemplos de textura por face,
bloco rotacionável, bloco transparente e bloco sem colisão.

## Resource pack

O plugin monta sozinho o resource pack com as texturas e o coloca no topo da pilha do servidor.
O `terrain_texture.json` e o `manifest.json` são gerados a partir dos PNGs da pasta `textures/`;
o nome do arquivo (sem `.png`) é o nome usado no campo `texture`.

Saídas na pasta de dados do plugin:

| Arquivo | Para que serve |
| --- | --- |
| `CustomBlocks.mcpack` | O pack que o servidor envia aos jogadores |
| `resource_pack/` | O mesmo pack descompactado, para conferir ou editar |
| `pack-version.json` | Controla a versão do pack |

A versão do pack só sobe quando o conteúdo muda, então os jogadores não rebaixam tudo a cada boot.

Para incluir modelos próprios (`minecraft:geometry.*`), adicione o `models/` ao pack em
`resource_pack/`, desligue `resource-pack.generate` no `config.yml` e passe a manter o pack à mão.

Se `force-resources` estiver desligado no `config.yml`, quem recusar o download vai ver os blocos
sem textura — o plugin avisa no console quando esse é o caso.

## Comandos

| Comando | Descrição |
| --- | --- |
| `/customblocks list` | Lista os blocos registrados |
| `/customblocks info <bloco>` | Mostra as propriedades de um bloco |
| `/customblocks give [jogador] <bloco> [qtd]` | Entrega o bloco |
| `/customblocks pack` | Regera o resource pack |

Aliases: `/cblocks`, `/cb`. Permissão: `customblocks.command` (padrão: op).

## API para outros plugins

Adicione `CustomBlocks` no `depend` do seu `plugin.yml` e registre no `onEnable`:

```php
use Unknown\CustomBlocks\CustomBlocks;

CustomBlocks::getInstance()->registerBlock("meuplugin:bloco", [
    "name" => "Meu Bloco",
    "texture" => "meu_bloco",
    "hardness" => 3.0,
    "tool" => "pickaxe",
]);
```

O array aceita exatamente os mesmos campos do `blocks.yml`. Para pegar a instância do bloco depois:

```php
$block = CustomiesBlockFactory::getInstance()->get("meuplugin:bloco");
$item = $block->asItem();
```

## O Customies embutido

A biblioteca fica em [`src/Unknown/CustomBlocks/libs/customiesdevs/customies/`](src/Unknown/CustomBlocks/libs/customiesdevs/customies/),
com o namespace reescrito para `Unknown\CustomBlocks\libs\...` — o mesmo esquema de virion que o
Poggit usa. É o código do Customies 1.4.0 sem alterações, menos o `Customies.php` (o entrypoint de
plugin, que não faz sentido numa lib). O que ele fazia passou para o `onEnable` do CustomBlocks:
registrar o `CustomiesListener` e chamar `addWorkerInitHook()` depois que o servidor sobe.

O Customies é MIT; a licença original está junto em `libs/customiesdevs/customies/LICENSE`.

Para atualizar a lib: copie o `src/` da versão nova por cima e rode

```bash
find src/Unknown/CustomBlocks/libs -name "*.php" -exec \
  sed -i 's/\bcustomiesdevs\\customies\b/Unknown\\CustomBlocks\\libs\\customiesdevs\\customies/g' {} +
rm src/Unknown/CustomBlocks/libs/customiesdevs/customies/Customies.php
```

## Limites conhecidos

- Os blocos precisam ser registrados no `onEnable`; depois disso o Customies fecha a palette.
- A rotação usa as quatro direções horizontais. Eixos verticais exigem uma geometria própria.
- O gerador de resource pack cuida de texturas; modelos, sons e traduções ficam por sua conta.
- Ao remover um bloco do `blocks.yml`, o que já estava construído no mundo vira bloco desconhecido.
  Quebre ou substitua os blocos antes de tirar a definição.
