# Unreleased — StartGame preflight и полный source

- Инициализация свежего decode-target для telemetry без правок vendor.
- Проверки непустых telemetry-полей, повторного кодирования и усечения StartGame.
- Манифест обязательных исходников и ./start.sh --check-source.
- Отдельный workflow установленных кодеков 671/766/975/1001.
- Восстановлены инструкции и зафиксирован план нативного Rust gateway.

Это исправление существующей альфы, не подтверждённый игровой релиз.

# 0.4.0-alpha — исправления границ совместимости

Изменены исходники 0.3.0; не добавлен заранее проверенный бинарник.

- Pin/accepted-protocol guard, явные пять data assets и запрет неподтверждённого modern fallback.
- Проверка metadata и item dictionaries, fingerprint lock после успешной загрузки.
- Независимый network-NBT audit, сравнение с PHP runtime map перед открытием UDP.
- Read-only исследование исторических Git-коммитов без запуска старого PHP.
- Разные IPC/пакеты для prediction correction и teleport; ground flag.
- Безопасная диагностика PacketViolationWarning.
- Полная проверка хвоста чанка клиентом; блокировка автоматической подмены его NBT для modern.
- Единый verification report с passed/failed/not_run и подготовленный CI.

Игровые механики предыдущей альфы сохранены в исходниках. Нет подтверждённого официального
входа, новых vanilla-механик или фактической поддержки 31/всех старых протоколов.
