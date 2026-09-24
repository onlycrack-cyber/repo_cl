"""Static and end-to-end checks of zbx_export_templates_hpe_ssmc.yaml.

End-to-end: the collector's --test output is pushed through every LLD rule and
item preprocessing step (JSONPath evaluated here, JavaScript through node when
available) to prove each discovered entity yields a value.
"""
import json
import os
import re
import shutil
import subprocess
import sys
import unittest

import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
TEMPLATE_FILE = os.path.join(ROOT, 'zbx_export_templates_hpe_ssmc.yaml')

# Keys that existed before the refactor and hold history in production.
LEGACY_KEYS = {
    'collect.error', 'system.allocatedcapacity', 'system.diskcount', 'system.freecapacity',
    'system.name', 'system.state', 'system.totalcapacity',
    'alerts.discovery', 'batteries.discovery', 'disks.discovery', 'nodes.discovery',
    'ports.discovery', 'volumes.discovery',
    'alert.message[{#ALERT_ID}]', 'alert.severity[{#ALERT_ID}]', 'battery.status[{#BATTERY_ID}]',
    'disk.capacity[{#DISK_ID}]', 'disk.model[{#DISK_ID}]', 'disk.serial[{#DISK_ID}]',
    'disk.state[{#DISK_ID}]', 'disk.type[{#DISK_ID}]', 'node.model[{#NODE_ID}]',
    'node.status[{#NODE_ID}]', 'port.linkstate[{#PORT_POS}]', 'volume.size[{#VOLUME_NAME}]',
    'volume.state[{#VOLUME_NAME}]', 'volume.used[{#VOLUME_NAME}]', 'volume.util[{#VOLUME_NAME}]',
}
UUID_V4 = re.compile(r'^[0-9a-f]{12}4[0-9a-f]{3}[89ab][0-9a-f]{15}$')
FUNC_REF = re.compile(r'/([^/]+)/((?:[^,()"\[]|\[(?:"[^"]*"|[^\]])*\])+)')
USER_MACRO = re.compile(r'\{\$[A-Z0-9_.]+\}')


def read_text():
    with open(TEMPLATE_FILE) as fh:
        return fh.read()


def load():
    return yaml.safe_load(read_text())['zabbix_export']


def collector_output(section):
    out = subprocess.run([sys.executable, os.path.join(ROOT, 'ssmc_collect.py'), '--test',
                          '--section', section, '--node-addrs', '0=127.0.0.2',
                          '--probe-ports', '1'],
                         stdout=subprocess.PIPE, check=False, timeout=60).stdout
    return out.decode()


# ---- tiny JSONPath evaluator for the expressions the template uses
class NoValue(Exception):
    pass


def jsonpath(doc, path):
    m = re.match(r"^\$\.(\w+)\[\?\(@\.(\w+) == ('?)(.*?)\3\)\]\.(\w+)\.first\(\)$", path)
    if m:
        coll, field, quoted, want, attr = m.groups()
        for row in doc.get(coll) or []:
            have = row.get(field)
            if (str(have) == want) if quoted else (have == json.loads(want)):
                if attr not in row:
                    raise NoValue(path)
                return row[attr]
        raise NoValue(path)
    m = re.match(r'^\$\.(\w+)\.length\(\)$', path)
    if m:
        if m.group(1) not in doc:
            raise NoValue(path)
        return len(doc[m.group(1)])
    m = re.match(r'^\$((?:\.\w+)+)$', path)
    if not m:
        raise AssertionError('unsupported JSONPath in test evaluator: %s' % path)
    cur = doc
    for part in m.group(1).split('.')[1:]:
        if not isinstance(cur, dict) or part not in cur:
            raise NoValue(path)
        cur = cur[part]
    return cur


def run_js(code, value):
    script = 'var f = function (value) {\n%s\n};\n' \
             'try { process.stdout.write(JSON.stringify({ok: String(f(%s))})); }\n' \
             'catch (e) { process.stdout.write(JSON.stringify({err: String(e)})); }' % (
                 code, json.dumps(value))
    out = subprocess.run(['node', '-e', script], stdout=subprocess.PIPE, check=True).stdout
    res = json.loads(out)
    if 'err' in res:
        raise NoValue(res['err'])
    return res['ok']


