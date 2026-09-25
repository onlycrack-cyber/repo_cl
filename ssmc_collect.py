#!/usr/bin/env python3
"""HPE 3PAR / Primera collector for the "HPE SSMC StorageArray" Zabbix template.

Runs every CLI command for one poll over a single SSH session, in parallel
channels, and prints one JSON document for Zabbix dependent items to parse.

Sections (--section, comma separated):
  all        every SSH section below plus TLS certificate probes (default)
  system     showsys + showport          -> "system", "ports"
  disks      showpd + showpd -i          -> "disks"
  volumes    showvv                      -> "volumes"
  batteries  showbattery                 -> "batteries"
  nodes      shownode                    -> "nodes"
  alerts     showalert                   -> "alerts", "alert_summary"
  certs      showcert + TLS probes       -> "certificates"
  net        ICMP/TCP reachability of the array and its nodes (no SSH) -> "net"

Credentials are resolved from, in order: --user / --password /
--password-file, the SSMC_SSH_USER / SSMC_SSH_PASS / SSMC_SSH_PASS_FILE /
SSMC_SSH_KEY_FILE environment variables, and the [<host>] or [default]
section of the --config INI file. Empty values fall through to the next source.

--password exists for the Zabbix External check, where the template passes
the {$SSMC_SSH_PASS} Secret text macro. Arguments are visible to local users
in the process list while the script runs; use --password-file or the config
file where that matters. Pass it as --password=VALUE so a password starting
with "-" is not mistaken for an option.

Exit codes: 0 OK, 1 partial (some commands failed), 2 collection failed
(e.g. SSH unreachable), 3 usage or configuration error, 4 --validate failed.
A JSON document is printed on stdout in every case except an unknown option
(argparse prints usage to stderr and exits 2).
Zabbix 7.0 stores the output of an External check whatever its exit code
(verified on 7.0.31), so failures reach Zabbix as JSON ("error", "status")
and fire the collector triggers; the exit code is for cron/manual runs.

The deprecated positional form used by the old External check,
`ssmc_collect.py HOST USER PASS SECTION`, still works and always exits 0.
"""

import argparse
import configparser
import datetime
import json
import logging
import os
import re
import select
import shutil
import socket
import ssl
import stat
import subprocess
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor

VERSION = '2.1.0'

EXIT_OK = 0
EXIT_PARTIAL = 1
EXIT_FAILED = 2
EXIT_USAGE = 3
EXIT_INVALID = 4

SSH_SECTIONS = {
    'system': ('showsys', 'showport'),
    'disks': ('showpd', 'showpd -i'),
    'volumes': ('showvv',),
    'batteries': ('showbattery',),
    'nodes': ('shownode',),
    'alerts': ('showalert',),
    'certs': ('showcert',),
}
ALL_SECTIONS = ('system', 'disks', 'volumes', 'batteries', 'nodes', 'alerts', 'certs')
KNOWN_SECTIONS = set(ALL_SECTIONS) | {'all', 'net'}

DEFAULT_CONFIG = '/etc/zabbix/ssmc_collect.conf'
DEFAULT_KNOWN_HOSTS = '~/.ssh/ssmc_known_hosts'

# Generic component state codes shared by disks, volumes, nodes and batteries.
STATE_OK, STATE_DEGRADED, STATE_NEW, STATE_FAILED, STATE_UNKNOWN = 1, 2, 3, 4, 5

# Values match the 'HPE SSMC Port Link State' value map.
PORT_STATES = {
    'config_wait': 1, 'alpa_wait': 2, 'login_wait': 3, 'ready': 4,
    'loss_sync': 5, 'error_state': 6, 'error': 6, 'xxx': 7,
    'nonparticipate': 8, 'nonparticipating': 8, 'initializing': 9,
    'pending_reset': 10, 'offline': 11,
}
PORT_UNKNOWN = 0

# Values match the 'HPE SSMC Alert Severity' value map.
ALERT_SEVERITIES = {
    'debug': 1, 'info': 1, 'informational': 1, 'minor': 2,
    'warning': 3, 'warn': 3, 'degraded': 3,
    'major': 4, 'critical': 5, 'crit': 5, 'fatal': 6,
}
ALERT_CRITICAL = 5
ALERT_MAJOR = 4

CERT_DATE_RE = re.compile(
    r'([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{2}):(\d{2}):(\d{2})\s+(\d{4})(?:\s+(GMT|UTC))?')
MONTHS = {m: i for i, m in enumerate(
    ('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'), 1)}


class CollectorError(Exception):
    """Fatal problem that prevents any SSH section from being collected."""


class ConfigError(Exception):
    """Invalid arguments or configuration."""


# --------------------------------------------------------------------------
# Parsers: pure functions, CLI text in -> python structures out.
# Every parser skips lines it does not understand instead of failing.
# --------------------------------------------------------------------------

def _rows(text):
    for line in (text or '').splitlines():
        parts = line.split()
        if parts:
            yield parts


def _int(value, default=0):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _state(value, ok=('normal', 'ok', 'good', 'up'), degraded=('degraded',),
           failed=('failed', 'down', 'missing'), new=('new',)):
    v = (value or '').lower()
    if v in ok:
        return STATE_OK
    if v in degraded:
        return STATE_DEGRADED
    if v in failed:
        return STATE_FAILED
    if v in new:
        return STATE_NEW
    return STATE_UNKNOWN


