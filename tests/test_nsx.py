"""Checks vmware/nsx/zbx_template_vmware_nsx.{yaml,json} ("VMware NSX by HTTP").

Static checks plus an end-to-end run: tests/fixtures/nsx/api.json (NSX API
responses keyed by path) is pushed through every master item, LLD rule
(JSONPath, JavaScript, macro paths, filters, overrides) and item prototype,
and simple trigger expressions are evaluated on the results. JavaScript runs
in node, including the BGP Script item against a stub HttpRequest.
"""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

import yaml

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
NSX = os.path.join(ROOT, 'vmware', 'nsx')
YAML_FILE = os.path.join(NSX, 'zbx_template_vmware_nsx.yaml')
JSON_FILE = os.path.join(NSX, 'zbx_template_vmware_nsx.json')
FIXTURES = os.path.join(ROOT, 'tests', 'fixtures', 'nsx', 'api.json')
T = 'VMware NSX by HTTP'
UUID_V4 = re.compile(r'^[0-9a-f]{12}4[0-9a-f]{3}[89ab][0-9a-f]{15}$')
URL = 'https://nsx.example'

with open(YAML_FILE) as _fh:
    TEXT = _fh.read()
EXPORT = yaml.safe_load(TEXT)['zabbix_export']
TPL = EXPORT['templates'][0]
with open(FIXTURES) as _fh:
    API = json.load(_fh)


class NoValue(Exception):
    pass


# ---- JSONPath: the subset the template uses
SEGMENT = re.compile(r"\.(\w+)\(\)|\.(\w+)|\[\?\((.*?)\)\]")
COND = re.compile(r"^@\.(\w+)=='([^']*)'$")


def jsonpath(doc, path):
    assert path.startswith('$'), path
    rest, cur, many = path[1:], doc, False
    while rest:
        m = SEGMENT.match(rest)
        assert m, 'unsupported JSONPath: %s' % path
        rest = rest[m.end():]
        func, name, cond = m.groups()
        if func == 'length':
            if not isinstance(cur, list):
                raise NoValue(path)
            cur, many = len(cur), False
        elif func == 'first':
            if not cur:
                raise NoValue(path)
            cur, many = cur[0], False
        elif func:
            raise AssertionError('unsupported function %s' % func)
        elif name:
            if many:
                cur = [c[name] for c in cur if isinstance(c, dict) and name in c]
            elif isinstance(cur, dict) and name in cur:
                cur = cur[name]
            else:
                raise NoValue(path)
        else:
            conds = [COND.match(c.strip()).groups() for c in cond.split('&&')]
            cur = [c for c in (cur or []) if all(str(c.get(f)) == v for f, v in conds)]
            many = True
            if not cur:
                raise NoValue(path)  # Zabbix fails on an empty match; the template must handle it
    return cur


def run_node(script):
    out = subprocess.run(['node', '-e', script], stdout=subprocess.PIPE, check=True).stdout
    res = json.loads(out)
    if 'err' in res:
        raise NoValue(res['err'])
    return res['ok']


def run_js(code, value):
    return run_node('var f = function (value) {\n%s\n};\n'
                    'try { process.stdout.write(JSON.stringify({ok: String(f(%s))})); }\n'
                    'catch (e) { process.stdout.write(JSON.stringify({err: String(e)})); }'
                    % (code, json.dumps(value)))


def subst(text, macros):
    for k, v in macros.items():
        text = text.replace(k, v)
    return text


def preprocess(steps, value, macros):
    """Final value as a string, or None when discarded."""
    for st in steps:
        params = [subst(p, macros) for p in st.get('parameters', [])]
        try:
            if st['type'] == 'JSONPATH':
                r = jsonpath(json.loads(value), params[0])
                value = r if isinstance(r, str) else json.dumps(r)
            elif st['type'] == 'JAVASCRIPT':
                value = run_js(params[0], value)
            elif st['type'] == 'MULTIPLIER':
                value = repr(float(value) * float(params[0]))
            elif st['type'] != 'DISCARD_UNCHANGED_HEARTBEAT':
                raise AssertionError('unexpected step %s' % st['type'])
        except NoValue:
            if st.get('error_handler') == 'DISCARD_VALUE':
                return None
            if st.get('error_handler') == 'CUSTOM_VALUE':
                value = st['error_handler_params']
                continue
            raise
    return value


