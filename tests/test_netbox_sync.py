"""Checks of netbox/netbox_zabbix_sync.py against in-memory NetBox and Zabbix fakes.

pynetbox is not needed: the syncer only sees plain dicts from the NetBox source.
"""
import copy
import hashlib
import hmac
import os
import sys
import tempfile
import time
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, 'netbox'))

import netbox_zabbix_sync as nzs  # noqa: E402

nzs.log.disabled = True


def device(dev_id, name, model='ProLiant DL380 Gen10', status='active', ip='10.0.0.1/24',
           site='DC1', role='Server', **extra):
    dev = {
        'id': dev_id, 'name': name, 'status': {'value': status, 'label': status.title()},
        'site': {'name': site, 'slug': site.lower()},
        'role': {'name': role, 'slug': role.lower()},
        'device_type': {'model': model, 'slug': model.lower().replace(' ', '-'),
                        'manufacturer': {'name': 'HPE'}},
        'primary_ip4': {'address': ip} if ip else None,
        'tenant': None, 'serial': 'SN%d' % dev_id, 'custom_fields': {},
    }
    dev.update(extra)
    return dev


class FakeNetBox(object):
    def __init__(self, devices=None, fail=False):
        self.devices = devices or []
        self.fail = fail
        self.url = 'https://netbox.example'

    def fetch_devices(self, extra_filters=None):
        if self.fail:
            raise nzs.NetBoxError('connection refused')
        devs = self.devices
        if extra_filters and 'name' in extra_filters:
            devs = [d for d in devs if d['name'] in extra_filters['name']]
        return copy.deepcopy(devs)

    def get_device(self, dev_id):
        for d in self.devices:
            if d['id'] == dev_id:
                return copy.deepcopy(d)
        return None