def parse_showsys(text):
    """showsys: ID Name Model(may contain spaces) Serial Nodes Master Total Alloc Free Failed."""
    system = {'id': None, 'name': '', 'model': '', 'serial': '', 'nodeCount': 0,
              'totalCapacityMiB': 0, 'allocatedCapacityMiB': 0,
              'freeCapacityMiB': 0, 'failedCapacityMiB': 0}
    for parts in _rows(text):
        if len(parts) < 9 or not parts[0].isdigit():
            continue
        tail = parts[-6:]
        if not all(p.isdigit() for p in tail):
            continue
        system.update(
            id=int(parts[0]), name=parts[1],
            model=' '.join(parts[2:-7]), serial=parts[-7],
            nodeCount=int(tail[0]),
            totalCapacityMiB=int(tail[2]), allocatedCapacityMiB=int(tail[3]),
            freeCapacityMiB=int(tail[4]), failedCapacityMiB=int(tail[5]))
        break
    total = system['totalCapacityMiB']
    system['usedPct'] = round(100.0 * (total - system['freeCapacityMiB']) / total, 2) if total else 0.0
    return system


def parse_showport(text):
    """showport: N:S:P Mode State Node_WWN Port_WWN/HW_Addr Type Protocol ..."""
    ports = []
    for parts in _rows(text):
        if len(parts) < 3 or parts[0].count(':') != 2 or parts[0] == 'N:S:P':
            continue
        nsp = parts[0].split(':')
        state_str = parts[2]
        ports.append({
            'pos': parts[0],
            'portPos': {'node': nsp[0], 'slot': nsp[1], 'cardPort': nsp[2]},
            'mode': parts[1],
            'type': parts[5] if len(parts) > 5 else 'N/A',
            'protocol': parts[6] if len(parts) > 6 else 'N/A',
            'linkState': PORT_STATES.get(state_str.lower(), PORT_UNKNOWN),
            'linkState_str': state_str,
        })
    return ports


def parse_showpd_inventory(text):
    """showpd -i: Id CagePos State Node_WWN MFR Model Serial ..."""
    inv = {}
    for parts in _rows(text):
        if len(parts) >= 7 and parts[0].isdigit() and parts[1] != 'total':
            inv[int(parts[0])] = {'manufacturer': parts[4], 'model': parts[5], 'serial': parts[6]}
    return inv


def parse_showpd(text, inventory=None):
    """showpd: Id CagePos Type RPM State Total Free PortA PortB Capacity(GB)."""
    inventory = inventory or {}
    disks = []
    for parts in _rows(text):
        if len(parts) < 7 or not parts[0].isdigit() or parts[1] == 'total':
            continue
        did = int(parts[0])
        pos = (parts[1].split(':') + ['', '', ''])[:3]
        inv = inventory.get(did, {})
        disks.append({
            'id': did,
            'pos': parts[1],
            'position': {'cage': pos[0], 'mag': pos[1], 'disk': pos[2]},
            'diskType': parts[2],
            'state': _state(parts[4]),
            'state_str': parts[4],
            'capacityMiB': _int(parts[5]),
            'freeMiB': _int(parts[6]),
            'serial': inv.get('serial', ''),
            'model': inv.get('model', ''),
        })
    return disks


def parse_showvv(text):
    """showvv: columns located by header name, so 3.2.x and 3.3.x layouts both work."""
    volumes = []
    columns = None
    for parts in _rows(text):
        names = [p.strip('-') for p in parts]
        if 'Name' in names and 'Prov' in names:
            columns = {n: i for i, n in enumerate(names)}
            continue
        if columns is None or not parts[0].isdigit() or len(parts) != len(columns):
            continue
        state_col = columns.get('Detailed_State', columns.get('State'))
        state_str = parts[state_col] if state_col is not None else ''
        size = _int(parts[columns['VSize']]) if 'VSize' in columns else 0
        used = _int(parts[columns['Usr']]) if 'Usr' in columns else 0
        volumes.append({
            'id': int(parts[0]),
            'name': parts[columns['Name']],
            'provisioningType': parts[columns['Prov']],
            'type': parts[columns['Type']] if 'Type' in columns else '',
            'sizeMiB': size,
            'usedMiB': used,
            'utilPct': round(100.0 * used / size, 2) if size else 0.0,
            'state': _state(state_str, failed=('failed', 'unavailable'),
                            new=('copy', 'starting', 'stopping')),
            'state_str': state_str,
        })
    return volumes


def _parse_us_date(value):
    try:
        return int(datetime.datetime.strptime(value, '%m/%d/%Y')
                   .replace(tzinfo=datetime.timezone.utc).timestamp())
    except ValueError:
        return 0


def parse_showbattery(text):
    """showbattery: Node PS Bat Serial State ChrgLvl(%) ExpDate Expired Testing."""
    batteries = []
    for parts in _rows(text):
        if len(parts) < 5 or not (parts[0].isdigit() and parts[1].isdigit() and parts[2].isdigit()):
            continue
        node, ps, bat = parts[0], parts[1], parts[2]
        status_str = parts[4]
        batteries.append({
            'id': '%s.%s.%s' % (node, ps, bat),
            'node': node, 'ps': ps, 'slot': bat,
            'position': 'Node %s PS %s Batt %s' % (node, ps, bat),
            'serial': parts[3],
            'status': _state(status_str, failed=('failed', 'degraded', 'missing')),
            'status_str': status_str,
            'chargePct': _int(parts[5], None) if len(parts) > 5 else None,
            'expiry': _parse_us_date(parts[6]) if len(parts) > 6 else 0,
            'expired': 1 if len(parts) > 7 and parts[7].lower() == 'yes' else 0,
        })
    return batteries


