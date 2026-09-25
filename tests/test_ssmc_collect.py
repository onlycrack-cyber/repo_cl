import datetime
import json
import os
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SCRIPT = os.path.join(ROOT, 'ssmc_collect.py')
sys.path.insert(0, ROOT)
sys.path.insert(0, HERE)

import ssmc_collect as sc  # noqa: E402

FIX = os.path.join(HERE, 'fixtures')


def fixture(name):
    return sc.FixtureRunner(FIX).run(name)


def run_script(*args, env=None):
    full_env = dict(os.environ, SSMC_CONFIG='/nonexistent', HOME=tempfile.mkdtemp())
    for k in ('SSMC_SSH_USER', 'SSMC_SSH_PASS', 'SSMC_SSH_PASS_FILE'):
        full_env.pop(k, None)
    full_env.update(env or {})
    proc = subprocess.run([sys.executable, SCRIPT] + list(args), stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, env=full_env, timeout=60)
    return proc.returncode, json.loads(proc.stdout.decode()), proc.stderr.decode()


class ParserTests(unittest.TestCase):
    def test_showsys_model_with_space(self):
        s = sc.parse_showsys(fixture('showsys'))
        self.assertEqual((s['name'], s['model'], s['serial']), ('3PAR-ST01', 'HPE_3PAR 8200', 'CZ3812ABCD'))
        self.assertEqual(s['totalCapacityMiB'], 23068672)
        self.assertEqual(s['allocatedCapacityMiB'], 14680064)
        self.assertEqual(s['freeCapacityMiB'], 8388608)
        self.assertEqual(s['nodeCount'], 2)

    def test_showsys_model_without_space(self):
        s = sc.parse_showsys(' 1 arr01 7200c 1234567 2 0 100 60 40 0\n')
        self.assertEqual((s['model'], s['serial'], s['freeCapacityMiB']), ('7200c', '1234567', 40))

    def test_showport_type_and_states(self):
        ports = {p['pos']: p for p in sc.parse_showport(fixture('showport'))}
        self.assertEqual(len(ports), 8)
        self.assertEqual(ports['0:1:1']['type'], 'host')
        self.assertEqual(ports['0:1:2']['type'], 'free')
        self.assertEqual(ports['0:1:2']['linkState'], 5)
        self.assertEqual(ports['0:3:1']['linkState'], 11)
        self.assertEqual(ports['1:2:1']['protocol'], 'iSCSI')

    def test_showpd_with_inventory(self):
        disks = sc.parse_showpd(fixture('showpd'), sc.parse_showpd_inventory(fixture('showpd -i')))
        self.assertEqual([d['state'] for d in disks], [1, 1, 2, 4, 3])
        self.assertEqual(disks[3]['serial'], 'ZC11233')
        self.assertEqual(disks[2]['pos'], '0:2:0')

    def test_showvv_header_driven_and_old_layout(self):
        vols = {v['name']: v for v in sc.parse_showvv(fixture('showvv'))}
        self.assertEqual(vols['vol_db01']['usedMiB'], 409600)
        self.assertEqual(vols['vol_app01']['state'], 2)
        self.assertEqual(vols['vol_app01.snap']['usedMiB'], 0)
        old = ('  Id Name Prov Type CopyOf BsId Rd -Detailed_State- Adm Snp Usr VSize\n'
               '   1 v1   tpvv base ---       1 RW normal            0   0 50  100\n\n')
        self.assertEqual(sc.parse_showvv(old)[0]['utilPct'], 50.0)

    def test_showvv_blank_lines_do_not_crash(self):
        # The original script raised IndexError on empty lines and lost all volumes.
        self.assertEqual(sc.parse_showvv('\n\n'), [])

    def test_showbattery_unique_ids_and_status(self):
        bats = sc.parse_showbattery(fixture('showbattery'))
        self.assertEqual(len({b['id'] for b in bats}), 4)
        self.assertEqual([b['status'] for b in bats], [1, 1, 4, 1])
        self.assertEqual(bats[3]['expired'], 1)

    def test_shownode(self):
        nodes = sc.parse_shownode(fixture('shownode'))
        self.assertEqual([(n['id'], n['status']) for n in nodes], [(0, 1), (1, 2)])
        out = sc.parse_shownode('   0 X-0 OK Yes No Off Green 1 1 100\n')
        self.assertEqual(out[0]['status'], sc.STATE_FAILED)

    def test_showalert_block_format_case_insensitive_severity(self):
        alerts = sc.parse_showalert(fixture('showalert'))
        self.assertEqual([a['id'] for a in alerts], [101, 102, 103, 104])  # 105 is Fixed
        self.assertEqual([a['severity_code'] for a in alerts], [5, 4, 3, 2])
        summary = sc.summarize_alerts(alerts)
        self.assertEqual((summary['critical'], summary['major']), (1, 1))
        self.assertIn('Battery 0 Failed', summary['last_critical'])

    def test_showalert_fatal_counts_as_critical(self):
        alerts = sc.parse_showalert('Id : 7\nState : New\nSeverity : Fatal\nMessage : boom\n')
        self.assertEqual(sc.summarize_alerts(alerts)['critical'], 1)

    def test_showcert(self):
        now = int(time.time())
        certs = {c['id']: c for c in sc.parse_showcert(sc.FixtureRunner(FIX, now).run('showcert'), now)}
        self.assertEqual(set(certs), {'cim', 'unified-server', 'wsapi', 'syslog-sec-client', 'ldap-rootca'})
        self.assertAlmostEqual(certs['wsapi']['daysLeft'], 5, delta=0.01)
        self.assertLess(certs['syslog-sec-client']['daysLeft'], 0)
        self.assertEqual(certs['ldap-rootca']['cn'], 'Corp Root CA')

    def test_overall_state(self):
        data = sc.build_sections({c: fixture(c) for cmds in sc.SSH_SECTIONS.values() for c in cmds},
                                 set(sc.ALL_SECTIONS), int(time.time()))
        self.assertEqual(data['system']['overallState'], 2)
        state, reasons = sc.compute_overall_state({'system': {'nodeCount': 2}, 'nodes': [
            {'name': 'Node 0', 'status': 1, 'status_str': 'OK'}]})
        self.assertEqual(state, 3)
        self.assertIn('1 of 2 nodes present', reasons)

    def test_node_addrs(self):
        t = sc.parse_node_addrs('10.0.0.5', '0=10.0.0.11, 1=10.0.0.12,10.0.0.5,10.0.0.13')
        self.assertEqual([(x['label'], x['addr']) for x in t], [
            ('mgmt', '10.0.0.5'), ('Node 0', '10.0.0.11'), ('Node 1', '10.0.0.12'),
            ('10.0.0.13', '10.0.0.13')])

    def test_tls_endpoints(self):
        self.assertEqual(sc.parse_tls_endpoints('arr', '8080, ssmc:8443,bad'),
                         [('arr', 8080), ('ssmc', 8443)])


