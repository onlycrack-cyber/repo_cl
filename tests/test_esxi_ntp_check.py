"""Unit tests for esxi_ntp_check/esxi_health_check.py (pyVmomi is stubbed out)."""
import importlib.util
import os
import tempfile
import time
import sys
import types
import unittest
from unittest import mock

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCRIPT = os.path.join(ROOT, 'esxi_ntp_check', 'esxi_health_check.py')


def _load():
    pyvim = types.ModuleType('pyVim')
    connect = types.ModuleType('pyVim.connect')
    connect.SmartConnect = connect.Disconnect = lambda *a, **k: None
    pyvmomi = types.ModuleType('pyVmomi')
    pyvmomi.vim = types.SimpleNamespace()
    pyvmomi.vmodl = types.SimpleNamespace(query=types.SimpleNamespace())
    mods = {'pyVim': pyvim, 'pyVim.connect': connect, 'pyVmomi': pyvmomi}
    with mock.patch.dict(sys.modules, mods):
        spec = importlib.util.spec_from_file_location('esxi_health_check', SCRIPT)
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
    return mod


M = _load()


def rec(**kw):
    base = {'state': 'connected', 'configured': 1, 'ip': '10.0.0.1', 'mgmt_ip': '10.0.0.1',
            'vmk': ['10.0.0.1'], 'storage': ['online', 'online'],
            'dns': ['10.0.0.53', '10.0.1.53'], 'power': 'High performance'}
    base.update(kw)
    return base


class Storage(unittest.TestCase):
    def check(self, st):
        return M._check_value('storage', rec(storage=st), [])

    def test_all_online(self):
        self.assertEqual(self.check(['online', 'online']), 1)

    def test_one_of_two_offline_alerts(self):
        self.assertEqual(self.check(['online', 'offline']), 0)

    def test_unbound_alerts(self):
        self.assertEqual(self.check(['online', 'unbound']), 0)

    def test_unknown_ignored(self):
        self.assertEqual(self.check(['online', 'unknown']), 1)

    def test_only_unknown_is_not_a_problem(self):
        self.assertEqual(self.check(['unknown', 'unknown']), 1)

    def test_no_data_is_error_not_zero(self):
        self.assertIsNone(self.check([]))
        self.assertIsNone(self.check(None))


class Others(unittest.TestCase):
    def test_disconnected_host_gives_no_value_for_every_mode(self):
        r = rec(state='notResponding')
        for mode in ('ntp', 'vmk', 'storage', 'dns', 'power'):
            self.assertIsNone(M._check_value(mode, r, []), mode)

    def test_power(self):
        self.assertEqual(M._check_value('power', rec(), ['High performance']), 1)
        self.assertEqual(M._check_value('power', rec(power='Balanced'), ['High performance']), 0)
        self.assertEqual(M._check_value('power', rec(power='Balanced'), ['']), 0)  # empty = default
        self.assertIsNone(M._check_value('power', rec(power=None), []))

    def test_dns(self):
        self.assertEqual(M._check_value('dns', rec(), ['', '']), 1)
        self.assertEqual(M._check_value('dns', rec(dns=['10.0.0.53']), ['', '']), 0)
        self.assertEqual(M._check_value('dns', rec(), ['10.0.0.53', '10.0.1.53']), 1)
        self.assertEqual(M._check_value('dns', rec(), ['10.0.0.53', '9.9.9.9']), 0)
        self.assertIsNone(M._check_value('dns', rec(dns=None), []))

    def test_vmk(self):
        self.assertEqual(M._check_value('vmk', rec(), []), 1)
        self.assertEqual(M._check_value('vmk', rec(vmk=[]), []), 0)
        self.assertIsNone(M._check_value('vmk', rec(vmk=None), []))


