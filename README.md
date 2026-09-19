# No-Code Engine

Универсальное хранилище произвольных списков на JSON поверх реляционной СУБД (SQLite/MySQL).  
PHP 8.0+ / Zero Dependencies.

## Возможности

- **Типы контента** — создание схем данных с полями (string, number, boolean, enum) и валидацией
- **Списки** — экземпляры типов контента с элементами
- **Сущности** — CRUD операции над элементами списков
- **Связи между сущностями** — многие-ко-многим через `entity_links`
- **JSON-индексы** — индексация полей JSON для ускорения поиска
- **Встроенная аутентификация** — регистрация, вход, CSRF-защита
- **Двойной интерфейс** — HTML UI и JSON API
- **Встроенные тесты** — CI-режим для проверки функциональности

## Структура проекта

```
/workspace
├── index.php          # Основной файл движка (все классы и логика)
├── README.md          # Документация
└── views/             # PHTML-шаблоны
    ├── _layout.phtml         # Базовый макет
    ├── home.phtml            # Главная страница
    ├── error.phtml           # Страница ошибки
    ├── login.phtml           # Вход
    ├── register.phtml        # Регистрация
    ├── content_type_list.phtml   # Список типов контента
    ├── content_type_edit.phtml   # Редактирование типа контента
    ├── list_index.phtml          # Список списков
    ├── list_edit.phtml           # Редактирование списка
    ├── entity_list.phtml         # Список элементов
    ├── entity_item_edit.phtml    # Редактирование элемента
    └── lookup.phtml              # Поиск связей
```

## База данных

Движок автоматически создаёт следующие таблицы при первом запуске:

| Таблица | Описание |
|---------|----------|
| `users` | Пользователи (email, password_hash) |
| `content_types` | Типы контента (name, schema_json) |
| `entity_lists` | Списки сущностей, привязанные к типам контента |
| `entity_items` | Элементы списков (data_json хранит данные полей) |
| `entity_links` | Связи между элементами (from_item_id → to_item_id) |
| `entity_indexes` | Метаданные JSON-индексов |

Поддерживаемые СУБД: **SQLite** (по умолчанию) и **MySQL**.

## Быстрый старт

### Запуск встроенного сервера

```bash
php -S localhost:8000 index.php
```

Откройте в браузере:
- HTML: http://localhost:8000/?action=home
- JSON API: http://localhost:8000/?action=home&format=json

### Переменные окружения

| Переменная | Описание | По умолчанию |
|------------|----------|--------------|
| `APP_ENV` | Окружение (`dev` или `prod`) | `dev` |
| `DB_DSN` | DSN базы данных | `./nocode.db` (SQLite) |

Пример для MySQL:
```bash
export DB_DSN="mysql:host=localhost;dbname=nocode;charset=utf8mb4"
export APP_ENV=prod
php -S localhost:8000 index.php
```

## Действия (Actions)

| Action | Описание | Метод |
|--------|----------|-------|
| `home` | Главная страница | GET |
| `register` | Регистрация пользователя | GET/POST |
| `login` | Вход | GET/POST |
| `logout` | Выход | POST |
| `content_type_list` | Список типов контента | GET |
| `content_type_edit` | Создание/редактирование типа | GET/POST |
| `content_type_delete` | Удаление типа | POST |
| `list_index` | Список списков | GET |
| `list_edit` | Создание/редактирование списка | GET/POST |
| `list_delete` | Удаление списка | POST |
| `entity_list` | Просмотр элементов списка | GET |
| `entity_item_edit` | Создание/редактирование элемента | GET/POST |
| `delete_entity_item` | Удаление элемента | POST |
| `create_field_index` | Создание индекса по полю | POST |
| `entity_link_save` | Сохранение связи | POST |
| `entity_lookup` | Поиск связей | GET |

## JSON API

Любой action поддерживает режим JSON через параметр `?format=json`:

```bash
curl "http://localhost:8000/?action=home&format=json"
```

Ответ:
```json
{
  "contentTypes": [...],
  "lists": [...],
  "indexes": [...]
}
```

## Схема типа контента

При создании типа контента задаётся JSON-схема полей:

```json
{
  "title": { "type": "string", "required": true, "min_length": 1, "max_length": 200 },
  "amount": { "type": "number", "required": false, "min": 0, "max": 1000000 },
  "status": { "type": "enum", "enum": ["draft", "published", "archived"] },
  "active": { "type": "boolean" }
}
```

## Тестирование

### CLI режим

```bash
php index.php --run-ci
```

### Web режим (только dev)

```
http://localhost:8000/?run_ci=1
```

## Архитектура

Движок использует паттерн **ADR (Action-Domain-Responder)**:

- **Action** — маршрутизация по параметру `action`
- **Domain** — бизнес-логика в классах-расширениях `BaseAdrSlice`
- **Responder** — формирование ответа (HTML или JSON)

Классы:
- `Db` — подключение к БД и миграции
- `Engine` — рендеринг view-шаблонов
- `Layout` — обёртка страниц в макет
- `Json` — JSON-ответы
- `Csrf` — CSRF-токены
- `Auth` — аутентификация
- `NoCode` — валидация схем, работа с JSON-полями

## Лицензия

Zero Dependencies / Public Domain

## Версия

**1.7.0**