def walk(node, key):
    if isinstance(node, dict):
        for k, v in node.items():
            if k == key:
                yield v
            yield from walk(v, key)
    elif isinstance(node, list):
        for v in node:
            yield from walk(v, key)


def all_items():
    yield from TPL['items']
    for r in TPL['discovery_rules']:
        yield from r['item_prototypes']


def all_triggers():
    for it in all_items():
        yield from it.get('triggers', [])
        yield from it.get('trigger_prototypes', [])
    for r in TPL['discovery_rules']:
        yield from r.get('trigger_prototypes', [])


def template_macros():
    m = {x['macro']: x.get('value', '') for x in TPL['macros']}
    m['{$NSX.MANAGER.URL}'] = URL
    return m


def resolve_user_macros(text, macros):
    # Context macros fall back to the macro without context.
    text = re.sub(r'\{\$([A-Z0-9_.]+):"[^"]*"\}', lambda m: '{$%s}' % m.group(1), text)
    return subst(text, macros)


def stub_http_script(params):
    """Runs the BGP Script item in node with HttpRequest serving the fixtures."""
    item = next(i for i in TPL['items'] if i['type'] == 'SCRIPT')
    harness = '''
var API = %s, BASE = %s, seen = [];
function btoa(s) { return Buffer.from(s, 'binary').toString('base64'); }
function HttpRequest() { this.headers = []; this.status = 0; }
HttpRequest.prototype.addHeader = function (h) { this.headers.push(h); };
HttpRequest.prototype.setProxy = function () {};
HttpRequest.prototype.getStatus = function () { return this.status; };
HttpRequest.prototype.get = function (url) {
    seen.push(this.headers.slice());
    var path = url.substring(BASE.length);
    if (url.indexOf(BASE) !== 0 || !API.hasOwnProperty(path)) { this.status = 404; return '{"error_code": 404}'; }
    this.status = 200;
    return JSON.stringify(API[path]);
};
var f = function (value) {
%s
};
try { process.stdout.write(JSON.stringify({ok: f(%s), headers: seen})); }
catch (e) { process.stdout.write(JSON.stringify({err: String(e)})); }
''' % (json.dumps(API), json.dumps(URL), item['params'], json.dumps(json.dumps(params)))
    out = subprocess.run(['node', '-e', harness], stdout=subprocess.PIPE, check=True).stdout
    res = json.loads(out)
    if 'err' in res:
        raise NoValue(res['err'])
    return res['ok'], res['headers']


def master_value(item, macros):
    if item['type'] == 'SCRIPT':
        params = {p['name']: resolve_user_macros(p['value'], macros) for p in item['parameters']}
        return stub_http_script(params)[0]
    assert item['type'] == 'HTTP_AGENT', item['key']
    url = subst(resolve_user_macros(item['url'], macros), macros)
    path = url[len(URL):]
    if path not in API:
        raise NoValue('no fixture for ' + path)
    return json.dumps(API[path])


def filter_ok(rule, row, macros):
    for c in rule.get('filter', {}).get('conditions', []):
        rx = resolve_user_macros(c['value'], macros)
        hit = re.search(rx, row.get(c['macro'], '')) is not None
        if hit != (c.get('operator', 'MATCHES_REGEX') == 'MATCHES_REGEX'):
            return False
    return True


TRIGGER = re.compile(r'^last\((/[^/]+/[^)]+?)\)(=|<>|>)(\d+)$')