class FindHost(unittest.TestCase):
    DATA = {'esx01.dc1.local': rec(ip='10.0.0.1'), 'esx10.dc1.local': rec(ip='10.0.0.10')}

    def test_exact_ip_and_unique_short(self):
        self.assertEqual(M._find_host(self.DATA, 'ESX01.dc1.local')[0], 'esx01.dc1.local')
        self.assertEqual(M._find_host(self.DATA, '10.0.0.10')[0], 'esx10.dc1.local')
        self.assertEqual(M._find_host(self.DATA, 'esx01')[0], 'esx01.dc1.local')

    def test_no_foreign_fallbacks(self):
        self.assertEqual(M._find_host(self.DATA, 'esx1'), (None, None))   # no substring
        self.assertEqual(M._find_host({'a.x': rec()}, 'gone.x'), (None, None))  # no "single host"

    def test_ambiguous_short_name(self):
        data = {'esx01.dc1': rec(), 'esx01.dc2': rec(ip='10.0.0.2')}
        self.assertEqual(M._find_host(data, 'esx01'), (None, None))


class Misc(unittest.TestCase):
    def test_vmk_addrs_prefers_vmk0(self):
        def vnic(dev, ip):
            ipc = types.SimpleNamespace(ipAddress=ip)
            return types.SimpleNamespace(device=dev, spec=types.SimpleNamespace(ip=ipc))
        addrs, mgmt = M._vmk_addrs([vnic('vmk1', '10.9.9.9'), vnic('vmk0', '10.0.0.1'),
                                    vnic('vmk2', '0.0.0.0')])
        self.assertEqual(addrs, ['10.9.9.9', '10.0.0.1'])
        self.assertEqual(mgmt, '10.0.0.1')

    def test_modes(self):
        self.assertEqual(M._resolve_mode(['x', 'hosts', 'u', 'a', 'b'])[::2], ('hosts', 2))
        self.assertEqual(M._resolve_mode(['x', 'dns', 'H', 'u', 'a', 'b'])[::2], ('dns', 3))
        self.assertEqual(M._resolve_mode(['x', 'Host.', 'u', 'a', 'b']), ('ntp', 'host', 2))


class O:
    """Hashable stand-in for a managed object."""


def full(**kw):
    return rec(ntp_offset=None, mm=False, pnic=None, sw=None, ts=time.time(), **kw)


class NtpConfigured(unittest.TestCase):
    def mk(self, servers=('pool.ntp.org',), running=True, lazy=False, svc=True):
        dts, sv = O(), O()
        info = types.SimpleNamespace(ntpConfig=types.SimpleNamespace(server=list(servers)))
        d = {'configManager.dateTimeSystem': dts, 'configManager.serviceSystem': sv if svc else None}
        props = {dts: {} if lazy else {'dateTimeInfo': info},
                 sv: {'service': [types.SimpleNamespace(key='ntpd', running=running)]}}
        if lazy:
            dts.dateTimeInfo = info
        return d, props

    def test_ok_and_not_running_and_no_servers(self):
        self.assertEqual(M._ntp_configured(*self.mk()), 1)
        self.assertEqual(M._ntp_configured(*self.mk(running=False)), 0)
        self.assertEqual(M._ntp_configured(*self.mk(servers=())), 0)
        self.assertEqual(M._ntp_configured(*self.mk(lazy=True)), 1)

    def test_unreadable_is_none_not_zero(self):
        self.assertIsNone(M._ntp_configured({}, {}))                          # no dateTimeSystem ref
        dts = O()
        class Bad(O):
            @property
            def dateTimeInfo(self):
                raise RuntimeError('boom')
        dts = Bad()
        self.assertIsNone(M._ntp_configured({'configManager.dateTimeSystem': dts}, {}))


class BatchOptional(unittest.TestCase):
    def test_bad_property_is_isolated_and_reported(self):
        def fake(content, objs, specs, errors=None):
            paths = specs[0][1]
            if 'bad' in paths:
                errors.append('InvalidProperty: bad')
                return {}
            return {h: {p: 1 for p in paths} for h, _ in objs}
        warn = []
        with mock.patch.object(M, '_batch_props', fake):
            got = M._batch_optional(None, ['h1'], ['a', 'bad', 'b'], warn, htype='HostSystem')
        self.assertEqual(got, {'h1': {'a': 1, 'b': 1}})
        self.assertEqual(len(warn), 1)
        self.assertIn('bad', warn[0])

    def test_all_good_single_call(self):
        calls = []
        def fake(content, objs, specs, errors=None):
            calls.append(specs)
            return {'h1': {'a': 1}}
        warn = []
        with mock.patch.object(M, '_batch_props', fake):
            got = M._batch_optional(None, ['h1'], ['a'], warn, htype='HostSystem')
        self.assertEqual((got, warn, len(calls)), ({'h1': {'a': 1}}, [], 1))