def preprocess(steps, value, macros):
    """Returns the final value as a string, or None when discarded."""
    for step in steps:
        params = [subst(p, macros) for p in step.get('parameters', [])]
        try:
            if step['type'] == 'JSONPATH':
                try:
                    doc = json.loads(value)
                except ValueError:
                    raise NoValue('not JSON')  # Zabbix: preprocessing step error
                r = jsonpath(doc, params[0])
                value = r if isinstance(r, str) else json.dumps(r)
            elif step['type'] == 'JAVASCRIPT':
                if not shutil.which('node'):
                    raise unittest.SkipTest('node not installed')
                value = run_js(params[0], value)
            elif step['type'] == 'MULTIPLIER':
                value = str(float(value) * float(params[0]))
            elif step['type'] == 'DISCARD_UNCHANGED_HEARTBEAT':
                pass
            else:
                raise AssertionError('unexpected preprocessing type %s' % step['type'])
        except NoValue:
            handler = step.get('error_handler')
            if handler == 'DISCARD_VALUE':
                return None
            if handler == 'CUSTOM_VALUE':
                value = step.get('error_handler_params', '')
                continue
            raise
    return value


def subst(text, macros):
    for k, v in macros.items():
        text = text.replace(k, v)
    return text


class TemplateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.export = load()
        cls.tpl = cls.export['templates'][0]
        cls.name = cls.tpl['template']
        cls.items = {i['key']: i for i in cls.tpl['items']}
        cls.rules = {r['key']: r for r in cls.tpl['discovery_rules']}
        cls.macros = {m['macro']: m.get('value', '') for m in cls.tpl['macros']}

    def all_items(self):
        yield from self.tpl['items']
        for r in self.tpl['discovery_rules']:
            yield from r['item_prototypes']

    def all_triggers(self):
        for i in self.tpl['items']:
            for t in i.get('triggers', []):
                yield t, set(self.items)
        yield from ((t, set(self.items)) for t in self.export.get('triggers', []))
        for r in self.tpl['discovery_rules']:
            keys = set(self.items) | {p['key'] for p in r['item_prototypes']}
            for p in r['item_prototypes']:
                for t in p.get('trigger_prototypes', []):
                    yield t, keys
            for t in r.get('trigger_prototypes', []):
                yield t, keys

    def test_version_and_legacy_keys(self):
        self.assertEqual(self.export['version'], '7.0')
        keys = set(self.items) | set(self.rules) | {
            p['key'] for r in self.rules.values() for p in r['item_prototypes']}
        self.assertEqual(LEGACY_KEYS - keys, set())

    def test_uuids_unique_v4(self):
        text = read_text()
        uuids = re.findall(r'uuid: (\S+)', text)
        self.assertEqual(len(uuids), len(set(uuids)), 'duplicate uuid')
        for u in uuids:
            self.assertRegex(u, UUID_V4)

    def test_masters_are_active_agent_and_dependents_resolve(self):
        masters = {k for k, i in self.items.items() if i['type'] != 'DEPENDENT'}
        self.assertEqual({self.items[k]['type'] for k in masters}, {'ZABBIX_ACTIVE'})
        for k in masters:
            self.assertNotIn('PASS', k, 'credentials must not be part of an item key')
        for it in list(self.all_items()) + list(self.rules.values()):
            if it['type'] == 'DEPENDENT':
                self.assertIn(it['master_item']['key'], masters, it['key'])
            else:
                self.assertIn(it['type'], ('ZABBIX_ACTIVE',), it['key'])

    def test_trigger_expressions_reference_existing_items(self):
        for trig, keys in self.all_triggers():
            for expr in (trig['expression'], trig.get('recovery_expression', '')):
                refs = FUNC_REF.findall(expr)
                if expr:
                    self.assertTrue(refs, expr)
                for host, key in refs:
                    self.assertEqual(host, self.name, expr)
                    self.assertIn(key, keys, 'unknown key in %r' % expr)

    def test_multi_item_triggers_live_at_top_or_rule_level(self):
        for i in self.tpl['items']:
            for t in i.get('triggers', []):
                self.assertEqual({k for _, k in FUNC_REF.findall(t['expression'])}, {i['key']}, t['name'])
        for r in self.tpl['discovery_rules']:
            for p in r['item_prototypes']:
                for t in p.get('trigger_prototypes', []):
                    self.assertEqual({k for _, k in FUNC_REF.findall(t['expression'])}, {p['key']}, t['name'])

    def test_dependencies_resolve(self):
        index = {(t['name'], t['expression']) for t, _ in self.all_triggers()}
        for t, _ in self.all_triggers():
            for d in t.get('dependencies', []):
                self.assertIn((d['name'], d['expression']), index)

    def test_user_macros_defined(self):
        text = read_text()
        used = set(USER_MACRO.findall(text))
        self.assertEqual(used - set(self.macros), set())
        self.assertNotIn('{$SSMC_SSH_PASS}', self.macros)

    def test_tags(self):
        for it in self.all_items():
            tags = {t['tag'] for t in it['tags']}
            self.assertTrue({'component', 'service', 'target'} <= tags, it['key'])
        for t, _ in self.all_triggers():
            tags = {x['tag'] for x in t['tags']}
            self.assertTrue({'component', 'service', 'target', 'scope'} <= tags, t['name'])

    def test_recovery_mode_consistent(self):
        for t, _ in self.all_triggers():
            self.assertEqual('recovery_expression' in t,
                             t.get('recovery_mode') == 'RECOVERY_EXPRESSION', t['name'])

    def test_javascript_is_es5(self):
        for it in list(self.all_items()) + list(self.rules.values()):
            for step in it.get('preprocessing', []):
                if step['type'] == 'JAVASCRIPT':
                    code = step['parameters'][0]
                    self.assertIsNone(re.search(r'=>|\blet\b|\bconst\b|`', code), it['key'])

    # ---- end-to-end against collector output
    def _run_rule(self, rule, master_value):
        lld = preprocess(rule['preprocessing'], master_value, self.macros)
        rows = json.loads(lld)
        entities = []
        for row in rows:
            if rule.get('lld_macro_paths'):
                macros = {p['lld_macro']: str(jsonpath(row, p['path'])) for p in rule['lld_macro_paths']}
            else:
                macros = row
            ok = True
            for cond in rule.get('filter', {}).get('conditions', []):
                rx = self.macros.get(cond['value'], cond['value'])
                if rx == 'CHANGE_IF_NEEDED':
                    rx = '^CHANGE_IF_NEEDED$'
                match = re.search(rx, macros[cond['macro']]) is not None
                ok &= match if cond['operator'] == 'MATCHES_REGEX' else not match
            if ok:
                entities.append(macros)
        return entities

    def test_end_to_end(self):
        outputs = {'all': collector_output('all'), 'net': collector_output('net')}
        doc_all = json.loads(outputs['all'])
        self.assertIsNone(doc_all['error'])
        master_for = {}
        for key in self.items:
            if key.startswith('ssmc.collect['):
                master_for[key] = outputs['net' if ',net,' in key else 'all']

        values = {}
        for it in self.tpl['items']:
            if it['type'] != 'DEPENDENT':
                continue
            v = preprocess(it['preprocessing'], master_for[it['master_item']['key']], self.macros)
            self.assertIsNotNone(v, '%s produced no value' % it['key'])
            values[it['key']] = v
        self.assertEqual(values['collect.error'], '')
        self.assertEqual(values['collect.status'], '0')
        self.assertEqual(values['system.state'], '2')
        self.assertEqual(values['system.diskcount'], '5')
        self.assertEqual(values['alerts.count.critical'], '1')
        self.assertAlmostEqual(float(values['system.totalcapacity']), 23.068672)

        found = {}
        for key, rule in self.rules.items():
            master = master_for[rule['master_item']['key']]
            entities = self._run_rule(rule, master)
            found[key] = entities
            for ent in entities:
                for proto in rule['item_prototypes']:
                    steps = json.loads(subst(json.dumps(proto['preprocessing']), ent))
                    v = preprocess(steps, master, self.macros)
                    optional = proto['key'].startswith('net.node.icmp')  # no ping binary in CI
                    if not optional:
                        self.assertIsNotNone(v, '%s for %s' % (proto['key'], ent))
                    values[subst(proto['key'], ent)] = v

        self.assertEqual(len(found['disks.discovery']), 5)
        self.assertEqual([e['{#PORT_POS}'] for e in found['ports.discovery']].count('0:1:2'), 0,
                         'free port must be filtered out')
        self.assertEqual({e['{#ALERT_ID}'] for e in found['alerts.discovery']}, {'101', '102'})
        self.assertEqual(len(found['certs.discovery']), 5)
        self.assertEqual({e['{#NET_ADDR}'] for e in found['net.discovery']}, {'127.0.0.1', '127.0.0.2'})
        self.assertEqual(values['alert.severity[101]'], '5')
        self.assertEqual(values['node.status[1]'], '2')
        self.assertEqual(values['battery.status[1.0.0]'], '4')
        self.assertEqual(values['battery.expired[1.1.0]'], '1')
        self.assertEqual(values['port.linkstate[1:1:2]'], '5')
        self.assertEqual(values['volume.util[vol_app01]'], '95.0')
        self.assertLess(float(values['cert.days_left[syslog-sec-client]']), 0)

    def test_missing_entities(self):
        if not shutil.which('node'):
            self.skipTest('node not installed')
        doc = json.loads(collector_output('all'))
        doc['alerts'] = [a for a in doc['alerts'] if a['id'] != 101]
        doc['nodes'] = [n for n in doc['nodes'] if n['id'] != 1]
        value = json.dumps(doc)
        rules = self.rules
        sev = rules['alerts.discovery']['item_prototypes'][1]
        node = rules['nodes.discovery']['item_prototypes'][1]
        self.assertEqual(sev['key'], 'alert.severity[{#ALERT_ID}]')
        self.assertEqual(node['key'], 'node.status[{#NODE_ID}]')
        steps = json.loads(subst(json.dumps(sev['preprocessing']), {'{#ALERT_ID}': '101'}))
        self.assertEqual(preprocess(steps, value, self.macros), '0', 'fixed alert must resolve')
        steps = json.loads(subst(json.dumps(node['preprocessing']), {'{#NODE_ID}': '1'}))
        self.assertEqual(preprocess(steps, value, self.macros), '4', 'vanished node must alert')
        # Whole poll failed: sections absent -> values discarded, LLD keeps entities.
        failed = json.dumps({'error': 'SSH connect failed', 'status': 2, 'duration': 5.0})
        self.assertIsNone(preprocess(steps, failed, self.macros))
        with self.assertRaises(NoValue):
            preprocess(rules['disks.discovery']['preprocessing'], failed, self.macros)

    def test_non_json_output_raises_collection_failed(self):
        # e.g. script missing or python traceback: the agent returns that text as the value.
        if not shutil.which('node'):
            self.skipTest('node not installed')
        raw = "python3: can't open file '/usr/lib/zabbix/ssmc/ssmc_collect.py': [Errno 13] Permission denied"
        status = preprocess(self.items['collect.status']['preprocessing'], raw, self.macros)
        error = preprocess(self.items['collect.error']['preprocessing'], raw, self.macros)
        self.assertEqual(status, '4')
        self.assertTrue(error.startswith('invalid collector output: python3'), error)
        failed = [t for t, _ in self.all_triggers() if t['name'].startswith('Data collection failed')][0]
        self.assertIn('min(/%s/collect.status,#2)>=2' % self.name, failed['expression'])
        self.assertIn('length(last(/%s/collect.error))>0' % self.name, failed['expression'])


if __name__ == '__main__':
    unittest.main()