class Collected:
    """Every resolved item value and every discovered trigger after one polling round."""

    def __init__(self):
        self.values, self.triggers, self.discovered = {}, [], {}
        user = template_macros()
        masters = {i['key']: master_value(i, user) for i in TPL['items'] if i['type'] != 'DEPENDENT'}
        for it in TPL['items']:
            if it['type'] == 'DEPENDENT':
                self.values[it['key']] = preprocess(it['preprocessing'], masters[it['master_item']['key']], user)
            for t in it.get('triggers', []):
                self.triggers.append((t, {}))
        for rule in TPL['discovery_rules']:
            raw = preprocess(rule['preprocessing'], masters[rule['master_item']['key']], user)
            rows = []
            for row in json.loads(raw):
                lld = {k: v for k, v in row.items() if k.startswith('{#')}
                for p in rule.get('lld_macro_paths', []):
                    lld[p['lld_macro']] = str(jsonpath(row, p['path']))
                if filter_ok(rule, lld, user):
                    rows.append(lld)
            self.discovered[rule['key']] = rows
            for lld in rows:
                macros = dict(user, **lld)
                proto_masters = {}
                for p in rule['item_prototypes']:
                    key = subst(p['key'], lld)
                    if p['type'] == 'HTTP_AGENT':
                        proto_masters[p['key']] = master_value(p, macros)
                        continue
                    master = proto_masters.get(p['master_item']['key']) or masters[p['master_item']['key']]
                    self.values[key] = preprocess(p['preprocessing'], master, macros)
                triggers = [t for p in rule['item_prototypes'] for t in p.get('trigger_prototypes', [])]
                triggers += rule.get('trigger_prototypes', [])
                for t in triggers:
                    t = self.apply_overrides(rule, lld, dict(t))
                    if t is not None:
                        self.triggers.append((t, macros))

    @staticmethod
    def apply_overrides(rule, lld, trig):
        for o in rule.get('overrides', []):
            if not all(re.search(c['value'], lld.get(c['macro'], '')) for c in o['filter']['conditions']):
                continue
            for op in o['operations']:
                if op['operationobject'] != 'TRIGGER_PROTOTYPE':
                    continue
                hit = (op['value'] in trig['name']) if op['operator'] == 'LIKE' else \
                    re.search(op['value'], trig['name']) is not None
                if hit:
                    if op.get('discover') == 'NO_DISCOVER':
                        return None
                    if 'severity' in op:
                        trig['priority'] = op['severity']
            if o.get('stop') == 'STOP':
                break
        return trig

    def firing(self):
        """(name, priority) of triggers with a simple last(...) expression that are in PROBLEM."""
        out = []
        for t, macros in self.triggers:
            m = TRIGGER.match(t['expression'])
            if not m:
                continue
            key = subst(m.group(1).split('/', 2)[2], macros)
            value = self.values.get(key)
            if value is None:
                continue
            v, n = float(value), float(m.group(3))
            if {'=': v == n, '<>': v != n, '>': v > n}[m.group(2)]:
                out.append((subst(t['name'], macros), t.get('priority', 'NOT_CLASSIFIED')))
        return sorted(out)