class FakeZabbix(object):
    """Just enough of the Zabbix 7.0 API for the syncer."""

    def __init__(self, templates=('ICMP Ping', 'HPE DL380 Gen10', 'HPE DL380 Gen10 Plus',
                                  'HPE DL360 Gen10', 'HPE Gen9', 'Linux by Zabbix agent'),
                 groups=('Manual',)):
        self.version = (7, 0)
        self.calls = []
        self.seq = 100
        self.groups = {self._id(): n for n in groups}
        self.templates = {self._id(): n for n in templates}
        self.hosts = {}
        self.maintenances = {}

    def _id(self):
        self.seq += 1
        return str(self.seq)

    def writes(self):
        return [c for c in self.calls if not c[0].endswith('.get')]

    def _host_out(self, h):
        out = copy.deepcopy(h)
        out['hostgroups'] = [{'groupid': g, 'name': self.groups[g]} for g in h['groups']]
        del out['groups']
        out['parentTemplates'] = [{'templateid': t, 'host': self.templates[t], 'name': self.templates[t]}
                                  for t in h['templates']]
        del out['templates']
        return out

    def call(self, method, params=None):
        self.calls.append((method, copy.deepcopy(params)))
        handler = getattr(self, method.replace('.', '_'))
        return handler(params)

    def hostgroup_get(self, p):
        return [{'groupid': k, 'name': v} for k, v in self.groups.items()]

    def hostgroup_create(self, p):
        gid = self._id()
        self.groups[gid] = p['name']
        return {'groupids': [gid]}

    def template_get(self, p):
        return [{'templateid': k, 'host': v, 'name': v} for k, v in self.templates.items()]

    def proxy_get(self, p):
        return []

    def maintenance_get(self, p):
        return [copy.deepcopy(m) for m in self.maintenances.values()
                if m['name'].startswith(p['search']['name'])]

    def maintenance_create(self, p):
        mid = self._id()
        self.maintenances[mid] = {'maintenanceid': mid, 'name': p['name'],
                                  'active_till': str(p['active_till']), 'hosts': p['hosts']}
        return {'maintenanceids': [mid]}

    def maintenance_delete(self, p):
        for mid in p:
            del self.maintenances[mid]
        return {'maintenanceids': p}

    def host_get(self, p):
        result = []
        for h in self.hosts.values():
            if 'tags' in p:
                want = p['tags'][0]
                if not any(t['tag'] == want['tag'] and t['value'] == want['value'] for t in h['tags']):
                    continue
            if 'filter' in p and h['host'] not in p['filter']['host']:
                continue
            out = self._host_out(h)
            if 'selectInventory' not in p:
                out.pop('inventory')
            result.append(out)
        return result

    def host_create(self, p):
        if any(h['host'] == p['host'] for h in self.hosts.values()):
            raise nzs.ZabbixError('host.create: Host with the same name "%s" already exists.' % p['host'])
        hid = self._id()
        ifaces = []
        for i in p.get('interfaces', []):
            i = dict(i, interfaceid=self._id())
            i = {k: (str(v) if k != 'details' else v) for k, v in i.items()}
            ifaces.append(i)
        self.hosts[hid] = {
            'hostid': hid, 'host': p['host'], 'name': p.get('name', p['host']),
            'status': str(p.get('status', 0)), 'description': p.get('description', ''),
            'inventory_mode': str(p.get('inventory_mode', -1)), 'inventory': dict(p.get('inventory', {})),
            'monitored_by': '0', 'proxyid': '0',
            'groups': [g['groupid'] for g in p['groups']],
            'templates': [t['templateid'] for t in p.get('templates', [])],
            'interfaces': ifaces, 'tags': copy.deepcopy(p.get('tags', [])),
        }
        return {'hostids': [hid]}

    def host_update(self, p):
        h = self.hosts[p['hostid']]
        for key in ('host', 'name', 'description'):
            if key in p:
                h[key] = p[key]
        if 'status' in p:
            h['status'] = str(p['status'])
        if 'groups' in p:
            h['groups'] = [g['groupid'] for g in p['groups']]
        if 'templates' in p:
            h['templates'] = [t['templateid'] for t in p['templates']]
        if 'tags' in p:
            h['tags'] = copy.deepcopy(p['tags'])
        if 'inventory_mode' in p:
            h['inventory_mode'] = str(p['inventory_mode'])
        if 'inventory' in p:
            h['inventory'].update(p['inventory'])
        return {'hostids': [p['hostid']]}

    def hostinterface_update(self, p):
        for h in self.hosts.values():
            for i in h['interfaces']:
                if i['interfaceid'] == p['interfaceid']:
                    i.update({k: str(v) for k, v in p.items() if k != 'details'})
        return {'interfaceids': [p['interfaceid']]}

    def hostinterface_create(self, p):
        h = self.hosts[p['hostid']]
        h['interfaces'].append({k: str(v) for k, v in p.items() if k not in ('hostid', 'details')})
        h['interfaces'][-1]['interfaceid'] = self._id()
        return {'interfaceids': [h['interfaces'][-1]['interfaceid']]}

    # helpers for assertions
    def host(self, name):
        for h in self.hosts.values():
            if h['host'] == name:
                return h
        raise KeyError(name)

    def group_names(self, name):
        return sorted(self.groups[g] for g in self.host(name)['groups'])

    def template_names(self, name):
        return sorted(self.templates[t] for t in self.host(name)['templates'])


def make_config(**overrides):
    env = {'NETBOX_URL': 'https://netbox.example', 'NETBOX_TOKEN': 'nbt',
           'ZABBIX_URL': 'https://zabbix.example', 'ZABBIX_TOKEN': 'zbt'}
    data = {
        'templates': {
            'rules': [
                {'name': 'DL380 Gen10 Plus', 'contains': '380 g10 plus', 'templates': ['HPE DL380 Gen10 Plus']},
                {'name': 'DL380 Gen10', 'contains': '380 g10', 'templates': ['HPE DL380 Gen10']},
                {'name': 'DL360 Gen10', 'contains': ['.360G10'], 'templates': ['HPE DL360 Gen10']},
                {'name': 'Gen9', 'regex': r'DL3[68]0\s*G(en)?9', 'templates': ['HPE Gen9']},
            ],
            'default': ['ICMP Ping'],
        },
    }
    data = nzs.deep_merge(data, overrides)
    with tempfile.NamedTemporaryFile('w', suffix='.yaml', delete=False) as fh:
        import yaml
        yaml.safe_dump(data, fh)
    try:
        return nzs.load_config(fh.name, environ=env)
    finally:
        os.unlink(fh.name)


