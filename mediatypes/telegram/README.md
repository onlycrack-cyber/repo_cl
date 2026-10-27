# Telegram — Media Type (Webhook) для Zabbix

Один общий Media Type для всех пользователей: chat_id берётся из поля **Send to**
медиа пользователя через макрос `{ALERT.SENDTO}`.

## Установка

1. *Alerts → Media types → Create media type*, Type: **Webhook**, Name: `Telegram`.
2. Параметры:

| Name            | Value              | Обязателен |
|-----------------|--------------------|------------|
| `Token`         | *(пусто — вставьте токен бота)* | да |
| `ChatID`        | `{ALERT.SENDTO}`   | да |
| `Subject`       | `{ALERT.SUBJECT}`  | да |
| `Message`       | `{ALERT.MESSAGE}`  | да |
| `HTTPProxy`     | *(пусто)*          | нет |
| `MessageIsHTML` | `false`            | нет |

3. Script — содержимое [`telegram_webhook.js`](telegram_webhook.js).
4. У пользователя: *Users → Media → Add*, Type `Telegram`, **Send to** — chat_id
   (личный — число, группа/канал — `-100…`). Бот должен быть добавлен в группу/канал,
   а в личном чате пользователь должен нажать `/start`.

## Отладка

Строки лога имеют префикс `[ Telegram Webhook ]`, уровень 4 (Debug); ошибки — уровень 3.
Временно поднять уровень: `zabbix_server -R log_level_increase="alert manager"`.