def parse_shownode(text):
    """shownode: Node Name State Master InCluster Service_LED LED ..."""
    nodes = []
    for parts in _rows(text):
        if len(parts) < 5 or not parts[0].isdigit():
            continue
        status_str = parts[2]
        in_cluster = parts[4].lower() == 'yes'
        status = _state(status_str)
        if not in_cluster and status == STATE_OK:
            status = STATE_FAILED
        nodes.append({
            'id': int(parts[0]),
            'name': 'Node ' + parts[0],
            'nodeName': parts[1],
            'model': '',
            'master': parts[3].lower() == 'yes',
            'inCluster': in_cluster,
            'status': status,
            'status_str': status_str,
        })
    return nodes


def parse_showalert(text):
    """showalert prints 'Key : Value' blocks separated by blank lines.

    Alerts in state Fixed are dropped. A tabular one-line layout
    (Id Date Time TZ Severity Ack Message...) is also accepted.
    """
    alerts = []
    blocks = re.split(r'\n\s*\n', (text or '').strip())
    for block in blocks:
        fields = {}
        for line in block.splitlines():
            if ':' in line:
                key, _, value = line.partition(':')
                fields[key.strip().lower()] = value.strip()
        if 'id' in fields and fields['id'].isdigit():
            alerts.append({
                'id': int(fields['id']),
                'state': fields.get('state', ''),
                'code': fields.get('messagecode', ''),
                'time': fields.get('time', ''),
                'severity': fields.get('severity', ''),
                'type': fields.get('type', ''),
                'message': fields.get('message', ''),
                'acknowledged': fields.get('state', '').lower() == 'acknowledged',
            })
    if not alerts:
        for parts in _rows(text):
            if len(parts) >= 7 and parts[0].isdigit():
                alerts.append({
                    'id': int(parts[0]), 'state': 'New', 'code': '',
                    'time': parts[1] + ' ' + parts[2], 'severity': parts[4], 'type': '',
                    'message': ' '.join(parts[6:]),
                    'acknowledged': parts[5].lower() == 'y',
                })
    result = []
    for a in alerts:
        if a['state'].lower() == 'fixed':
            continue
        a['severity_code'] = ALERT_SEVERITIES.get(a['severity'].lower(), 0)
        result.append(a)
    return result


def summarize_alerts(alerts):
    critical = [a for a in alerts if a['severity_code'] >= ALERT_CRITICAL]
    major = [a for a in alerts if a['severity_code'] == ALERT_MAJOR]
    last = max(critical, key=lambda a: (a['time'], a['id'])) if critical else None
    return {
        'total': len(alerts),
        'critical': len(critical),
        'major': len(major),
        'last_critical': ('[%s] %s' % (last['id'], last['message'])) if last else '',
    }


def _cert_date_to_epoch(match):
    mon, day, hh, mm, ss, year = match.group(1, 2, 3, 4, 5, 6)
    if mon not in MONTHS:
        return None
    dt = datetime.datetime(int(year), MONTHS[mon], int(day), int(hh), int(mm), int(ss),
                           tzinfo=datetime.timezone.utc)
    return int(dt.timestamp())


def _cert_entry(cert_id, service, cn, ctype, source, not_after, now, fingerprint=''):
    return {
        'id': cert_id, 'service': service, 'cn': cn or 'N/A', 'type': ctype,
        'source': source, 'fingerprint': fingerprint,
        'notAfter': not_after,
        'daysLeft': round((not_after - now) / 86400.0, 2),
    }


def parse_showcert(text, now=None):
    """showcert: Service Commonname(may contain spaces) Type Enddate Fingerprint."""
    now = int(now if now is not None else time.time())
    certs = []
    seen = {}
    for line in (text or '').splitlines():
        match = CERT_DATE_RE.search(line)
        if not match:
            continue
        head = line[:match.start()].split()
        if len(head) < 2:
            continue
        not_after = _cert_date_to_epoch(match)
        if not_after is None:
            continue
        service, ctype = head[0], head[-1]
        cn = ' '.join(head[1:-1])
        cert_id = service if ctype == 'cert' else '%s-%s' % (service, ctype)
        seen[cert_id] = seen.get(cert_id, 0) + 1
        if seen[cert_id] > 1:
            cert_id = '%s-%d' % (cert_id, seen[cert_id])
        tail = line[match.end():].split()
        certs.append(_cert_entry(cert_id, service, cn, ctype, 'array', not_after, now,
                                 tail[0] if tail else ''))
    return certs


# --------------------------------------------------------------------------
# Derived state
# --------------------------------------------------------------------------