class SyncTestCase(unittest.TestCase):
    def setUp(self):
        self.zbx = FakeZabbix()
        self.nb = FakeNetBox([device(1, 'srv-01'), device(2, 'srv-02', model='ProLiant DL360 Gen10',
                                                            ip='10.0.0.2/24', role='Hypervisor')])
        self.cfg = make_config()

    def sync(self, **kw):
        return nzs.Syncer(self.cfg, self.nb, self.zbx, **kw).run_full()


class TemplateMatchingTest(unittest.TestCase):
    def setUp(self):
        self.tcfg = make_config()['templates']

    def match(self, model):
        return nzs.match_templates(self.tcfg, device(1, 'x', model=model))[0]

    def test_normalize(self):
        self.assertEqual(nzs.normalize_model('ProLiant DL380 Gen10'), 'proliantdl380g10')
        self.assertEqual(nzs.normalize_model('.360G10'), '360g10')
        self.assertEqual(nzs.normalize_model('380 G9'), '380g9')

    def test_rules(self):
        self.assertEqual(self.match('ProLiant DL380 Gen10'), ['HPE DL380 Gen10'])
        self.assertEqual(self.match('DL380 G10'), ['HPE DL380 Gen10'])
        self.assertEqual(self.match('ProLiant DL380 Gen10 Plus'), ['HPE DL380 Gen10 Plus'])
        self.assertEqual(self.match('ProLiant DL360 Gen10'), ['HPE DL360 Gen10'])
        self.assertEqual(self.match('ProLiant DL380 Gen9'), ['HPE Gen9'])
        self.assertEqual(self.match('DL360 G9'), ['HPE Gen9'])
        self.assertEqual(self.match('Catalyst 9300'), ['ICMP Ping'])

    def test_custom_field_overrides_rules(self):
        self.tcfg['custom_field'] = 'zabbix_templates'
        dev = device(1, 'x', custom_fields={'zabbix_templates': 'A, B'})
        self.assertEqual(nzs.match_templates(self.tcfg, dev)[0], ['A', 'B'])