class StaticTests(unittest.TestCase):
    def test_version_and_name(self):
        self.assertEqual(EXPORT['version'], '7.0')
        self.assertEqual(TPL['template'], T)

    def test_json_equals_yaml(self):
        with open(JSON_FILE) as fh:
            self.assertEqual(json.load(fh)['zabbix_export'], EXPORT)

    def test_uuids_unique_v4(self):
        uuids = list(walk(TPL, 'uuid'))
        self.assertEqual(len(uuids), len(set(uuids)))
        for u in uuids:
            self.assertRegex(u, UUID_V4)

    def test_keys_unique(self):
        keys = [i['key'] for i in all_items()] + [r['key'] for r in TPL['discovery_rules']]
        self.assertEqual(len(keys), len(set(keys)))

    def test_required_items(self):
        master = next(i for i in TPL['items'] if i['key'] == 'nsx.cluster.status.get')
        self.assertEqual(master['name'], 'NSX: Get Cluster Status')
        self.assertEqual(master['type'], 'HTTP_AGENT')
        self.assertEqual(master['url'], '{$NSX.MANAGER.URL}/api/v1/cluster/status')
        deps = {i['key'] for i in TPL['items'] if i.get('master_item', {}).get('key') == master['key']}
        self.assertTrue({'nsx.cluster.overall_status', 'nsx.cluster.mgmt_status'} <= deps)
        macros = {m['macro']: m for m in TPL['macros']}
        self.assertEqual(macros['{$NSX.DATA.TIMEOUT}']['value'], '15s')
        self.assertEqual(macros['{$NSX.PASSWORD}'].get('type'), 'SECRET_TEXT')

    def test_http_items_authenticate_and_time_out(self):
        for it in all_items():
            if it['type'] == 'HTTP_AGENT':
                self.assertEqual(it['authtype'], 'BASIC', it['key'])
                self.assertEqual((it['username'], it['password']), ('{$NSX.USER}', '{$NSX.PASSWORD}'))
                self.assertEqual(it['timeout'], '{$NSX.DATA.TIMEOUT}')
                self.assertTrue(it['url'].startswith('{$NSX.MANAGER.URL}/'), it['key'])
                self.assertEqual(it['history'], '0')

    def test_dependents_resolve(self):
        top = {i['key']: i for i in TPL['items']}
        for it in TPL['items']:
            if it['type'] == 'DEPENDENT':
                self.assertNotEqual(top[it['master_item']['key']]['type'], 'DEPENDENT')
        for r in TPL['discovery_rules']:
            self.assertIn(r['master_item']['key'], top)
            local = {p['key'] for p in r['item_prototypes']}
            for p in r['item_prototypes']:
                if p['type'] == 'DEPENDENT':
                    self.assertTrue(p['master_item']['key'] in local or p['master_item']['key'] in top, p['key'])

    def test_triggers_reference_items_they_belong_to(self):
        own = '/%s/' % T
        for it in all_items():
            for t in it.get('triggers', []) + it.get('trigger_prototypes', []):
                keys = set(re.findall(r'\(/%s/(.+?)(?:,[^,()]*)?\)' % re.escape(T), t['expression']))
                self.assertEqual(keys, {it['key']}, t['name'])
        for r in TPL['discovery_rules']:
            local = {p['key'] for p in r['item_prototypes']}
            for t in r.get('trigger_prototypes', []):
                keys = set(re.findall(r'\(/%s/(.+?)\)' % re.escape(T), t['expression']))
                self.assertTrue(len(keys) > 1 and keys <= local, t['name'])
        self.assertIn(own, TEXT)

    def test_dependencies_point_to_existing_triggers(self):
        by_name = {t['name']: t['expression'] for t in all_triggers()}
        for t in all_triggers():
            for d in t.get('dependencies', []):
                self.assertEqual(by_name.get(d['name']), d['expression'], t['name'])

    def test_user_macros_defined_and_used(self):
        defined = {m['macro'] for m in TPL['macros']}
        used = {re.sub(r':"[^"]*"\}$', '}', m) for m in re.findall(r'\{\$[A-Z0-9_.]+(?::"[^"]*")?\}', TEXT)}
        self.assertEqual(used - defined, set())
        self.assertEqual(defined - used, set())

    def test_lld_macros_known(self):
        for r in TPL['discovery_rules']:
            known = {p['lld_macro'] for p in r.get('lld_macro_paths', [])}
            for st in r['preprocessing']:
                if st['type'] == 'JAVASCRIPT':
                    known |= set(re.findall(r"'(\{#[A-Z0-9_.]+\})'", st['parameters'][0]))
            text = json.dumps([r['item_prototypes'], r.get('trigger_prototypes', []), r.get('filter', {}),
                               r.get('overrides', [])])
            self.assertEqual(set(re.findall(r'\{#[A-Z0-9_.]+\}', text)) - known, set(), r['key'])

    def test_javascript_is_es5(self):
        # Zabbix runs Duktape: no let/const/arrow functions/template literals.
        codes = [i['params'] for i in all_items() if i['type'] == 'SCRIPT']
        codes += [s['parameters'][0] for s in walk(TPL, 'preprocessing') for s in s if s['type'] == 'JAVASCRIPT']
        self.assertTrue(codes)
        for code in codes:
            self.assertNotRegex(code, r'\b(let|const)\s|=>|`')

    def test_alarm_severity_overrides(self):
        rule = next(r for r in TPL['discovery_rules'] if r['key'] == 'nsx.alarm.discovery')
        got = {o['filter']['conditions'][0]['value']: o['operations'][0]['severity'] for o in rule['overrides']}
        self.assertEqual(got, {'^CRITICAL$': 'HIGH', '^HIGH$': 'AVERAGE', '^MEDIUM$': 'WARNING', '^LOW$': 'INFO'})
        self.assertEqual(rule['enabled_lifetime_type'], 'DISABLE_NEVER')

    def test_generator_output_is_committed(self):
        with tempfile.TemporaryDirectory() as tmp:
            subprocess.run([sys.executable, os.path.join(ROOT, 'vmware', 'tools', 'gen_nsx_template.py'), tmp],
                           check=True, stdout=subprocess.DEVNULL)
            for name in ('zbx_template_vmware_nsx.yaml', 'zbx_template_vmware_nsx.json'):
                with open(os.path.join(tmp, name)) as fresh, open(os.path.join(NSX, name)) as committed:
                    self.assertEqual(fresh.read(), committed.read(), 'regenerate ' + name)