def compute_overall_state(data):
    """1=Normal, 2=Degraded, 3=Failed, 5=Unknown (value map 'HPE SSMC System State')."""
    if 'nodes' not in data:
        return STATE_UNKNOWN, ['node data not collected']
    failed, degraded = [], []
    nodes = data['nodes']
    expected = data.get('system', {}).get('nodeCount') or 0
    if expected and len(nodes) < expected:
        failed.append('%d of %d nodes present' % (len(nodes), expected))
    for n in nodes:
        if n['status'] == STATE_FAILED:
            failed.append('%s %s' % (n['name'], n['status_str']))
        elif n['status'] != STATE_OK:
            degraded.append('%s %s' % (n['name'], n['status_str']))
    for d in data.get('disks', []):
        if d['state'] in (STATE_DEGRADED, STATE_FAILED):
            degraded.append('disk %s %s' % (d['pos'], d['state_str']))
    for b in data.get('batteries', []):
        if b['status'] != STATE_OK:
            degraded.append('battery %s %s' % (b['id'], b['status_str']))
    for v in data.get('volumes', []):
        if v['state'] in (STATE_DEGRADED, STATE_FAILED):
            degraded.append('volume %s %s' % (v['name'], v['state_str']))
    if failed:
        return 3, failed + degraded
    if degraded:
        return 2, degraded
    return 1, []


# --------------------------------------------------------------------------
# Command runners
# --------------------------------------------------------------------------

class Deadline:
    def __init__(self, seconds):
        self.end = time.monotonic() + seconds

    def remaining(self, cap=None):
        left = max(0.0, self.end - time.monotonic())
        return min(left, cap) if cap is not None else left


class SSHRunner:
    """One SSH transport, one channel per command, bounded parallelism."""

    def __init__(self, host, creds, opts, deadline, warnings):
        try:
            import paramiko
        except ImportError:
            raise CollectorError('python module "paramiko" is not installed')
        # stdout carries the JSON for Zabbix; keep paramiko's log records off stderr.
        logging.getLogger('paramiko').addHandler(logging.NullHandler())
        logging.getLogger('paramiko').propagate = False
        self.paramiko = paramiko
        self.opts = opts
        self.deadline = deadline
        self.warnings = warnings
        self.client = paramiko.SSHClient()
        self._connect(host, creds)
        self.transport = self.client.get_transport()
        self.transport.set_keepalive(0)
        self.slots = threading.Semaphore(max(1, opts.parallel))

    def _connect(self, host, creds):
        paramiko = self.paramiko
        known_hosts = os.path.expanduser(creds.get('known_hosts') or DEFAULT_KNOWN_HOSTS)
        policy = (creds.get('host_key_policy') or 'tofu').lower()
        if os.path.exists(known_hosts):
            self.client.load_host_keys(known_hosts)
        if policy == 'strict':
            self.client.set_missing_host_key_policy(paramiko.RejectPolicy())
        elif policy in ('tofu', 'insecure'):
            self.client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
            if policy == 'insecure':
                self.warnings.append('host_key_policy=insecure: SSH host key is not verified')
        else:
            raise ConfigError('host_key_policy must be strict, tofu or insecure')
        t = self.deadline.remaining(self.opts.connect_timeout)
        try:
            self.client.connect(
                host, port=int(creds.get('port') or 22),
                username=creds['user'], password=creds.get('password') or None,
                key_filename=creds.get('key_file') or None,
                timeout=t, banner_timeout=t, auth_timeout=t,
                allow_agent=False, look_for_keys=False)
        except paramiko.BadHostKeyException as ex:
            raise CollectorError('SSH host key mismatch for %s (possible MITM, check %s): %s'
                                 % (host, known_hosts, ex))
        except paramiko.AuthenticationException:
            raise CollectorError('SSH authentication failed for user %r' % creds['user'])
        except (socket.timeout, TimeoutError):
            raise CollectorError('SSH connect to %s timed out' % host)
        except (paramiko.SSHException, OSError) as ex:
            raise CollectorError('SSH connect to %s failed: %s' % (host, ex))
        if policy == 'tofu':
            self._save_host_keys(known_hosts)

    def _save_host_keys(self, path):
        try:
            os.makedirs(os.path.dirname(path) or '.', mode=0o700, exist_ok=True)
            self.client.save_host_keys(path)
        except OSError as ex:
            self.warnings.append('cannot save host key to %s: %s' % (path, ex))

    def run(self, cmd):
        with self.slots:
            return self._run(cmd)

    def _run(self, cmd):
        timeout = self.deadline.remaining(self.opts.command_timeout)
        if timeout <= 0:
            raise TimeoutError('deadline exceeded before start')
        end = time.monotonic() + timeout
        try:
            chan = self.transport.open_session(timeout=timeout)
        except self.paramiko.ChannelException:
            # Some arrays cap concurrent CLI sessions; wait for others and retry once.
            time.sleep(0.5)
            chan = self.transport.open_session(timeout=max(0.1, end - time.monotonic()))
        out, err = [], []
        try:
            chan.exec_command(cmd)
            while True:
                if chan.recv_ready():
                    out.append(chan.recv(65536))
                elif chan.recv_stderr_ready():
                    err.append(chan.recv_stderr(65536))
                elif chan.exit_status_ready():
                    break
                else:
                    left = end - time.monotonic()
                    if left <= 0:
                        raise TimeoutError('timed out after %.1fs' % timeout)
                    select.select([chan], [], [], min(left, 0.2))
            status = chan.recv_exit_status()
        finally:
            chan.close()
        stdout = b''.join(out).decode('utf-8', 'replace')
        stderr = b''.join(err).decode('utf-8', 'replace').strip()
        if status != 0 and not stdout.strip():
            raise RuntimeError('exit %s: %s' % (status, stderr or 'no output'))
        return stdout

    def close(self):
        try:
            self.client.close()
        except Exception:
            pass


