# Что именно тестируется

| Команда | Проверяет | Не доказывает |
|---|---|---|
| `./start.sh --check-source` | Наличие каждого файла из resources/source-files.json | Правильность его содержимого, сборку или вход |
| `./start.sh --unit` | Автономные PHP/JS/Python тесты, plugin-процессы, новый регрессионный тест фабрики | Настоящий Bedrock login |
| `./start.sh --doctor` | Установленные NetherGames/RakLib API, кодеки и независимую сверку NBT | RakNet-сессию и игру |
| `./start.sh --test` | Дополнительно Rust unit/build и настоящий IPC/storage | Вход официального Minecraft |
| `./tools/e2e.sh --version 1.26.20 --scenario creative` | Реальный сервер и отдельный headless-клиент во временном мире | Рендеринг и управление официального клиента |

`tests/packet_factory_unit.php` явно использует test doubles. Он воспроизводит PHP-ошибку неинициализированного поля по ссылке и проверяет фабрику. `tests/integration.php` использует реальные зависимости: проверяет непустую telemetry, обратное кодирование StartGame и отклонение обрезанного пакета. Эти два уровня нельзя смешивать.

Workflow source.yml сохраняет конкретный source archive, Git SHA, доступный Rust binary и фактические логи. Workflow preflight.yml отдельно запускает установленные кодеки. Зелёный unit job не заменяет preflight; зелёный preflight не заменяет официальный плейтест.

Фактические ограничения текущей проверки: [../TEST_REPORT.md](../TEST_REPORT.md). Ручной сценарий: [OFFICIAL_CLIENT_TESTING.md](OFFICIAL_CLIENT_TESTING.md). Сетевые тесты проводи на временной карте; не публикуй кэши авторизации.