class CacheAndDeadline(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        p = mock.patch.object(M.tempfile, 'gettempdir', return_value=self.tmp.name)
        p.start()
        self.addCleanup(p.stop)

    def test_cache_name_is_per_user(self):
        name = os.path.basename(M._cache_file('https://vc'))
        self.assertIn('_%s.json' % os.getuid(), name)
        # not the cache file of the old esxi_ntp_check.py, which may run on the same server
        self.assertTrue(name.startswith('esxi_health_cache_'), name)

    def test_lock_failure_is_not_fatal(self):
        real = os.open
        def deny(path, *a, **k):
            if str(path).endswith('.lock'):
                raise PermissionError(13, 'denied')
            return real(path, *a, **k)
        with mock.patch.object(M.os, 'open', deny), \
                mock.patch.object(M, '_fetch', return_value={'a': full()}) as f:
            got = M._get_data('https://vc', 'u', 'p')
        self.assertIn('a', got)
        self.assertEqual(f.call_count, 1)

    def test_cached_second_call_does_not_fetch(self):
        with mock.patch.object(M, '_fetch', return_value={'a': full()}) as f:
            M._get_data('https://vc', 'u', 'p')
            M._get_data('https://vc', 'u', 'p')
        self.assertEqual(f.call_count, 1)

    def test_lld_rows_survive_a_disconnect(self):
        nics = {'vmnic0': {'up': 1, 'mb': 10000}}
        sws = {'vSwitch0': {'n': 1, 'up': 1}}
        with mock.patch.dict(os.environ, {'ESXI_NTP_REFRESH': '1'}):
            with mock.patch.object(M, '_fetch', return_value={'a': full(), 'b': full()}):
                first = M._get_data('https://vc', 'u', 'p')
            first['a']['pnic'], first['a']['sw'] = nics, sws
            with open(M._cache_file('https://vc'), 'w') as f:
                M.json.dump(first, f)
            down = full(state='notResponding')
            with mock.patch.object(M, '_fetch', return_value={'a': down, 'b': full()}):
                got = M._get_data('https://vc', 'u', 'p')
        self.assertEqual((got['a']['pnic'], got['a']['sw']), (nics, sws))
        out = M._build_collect(got, [])
        self.assertEqual(out['hosts']['a'], {'state': 'notResponding', 'alert': 0})
        self.assertEqual([r['{#NIC}'] for r in out['lld']['pnic']], ['vmnic0'])
        self.assertNotIn('a', out['pnic'])                  # значения не отдаём

    def test_deadline_exits_hard(self):
        with mock.patch.object(M.os, '_exit') as ex, mock.patch.object(M.sys, 'stderr'):
            M._on_deadline(14, None)
        ex.assert_called_once_with(1)

    def test_offsets_do_not_wait_for_queued_tasks(self):
        calls = []
        class Spy(M.ThreadPoolExecutor):
            def shutdown(self, wait=True, *, cancel_futures=False):
                calls.append((wait, cancel_futures))
                super().shutdown(wait=wait, cancel_futures=cancel_futures)
        with mock.patch.object(M, 'ThreadPoolExecutor', Spy):
            M._ntp_offsets(None, [])
            import datetime as dt
            now = dt.datetime(2026, 1, 1, tzinfo=dt.timezone.utc)
            M._ntp_offsets(now, [('a', types.SimpleNamespace(QueryDateTime=lambda: now))], workers=1)
        self.assertEqual(calls, [(False, True)])


if __name__ == '__main__':
    unittest.main()