class FixtureRunner:
    """Serves recorded CLI output instead of SSH (used by --test)."""

    FILES = {'showpd -i': 'showpd_i.txt'}

    def __init__(self, directory, now=None):
        self.directory = directory
        self.now = int(now if now is not None else time.time())

    def run(self, cmd):
        name = self.FILES.get(cmd, cmd.split()[0] + '.txt')
        path = os.path.join(self.directory, name)
        if not os.path.exists(path):
            raise RuntimeError('no fixture %s' % path)
        with open(path) as fh:
            text = fh.read()
        return re.sub(r'\{\{now([+-]\d+)d\}\}', self._date, text)

    def _date(self, match):
        dt = datetime.datetime.fromtimestamp(self.now + int(match.group(1)) * 86400,
                                             tz=datetime.timezone.utc)
        return '%s %2d %s GMT' % (dt.strftime('%b'), dt.day, dt.strftime('%H:%M:%S %Y'))

    def close(self):
        pass


# --------------------------------------------------------------------------
# Network probes (no SSH, safe to run every minute)
# --------------------------------------------------------------------------

def tcp_probe(addr, port, timeout):
    start = time.monotonic()
    try:
        with socket.create_connection((addr, port), timeout=timeout):
            return 1, round((time.monotonic() - start) * 1000.0, 2)
    except OSError:
        return 0, None


PING_LOSS_RE = re.compile(r'([\d.]+)% packet loss')
PING_RTT_RE = re.compile(r'= [\d.]+/([\d.]+)/')


def icmp_probe(addr, count, timeout):
    """Uses the system ping binary (no root needed). Returns (loss%, avg rtt ms) or (None, None)."""
    ping = shutil.which('ping')
    if not ping:
        return None, None
    cmd = [ping, '-n', '-q', '-c', str(count), '-i', '0.2', '-W', '1', addr]
    try:
        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                              timeout=timeout, check=False)
    except (subprocess.TimeoutExpired, OSError):
        return 100.0, None
    text = proc.stdout.decode('utf-8', 'replace')
    loss = PING_LOSS_RE.search(text)
    rtt = PING_RTT_RE.search(text)
    if not loss:
        return 100.0, None
    return float(loss.group(1)), (float(rtt.group(1)) if rtt else None)


def parse_node_addrs(host, spec):
    """'0=10.0.0.11,1=10.0.0.12' or '10.0.0.11,10.0.0.12'. The array itself is always probed."""
    targets = [{'label': 'mgmt', 'role': 'mgmt', 'addr': host}]
    for item in (spec or '').replace(';', ',').split(','):
        item = item.strip()
        if not item:
            continue
        label, sep, addr = item.partition('=')
        if not sep:
            label, addr = item, item
        else:
            label = label.strip()
            label = 'Node ' + label if label.isdigit() else label
        addr = addr.strip()
        if addr and all(t['addr'] != addr for t in targets):
            targets.append({'label': label, 'role': 'node', 'addr': addr})
    return targets


def collect_net(host, spec, opts, deadline):
    targets = parse_node_addrs(host, spec)
    ports = [p for p in (_int(x, None) for x in opts.probe_ports.split(',')) if p]
    budget = deadline.remaining(opts.net_timeout)

    def probe(t):
        tcp = {}
        for port in ports:
            tcp[str(port)] = tcp_probe(t['addr'], port, min(budget, 2.0))[0]
        loss, rtt = icmp_probe(t['addr'], opts.ping_count, budget)
        icmp_ok = loss is not None and loss < 100.0
        entry = dict(t)
        entry.update({
            'tcp': tcp,
            'ssh': tcp.get('22', max(tcp.values()) if tcp else 0),
            'up': 1 if icmp_ok or any(tcp.values()) else 0,
        })
        # ICMP fields are omitted (not null) when unavailable, so Zabbix discards them.
        if loss is not None:
            entry['icmp'] = 1 if icmp_ok else 0
            entry['loss'] = loss
        if rtt is not None:
            entry['rtt'] = rtt
        return entry

    with ThreadPoolExecutor(max_workers=min(16, len(targets))) as pool:
        return list(pool.map(probe, targets))


def tls_probe(addr, port, timeout, now):
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    with socket.create_connection((addr, port), timeout=timeout) as sock:
        with ctx.wrap_socket(sock, server_hostname=addr) as tls:
            der = tls.getpeercert(binary_form=True)
    not_after, cn = _decode_der(der)
    return _cert_entry('tls-%s-%s' % (addr, port), 'tls:%s' % port, cn, 'cert',
                       'tls', not_after, now)


