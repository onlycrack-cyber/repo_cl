#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#
# Telegram alert script for Zabbix ("Script" media type) that also delivers reports of Custom Problem Reports:
# the message, and the report file (HTML / PDF / CSV) as a document when the module passes one.
#
# Media type: Type "Script", Script name "cr_telegram.py", parameters:
#   {ALERT.SENDTO}       chat ID (or @channel) - "Send to" of the user media / additional recipient of a schedule
#   {ALERT.SUBJECT}
#   {ALERT.MESSAGE}
#   <bot token>          optional; otherwise TELEGRAM_BOT_TOKEN or the file /etc/zabbix/cr_telegram.conf
#                        ("token=123456:ABC..."), readable by the users that run the script
#
# The module passes the report file as CR_REPORT_FILE / CR_REPORT_FILENAME / CR_REPORT_MIME and sets CR_REPORT=1.
# With 'script_message_format' => 'html' in conf/config.php the message uses Telegram HTML (<b>, copyable <pre>
# tables); plain text is sent as is. Ordinary Zabbix alerts work too (subject + message).
#
# Optional environment: TELEGRAM_API_URL (default https://api.telegram.org), HTTPS_PROXY.
# Logs go to stderr only (Zabbix and the module show them on errors), so no log file permissions are needed.
# Python 3 standard library only.

import json
import mimetypes
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

CONFIG = "/etc/zabbix/cr_telegram.conf"
MAX_MESSAGE = 4096
TIMEOUT = 30


def token_from(args):
    if len(args) > 3 and args[3].strip():
        return args[3].strip()
    if os.environ.get("TELEGRAM_BOT_TOKEN"):
        return os.environ["TELEGRAM_BOT_TOKEN"].strip()
    try:
        with open(CONFIG, encoding="utf-8") as f:
            for line in f:
                key, _, value = line.partition("=")
                if key.strip() == "token" and value.strip():
                    return value.strip()
    except OSError:
        pass
    raise SystemExit("no bot token: 4th parameter of the media type, TELEGRAM_BOT_TOKEN or %s" % CONFIG)


def is_html(text):
    """The module's HTML summary: Telegram tags and escaped entities, no other markup."""
    return bool(re.search(r"</?(b|i|pre|code)>", text))


def api(token, method, fields, document=None):
    url = "%s/bot%s/%s" % (os.environ.get("TELEGRAM_API_URL", "https://api.telegram.org").rstrip("/"), token, method)

    if document is None:
        body = urllib.parse.urlencode(fields).encode("utf-8")
        headers = {"Content-Type": "application/x-www-form-urlencoded"}
    else:
        boundary = uuid.uuid4().hex
        parts = []
        for name, value in fields.items():
            parts.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n"
                          % (boundary, name, value)).encode("utf-8"))
        name, mime, content = document
        parts.append(("--%s\r\nContent-Disposition: form-data; name=\"document\"; filename=\"%s\"\r\n"
                      "Content-Type: %s\r\n\r\n" % (boundary, name.replace('"', ""), mime)).encode("utf-8"))
        parts.append(content)
        parts.append(("\r\n--%s--\r\n" % boundary).encode("utf-8"))
        body = b"".join(parts)
        headers = {"Content-Type": "multipart/form-data; boundary=" + boundary}

    request = urllib.request.Request(url, data=body, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            result = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            result = json.loads(e.read().decode("utf-8"))
        except ValueError:
            raise SystemExit("Telegram %s: HTTP %d" % (method, e.code))
    except (urllib.error.URLError, OSError) as e:
        raise SystemExit("Telegram %s: %s" % (method, e))

    if not result.get("ok"):
        raise SystemExit("Telegram %s: %s" % (method, result.get("description", result)))


def main(args):
    if len(args) < 3:
        raise SystemExit("usage: cr_telegram.py <chat id> <subject> <message> [bot token]")

    chat, subject, message = args[0].strip(), args[1], args[2]
    token = token_from(args)
    report = os.environ.get("CR_REPORT") == "1"

    # A report of the module already has its title in the message; an ordinary alert gets the subject first.
    text = message if report or not subject else subject + "\n" + message
    fields = {"chat_id": chat, "text": text[:MAX_MESSAGE], "disable_web_page_preview": "true"}
    if is_html(text):
        fields["parse_mode"] = "HTML"

    api(token, "sendMessage", fields)

    path = os.environ.get("CR_REPORT_FILE", "")
    if path:
        with open(path, "rb") as f:
            content = f.read()
        name = os.environ.get("CR_REPORT_FILENAME") or os.path.basename(path)
        mime = os.environ.get("CR_REPORT_MIME") or mimetypes.guess_type(name)[0] or "application/octet-stream"
        api(token, "sendDocument", {"chat_id": chat, "caption": subject[:1024]}, (name, mime, content))


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except SystemExit as e:
        if e.code not in (None, 0):
            sys.stderr.write("%s\n" % e.code)
            sys.exit(1)