class CreateUpdateTest(SyncTestCase):
    def test_create_then_idempotent(self):
        s = self.sync()
        self.assertEqual(sorted(s.created), ['srv-01', 'srv-02'])
        h = self.zbx.host('srv-01')
        self.assertEqual(h['interfaces'][0]['ip'], '10.0.0.1')
        self.assertEqual(h['interfaces'][0]['port'], '10050')
        self.assertEqual(self.zbx.group_names('srv-01'), ['NetBox/Roles/Server', 'NetBox/Sites/DC1'])
        self.assertEqual(self.zbx.template_names('srv-01'), ['HPE DL380 Gen10'])
        self.assertEqual(self.zbx.template_names('srv-02'), ['HPE DL360 Gen10'])
        self.assertIn({'tag': 'netbox_device_id', 'value': '1'}, h['tags'])
        self.assertIn({'tag': 'netbox_sync', 'value': 'managed'}, h['tags'])

        self.zbx.calls = []
        s = self.sync()
        self.assertEqual(sorted(s.unchanged), ['srv-01', 'srv-02'])
        self.assertEqual(self.zbx.writes(), [])
        self.assertEqual(s.exit_code(), 0)

    def test_update_ip_role_model_keeps_manual(self):
        self.sync()
        h = self.zbx.host('srv-01')
        manual_gid = [k for k, v in self.zbx.groups.items() if v == 'Manual'][0]
        linux = [k for k, v in self.zbx.templates.items() if v == 'Linux by Zabbix agent'][0]
        h['groups'].append(manual_gid)
        h['templates'].append(linux)
        h['tags'].append({'tag': 'owner', 'value': 'team-a'})

        dev = self.nb.devices[0]
        dev['primary_ip4'] = {'address': '10.9.9.9/24'}
        dev['role'] = {'name': 'Storage', 'slug': 'storage'}
        dev['device_type']['model'] = 'ProLiant DL380 Gen10 Plus'
        s = self.sync()
        self.assertEqual(s.updated, ['srv-01'])
        self.assertEqual(h['interfaces'][0]['ip'], '10.9.9.9')
        self.assertEqual(self.zbx.group_names('srv-01'),
                         ['Manual', 'NetBox/Roles/Storage', 'NetBox/Sites/DC1'])
        self.assertEqual(self.zbx.template_names('srv-01'), ['HPE DL380 Gen10 Plus', 'Linux by Zabbix agent'])
        self.assertIn({'tag': 'owner', 'value': 'team-a'}, h['tags'])

    def test_inventory_idempotent(self):
        self.cfg['inventory']['enabled'] = True
        self.sync()
        self.assertEqual(self.zbx.host('srv-01')['inventory']['serialno_a'], 'SN1')
        self.assertEqual(self.sync().updated, [])
        self.nb.devices[0]['serial'] = 'NEW'
        self.assertEqual(self.sync().updated, ['srv-01'])
        self.assertEqual(self.zbx.host('srv-01')['inventory']['serialno_a'], 'NEW')

    def test_rename_by_device_id(self):
        self.sync()
        self.nb.devices[0]['name'] = 'srv-01-new'
        s = self.sync()
        self.assertEqual(s.updated, ['srv-01-new'])
        self.assertEqual(self.zbx.host('srv-01-new')['name'], 'srv-01-new')
        self.assertEqual(len(self.zbx.hosts), 2)

    def test_no_ip_skipped(self):
        self.nb.devices[0]['primary_ip4'] = None
        s = self.sync()
        self.assertEqual(s.created, ['srv-02'])
        self.assertEqual(s.skipped[0]['host'], 'srv-01')

    def test_existing_unmanaged_host(self):
        self.zbx.host_create({'host': 'srv-01', 'groups': [{'groupid': '101'}]})
        s = self.sync()
        self.assertEqual(s.skipped[0]['host'], 'srv-01')
        self.assertEqual(len(self.zbx.hosts), 2)
        self.cfg['zabbix']['adopt_existing'] = True
        s = self.sync()
        self.assertEqual(s.updated, ['srv-01'])
        self.assertIn({'tag': 'netbox_device_id', 'value': '1'}, self.zbx.host('srv-01')['tags'])

    def test_dry_run_writes_nothing(self):
        s = self.sync(dry_run=True)
        self.assertEqual(sorted(s.created), ['srv-01', 'srv-02'])
        self.assertEqual(self.zbx.writes(), [])

    def test_zabbix_error_isolated(self):
        self.nb.devices.insert(0, device(3, 'dup', ip='10.0.0.3/24'))
        self.zbx.host_create({'host': 'dup-x', 'groups': []})
        orig = self.zbx.host_create

        def failing(p):
            if p['host'] == 'dup':
                raise nzs.ZabbixError('host.create: boom')
            return orig(p)
        self.zbx.host_create = failing
        s = self.sync()
        self.assertEqual(sorted(s.created), ['srv-01', 'srv-02'])
        self.assertEqual(s.errors[0]['host'], 'dup')
        self.assertEqual(s.exit_code(), 1)