def _decode_der(der):
    try:
        from cryptography import x509
        from cryptography.x509.oid import NameOID
        cert = x509.load_der_x509_certificate(der)
        na = getattr(cert, 'not_valid_after_utc', None) or \
            cert.not_valid_after.replace(tzinfo=datetime.timezone.utc)
        cns = cert.subject.get_attributes_for_oid(NameOID.COMMON_NAME)
        return int(na.timestamp()), (cns[0].value if cns else '')
    except ImportError:
        import tempfile
        with tempfile.NamedTemporaryFile('w', suffix='.pem', delete=False) as fh:
            fh.write(ssl.DER_cert_to_PEM_cert(der))
        try:
            info = ssl._ssl._test_decode_cert(fh.name)  # pylint: disable=protected-access
        finally:
            os.unlink(fh.name)
        cn = ''
        for rdn in info.get('subject', ()):
            for k, v in rdn:
                if k == 'commonName':
                    cn = v
        return int(ssl.cert_time_to_seconds(info['notAfter'])), cn


def parse_tls_endpoints(host, spec):
    endpoints = []
    for item in (spec or '').split(','):
        item = item.strip()
        if not item:
            continue
        h, sep, p = item.rpartition(':')
        if not sep:
            h, p = host, item
        if _int(p, None):
            endpoints.append((h or host, int(p)))
    return endpoints


# --------------------------------------------------------------------------
# Collection
# --------------------------------------------------------------------------

def build_sections(runner_outputs, sections, now):
    """Turn raw command output into the JSON sections. Missing output -> section omitted."""
    out = runner_outputs
    data = {}
    if 'system' in sections and 'showsys' in out:
        data['system'] = parse_showsys(out['showsys'])
    if 'system' in sections and 'showport' in out:
        data['ports'] = parse_showport(out['showport'])
    if 'disks' in sections and 'showpd' in out:
        data['disks'] = parse_showpd(out['showpd'], parse_showpd_inventory(out.get('showpd -i', '')))
    if 'volumes' in sections and 'showvv' in out:
        data['volumes'] = parse_showvv(out['showvv'])
    if 'batteries' in sections and 'showbattery' in out:
        data['batteries'] = parse_showbattery(out['showbattery'])
    if 'nodes' in sections and 'shownode' in out:
        data['nodes'] = parse_shownode(out['shownode'])
        model = data.get('system', {}).get('model', '')
        for n in data['nodes']:
            n['model'] = model
    if 'alerts' in sections and 'showalert' in out:
        data['alerts'] = parse_showalert(out['showalert'])
        data['alert_summary'] = summarize_alerts(data['alerts'])
    if 'certs' in sections and 'showcert' in out:
        data['certificates'] = parse_showcert(out['showcert'], now)
    if 'system' in data:
        state, reasons = compute_overall_state(data)
        data['system']['overallState'] = state
        data['system']['stateReasons'] = reasons
    return data


def collect(host, sections, creds, opts, runner=None):
    started = time.monotonic()
    now = int(time.time())
    deadline = Deadline(opts.deadline)
    result = {'version': VERSION, 'host': host, 'timestamp': now,
              'sections': sorted(sections), 'error': None, 'errors': {}, 'warnings': []}
    warnings = result['warnings']
    fatal = None

    ssh_sections = [s for s in sections if s in SSH_SECTIONS]
    commands = []
    for s in ssh_sections:
        commands.extend(c for c in SSH_SECTIONS[s] if c not in commands)

    net_future = None
    pool = ThreadPoolExecutor(max_workers=max(2, opts.parallel + 1))
    try:
        if 'net' in sections:
            net_future = pool.submit(collect_net, host, opts.node_addrs, opts, deadline)
        tls_futures = {}
        if 'certs' in sections:
            for h, p in parse_tls_endpoints(host, opts.tls_endpoints):
                tls_futures['%s:%s' % (h, p)] = pool.submit(
                    tls_probe, h, p, deadline.remaining(opts.command_timeout), now)

        outputs = {}
        if commands:
            try:
                if runner is None:
                    runner = SSHRunner(host, creds, opts, deadline, warnings)
                try:
                    futures = {c: pool.submit(runner.run, c) for c in commands}
                    for cmd, fut in futures.items():
                        try:
                            outputs[cmd] = fut.result(timeout=deadline.remaining() + 1)
                        except Exception as ex:  # one command failing must not kill the poll
                            result['errors'][cmd] = '%s: %s' % (type(ex).__name__, ex) \
                                if str(ex) else type(ex).__name__
                finally:
                    runner.close()
            except CollectorError as ex:
                fatal = str(ex)
            result.update(build_sections(outputs, ssh_sections, now))

        if tls_futures:
            certs = result.setdefault('certificates', [])
            for name, fut in tls_futures.items():
                try:
                    certs.append(fut.result(timeout=deadline.remaining() + 1))
                except Exception as ex:
                    result['errors']['tls ' + name] = '%s: %s' % (type(ex).__name__, ex)
        if net_future is not None:
            try:
                result['net'] = net_future.result(timeout=deadline.remaining() + 1)
            except Exception as ex:
                result['errors']['net'] = '%s: %s' % (type(ex).__name__, ex)
    finally:
        pool.shutdown(wait=False)

    if fatal:
        result['error'] = fatal
        status = EXIT_FAILED
    elif result['errors']:
        result['error'] = 'partial: ' + '; '.join(
            '%s: %s' % kv for kv in sorted(result['errors'].items()))
        status = EXIT_PARTIAL
    else:
        status = EXIT_OK
    result['status'] = status
    result['duration'] = round(time.monotonic() - started, 3)
    return result, status


