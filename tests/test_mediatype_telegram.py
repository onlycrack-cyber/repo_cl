"""Checks of the Telegram webhook media type (mediatypes/telegram).

The script embedded in the YAML export must match telegram_webhook.js, and the
script itself is run through node with HttpRequest/Zabbix stubs when available.
"""
import json
import os
import shutil
import subprocess
import unittest

import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
MEDIATYPE_DIR = os.path.join(ROOT, 'mediatypes', 'telegram')
SCRIPT_FILE = os.path.join(MEDIATYPE_DIR, 'telegram_webhook.js')
EXPORT_FILE = os.path.join(MEDIATYPE_DIR, 'zbx_mediatype_telegram.yaml')

# Runs the webhook like Zabbix does (script body wrapped in a function) and
# prints {"result"|"error", "request", "logs"} as JSON.
NODE_RUNNER = r"""
var fs = require('fs');
var src = fs.readFileSync(process.argv[1], 'utf8');
var status = parseInt(process.argv[3], 10);
var response = process.argv[4];
var out = {logs: [], request: null};
var Zabbix = {log: function (level, msg) { out.logs.push([level, msg]); }};
function HttpRequest() {
    this.addHeader = function () {};
    this.setProxy = function (p) { out.proxy = p; };
    this.post = function (url, body) { out.request = {url: url, body: JSON.parse(body)}; return response; };
    this.getStatus = function () { return status; };
}
try {
    out.result = new Function('value', 'Zabbix', 'HttpRequest', src)(process.argv[2], Zabbix, HttpRequest);
} catch (e) {
    out.error = String(e);
}
console.log(JSON.stringify(out));
"""

PARAMS = {
    'Token': '123456:SECRETtokenVALUE',
    'ChatID': '-1001234567890',
    'Subject': 'Problem: CPU > 90%',
    'Message': 'Host: a & b <srv>',
    'HTTPProxy': '',
    'MessageIsHTML': 'false',
}


def load_mediatype():
    with open(EXPORT_FILE, encoding='utf-8') as fh:
        export = yaml.safe_load(fh)['zabbix_export']
    return export['media_types'][0]


class ExportTest(unittest.TestCase):
    def test_script_in_sync(self):
        with open(SCRIPT_FILE, encoding='utf-8') as fh:
            self.assertEqual(load_mediatype()['script'], fh.read(),
                             'zbx_mediatype_telegram.yaml script differs from telegram_webhook.js')

    def test_parameters(self):
        mt = load_mediatype()
        self.assertEqual(mt['type'], 'WEBHOOK')
        params = {p['name']: p['value'] for p in mt['parameters']}
        self.assertEqual(params['ChatID'], '{ALERT.SENDTO}')
        self.assertEqual(params['Subject'], '{ALERT.SUBJECT}')
        self.assertEqual(params['Message'], '{ALERT.MESSAGE}')
        self.assertEqual(params['Token'], '')

    def test_message_templates_unique(self):
        keys = [(t['event_source'], t['operation_mode']) for t in load_mediatype()['message_templates']]
        self.assertEqual(len(keys), len(set(keys)))
        self.assertIn(('TRIGGERS', 'PROBLEM'), keys)
        self.assertIn(('TRIGGERS', 'RECOVERY'), keys)


@unittest.skipUnless(shutil.which('node'), 'node not installed')
class ScriptTest(unittest.TestCase):
    def run_script(self, params, status=200, response='{"ok":true}'):
        proc = subprocess.run(
            ['node', '-e', NODE_RUNNER, SCRIPT_FILE, json.dumps(params), str(status), response],
            check=True, stdout=subprocess.PIPE, universal_newlines=True)
        return json.loads(proc.stdout)

    def test_success(self):
        out = self.run_script(PARAMS)
        self.assertEqual(out.get('result'), 'OK', out)
        self.assertEqual(out['request']['url'],
                         'https://api.telegram.org/bot123456:SECRETtokenVALUE/sendMessage')
        body = out['request']['body']
        self.assertEqual(body['chat_id'], '-1001234567890')
        self.assertEqual(body['parse_mode'], 'HTML')
        self.assertTrue(body['disable_web_page_preview'])
        self.assertTrue(body['text'].startswith('<b>Problem: CPU &gt; 90%</b>'))
        self.assertIn('Host: a &amp; b &lt;srv&gt;', body['text'])

    def test_token_not_logged(self):
        out = self.run_script(PARAMS)
        for _, msg in out['logs']:
            self.assertNotIn('SECRETtokenVALUE', msg)

    def test_api_error(self):
        out = self.run_script(PARAMS, 400, '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}')
        self.assertIn('HTTP 400: Bad Request: chat not found', out.get('error', ''))
        self.assertIn(3, [level for level, _ in out['logs']])

    def test_missing_and_unresolved(self):
        for name in ('Token', 'ChatID', 'Subject', 'Message'):
            params = dict(PARAMS)
            del params[name]
            self.assertIn('Missing required parameter: ' + name, self.run_script(params).get('error', ''))
        params = dict(PARAMS, ChatID='{ALERT.SENDTO}')
        self.assertIn('unresolved macro', self.run_script(params).get('error', ''))

    def test_long_message_truncated(self):
        out = self.run_script(dict(PARAMS, Message='&' * 5000))
        self.assertLessEqual(len(out['request']['body']['text']), 4096)

    def test_html_passthrough_and_proxy(self):
        out = self.run_script(dict(PARAMS, Message='<code>x</code>', MessageIsHTML='true',
                                   HTTPProxy='http://proxy:3128'))
        self.assertIn('<code>x</code>', out['request']['body']['text'])
        self.assertEqual(out['proxy'], 'http://proxy:3128')


if __name__ == '__main__':
    unittest.main()
