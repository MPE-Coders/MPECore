# Проверки исправления StartGame и комплектности исходников

Дата: 2026-09-26. Это не подтверждение игрового релиза.

## Наблюдение пользователя

`cargo build --release --locked` завершился успешно. `./start.sh --unit` завершился без ошибок. `./start.sh --playtest --version 1.26.20` остановился до UDP в `tests/integration.php`: `StartGamePacket::$serverTelemetryData` передан по ссылке без инициализации.

## Выполнено при подготовке патча

- 7 тестов `php tests/packet_factory_unit.php`: воспроизведение ошибки языка PHP, нейтральная инициализация, замена значением при decode, независимость объектов, отсутствие изменений других packet-классов, запрет непакетных классов и сохранение inbound allow-list.
- 6 тестов `python3 -m unittest discover -s tests -p source_completeness_test.py -v`: комплектный/неполный набор файлов, каталог вместо файла, неверный manifest, обход пути и внешняя ссылка.
- Проверка синтаксиса изменённых PHP/Python/Bash файлов.

`packet_factory_unit.php` использует явно обозначенные test doubles и проверяет PHP-правило и фабрику. Это НЕ тест настоящего Bedrock-кодека.

## Проверяется установленными зависимостями

`tests/integration.php` по-прежнему использует реальные NetherGames/RakLib и ext-encoding. Добавлены непустые поля telemetry, сверка прочитанных значений с учётом версии, повторное кодирование StartGame в те же байты и отклонение обрезанного пакета. Проверка не пропускается при обычном запуске. Vendor-файлы не меняются.

В этой среде нет установленных сетевых зависимостей и Rust toolchain; installed-codec roundtrip, новый Rust build, сетевой E2E и официальный клиент здесь не выполнялись. Результаты GitHub Actions нужно читать у конкретного commit/run, а не выводить из наличия workflow.

## Команды

```bash
./start.sh --check-source
./start.sh --unit
./start.sh --playtest --version 1.26.20
```

Workflow `source.yml` выполняет standalone-тесты, реальную Rust-сборку/IPC и сохраняет исходники с логами. `preflight.yml` отдельно проверяет реальные кодеки профилей 671, 766, 975, 1001. Даже успешный preflight не означает успешный вход официального Minecraft.
