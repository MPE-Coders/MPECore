# Исправление missingStructureData при входе 26.50/26.51

Симптом: онлайн-идентичность проверена, сеанс проходит resource packs и joining,
переходит в spawning, после чего официальный клиент закрывает соединение с
сообщением «Отсутствуют данные строения с сервера».
Это ключ `disconnectionScreen.missingStructureData` в локализации Mojang.

## Причина и исправление

В прежней `SpawnSequence` первым пакетом был StartGame. Не отправлялись
JigsawStructureDataPacket (313) и VoxelShapesPacket (337). Клиенту нужны эти
реестры при построении мира, а не после StartGame вместе с чанками.

Теперь `WorldStartData` формирует последовательность:

```
JigsawStructureDataPacket
VoxelShapesPacket
StartGamePacket
метаданные игрока / предметы / сущности / биомы / способности
чанки / PLAYER_SPAWN / подтверждение инициализации от клиента
```

Jigsaw включён для протоколов >=712, voxel shapes — >=924; старым клиентам
новые пакеты не посылаются. Кодек native обрабатывает customShapeCount с 944.
Для 2193 оба пакета разрешены в явном списке преобразователя и проверяются
реальными PHP/Prismarine-кодеками. Палитры, форматы карт и авторизация не меняются.

Текущий мир плоский, без правил структур и пользовательских voxel shapes:
передаётся NBT compound с четырьмя **типизированными пустыми списками**
`processors`, `template_pools`, `jigsaws`, `structure_sets`, а также пустые
массивы shapes/name_map с custom_shape_count=0. Это корректное объявление
отсутствия пользовательских правил, НЕ генерация ванильных деревень/структур.
Когда появятся behavior packs/структуры, данные этого слоя нужно расширить.

## Обновление

Остановите сервер, обновите `feat/bedrock-26.51` и запустите обычный `./start.sh`.
Для диагностики одной версии: `./start.sh --playtest --version 1.26.51`.
Не удаляйте мир, vendor, палитры или кэш авторизации. Настройки мультиверсии
и профили 975/1001/2193 сохраняются. Патч не выключает проверку онлайн-входа.

Сообщение `Server full or duplicate identity` — другой отказ: один аккаунт
уже мог находиться на сервере. Проверяйте второй клиент отдельным аккаунтом
либо сначала выйдите с первого устройства. Защита от дубликатов не изменена.

## Новые проверки

Тестовый клиент теперь проверяет не только наличие StartGame и чанка, но и
**фактически полученные до StartGame** обязательные реестры. Он записывает
`world-start-data-before-start-game` с индексами пакетов в JSON-отчёт. Эта же
проверка включена для всех трёх одновременных клиентов в multiversion E2E.

`--doctor` проверяет порядок и содержимое реестров и записывает тестовые
пакеты (без токенов входа) в `data/world-start-codec.json`. Независимая проверка:

```bash
./start.sh --doctor
NODE="$(python3 tools/node-runtime.py --no-download)"
"$NODE" tools/world-start-doctor.cjs
./tools/e2e.sh --version 1.26.51 --scenario creative
./tools/e2e.sh --multi
```

Для профиля 2193 CI запускает этот декодер на настоящих PHP-generated байтах.
Он также проверяет удаление обязательного пакета, неправильный порядок,
усечение и лишние байты. Одного успешного NBT parse недостаточно: библиотека
может допустить отсутствие конечного TAG_End, поэтому проверяется точное
обратное кодирование исходного буфера.

Локальная отрицательная проверка: новый клиент против неизменённой серверной
SpawnSequence коммита 8d65b700 завершился кодом 1 с
`StartGame received before jigsaw_structure_data (missingStructureData)`.
После патча локально прошли Creative E2E 975/1001/2193 и одновременная
мультиверсия. Rust-код не менялся; локально использован бинарник предыдущего
CI. Вход официальным клиентом после патча требует повторной ручной проверки;
headless E2E с offline-идентичностью и шифрованием её не заменяет.

## Первичные источники

- Mojang localization: https://github.com/Mojang/bedrock-samples/blob/main/resource_pack/texts/ru_RU.lang
- Mojang packet 313: https://mojang.github.io/bedrock-protocol-docs/1.26.50/packets/jigsaw-structure-data-packet/
- Mojang packet 337 (before StartGame): https://mojang.github.io/bedrock-protocol-docs/1.26.50/packets/voxel-shapes-packet/
- Independent reference ordering/empty registries, Pumpkin commit 003d3c49eaf1ca21207671be55a91587bc1330b5:
  `crates/pumpkin/src/world/mod.rs` and `crates/pumpkin-protocol/src/bedrock/client/{jigsaw_structure_data,voxel_shapes}.rs`.
- Version gates: CloudburstMC/Protocol commit 989332f6d4c2a93cf41212c9ba1b5dd14b00f693,
  `Bedrock_v712`, `Bedrock_v924`, `VoxelShapesSerializer_v944`.
