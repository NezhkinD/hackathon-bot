# Telegram Chat Export Parser Bot

Telegram-бот для извлечения списка участников из экспорта чата. Пользователи загружают файлы экспорта чата (JSON/HTML), бот парсит их и возвращает список уникальных участников.

## Возможности

- Парсинг экспортов чатов Telegram в форматах JSON и HTML
- Извлечение авторов сообщений и упомянутых пользователей
- Исключение удалённых аккаунтов и дубликатов
- Вывод результата:
  - Текстовым сообщением (до 50 пользователей)
  - Excel-файлом (51+ пользователей)
- Rate limiting для защиты от злоупотреблений
- Без хранения данных — файлы обрабатываются в памяти и удаляются сразу после обработки

## Архитектура

### Технологии

- **PHP 8.4** (FPM Alpine)
- **Symfony 7.4** (минимальный скелет)
- **Docker** для контейнеризации

### Структура проекта

```
.
├── app/                          # Symfony-приложение
│   ├── src/
│   │   ├── Command/              # Консольные команды
│   │   │   └── BotPollingCommand.php   # Long polling для бота
│   │   ├── DTO/                  # Data Transfer Objects
│   │   │   ├── Participant.php   # Модель участника
│   │   │   └── ProcessingResult.php    # Результат обработки
│   │   ├── Exception/            # Кастомные исключения
│   │   ├── Service/
│   │   │   ├── Parser/           # Парсеры экспортов
│   │   │   │   ├── ParserInterface.php
│   │   │   │   ├── ParserFactory.php
│   │   │   │   ├── JsonExportParser.php
│   │   │   │   └── HtmlExportParser.php
│   │   │   ├── Export/           # Форматирование вывода
│   │   │   │   ├── TextFormatter.php
│   │   │   │   └── ExcelExporter.php
│   │   │   ├── ChatExportProcessor.php  # Основная логика обработки
│   │   │   ├── RateLimiter.php   # Ограничение запросов
│   │   │   └── Telegram/
│   │   │       └── BotService.php
│   │   └── Telegram/Command/     # Команды бота
│   │       ├── StartCommand.php
│   │       ├── HelpCommand.php
│   │       └── GenericmessageCommand.php
│   ├── config/                   # Конфигурация Symfony
│   ├── public/                   # Веб-корень
│   └── .env                      # Переменные окружения
├── .docker/
│   ├── Dockerfile.php            # PHP-FPM контейнер
│   └── configs/                  # php.ini, xdebug.ini
└── docker-compose.yaml           # Оркестрация контейнеров
```

### Ключевые зависимости

| Пакет | Назначение |
|-------|------------|
| `longman/telegram-bot` | Telegram Bot API |
| `phpoffice/phpspreadsheet` | Генерация Excel-файлов |
| `symfony/dom-crawler` | Парсинг HTML |
| `symfony/css-selector` | CSS-селекторы для DOM |

## Быстрый старт

### Требования

- Docker и Docker Compose
- Telegram Bot Token (получить у [@BotFather](https://t.me/BotFather))

### Установка

1. **Клонируйте репозиторий:**
   ```bash
   git clone <repository-url>
   cd hackathon-bot
   ```

2. **Создайте файл окружения:**
   ```bash
   cp app/.env.example app/.env
   ```

3. **Настройте переменные окружения в `app/.env`:**
   ```env
   BOT_TOKEN=your_telegram_bot_token
   BOT_USERNAME=your_bot_username
   APP_SECRET=your_random_secret_string
   ```

4. **Запустите контейнеры:**
   ```bash
   docker compose up -d --build
   ```

5. **Установите зависимости:**
   ```bash
   docker compose exec bot composer install
   ```

Бот запустится в режиме long polling и будет готов принимать файлы.

## Использование

1. Экспортируйте чат из Telegram Desktop:
   - Откройте чат → `⋮` → "Экспорт истории чата"
   - Выберите формат JSON или HTML

2. Отправьте экспортированный файл боту

3. Получите список участников:
   - Текстом — если участников меньше 51
   - Excel-файлом — если участников 51 и более

### Структура Excel-файла

При экспорте в Excel создаются вкладки:
- **Участники** — авторы сообщений
- **Упоминания** — упомянутые пользователи
- **Каналы** — упомянутые каналы

Колонки:
- Дата экспорта
- Username (@username)
- Имя и фамилия
- Описание (если доступно)
- Дата регистрации (если доступно)
- Наличие канала в профиле

## Команды разработки

```bash
# Просмотр логов
docker compose logs -f bot

# Вход в контейнер
docker compose exec bot sh

# Пересборка после изменений Dockerfile
docker compose up -d --build

# Очистка кэша Symfony
docker compose exec bot php bin/console cache:clear

# Запуск тестов
docker compose exec bot composer test
```

## Конфигурация

### Rate Limiting

В `app/.env`:
```env
RATE_LIMIT_FILES=5      # Максимум файлов
RATE_LIMIT_WINDOW=30    # За период (секунды)
```

### Часовой пояс

По умолчанию: `Europe/Moscow`. Настраивается в `Dockerfile.php`.