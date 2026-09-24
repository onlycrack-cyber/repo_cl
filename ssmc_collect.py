#!/usr/bin/env python3
import sys, json, paramiko, socket

HOST = sys.argv[1] if len(sys.argv) > 1 else ''
SSH_USER = sys.argv[2] if len(sys.argv) > 2 else ''
SSH_PASS = sys.argv[3] if len(sys.argv) > 3 else ''
SECTION = sys.argv[4] if len(sys.argv) > 4 else 'system'

if not HOST:
    print(json.dumps({'error': '{$SSMC_HOST} is empty'}))
    sys.exit(0)

system = {'name': '', 'model': '', 'serial': '', 'systemVersion': '',
          'overallState': 1,
          'totalCapacityMiB': 0, 'allocatedCapacityMiB': 0, 'freeCapacityMiB': 0}
disks = []; volumes = []; ports = []; batteries = []; alerts = []; nodes = []

def run(client, cmd, timeout=10):
    try:
        i, o, e = client.exec_command(cmd, timeout=timeout)
        return o.read().decode(), e.read().decode().strip()
    except socket.timeout:
        return '', 'timeout'
    except Exception as ex:
        return '', str(ex)

def output(data):
    print(json.dumps(data))
    sys.exit(0)

try:
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=SSH_USER, password=SSH_PASS,
                  timeout=7, banner_timeout=7, allow_agent=False, look_for_keys=False)

    if SECTION == 'system':
        out, err = run(client, 'showsys')
        for line in out.splitlines():
            parts = line.strip().split()
            if len(parts) >= 8 and parts[0].isdigit():
                system['name'] = parts[1]
                system['model'] = parts[2]
                system['serial'] = parts[4] if len(parts) > 4 else ''
                nums = [int(p) for p in parts if p.isdigit()]
                if len(nums) >= 4:
                    system['totalCapacityMiB'] = nums[-4]
                    system['freeCapacityMiB'] = nums[-2]
                    system['allocatedCapacityMiB'] = nums[-3]
                break

        out, err = run(client, 'showport')
        for line in out.splitlines():
            parts = line.strip().split()
            if len(parts) < 4 or parts[0].count(':') != 2 or parts[0] == 'N:S:P':
                continue
            try:
                nsp = parts[0].split(':')
                ptype = parts[4] if len(parts) > 4 and parts[4] not in ('free', '-') else (
                    parts[5] if len(parts) > 5 and parts[5] not in ('-', '') else 'N/A')
                ports.append({
                    'portPos': {'node': nsp[0], 'slot': nsp[1], 'cardPort': nsp[2]},
                    'type': ptype, 'mode': parts[1],
                    'linkState': 4 if len(parts) > 2 and parts[2].lower() == 'ready' else 9
                })
            except (IndexError, ValueError):
                pass

        client.close()
        output({'error': None, 'system': system, 'ports': ports})

    elif SECTION == 'disks':
        disk_inv = {}
        out, err = run(client, 'showpd -i')
        for line in out.splitlines():
            parts = line.strip().split()
            if len(parts) >= 7 and parts[0].isdigit():
                try:
                    disk_inv[int(parts[0])] = dict(serial=parts[6], model=parts[5])
                except (ValueError, IndexError):
                    pass

        out, err = run(client, 'showpd')
        for line in out.splitlines():
            line = line.strip()
            if not line or line.startswith('--'):
                continue
            parts = line.split()
            if len(parts) < 2:
                continue
            if len(parts) >= 4 and parts[1].lower() == 'total' and parts[2].isdigit():
                continue
            if not parts[0].isdigit() or len(parts) < 7:
                continue
            try:
                did = int(parts[0])
                inv = disk_inv.get(did, {})
                pos = parts[1].split(':')
                cap = int(parts[5]) if parts[5].isdigit() else 0
                disks.append({
                    'id': did,
                    'state': {'normal': 1, 'degraded': 2, 'failed': 4, 'new': 3}.get(parts[4].lower(), 5),
                    'state_str': parts[4],
                    'position': {'cage': pos[0], 'mag': pos[1] if len(pos) > 1 else '',
                                'disk': pos[2] if len(pos) > 2 else ''},
                    'diskType': parts[2],
                    'capacityMiB': cap,
                    'serial': inv.get('serial', ''),
                    'model': inv.get('model', '')
                })
            except (ValueError, IndexError):
                pass

        client.close()
        output({'error': None, 'disks': disks})

    elif SECTION == 'volumes':
        out, err = run(client, 'showvv')
        if 'invalid' not in err.lower():
            for line in out.splitlines():
                parts = line.strip().split()
                if not parts[0].isdigit() or len(parts) < 11:
                    continue
                try:
                    known_states = {'normal', 'degraded', 'failed', 'copy', 'starting', 'stopping', 'unavailable'}
                    if len(parts) >= 13 and parts[7].lower() not in known_states:
                        state_str = parts[9]
                        usedMiB = int(parts[11])
                        sizeMiB = int(parts[12])
                    else:
                        state_str = parts[7]
                        usedMiB = int(parts[10])
                        sizeMiB = int(parts[11])
                    volumes.append(dict(
                        id=int(parts[0]), name=parts[1],
                        sizeMiB=sizeMiB, usedMiB=usedMiB,
                        provisioningType=parts[2],
                        state={'normal': 1, 'degraded': 2, 'failed': 4, 'copy': 3, 'starting': 3, 'stopping': 3, 'unavailable': 4}.get(state_str.lower(), 5),
                        state_str=state_str
                    ))
                except (ValueError, IndexError):
                    pass

        client.close()
        output({'error': None, 'volumes': volumes})

    elif SECTION == 'batteries':
        out, err = run(client, 'showbattery')
        for line in out.splitlines():
            parts = line.strip().split()
            if len(parts) >= 5 and parts[0].isdigit():
                try:
                    if parts[2].isalpha():
                        node, status_str = parts[0], parts[2]
                        batteries.append({'id': int(node), 'node': node, 'slot': '0',
                            'position': f'Node {node}', 'status': 4 if status_str.lower() in ('failed', 'degraded') else (1 if status_str.lower() in ('ok', 'good') else 5), 'status_str': status_str})
                    else:
                        node, slot = parts[1], parts[2]
                        status_str = parts[-2] if len(parts) >= 6 else parts[-1]
                        batteries.append({'id': int(parts[0]), 'node': node, 'slot': slot,
                            'position': f'Node {node} Batt {slot}', 'status': 4 if status_str.lower() in ('failed', 'degraded') else (1 if status_str.lower() in ('ok', 'good') else 5), 'status_str': status_str})
                except (ValueError, IndexError):
                    pass

        client.close()
        output({'error': None, 'batteries': batteries})

    elif SECTION == 'nodes':
        out, err = run(client, 'shownode')
        for line in out.splitlines():
            parts = line.strip().split()
            if len(parts) >= 5 and parts[0].isdigit():
                try:
                    status_str = parts[2]
                    status = 4 if status_str.lower() in ('failed', 'degraded', 'down') else (1 if status_str.lower() in ('ok', 'good', 'up') else 5)
                    nodes.append({'id': int(parts[0]), 'name': 'Node ' + parts[1].split(':')[0], 'model': parts[4] if len(parts) > 4 else '', 'status': status, 'status_str': status_str})
                except (ValueError, IndexError):
                    pass

        client.close()
        output({'error': None, 'nodes': nodes})

    elif SECTION == 'alerts':
        out, err = run(client, 'showalert')
        for line in out.splitlines():
            parts = line.strip().split()
            if len(parts) >= 7 and parts[0].isdigit():
                alerts.append({
                    'id': int(parts[0]),
                    'time': parts[1] + ' ' + parts[2],
                    'severity': parts[4],
                    'acknowledged': parts[5].lower() == 'y',
                    'message': ' '.join(parts[6:])
                })

        client.close()
        output({'error': None, 'alerts': alerts})

    else:
        client.close()
        output({'error': f'Unknown section: {SECTION}'})

except Exception as e:
    import traceback
    err = str(e) + '\n' + traceback.format_exc()
    if SECTION == 'system':
        output({'error': err, 'system': system, 'ports': ports})
    elif SECTION == 'disks':
        output({'error': err, 'disks': disks})
    elif SECTION == 'volumes':
        output({'error': err, 'volumes': volumes})
    elif SECTION == 'batteries':
        output({'error': err, 'batteries': batteries})
    elif SECTION == 'nodes':
        output({'error': err, 'nodes': nodes})
    elif SECTION == 'alerts':
        output({'error': err, 'alerts': alerts})
    else:
        output({'error': err})