class DisableAndMaintenanceTest(SyncTestCase):
    def test_status_and_missing_disable(self):
        self.sync()
        self.zbx.host_create({'host': 'manual', 'groups': []})
        self.nb.devices[0]['status'] = {'value': 'staged'}
        del self.nb.devices[1]
        self.cfg['safety']['max_disable_ratio'] = 1
        s = self.sync()
        self.assertEqual(sorted(s.disabled), ['srv-01', 'srv-02'])
        self.assertEqual(self.zbx.host('srv-01')['status'], '1')
        self.assertIn({'tag': 'netbox_status', 'value': 'deleted'}, self.zbx.host('srv-02')['tags'])
        self.assertEqual(self.zbx.host('manual')['status'], '0')

        # back to active -> enabled again
        self.nb.devices[0]['status'] = {'value': 'active'}
        s = self.sync()
        self.assertEqual(s.updated, ['srv-01'])
        self.assertEqual(self.zbx.host('srv-01')['status'], '0')

    def test_safety_blocks_mass_disable(self):
        self.sync()
        self.nb.devices = []
        s = self.sync()
        self.assertTrue(s.fatal)
        self.assertEqual(s.exit_code(), 2)
        self.assertEqual(self.zbx.host('srv-01')['status'], '0')
        s = nzs.Syncer(self.cfg, self.nb, self.zbx).run_full(force=True)
        self.assertEqual(sorted(s.disabled), ['srv-01', 'srv-02'])

    def test_netbox_down_changes_nothing(self):
        self.sync()
        self.zbx.calls = []
        self.nb.fail = True
        with self.assertRaises(nzs.NetBoxError):
            self.sync()
        self.assertEqual(self.zbx.calls, [])

    def test_decommissioning_propose_then_approve(self):
        self.sync()
        self.nb.devices[0]['status'] = {'value': 'decommissioning'}
        s = self.sync()
        self.assertEqual(s.mm_proposed, ['srv-01'])
        self.assertIn({'tag': 'netbox_mm', 'value': 'proposed'}, self.zbx.host('srv-01')['tags'])
        self.assertEqual(self.zbx.maintenances, {})
        self.assertEqual(self.zbx.host('srv-01')['status'], '0')

        s = nzs.Syncer(self.cfg, self.nb, self.zbx).approve_maintenance(['all'])
        self.assertEqual(s.mm_created, ['srv-01'])
        mm = list(self.zbx.maintenances.values())[0]
        self.assertEqual(mm['name'], 'NetBox decommissioning: srv-01')
        self.assertGreater(int(mm['active_till']), time.time() + 71 * 3600)
        self.assertIn({'tag': 'netbox_mm', 'value': 'active'}, self.zbx.host('srv-01')['tags'])

        s = self.sync()  # already in maintenance -> nothing to do
        self.assertEqual(s.mm_proposed, [])
        self.assertEqual(s.mm_created, [])

        self.nb.devices[0]['status'] = {'value': 'active'}
        s = self.sync()
        self.assertEqual(s.mm_removed, ['srv-01'])
        self.assertEqual(self.zbx.maintenances, {})
        self.assertNotIn('netbox_mm', [t['tag'] for t in self.zbx.host('srv-01')['tags']])

    def test_decommissioning_interactive_and_auto(self):
        self.sync()
        self.nb.devices[0]['status'] = {'value': 'decommissioning'}
        s = nzs.Syncer(self.cfg, self.nb, self.zbx, interactive=True, prompt=lambda q: False).run_full()
        self.assertEqual((s.mm_created, s.mm_proposed), ([], []))
        s = nzs.Syncer(self.cfg, self.nb, self.zbx, interactive=True, prompt=lambda q: True).run_full()
        self.assertEqual(s.mm_created, ['srv-01'])

        self.zbx.maintenances = {}
        self.cfg['maintenance']['mode'] = 'auto'
        s = self.sync()
        self.assertEqual(s.mm_created, ['srv-01'])

    def test_webhook_partial_sync(self):
        self.sync()
        deleted = self.nb.devices.pop(0)
        s = nzs.Syncer(self.cfg, self.nb, self.zbx).run_ids([deleted['id'], 2])
        self.assertEqual(s.disabled, ['srv-01'])
        self.assertEqual(s.unchanged, ['srv-02'])


class FakeResponse(object):
    def __init__(self, data, status=200):
        self.data = data
        self.status_code = status

    def raise_for_status(self):
        if self.status_code >= 400:
            raise nzs.requests.HTTPError('HTTP %d' % self.status_code)

    def json(self):
        return self.data


class FakeSession(object):
    def __init__(self, responses):
        self.responses = list(responses)
        self.sent = []

    def post(self, url, json=None, headers=None, **kw):
        self.sent.append((json, dict(headers)))
        item = self.responses.pop(0)
        if isinstance(item, Exception):
            raise item
        return item