# --------------------------------------------------------------------------
# Output validation (used by --validate / --test; no external dependencies)
# --------------------------------------------------------------------------

SCHEMA = {
    'system': (dict, {'name': str, 'model': str, 'serial': str, 'overallState': int,
                      'totalCapacityMiB': int, 'allocatedCapacityMiB': int,
                      'freeCapacityMiB': int, 'usedPct': float}),
    'ports': (list, {'pos': str, 'type': str, 'mode': str, 'linkState': int}),
    'disks': (list, {'id': int, 'pos': str, 'state': int, 'diskType': str,
                     'capacityMiB': int, 'serial': str, 'model': str}),
    'volumes': (list, {'id': int, 'name': str, 'sizeMiB': int, 'usedMiB': int,
                       'utilPct': float, 'state': int}),
    'batteries': (list, {'id': str, 'position': str, 'status': int, 'expired': int}),
    'nodes': (list, {'id': int, 'name': str, 'status': int}),
    'alerts': (list, {'id': int, 'severity': str, 'severity_code': int, 'message': str}),
    'alert_summary': (dict, {'total': int, 'critical': int, 'major': int, 'last_critical': str}),
    'certificates': (list, {'id': str, 'service': str, 'notAfter': int, 'daysLeft': float}),
    'net': (list, {'addr': str, 'label': str, 'up': int, 'ssh': int}),
}


def validate(result):
    problems = []
    for key in ('version', 'host', 'timestamp', 'duration', 'status', 'errors'):
        if key not in result:
            problems.append('missing top-level key %r' % key)
    if not (result.get('error') is None or isinstance(result.get('error'), str)):
        problems.append('"error" must be null or string')
    for section, (container, fields) in SCHEMA.items():
        if section not in result:
            continue
        value = result[section]
        if not isinstance(value, container):
            problems.append('%s: expected %s' % (section, container.__name__))
            continue
        for i, row in enumerate(value if container is list else [value]):
            for field, ftype in fields.items():
                v = row.get(field)
                ok = isinstance(v, (int, float)) and not isinstance(v, bool) \
                    if ftype is float else isinstance(v, ftype) and not isinstance(v, bool)
                if not ok:
                    problems.append('%s[%d].%s: expected %s, got %r' % (section, i, field, ftype.__name__, v))
        if container is list:
            ids = [row.get('id', row.get('pos', row.get('addr'))) for row in value]
            if len(ids) != len(set(ids)):
                problems.append('%s: duplicate ids (LLD would reject them)' % section)
    try:
        json.loads(json.dumps(result))
    except (TypeError, ValueError) as ex:
        problems.append('not JSON serialisable: %s' % ex)
    return problems


# --------------------------------------------------------------------------
# Configuration / credentials
# --------------------------------------------------------------------------

def _read_secret_file(path, warnings):
    path = os.path.expanduser(path)
    st = os.stat(path)
    if st.st_mode & (stat.S_IRWXG | stat.S_IRWXO):
        warnings.append('%s is readable by group/others, chmod 600 it' % path)
    with open(path) as fh:
        return fh.read().strip()


def resolve_credentials(host, args, warnings):
    creds = {}
    cfg_path = args.config or os.environ.get('SSMC_CONFIG') or DEFAULT_CONFIG
    if os.path.exists(cfg_path):
        st = os.stat(cfg_path)
        if st.st_mode & (stat.S_IRWXG | stat.S_IRWXO) & ~stat.S_IRGRP:
            warnings.append('%s is accessible by others, chmod 640 it' % cfg_path)
        parser = configparser.ConfigParser(interpolation=None)
        try:
            parser.read(cfg_path)
        except configparser.Error as ex:
            raise ConfigError('cannot parse %s: %s' % (cfg_path, ex))
        for section in ('default', host):
            if parser.has_section(section):
                creds.update({k: v for k, v in parser.items(section) if v != ''})
    elif args.config:
        raise ConfigError('config file %s not found' % args.config)

    env = os.environ
    for key, var in (('user', 'SSMC_SSH_USER'), ('password', 'SSMC_SSH_PASS'),
                     ('password_file', 'SSMC_SSH_PASS_FILE'), ('key_file', 'SSMC_SSH_KEY_FILE'),
                     ('known_hosts', 'SSMC_KNOWN_HOSTS'),
                     ('host_key_policy', 'SSMC_HOST_KEY_POLICY')):
        if env.get(var):
            creds[key] = env[var]
    if args.user:
        creds['user'] = args.user
    if args.password:
        creds['password'] = args.password
        creds.pop('password_file', None)
    elif args.password_file:
        creds['password_file'] = args.password_file
        creds.pop('password', None)
    if creds.get('password_file') and not creds.get('password'):
        try:
            creds['password'] = _read_secret_file(creds['password_file'], warnings)
        except OSError as ex:
            raise ConfigError('cannot read password file: %s' % ex)
    if not creds.get('user'):
        raise ConfigError('SSH user not set (use --user, SSMC_SSH_USER or %s)' % cfg_path)
    if not creds.get('password') and not creds.get('key_file'):
        raise ConfigError('no SSH password or key for %s (use {$SSMC_SSH_PASS}, SSMC_SSH_PASS, '
                          '--password-file or %s)' % (host, cfg_path))
    return creds


