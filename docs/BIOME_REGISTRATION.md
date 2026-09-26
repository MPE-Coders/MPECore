# Block / InitialConnection-90: исправление биомов для 2193

В трассе пользователя Login/онлайн-идентичность проходят. JigsawStructureData
и VoxelShapes идут до StartGame. Затем отправляются реестры и чанки, после
чего официальный клиент завершает соединение с кодом Block.

Обнаружена конкретная ошибка в `tools/import-2193.cjs`: импортёр брал только
plains и заменял исходный `id: null` на `id: 1`. Эти числа относятся к разным
пространствам: **1 — plains в палитре биомов чанка**, **65535 (0xffff) —
ванильное определение в BiomeDefinitionListPacket**. Cloudburst сериализует
null как short -1, то есть те же байты ff ff. Наш native-кодек использует
unsigned short, поэтому должен получать 65535, не -1 и не 1.

Теперь импортируются все 89 ванильных определений из прежнего закреплённого
Cloudburst-файла. Значения климата, теги и ARGB сохранены. Меняются только
представление null ID и написание mapWaterColor -> mapWaterColour для API PHP.
Чанки по-прежнему содержат plains=1; мир остаётся плоским. Новых биомов,
генерации, пакетов ресурсов или поведения блоков этот патч не добавляет.

Положительный explicit ID и непустые client-side chunkGenData в исходнике
отклоняются: их нельзя незаметно превратить в ванильные определения.
Перед открытием порта PHP отклоняет старый однобиомный/id=1 набор даже при
совпадающих файловых хешах. Независимый Python-аудит сравнивает полный набор
определений с исходным закреплённым дампом.

## Обновление

Остановить сервер, обновить feat/bedrock-26.51, затем выполнить обычный
`./start.sh`. Импортёр пересоздаёт только производные файлы в
`.runtime/bedrock/2193` из уже проверенных исходников. Не удалять мир, vendor,
кэш Microsoft-авторизации или server.json. В выводе подготовки ожидается:

```
"biomes":"vanilla-definitions","biome_definitions":89,"flat_world_biome":"plains"
PASS vanilla biome wire IDs profile 2193: 89 entries, id=65535
```

## Проверки и граница готовности

Новый клиент против неизменённого серверного импортёра отклонил настоящее
сетевое подключение: `Vanilla biome minecraft:plains: registration ID must
be 65535, not chunk biome ID 1`. Это воспроизведение неверных данных в нашей
семантической проверке, НЕ воспроизведение внутренней ошибки официального GUI.

Проверяется фактически полученный BiomeDefinitionListPacket: имена и ссылки
на строковую таблицу, ID, цвета, климат, теги и полнота 2193. В JSON-отчёте
появляется отдельный пункт `vanilla-biome-registration`. Проверка включена
также в одновременный тест 975/1001/2193. До/после используется тот же протокол;
проверка не подменяет версию или данные палитры блоков.

Неправильная регистрация исправлена и проверяется на пакетах. Исчезновение
именно пользовательской ошибки Block требует повторного входа официальным
клиентом либо сравнения с его успешной выгрузкой. Автоматический offline E2E
с включённым шифрованием не заменяет такую проверку.

## Источники

- Pinned Cloudburst dump: `resources/modern/2193.sources.json`, вход
  `stripped_biome_definitions.json`, commit a8a4341d7763d6eb8547cff3ca46b4153d60163d.
- Cloudburst protocol commit 989332f6d4c2a93cf41212c9ba1b5dd14b00f693:
  `bedrock-codec/src/main/java/org/cloudburstmc/protocol/bedrock/codec/v827/serializer/BiomeDefinitionListSerializer_v827.java`.
- Mojang protocol 2193 index: https://mojang.github.io/bedrock-protocol-docs/1.26.51/
- Same-protocol Login description: https://mojang.github.io/bedrock-protocol-docs/1.26.50/packets/login-packet/
- A similar client error reported elsewhere is corroboration, not proof of this cause:
  https://github.com/GeyserMC/Geyser/issues/6673