class ZabbixClientTest(unittest.TestCase):
    def setUp(self):
        self.sleep = nzs.time.sleep
        nzs.time.sleep = lambda s: None

    def tearDown(self):
        nzs.time.sleep = self.sleep

    def test_token_auth_header(self):
        session = FakeSession([FakeResponse({'result': '7.0.5'}), FakeResponse({'result': []}),
                               FakeResponse({'result': [{'hostid': '1'}]})])
        z = nzs.ZabbixAPI('https://zbx/', token='T', session=session)
        z.login()
        self.assertEqual(z.url, 'https://zbx/api_jsonrpc.php')
        self.assertEqual(z.call('host.get', {}), [{'hostid': '1'}])
        self.assertNotIn('Authorization', session.sent[0][1])
        self.assertEqual(session.sent[2][1]['Authorization'], 'Bearer T')

    def test_old_version_auth_in_body_and_logout(self):
        session = FakeSession([FakeResponse({'result': '6.0.30'}), FakeResponse({'result': 'SID'}),
                               FakeResponse({'result': []}), FakeResponse({'result': True})])
        z = nzs.ZabbixAPI('https://zbx', user='u', password='p', session=session)
        z.login()
        z.call('host.get', {})
        z.logout()
        self.assertEqual(session.sent[1][0]['params'], {'username': 'u', 'password': 'p'})
        self.assertEqual(session.sent[2][0]['auth'], 'SID')
        self.assertEqual(session.sent[3][0]['method'], 'user.logout')

    def test_api_error(self):
        session = FakeSession([FakeResponse({'error': {'message': 'Invalid params.', 'data': 'bad'}})])
        z = nzs.ZabbixAPI('https://zbx', token='T', session=session)
        with self.assertRaises(nzs.ZabbixError) as ctx:
            z.call('host.get', {})
        self.assertNotIsInstance(ctx.exception, nzs.ZabbixConnectionError)

    def test_retries(self):
        err = nzs.requests.ConnectionError('refused')
        session = FakeSession([err, FakeResponse({}, 502), FakeResponse({'result': []})])
        z = nzs.ZabbixAPI('https://zbx', token='T', session=session, retries=3)
        self.assertEqual(z.call('host.get', {}), [])
        # a write is not repeated after a read timeout: it may have been applied
        session = FakeSession([nzs.requests.ReadTimeout('slow'), FakeResponse({'result': {}})])
        z = nzs.ZabbixAPI('https://zbx', token='T', session=session, retries=3)
        with self.assertRaises(nzs.ZabbixConnectionError):
            z.call('host.create', {})
        self.assertEqual(len(session.sent), 1)


class ConfigAndWebhookTest(unittest.TestCase):
    def test_env_required(self):
        with self.assertRaises(nzs.ConfigError) as ctx:
            nzs.load_config(None, environ={'NETBOX_URL': 'x'})
        self.assertIn('netbox.token', str(ctx.exception))
        self.assertIn('ZABBIX_TOKEN', str(ctx.exception))

    def test_env_expansion_and_override(self):
        os.environ['NBZS_TEST_PW'] = 's3cret'
        try:
            cfg = make_config(zabbix={'token': None, 'user': 'api', 'password': '${NBZS_TEST_PW}'})
        finally:
            del os.environ['NBZS_TEST_PW']
        # ZABBIX_TOKEN from env wins over YAML; password from ${VAR}
        self.assertEqual(cfg['zabbix']['token'], 'zbt')
        self.assertEqual(cfg['zabbix']['password'], 's3cret')

    def test_bad_rule(self):
        with self.assertRaises(nzs.ConfigError):
            make_config(templates={'rules': [{'regex': '(', 'templates': ['x']}]})

    def test_signature(self):
        body = b'{"model": "device"}'
        sig = hmac.new(b'k', body, hashlib.sha512).hexdigest()
        self.assertTrue(nzs.verify_signature('k', body, sig))
        self.assertFalse(nzs.verify_signature('k', body, '00'))
        self.assertFalse(nzs.verify_signature('k', body, None))
        self.assertTrue(nzs.verify_signature(None, body, None))

    def test_parse_webhook(self):
        self.assertEqual(nzs.parse_webhook({'event': 'deleted', 'model': 'device', 'data': {'id': 5}}),
                         ('deleted', 5))
        self.assertIsNone(nzs.parse_webhook({'event': 'updated', 'model': 'site', 'data': {'id': 5}}))

    def test_cli_options_before_and_after_command(self):
        parser = nzs.build_parser()
        args = parser.parse_args(['-c', 'a.yaml', 'sync', '--dry-run'])
        self.assertEqual((args.config, args.dry_run, args.command), ('a.yaml', True, 'sync'))
        args = parser.parse_args(['check', '-c', 'b.yaml'])
        self.assertEqual((args.config, args.command), ('b.yaml', 'check'))

    def test_host_name_sanitized(self):
        self.assertEqual(nzs.zabbix_host_name('srv/01 (old)'), 'srv_01 _old_')


if __name__ == '__main__':
    unittest.main()
