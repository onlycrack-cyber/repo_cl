/*
 * Zabbix Media Type (Webhook): уведомления в Telegram
 * ----------------------------------------------------
 * Движок: Duktape (ES5) — только var, function, без let/const/стрелочных функций.
 *
 * Один общий Media Type для всех пользователей: получатель (chat_id) берётся
 * из макроса {ALERT.SENDTO}, т.е. из поля "Send to" медиа конкретного пользователя.
 *
 * Параметры (вкладка Media type -> Parameters):
 *   Token   — токен бота (вставляется вручную в интерфейсе Zabbix)
 *   ChatID  — {ALERT.SENDTO}
 *   Subject — {ALERT.SUBJECT}
 *   Message — {ALERT.MESSAGE}
 *   HTTPProxy        — (необязательный) прокси, например http://proxy.local:3128
 *   MessageIsHTML    — (необязательный) "true", если в шаблоне сообщения сознательно
 *                      используются HTML-теги Telegram (<b>, <i>, <code>, ...).
 *                      По умолчанию "false": Subject и Message экранируются.
 */

// Максимальная длина текста сообщения в Telegram Bot API
var TELEGRAM_MAX_LENGTH = 4096;
// Префикс для всех строк лога, чтобы их легко было найти grep'ом в zabbix_server.log
var LOG_PREFIX = '[ Telegram Webhook ] ';

/**
 * Запись в лог Zabbix (уровень 4 = Debug).
 * Видно при DebugLevel=4 или после "zabbix_server -R log_level_increase=alert manager".
 */
function log(msg) {
    Zabbix.log(4, LOG_PREFIX + msg);
}

/**
 * Маскирует токен для логов: оставляет ID бота (часть до ':')
 * и первые символы секрета, остальное заменяет звёздочками.
 */
function maskToken(token) {
    var str = String(token);
    var colon = str.indexOf(':');

    if (colon === -1) {
        return str.length > 4 ? str.substring(0, 4) + '***' : '***';
    }
    return str.substring(0, colon + 1) + str.substring(colon + 1, colon + 4) + '***';
}

/**
 * Экранирует спецсимволы HTML. Без этого Telegram отклонит сообщение с ошибкой
 * "Bad Request: can't parse entities", если в имени триггера есть, например,
 * "CPU load > 90%" или "a & b".
 */
function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Проверяет, что обязательный параметр передан, не пустой и не является
 * неразрешённым макросом (например, "{ALERT.SENDTO}", если у пользователя
 * не заполнено поле "Send to").
 */
function requireParam(params, name) {
    var val = params[name];

    if (typeof val === 'undefined' || val === null) {
        throw 'Missing required parameter: ' + name;
    }

    val = String(val).replace(/^\s+|\s+$/g, '');

    if (val === '') {
        throw 'Required parameter is empty: ' + name;
    }
    if (/^\{[A-Z0-9_.]+\}$/.test(val)) {
        throw 'Parameter ' + name + ' contains unresolved macro: ' + val;
    }
    return val;
}

/**
 * Собирает итоговый текст: тема жирным шрифтом, разделитель, тело сообщения.
 * Если текст длиннее лимита Telegram — обрезает тело (тему не трогает).
 */
function buildText(subject, message, messageIsHtml) {
    var head = '<b>' + escapeHtml(subject) + '</b>';
    var separator = '\n━━━━━━━━━━━━━━━\n';
    var body = messageIsHtml ? message : escapeHtml(message);
    var suffix = '\n…';
    var limit = TELEGRAM_MAX_LENGTH - head.length - separator.length;

    if (body.length > limit) {
        // Обрезка может разорвать HTML-тег при MessageIsHTML=true;
        // для экранированного текста обрезаем, не разрывая сущность &xx;
        body = body.substring(0, limit - suffix.length);
        if (!messageIsHtml) {
            body = body.replace(/&[a-z]*$/, '');
        }
        body += suffix;
    }

    return head + separator + body;
}

try {
    // 1. Разбор входных параметров. Zabbix передаёт их JSON-строкой в переменной value.
    var params = JSON.parse(value);

    // 2. Валидация обязательных полей (при отсутствии — throw).
    var token = requireParam(params, 'Token');
    var chatId = requireParam(params, 'ChatID');   // значение {ALERT.SENDTO}
    var subject = requireParam(params, 'Subject');
    var message = requireParam(params, 'Message');

    // Необязательные параметры
    var proxy = params.HTTPProxy ? String(params.HTTPProxy).replace(/^\s+|\s+$/g, '') : '';
    var messageIsHtml = String(params.MessageIsHTML).toLowerCase() === 'true';

    // Логируем входящие параметры без полного токена
    log('Incoming params: Token=' + maskToken(token) +
        ', ChatID=' + chatId +
        ', Subject=' + subject +
        ', Message length=' + message.length +
        ', HTTPProxy=' + (proxy || '-') +
        ', MessageIsHTML=' + messageIsHtml);

    // 3. Формирование тела запроса к Bot API
    var payload = {
        chat_id: chatId,
        text: buildText(subject, message, messageIsHtml),
        parse_mode: 'HTML',
        disable_web_page_preview: true
    };
    var body = JSON.stringify(payload);

    // URL с токеном в лог не пишем
    var url = 'https://api.telegram.org/bot' + token + '/sendMessage';

    log('Request URL: https://api.telegram.org/bot' + maskToken(token) + '/sendMessage');
    log('Request body: ' + body);

    // 4. Отправка запроса встроенным объектом Zabbix
    var request = new HttpRequest();
    request.addHeader('Content-Type: application/json');

    if (proxy !== '') {
        request.setProxy(proxy);
    }

    var response = request.post(url, body);
    var status = request.getStatus();

    log('Response HTTP status: ' + status);
    log('Response body: ' + response);

    // 5. Обработка ответа
    if (status !== 200) {
        var description = 'Unknown error';

        // Telegram при ошибке возвращает JSON вида:
        // {"ok":false,"error_code":400,"description":"Bad Request: chat not found"}
        try {
            var data = JSON.parse(response);
            if (data && data.description) {
                description = data.description;
            }
            // 429 Too Many Requests: Telegram сообщает, через сколько секунд повторить
            if (data && data.parameters && data.parameters.retry_after) {
                description += ' (retry after ' + data.parameters.retry_after + ' s)';
            }
        }
        catch (parseError) {
            // Ответ не JSON (например, HTML-страница прокси) — берём как есть
            if (response) {
                description = String(response).substring(0, 500);
            }
        }

        throw 'Telegram API returned HTTP ' + status + ': ' + description;
    }

    // 6. Успех — Zabbix пометит оповещение как отправленное
    log('Message sent successfully to chat ' + chatId);
    return 'OK';
}
catch (error) {
    // Ошибку пишем в лог на уровне 3 (Warning), чтобы она была видна без отладки,
    // и пробрасываем дальше — Zabbix покажет её в Reports -> Action log
    // и выполнит повторные попытки согласно настройкам Media type (Attempts).
    Zabbix.log(3, LOG_PREFIX + 'Sending failed: ' + error);
    throw 'Sending failed: ' + error;
}