def parse_sections(value):
    requested = {s.strip().lower() for s in value.split(',') if s.strip()}
    unknown = requested - KNOWN_SECTIONS
    if unknown:
        raise ConfigError('unknown section(s): %s' % ', '.join(sorted(unknown)))
    if 'all' in requested:
        requested.discard('all')
        requested.update(ALL_SECTIONS)
    return requested


def build_arg_parser():
    # allow_abbrev=False: otherwise e.g. '--pass' would silently match '--password-file'.
    p = argparse.ArgumentParser(description='HPE 3PAR collector for Zabbix (JSON on stdout).',
                                allow_abbrev=False)
    p.add_argument('--host', help='array management address')
    p.add_argument('--section', default='all', help='comma separated sections (default: all)')
    p.add_argument('--config', help='INI file with credentials (default %s)' % DEFAULT_CONFIG)
    p.add_argument('--user', help='SSH user')
    p.add_argument('--password', help='SSH password (use --password=VALUE; visible in the process list)')
    p.add_argument('--password-file', help='file containing only the SSH password')
    p.add_argument('--node-addrs', default='', help="node addresses, e.g. '0=10.0.0.11,1=10.0.0.12'")
    p.add_argument('--tls-endpoints', default='', help="TLS ports to check certs on, e.g. '8080,ssmc:8443'")
    p.add_argument('--probe-ports', default='22', help='TCP ports for the net section (default 22)')
    p.add_argument('--ping-count', type=int, default=3)
    p.add_argument('--parallel', type=int, default=4, help='concurrent CLI channels (default 4)')
    p.add_argument('--deadline', type=float, default=25.0,
                   help='hard time budget for the whole run, seconds (default 25)')
    p.add_argument('--connect-timeout', type=float, default=5.0)
    p.add_argument('--command-timeout', type=float, default=20.0)
    p.add_argument('--net-timeout', type=float, default=5.0)
    p.add_argument('--test', action='store_true',
                   help='use recorded CLI output from --fixtures instead of SSH; implies --validate')
    p.add_argument('--fixtures', default=os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                                      'tests', 'fixtures'))
    p.add_argument('--validate', action='store_true', help='check the output schema, exit 4 if invalid')
    p.add_argument('--pretty', action='store_true', help='indent JSON output')
    p.add_argument('--version', action='version', version=VERSION)
    return p


def _legacy_output(result, section):
    keys = {'system': ('system', 'ports'), 'disks': ('disks',), 'volumes': ('volumes',),
            'batteries': ('batteries',), 'nodes': ('nodes',), 'alerts': ('alerts',)}
    out = {'error': result.get('error'),
           'deprecated': 'positional arguments expose the password; see README'}
    for k in keys.get(section, ()):
        out[k] = result.get(k, {} if k == 'system' else [])
    return out


def main_legacy(argv):
    """Old External check contract: HOST USER PASS [SECTION]; always exit 0."""
    host, user, password = (argv + ['', '', ''])[:3]
    section = argv[3] if len(argv) > 3 else 'system'
    if not host:
        print(json.dumps({'error': '{$SSMC_HOST} is empty'}))
        return EXIT_OK
    if section not in SSH_SECTIONS:
        print(json.dumps({'error': 'Unknown section: %s' % section}))
        return EXIT_OK
    opts = build_arg_parser().parse_args([])
    try:
        result, _ = collect(host, {section}, {'user': user, 'password': password}, opts)
    except Exception as ex:  # legacy contract: JSON, never a traceback
        result = {'error': '%s: %s' % (type(ex).__name__, ex)}
    print(json.dumps(_legacy_output(result, section)))
    return EXIT_OK


def main(argv=None):
    argv = list(sys.argv[1:] if argv is None else argv)
    if argv and not argv[0].startswith('-'):
        return main_legacy(argv)

    parser = build_arg_parser()
    args = parser.parse_args(argv)
    host = args.host or ('127.0.0.1' if args.test else '')
    warnings = []
    try:
        if not host:
            raise ConfigError('--host is required')
        if args.parallel < 1 or args.deadline <= 0:
            raise ConfigError('--parallel must be >= 1 and --deadline > 0')
        sections = parse_sections(args.section)
        runner = None
        creds = {}
        if args.test:
            runner = FixtureRunner(args.fixtures)
        elif sections & set(SSH_SECTIONS):
            creds = resolve_credentials(host, args, warnings)
    except ConfigError as ex:
        print(json.dumps({'version': VERSION, 'host': host, 'error': 'config: %s' % ex,
                          'status': EXIT_USAGE}))
        return EXIT_USAGE

    try:
        result, status = collect(host, sections, creds, args, runner=runner)
    except Exception as ex:  # last resort: still hand Zabbix valid JSON
        result = {'version': VERSION, 'host': host, 'error': 'internal: %s: %s' % (type(ex).__name__, ex),
                  'errors': {}, 'status': EXIT_FAILED, 'timestamp': int(time.time()), 'duration': 0}
        status = EXIT_FAILED
    result['warnings'] = warnings + result.get('warnings', [])
    if args.test:
        result['test'] = True

    if args.validate or args.test:
        problems = validate(result)
        if problems:
            result['validation'] = problems
            status = result['status'] = EXIT_INVALID
    print(json.dumps(result, indent=2 if args.pretty else None, sort_keys=False))
    return status


if __name__ == '__main__':
    sys.exit(main())