@unittest.skipUnless(shutil.which('node'), 'node not installed')
class EndToEndTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.c = Collected()

    def v(self, key):
        return self.c.values[key]

    def test_cluster(self):
        self.assertEqual(self.v('nsx.cluster.overall_status'), '2')
        self.assertEqual(self.v('nsx.cluster.mgmt_status'), '1')
        self.assertEqual(self.v('nsx.cluster.nodes.online'), '3')
        self.assertEqual(self.v('nsx.cluster.nodes.offline'), '0')
        self.assertEqual(self.v('nsx.cluster.group.status[POLICY]'), '2')
        self.assertEqual(self.v('nsx.cluster.group.down[POLICY]'), '1')
        self.assertEqual(self.v('nsx.cluster.group.down[MANAGER]'), '0')

    def test_host_nodes_only_hosts(self):
        names = [r['{#NODE.NAME}'] for r in self.c.discovered['nsx.tn.host.discovery']]
        self.assertEqual(names, ['esx-01', 'esx-02'])
        self.assertEqual(self.v('nsx.tn.status[h-2]'), '2')
        self.assertEqual(self.v('nsx.tn.tunnel.down[h-2]'), '2')
        self.assertEqual(self.v('nsx.tn.tunnel.status[h-1]'), '1')

    def test_edges(self):
        edges = {r['{#EDGE.ID}']: r for r in self.c.discovered['nsx.edge.discovery']}
        self.assertEqual(edges['e-2']['{#EDGE.NAME}'], 'e-2')  # no display_name in the member
        self.assertEqual(edges['e-1']['{#EC.NAME}'], 'edge-cluster-01')
        self.assertEqual(float(self.v('nsx.edge.cpu.service[e-1]')), 95.0)
        self.assertEqual(float(self.v('nsx.edge.mem.util[e-1]')), 41.2)
        self.assertEqual(float(self.v('nsx.edge.mem.util[e-2]')), 25.0)  # mem_used / mem_total fallback
        self.assertEqual(float(self.v('nsx.edge.uptime[e-1]')), 86400.0)
        self.assertEqual(self.v('nsx.ec.status[ec-1]'), '2')

    def test_gateways(self):
        self.assertEqual(self.v('nsx.t0.ha.active.nodes[t0-gw]'), 'e-1')
        self.assertEqual(self.v('nsx.t0.ha.notok[t0-gw]'), '1')
        self.assertEqual(self.v('nsx.t0.ha.active[t0-aa]'), '2')
        self.assertEqual(self.v('nsx.t1.ha.standby[t1-app]'), '1')
        self.assertEqual(self.v('nsx.t1.ha.total[t1-dr]'), '0')  # DR-only tier-1
        self.assertEqual(self.v('nsx.t1.ha.active.nodes[t1-dr]'), '')

    def test_active_active_has_no_failover_triggers(self):
        names = [subst(t['name'], m) for t, m in self.c.triggers]
        self.assertIn('Tier-0 [T0-GW]: HA failover, active SR moved', names)
        self.assertNotIn('Tier-0 [T0-AA]: HA failover, active SR moved', names)
        self.assertFalse([n for n in names if 'T0-AA' in n and 'split brain' in n])

    def test_bgp(self):
        uids = [r['{#BGP.UID}'] for r in self.c.discovered['nsx.bgp.discovery']]
        self.assertEqual(uids, ['t0-gw|edge-01|10.0.0.2|10.0.0.1', 't0-gw|edge-02|10.0.1.2|10.0.1.1'])
        self.assertEqual(self.v('nsx.bgp.state["t0-gw|edge-01|10.0.0.2|10.0.0.1"]'), '1')
        self.assertEqual(self.v('nsx.bgp.state["t0-gw|edge-02|10.0.1.2|10.0.1.1"]'), '2')
        self.assertEqual(float(self.v('nsx.bgp.uptime["t0-gw|edge-01|10.0.0.2|10.0.0.1"]')), 3600.0)
        self.assertIn('t0-aa: GET /policy/api/v1/infra/tier-0s/t0-aa/locale-services: HTTP 404', self.v('nsx.bgp.errors'))

    def test_bgp_script_sends_basic_auth(self):
        _, headers = stub_http_script({'url': URL + '/', 'user': 'u', 'password': 'p', 'proxy': ''})
        self.assertTrue(headers)
        for h in headers:
            self.assertIn('Authorization: Basic dTpw', h)

    def test_alarms(self):
        found = {r['{#ALARM.ID}']: r for r in self.c.discovered['nsx.alarm.discovery']}
        self.assertEqual(sorted(found), ['al-1', 'al-2', 'al-3'])  # LOW filtered, RESOLVED not open
        self.assertEqual(found['al-1']['{#ALARM.FEATURE}'], 'Edge Health')
        self.assertEqual(found['al-2']['{#ALARM.NODE}'], 'e-2')
        self.assertLessEqual(len(found['al-3']['{#ALARM.SUMMARY}']), 120)
        self.assertEqual(self.v('nsx.alarms.count[critical]'), '1')
        self.assertEqual(self.v('nsx.alarms.count[low]'), '1')
        self.assertEqual(self.v('nsx.alarms.count[total]'), '4')

    def test_resolved_alarm_closes_problem(self):
        rule = next(r for r in TPL['discovery_rules'] if r['key'] == 'nsx.alarm.discovery')
        proto = rule['item_prototypes'][0]
        value = preprocess(proto['preprocessing'], json.dumps({'results': []}), {'{#ALARM.ID}': 'al-1'})
        self.assertEqual(value, '0')

    def test_expected_problems(self):
        firing = self.c.firing()
        expected = [
            ('BGP [T0-GW] 10.0.1.2 -> 10.0.1.1 (AS 65001, edge-02): session is not ESTABLISHED', 'HIGH'),
            ('Edge [e-2]: all overlay tunnels are down', 'HIGH'),
            ('Edge [e-2]: lost connection to NSX Manager', 'AVERAGE'),
            ('Edge [e-2]: status is DOWN', 'HIGH'),
            ('Edge cluster [edge-cluster-01]: status is DEGRADED', 'AVERAGE'),
            ('Host TN [esx-02]: overlay tunnels degraded', 'AVERAGE'),
            ('Host TN [esx-02]: status is DEGRADED', 'AVERAGE'),
            ('NSX alarm [CRITICAL]: Edge Health / Edge Node Down on edge-02', 'HIGH'),
            ('NSX alarm [HIGH]: routing / bgp_down on e-2', 'AVERAGE'),
            ('NSX: Management cluster is not stable', 'AVERAGE'),
            ('NSX: Service group [POLICY] is not stable', 'AVERAGE'),
            ('Tier-0 [T0-GW]: {ITEM.LASTVALUE1} SR node(s) DOWN or not in sync', 'HIGH'),
        ]
        for exp in expected:
            self.assertIn(exp, firing)
        alarm3 = [f for f in firing if f[0].startswith('NSX alarm [MEDIUM]')]
        self.assertEqual([p for _, p in alarm3], ['WARNING'])
        for name, _ in firing:
            self.assertNotIn('esx-01', name)
            self.assertNotIn('T1-DR', name)


if __name__ == '__main__':
    unittest.main()