class CliTests(unittest.TestCase):
    def test_test_mode_is_valid_and_fast(self):
        start = time.monotonic()
        code, out, err = run_script('--test')
        self.assertLess(time.monotonic() - start, 5)
        self.assertEqual(code, 0, out)
        self.assertEqual(err, '')
        self.assertIsNone(out['error'])
        self.assertNotIn('validation', out)
        self.assertEqual(sc.validate(out), [])

    def test_missing_credentials_is_usage_error(self):
        code, out, _ = run_script('--host', '127.0.0.1')
        self.assertEqual(code, sc.EXIT_USAGE)
        self.assertIn('SSH user not set', out['error'])

    def test_unknown_section(self):
        code, out, _ = run_script('--host', 'x', '--section', 'bogus')
        self.assertEqual(code, sc.EXIT_USAGE)

    def test_password_not_accepted_on_command_line(self):
        proc = subprocess.run([sys.executable, SCRIPT, '--host', 'x', '--password', 'p'],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(proc.returncode, 2)  # argparse rejects it

    def test_unreachable_host_fails_fast_with_json(self):
        # Use a port nothing listens on: CI runners have a real sshd on 127.0.0.1:22.
        probe = socket.socket()
        probe.bind(('127.0.0.1', 0))
        closed_port = probe.getsockname()[1]
        probe.close()
        cfg = os.path.join(tempfile.mkdtemp(), 'ssmc.conf')
        with open(cfg, 'w') as fh:
            fh.write('[default]\nuser = u\npassword = p\nport = %d\n' % closed_port)
        start = time.monotonic()
        code, out, _ = run_script('--host', '127.0.0.1', '--section', 'system',
                                  '--connect-timeout', '2', '--config', cfg,
                                  env={'SSMC_KNOWN_HOSTS': '/dev/null'})
        self.assertLess(time.monotonic() - start, 5)
        self.assertEqual(code, sc.EXIT_FAILED)
        self.assertIn('SSH connect', out['error'])
        self.assertNotIn('system', out)


class FakeArrayTests(unittest.TestCase):
    def setUp(self):
        from fake_3par import Fake3PAR
        self.home = tempfile.mkdtemp()
        self.Fake3PAR = Fake3PAR

    def _collect(self, fake, sections='all', password='s3cret', **overrides):
        args = sc.build_arg_parser().parse_args([])
        for k, v in overrides.items():
            setattr(args, k.replace('-', '_'), v)
        creds = {'user': '3paradm', 'password': password, 'port': fake.port,
                 'known_hosts': os.path.join(self.home, 'kh')}
        return sc.collect('127.0.0.1', sc.parse_sections(sections), creds, args)

    def test_full_poll_single_connection_parallel(self):
        fake = self.Fake3PAR(delays={c: 0.5 for c in ('showpd', 'showpd -i', 'showvv', 'showalert')})
        try:
            start = time.monotonic()
            result, status = self._collect(fake)
            elapsed = time.monotonic() - start
        finally:
            fake.close()
        self.assertEqual(status, sc.EXIT_OK, result['errors'])
        self.assertEqual(fake.connections, 1)
        self.assertEqual(len(fake.commands), 9)
        self.assertLess(elapsed, 1.9, 'commands were not run in parallel')
        self.assertEqual(sc.validate(result), [])
        self.assertEqual(result['alert_summary']['critical'], 1)
        self.assertTrue(os.path.exists(os.path.join(self.home, 'kh')), 'TOFU host key not saved')

    def test_slow_command_times_out_others_survive(self):
        fake = self.Fake3PAR(delays={'showvv': 10})
        try:
            start = time.monotonic()
            result, status = self._collect(fake, command_timeout=1.5, deadline=4.0)
            elapsed = time.monotonic() - start
        finally:
            fake.close()
        self.assertLess(elapsed, 4.5)
        self.assertEqual(status, sc.EXIT_PARTIAL)
        self.assertIn('showvv', result['errors'])
        self.assertIn('Timeout', result['errors']['showvv'])
        self.assertNotIn('volumes', result)
        self.assertIn('disks', result)
        self.assertTrue(result['error'].startswith('partial:'))

    def test_bad_password(self):
        fake = self.Fake3PAR()
        try:
            result, status = self._collect(fake, 'system', password='wrong')
        finally:
            fake.close()
        self.assertEqual(status, sc.EXIT_FAILED)
        self.assertIn('authentication failed', result['error'])

    def test_host_key_change_is_rejected(self):
        first = self.Fake3PAR()
        try:
            self._collect(first, 'nodes')
        finally:
            first.close()
        second = self.Fake3PAR()  # new random host key
        try:
            with open(os.path.join(self.home, 'kh')) as fh:
                line = fh.read().split()
            with open(os.path.join(self.home, 'kh'), 'w') as fh:
                fh.write('[127.0.0.1]:%d %s %s\n' % (second.port, line[1], line[2]))
            result, status = self._collect(second, 'nodes')
        finally:
            second.close()
        self.assertEqual(status, sc.EXIT_FAILED)
        self.assertIn('host key mismatch', result['error'])

    def test_legacy_positional_mode(self):
        fake = self.Fake3PAR()
        try:
            # The legacy form has no port option, so point the connection at the fake.
            orig = sc.SSHRunner._connect

            def connect(runner, host, creds):
                creds = dict(creds, port=fake.port, known_hosts=os.path.join(self.home, 'kh'))
                return orig(runner, host, creds)
            sc.SSHRunner._connect = connect
            try:
                import io
                import contextlib
                buf = io.StringIO()
                with contextlib.redirect_stdout(buf):
                    code = sc.main(['127.0.0.1', '3paradm', 's3cret', 'disks'])
            finally:
                sc.SSHRunner._connect = orig
        finally:
            fake.close()
        out = json.loads(buf.getvalue())
        self.assertEqual(code, 0)
        self.assertIsNone(out['error'])
        self.assertEqual(set(out), {'error', 'disks', 'deprecated'})
        self.assertEqual(len(out['disks']), 5)


class ProbeTests(unittest.TestCase):
    def test_tls_probe_reads_expiry(self):
        from cryptography import x509
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import ec
        from cryptography.x509.oid import NameOID
        key = ec.generate_private_key(ec.SECP256R1())
        name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'array.test')])
        not_after = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0) + \
            datetime.timedelta(days=12)
        cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name)
                .public_key(key.public_key()).serial_number(1)
                .not_valid_before(not_after - datetime.timedelta(days=365))
                .not_valid_after(not_after).sign(key, hashes.SHA256()))
        d = tempfile.mkdtemp()
        with open(os.path.join(d, 'c.pem'), 'wb') as fh:
            fh.write(cert.public_bytes(serialization.Encoding.PEM))
        with open(os.path.join(d, 'k.pem'), 'wb') as fh:
            fh.write(key.private_bytes(serialization.Encoding.PEM,
                                       serialization.PrivateFormat.PKCS8,
                                       serialization.NoEncryption()))
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(os.path.join(d, 'c.pem'), os.path.join(d, 'k.pem'))
        srv = socket.socket()
        srv.bind(('127.0.0.1', 0))
        srv.listen(1)
        port = srv.getsockname()[1]

        def serve():
            conn, _ = srv.accept()
            try:
                ctx.wrap_socket(conn, server_side=True).close()
            except (ssl.SSLError, OSError):
                pass
        threading.Thread(target=serve, daemon=True).start()
        now = int(time.time())
        entry = sc.tls_probe('127.0.0.1', port, 3, now)
        srv.close()
        self.assertEqual(entry['cn'], 'array.test')
        self.assertEqual(entry['notAfter'], int(not_after.timestamp()))
        self.assertAlmostEqual(entry['daysLeft'], 12, delta=0.01)

    def test_net_section_tcp(self):
        srv = socket.socket()
        srv.bind(('127.0.0.1', 0))
        srv.listen(4)
        port = srv.getsockname()[1]
        closed = socket.socket()
        closed.bind(('127.0.0.1', 0))
        closed_port = closed.getsockname()[1]
        closed.close()
        code, out, _ = run_script('--host', '127.0.0.1', '--section', 'net',
                                  '--node-addrs', '0=127.0.0.2',
                                  '--probe-ports', '%d,%d' % (port, closed_port))
        srv.close()
        self.assertEqual(code, 0, out)
        net = {n['addr']: n for n in out['net']}
        self.assertEqual(net['127.0.0.1']['up'], 1)
        self.assertEqual(net['127.0.0.1']['tcp'], {str(port): 1, str(closed_port): 0})
        self.assertEqual(net['127.0.0.2']['label'], 'Node 0')
        self.assertEqual(sc.validate(out), [])


if __name__ == '__main__':
    unittest.main()
