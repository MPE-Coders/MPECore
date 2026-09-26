# Версионные данные и первичные источники

- NetherGames BedrockData: https://github.com/NetherGamesMC/BedrockData
- NetherGames BedrockProtocol: https://github.com/NetherGamesMC/BedrockProtocol
- Официальное описание: https://mojang.github.io/bedrock-protocol-docs/

Точные версии зависимостей закреплены в composer.json/composer.lock и resources/upstream.json. Не обновлять отдельную палитру без соответствующей проверки профиля.

## Разные пространства данных

canonical_block_states*.nbt — последовательность отдельных TAG_Compound в network NBT. Порядок записей определяет runtime ID. Сортировать их как обычный словарь нельзя. Тип свойства является частью состояния: Byte(1) не равен Int(1).

block_state_meta_map*.json следует порядку палитры. required_item_list*.json — отдельные ID предметов, в том числе signed; component_nbt читается своим little-endian форматом, а не форматом сетевой палитры. Блоки и предметы могут использовать разные суффиксы данных в одном профиле.

Файлы рецептов, биомов и сущностей — данные. Они не добавляют автоматически крафт, AI, печи и все состояния в ограниченный игровой реестр.

## Проверки

`./start.sh --doctor` проверяет установленный source reference, принимаемый протокол, перечисленные assets, NBT-типы и metadata. Затем Python независимо сравнивает результаты с data/palettes.loaded.json. Fingerprint lock обнаруживает изменение первоначально выбранного набора, но не является подписью Mojang.

```bash
./start.sh --audit-data --protocol 975 --export-catalog --output data/catalog-975.json
```

Инструмент также умеет читать исторические Git-коммиты через --data-root/--data-ref и --codec-root/--codec-ref с --inventory-only. Он не делает checkout и не исполняет старый PHP. Обнаруженный старый профиль не считается автоматически включённым в runtime: необходимы отдельные совместимые кодеки, данные и клиентские проверки.
