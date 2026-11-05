"""Unit tests for esxi_ntp_check/esxi_ntp_check.py (pyVmomi is stubbed out)."""
import importlib.util
import os
import sys
import types
import unittest
from unittest import mock

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCRIPT = os.path.join(ROOT, 'esxi_ntp_check', 'esxi_ntp_check.py')


def _load():
    pyvim = types.ModuleType('pyVim')
    connect = types.ModuleType('pyVim.connect')
    connect.SmartConnect = connect.Disconnect = lambda *a, **k: None
    pyvmomi = types.ModuleType('pyVmomi')
    pyvmomi.vim = types.SimpleNamespace()
    pyvmomi.vmodl = types.SimpleNamespace(query=types.SimpleNamespace())
    mods = {'pyVim': pyvim, 'pyVim.connect': connect, 'pyVmomi': pyvmomi}
    with mock.patch.dict(sys.modules, mods):
        spec = importlib.util.spec_from_file_location('esxi_ntp_check', SCRIPT)
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


if __name__ == '__main__':
    unittest.main()
