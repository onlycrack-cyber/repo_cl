#!/usr/bin/env python3
"""Generates the "VMware NSX by HTTP" template (Zabbix 7.0) as YAML and JSON.

Run: python3 vmware/tools/gen_nsx_template.py vmware/nsx

Collection model: HTTP agent master items poll the NSX Manager REST API and
return raw JSON (history 0); dependent items and dependent LLD rules parse it
with JSONPath, JavaScript only where JSONPath cannot express the logic
(status-string -> number mapping, counting, flattening nested lists).

The one exception is BGP: neighbour status lives under
/tier-0s/<t0>/locale-services/<ls>/bgp/neighbors/status, i.e. two levels of
discovery deep, and Zabbix 7.0 has no nested LLD. A Script item walks
tier-0s -> locale-services -> neighbours and returns one flat list.

Both output files are generated; do not edit them by hand.
"""
import hashlib
import json
import os
import sys

import yaml

T = 'VMware NSX by HTTP'
URL = '{$NSX.MANAGER.URL}'


def uid(name):
    h = bytearray(hashlib.md5(('vmware-nsx:' + name).encode()).digest())
    h[6] = (h[6] & 0x0F) | 0x40
    h[8] = (h[8] & 0x3F) | 0x80
    return h.hex()


def ref(key):
    return '/%s/%s' % (T, key)


def tags(component, **extra):
    out = [{'tag': 'component', 'value': component}]
    out += [{'tag': k, 'value': v} for k, v in extra.items()]
    return sorted(out, key=lambda t: (t['tag'], t['value']))


# ------------------------------------------------------------------ value maps
# Every NSX status string is mapped to a number so it can be graphed and
# compared in triggers; unknown strings become 0 instead of failing the item.
STATUS = {'UNKNOWN': 0, 'UP': 1, 'DEGRADED': 2, 'DOWN': 3}
CLUSTER = {'UNKNOWN': 0, 'STABLE': 1, 'DEGRADED': 2, 'UNSTABLE': 3, 'UNAVAILABLE': 4,
           'INITIALIZING': 5, 'NO_CONTROLLERS': 6}
BGP = {'UNKNOWN': 0, 'ESTABLISHED': 1, 'IDLE': 2, 'CONNECT': 3, 'ACTIVE': 4, 'OPEN_SENT': 5,
       'OPEN_CONFIRM': 6, 'INVALID': 7}
ALARM = {'RESOLVED': 0, 'OPEN': 1, 'ACKNOWLEDGED': 2, 'SUPPRESSED': 3}

VALUEMAPS = [
    ('NSX status', STATUS),
    ('NSX cluster status', CLUSTER),
    ('NSX BGP state', BGP),
    ('NSX alarm status', ALARM),
]


def map_js(mapping):
    """JavaScript step: NSX status string -> number from the value map (unknown -> 0)."""
    pairs = ', '.join('%s: %d' % (k, v) for k, v in sorted(mapping.items(), key=lambda kv: kv[1]))
    return ('var map = {%s};\n'
            'var v = String(value).toUpperCase();\n'
            'return map.hasOwnProperty(v) ? map[v] : 0;' % pairs)


def step(kind, *params, on_fail=None):
    s = {'type': kind}
    if params:
        s['parameters'] = list(params)
    if on_fail is not None:
        if on_fail == 'discard':
            s['error_handler'] = 'DISCARD_VALUE'
        else:
            s['error_handler'] = 'CUSTOM_VALUE'
            s['error_handler_params'] = on_fail
    return s


def jsonpath(path, on_fail=None):
    return step('JSONPATH', path, on_fail=on_fail)


def js(code):
    return step('JAVASCRIPT', code)


def heartbeat(period='1h'):
    return step('DISCARD_UNCHANGED_HEARTBEAT', period)


def status_steps(path, mapping, on_fail=None):
    return [jsonpath(path, on_fail), js(map_js(mapping))]


# ------------------------------------------------------------------ builders
def http(name, key, path, delay, component, desc, query=(), entity=None):
    it = {'uuid': uid('item:' + key), 'name': name, 'type': 'HTTP_AGENT', 'key': key, 'delay': delay,
          'history': '0', 'value_type': 'TEXT', 'trends': '0', 'authtype': 'BASIC',
          'username': '{$NSX.USER}', 'password': '{$NSX.PASSWORD}', 'description': desc,
          'timeout': '{$NSX.DATA.TIMEOUT}', 'url': URL + path}
    if query:
        it['query_fields'] = [{'name': n, 'value': v} for n, v in query]
    it['headers'] = [{'name': 'Accept', 'value': 'application/json'}]
    it['http_proxy'] = '{$NSX.HTTP.PROXY}'
    it['tags'] = tags('raw', **(entity or {}))
    return it


def dep(name, key, master, steps, component, value_type='UNSIGNED', units='', valuemap=None, desc='',
        history='7d', triggers=(), entity=None, proto=False):
    it = {'uuid': uid('item:' + key), 'name': name, 'type': 'DEPENDENT', 'key': key, 'delay': '0',
          'history': history, 'value_type': value_type}
    if value_type in ('CHAR', 'TEXT'):
        it['trends'] = '0'
    if units:
        it['units'] = units
    if desc:
        it['description'] = desc
    if valuemap:
        it['valuemap'] = {'name': valuemap}
    it['preprocessing'] = list(steps)
    it['master_item'] = {'key': master}
    it['tags'] = tags(component, **(entity or {}))
    if triggers:
        it['trigger_prototypes' if proto else 'triggers'] = list(triggers)
    return it


def trigger(name, expr, priority, component, desc='', opdata='', deps=(), recovery=None, manual_close=False,
            entity=None, scope='availability'):
    t = {'uuid': uid('trigger:' + name), 'expression': expr}
    if recovery:
        t['recovery_mode'] = 'RECOVERY_EXPRESSION'
        t['recovery_expression'] = recovery
    t['name'] = name
    if opdata:
        t['opdata'] = opdata
    t['priority'] = priority
    if desc:
        t['description'] = desc
    if manual_close:
        t['manual_close'] = 'YES'
    if deps:
        t['dependencies'] = [{'name': d['name'], 'expression': d['expression']} for d in deps]
    t['tags'] = sorted(tags(component, **(entity or {})) + [{'tag': 'scope', 'value': scope}],
                       key=lambda x: (x['tag'], x['value']))
    return t


def lld(name, key, master, steps, macro_paths, protos, desc, filters=(), lifetime='7d', overrides=(),
        trigger_protos=(), keep_enabled=False):
    r = {'uuid': uid('rule:' + key), 'name': name, 'type': 'DEPENDENT', 'key': key, 'delay': '0'}
    if filters:
        r['filter'] = {'evaltype': 'AND', 'conditions': [
            {'macro': m, 'value': v, 'operator': op, 'formulaid': chr(ord('A') + i)}
            for i, (m, op, v) in enumerate(filters)]}
    r['lifetime'] = lifetime
    if keep_enabled:
        # 7.0 disables lost entities immediately by default. Lost alarms must stay enabled, so their
        # item keeps receiving "RESOLVED" and the problem closes by itself.
        r['enabled_lifetime_type'] = 'DISABLE_NEVER'
    r['description'] = desc
    r['item_prototypes'] = list(protos)
    if trigger_protos:
        r['trigger_prototypes'] = list(trigger_protos)
    r['master_item'] = {'key': master}
    if macro_paths:
        r['lld_macro_paths'] = [{'lld_macro': m, 'path': p} for m, p in macro_paths]
    r['preprocessing'] = list(steps)
    if overrides:
        r['overrides'] = list(overrides)
    return r


def name_filters(macro, prefix):
    return [(macro, 'MATCHES_REGEX', '{$NSX.%s.NAME.MATCHES}' % prefix),
            (macro, 'NOT_MATCHES_REGEX', '{$NSX.%s.NAME.NOT_MATCHES}' % prefix)]


# ------------------------------------------------------------------ 1. management cluster
CL_GET = 'nsx.cluster.status.get'
cl_overall = 'nsx.cluster.overall_status'
cl_unavail = trigger('NSX: Management cluster is unavailable', 'last(%s)=4' % ref(cl_overall), 'HIGH', 'cluster',
                     desc='detailed_cluster_status.overall_status = UNAVAILABLE: the NSX Manager cluster lost '
                          'quorum or all nodes are down. The data plane keeps forwarding, but no configuration '
                          'changes or realization are possible.',
                     opdata='Status: {ITEM.LASTVALUE1}')
cl_nodata = trigger('NSX: No data from NSX Manager API for {$NSX.NODATA.TIMEOUT}',
                    'nodata(%s,{$NSX.NODATA.TIMEOUT})=1' % ref(cl_overall), 'AVERAGE', 'cluster',
                    desc='The master item "NSX: Get Cluster Status" returns no data: wrong URL or credentials, '
                         'TLS/proxy problem, the API is down, or the user is locked out (see the item error '
                         'under Monitoring > Latest data).')
cluster_items = [
    http('NSX: Get Cluster Status', CL_GET, '/api/v1/cluster/status', '{$NSX.INTERVAL.STATUS}', 'raw',
         'Collects the overall status of the NSX Management Cluster (management, control and per-service '
         'groups). Raw JSON for the dependent items.'),
    dep('NSX: Cluster ID', 'nsx.cluster.id', CL_GET, [jsonpath('$.cluster_id'), heartbeat('1d')], 'cluster',
        value_type='CHAR', history='31d'),
    dep('NSX: Cluster overall status', cl_overall, CL_GET,
        status_steps('$.detailed_cluster_status.overall_status', CLUSTER), 'cluster', valuemap='NSX cluster status',
        desc='detailed_cluster_status.overall_status: STABLE, DEGRADED or UNAVAILABLE.',
        triggers=[
            cl_unavail,
            trigger('NSX: Management cluster is not stable', 'last(%s)<>1' % ref(cl_overall), 'AVERAGE', 'cluster',
                    desc='One or more cluster service groups are degraded. Check System > Appliances in NSX.',
                    opdata='Status: {ITEM.LASTVALUE1}', deps=[cl_unavail]),
            cl_nodata,
        ]),
    dep('NSX: Management cluster status', 'nsx.cluster.mgmt_status', CL_GET,
        status_steps('$.mgmt_cluster_status.status', CLUSTER), 'cluster', valuemap='NSX cluster status',
        desc='mgmt_cluster_status.status (management plane: Corfu datastore, Proton).',
        triggers=[trigger('NSX: Management plane cluster is not stable',
                          'last(%s)<>1' % ref('nsx.cluster.mgmt_status'), 'AVERAGE', 'cluster',
                          opdata='Status: {ITEM.LASTVALUE1}', deps=[cl_unavail])]),
    dep('NSX: Control cluster status', 'nsx.cluster.control_status', CL_GET,
        status_steps('$.control_cluster_status.status', CLUSTER), 'cluster', valuemap='NSX cluster status',
        desc='control_cluster_status.status (Central Control Plane).',
        triggers=[trigger('NSX: Control plane cluster is not stable',
                          'last(%s)<>1' % ref('nsx.cluster.control_status'), 'AVERAGE', 'cluster',
                          opdata='Status: {ITEM.LASTVALUE1}', deps=[cl_unavail])]),
    dep('NSX: Manager nodes online', 'nsx.cluster.nodes.online', CL_GET,
        [jsonpath('$.mgmt_cluster_status.online_nodes.length()', '0')], 'cluster'),
    dep('NSX: Manager nodes offline', 'nsx.cluster.nodes.offline', CL_GET,
        [jsonpath('$.mgmt_cluster_status.offline_nodes.length()', '0')], 'cluster',
        triggers=[trigger('NSX: {ITEM.LASTVALUE1} Manager node(s) offline',
                          'last(%s)>0' % ref('nsx.cluster.nodes.offline'), 'HIGH', 'cluster',
                          desc='A three-node NSX Manager cluster survives the loss of one node; losing a second '
                               'one makes the cluster read-only.', deps=[cl_unavail])]),
]

# Service groups (MANAGER, CONTROLLER, POLICY, HTTPS, DATASTORE, ...) from the same response.
GROUP = "$.detailed_cluster_status.groups[?(@.group_type=='{#GROUP.TYPE}')]"
MEMBERS_DOWN_JS = '''var members = JSON.parse(value), down = 0;
for (var i = 0; i < members.length; i++) {
    if (String(members[i].member_status).toUpperCase() !== 'UP') {
        down++;
    }
}
return down;'''
group_status = 'nsx.cluster.group.status[{#GROUP.TYPE}]'
group_rule = lld(
    'NSX: Cluster service groups discovery', 'nsx.cluster.group.discovery', CL_GET,
    [jsonpath('$.detailed_cluster_status.groups'), heartbeat()],
    [('{#GROUP.TYPE}', '$.group_type'), ('{#GROUP.ID}', '$.group_id')],
    [
        dep('NSX: Service group [{#GROUP.TYPE}]: status', group_status, CL_GET,
            status_steps(GROUP + '.group_status.first()', CLUSTER), 'cluster', valuemap='NSX cluster status',
            entity={'group': '{#GROUP.TYPE}'}, proto=True,
            triggers=[trigger('NSX: Service group [{#GROUP.TYPE}] is not stable',
                              'last(%s)<>1' % ref(group_status), 'AVERAGE', 'cluster',
                              opdata='Status: {ITEM.LASTVALUE1}', deps=[cl_unavail],
                              entity={'group': '{#GROUP.TYPE}'})]),
        dep('NSX: Service group [{#GROUP.TYPE}]: members not UP', 'nsx.cluster.group.down[{#GROUP.TYPE}]', CL_GET,
            [jsonpath(GROUP + '.members.first()'), js(MEMBERS_DOWN_JS)], 'cluster',
            entity={'group': '{#GROUP.TYPE}'}),
    ],
    'Service groups of the management cluster from detailed_cluster_status.groups.')

# ------------------------------------------------------------------ transport node helpers
TN_PATH = '/api/v1/transport-nodes/{#%s}/status'
TN_QUERY = [('source', 'cached')]
EDGE_MEM_JS = '''var s = (JSON.parse(value).node_status || {}).system_status || {};
if (s.edge_mem_usage && s.edge_mem_usage.system_mem_usage !== undefined) {
    return s.edge_mem_usage.system_mem_usage;
}
if (s.mem_total) {
    return 100 * s.mem_used / s.mem_total;
}
throw 'node_status.system_status reports no memory usage';'''


def node_status_items(prefix, macro, label, component, entity, node_down):
    """Status, manager/controller connectivity and overlay tunnel items of one transport node."""
    get = 'nsx.%s.status.get[{#%s}]' % (prefix, macro)
    k = lambda s: 'nsx.%s.%s[{#%s}]' % (prefix, s, macro)
    tun_down = trigger('%s: all overlay tunnels are down' % label, 'last(%s)=3' % ref(k('tunnel.status')), 'HIGH',
                       component, desc='tunnel_status.status = DOWN: no GENEVE tunnel (TEP to TEP) is up. '
                                       'Check TEP IP pool, MTU (>= 1600, 1700 recommended) and the transport VLAN.',
                       deps=[node_down], entity=entity)
    tun_degr = trigger('%s: overlay tunnels degraded' % label, 'last(%s)=2' % ref(k('tunnel.status')), 'AVERAGE',
                       component, desc='Some GENEVE tunnels or their BFD sessions are down.',
                       deps=[node_down, tun_down], entity=entity)
    return get, k, [
        dep('%s: overall status' % label, k('status'), get, status_steps('$.status', STATUS) + [heartbeat()],
            component, valuemap='NSX status', entity=entity, proto=True, desc='Transport node status: UP, DEGRADED, '
            'DOWN or UNKNOWN (aggregates manager/controller connectivity, tunnels and pNICs).',
            triggers=[node_down, trigger('%s: status is DEGRADED' % label, 'last(%s)=2' % ref(k('status')),
                                         'AVERAGE', component, deps=[node_down], entity=entity)]),
        dep('%s: manager connectivity' % label, k('mgmt_conn'), get,
            status_steps('$.mgmt_connection_status', STATUS) + [heartbeat()], component, valuemap='NSX status',
            entity=entity, proto=True,
            triggers=[trigger('%s: lost connection to NSX Manager' % label, 'last(%s)=3' % ref(k('mgmt_conn')),
                              'AVERAGE', component, deps=[node_down], entity=entity)]),
        dep('%s: controller connectivity' % label, k('ctrl_conn'), get,
            status_steps('$.control_connection_status.status', STATUS) + [heartbeat()], component,
            valuemap='NSX status', entity=entity, proto=True,
            triggers=[trigger('%s: lost connection to the Central Control Plane' % label,
                              'last(%s)=3' % ref(k('ctrl_conn')), 'AVERAGE', component, deps=[node_down],
                              entity=entity)]),
        dep('%s: overlay tunnels status' % label, k('tunnel.status'), get,
            status_steps('$.tunnel_status.status', STATUS) + [heartbeat()], component, valuemap='NSX status',
            entity=entity, proto=True, triggers=[tun_down, tun_degr], desc='tunnel_status.status of GENEVE tunnels. UNKNOWN '
            'is normal for a host without workloads on overlay segments (no tunnels needed).'),
        dep('%s: overlay tunnels up' % label, k('tunnel.up'), get, [jsonpath('$.tunnel_status.up_count', '0')],
            component, entity=entity),
        dep('%s: overlay tunnels down' % label, k('tunnel.down'), get, [jsonpath('$.tunnel_status.down_count', '0')],
            component, entity=entity, proto=True,
            triggers=[trigger('%s: {ITEM.LASTVALUE1} overlay tunnel(s) down' % label,
                              'last(%s)>0' % ref(k('tunnel.down')), 'WARNING', component,
                              deps=[node_down, tun_down], entity=entity)]),
        dep('%s: overlay tunnels degraded (status)' % label, k('tunnel.degraded'), get,
            [jsonpath('$.tunnel_status.degraded_count', '0')], component, entity=entity),
        dep('%s: pNIC status' % label, k('pnic.status'), get,
            status_steps('$.pnic_status.status', STATUS, on_fail='UNKNOWN') + [heartbeat()], component,
            valuemap='NSX status', entity=entity, proto=True,
            triggers=[trigger('%s: physical uplink(s) degraded or down' % label,
                              'last(%s)=2 or last(%s)=3' % (ref(k('pnic.status')), ref(k('pnic.status'))),
                              'WARNING', component, deps=[node_down], entity=entity)]),
    ]


# ------------------------------------------------------------------ 3A. host transport nodes
TN_GET = 'nsx.transport_nodes.get'
host_entity = {'node': '{#NODE.NAME}'}
host_down = trigger('Host TN [{#NODE.NAME}]: status is DOWN', 'last(%s)=3' % ref('nsx.tn.status[{#NODE.ID}]'),
                    'HIGH', 'transport-node', entity=host_entity,
                    desc='The ESXi host transport node is DOWN: workloads on it lose overlay connectivity.')
tn_get, _, host_items = node_status_items('tn', 'NODE.ID', 'Host TN [{#NODE.NAME}]', 'transport-node',
                                          host_entity, host_down)
host_rule = lld(
    'NSX: Host transport nodes discovery', 'nsx.tn.host.discovery', TN_GET,
    [jsonpath('$.results'), heartbeat()],
    [('{#NODE.ID}', '$.id'), ('{#NODE.NAME}', '$.display_name'),
     ('{#NODE.TYPE}', '$.node_deployment_info.resource_type')],
    [http('Host TN [{#NODE.NAME}]: Get status', tn_get, TN_PATH % 'NODE.ID', '{$NSX.INTERVAL.NODE}',
          'raw', 'GET /api/v1/transport-nodes/<id>/status of an ESXi host transport node.', TN_QUERY,
          entity=host_entity)] + host_items,
    'ESXi host transport nodes (node_deployment_info.resource_type = HostNode). Edge nodes are discovered '
    'from edge clusters.',
    filters=[('{#NODE.TYPE}', 'MATCHES_REGEX', '^HostNode$')] + name_filters('{#NODE.NAME}', 'TN'))

transport_items = [
    http('NSX: Get transport nodes', TN_GET, '/api/v1/transport-nodes', '{$NSX.INTERVAL.LLD}', 'raw',
         'List of transport nodes (ESXi hosts and Edges) for discovery.', [('page_size', '1000')]),
    dep('NSX: Transport nodes total', 'nsx.transport_nodes.count', TN_GET, [jsonpath('$.result_count')],
        'transport-node', history='31d'),
]

# ------------------------------------------------------------------ 3B. edge clusters and edge nodes
EC_GET = 'nsx.edge_clusters.get'
EDGE_LLD_JS = '''var clusters = JSON.parse(value).results || [], out = [];
for (var i = 0; i < clusters.length; i++) {
    var c = clusters[i], members = c.members || [];
    for (var j = 0; j < members.length; j++) {
        var m = members[j];
        out.push({
            '{#EDGE.ID}': m.transport_node_id,
            '{#EDGE.NAME}': m.display_name || m.transport_node_id,
            '{#EDGE.INDEX}': String(m.member_index),
            '{#EC.ID}': c.id,
            '{#EC.NAME}': c.display_name || c.id
        });
    }
}
return JSON.stringify(out);'''
ec_entity = {'edge-cluster': '{#EC.NAME}'}
ec_status = 'nsx.ec.status[{#EC.ID}]'
ec_down = trigger('Edge cluster [{#EC.NAME}]: status is DOWN', 'last(%s)=3' % ref(ec_status), 'HIGH',
                  'edge-cluster', entity=ec_entity,
                  desc='No member of the edge cluster is UP: north-south routing and stateful services of '
                       'the gateways placed on it are down.')
ec_rule = lld(
    'NSX: Edge clusters discovery', 'nsx.ec.discovery', EC_GET, [jsonpath('$.results'), heartbeat()],
    [('{#EC.ID}', '$.id'), ('{#EC.NAME}', '$.display_name')],
    [
        http('Edge cluster [{#EC.NAME}]: Get status', 'nsx.ec.status.get[{#EC.ID}]',
             '/api/v1/edge-clusters/{#EC.ID}/status', '{$NSX.INTERVAL.NODE}', 'raw',
             'GET /api/v1/edge-clusters/<id>/status.', entity=ec_entity),
        dep('Edge cluster [{#EC.NAME}]: status', ec_status, 'nsx.ec.status.get[{#EC.ID}]',
            status_steps('$.edge_cluster_status', STATUS) + [heartbeat()], 'edge-cluster', valuemap='NSX status',
            entity=ec_entity, proto=True,
            triggers=[ec_down, trigger('Edge cluster [{#EC.NAME}]: status is DEGRADED', 'last(%s)=2' % ref(ec_status),
                                       'AVERAGE', 'edge-cluster', deps=[ec_down], entity=ec_entity,
                                       desc='At least one edge node of the cluster is not UP: no redundancy.')]),
    ],
    'Edge clusters from /api/v1/edge-clusters.', filters=name_filters('{#EC.NAME}', 'EC'))

edge_entity = {'edge': '{#EDGE.NAME}', 'edge-cluster': '{#EC.NAME}'}
edge_label = 'Edge [{#EDGE.NAME}]'
edge_down = trigger('%s: status is DOWN' % edge_label, 'last(%s)=3' % ref('nsx.edge.status[{#EDGE.ID}]'), 'HIGH',
                    'edge', entity=edge_entity,
                    desc='Edge node {#EDGE.NAME} (member {#EDGE.INDEX} of edge cluster {#EC.NAME}) is DOWN. '
                         'Its SR instances failed over to the peer, if there is one.')
edge_get, ek, edge_items = node_status_items('edge', 'EDGE.ID', edge_label, 'edge', edge_entity, edge_down)
SYS = '$.node_status.system_status'


def util_trigger(what, key, macro, priority):
    m = '{$NSX.EDGE.%s.MAX:"{#EDGE.NAME}"}' % macro
    return trigger('%s: high %s (over %s%% for {$NSX.EDGE.UTIL.PERIOD})' % (edge_label, what, m),
                   'min(%s,{$NSX.EDGE.UTIL.PERIOD})>%s' % (ref(key), m), priority, 'edge',
                   opdata='Current: {ITEM.LASTVALUE1}', deps=[edge_down], entity=edge_entity, scope='performance')


edge_items += [
    dep('%s: datapath CPU usage (avg DPDK core)' % edge_label, ek('cpu.dpdk'), edge_get,
        [jsonpath(SYS + '.cpu_usage.avg_cpu_core_usage_dpdk')], 'edge', value_type='FLOAT', units='%',
        entity=edge_entity, proto=True,
        desc='Average load of the DPDK (fast path) cores as reported by the Edge. DPDK cores poll constantly, so '
             'OS tools show 100%; this value is the real packet-processing load.',
        triggers=[util_trigger('datapath CPU', ek('cpu.dpdk'), 'CPU.DP', 'AVERAGE')]),
    dep('%s: datapath CPU usage (highest DPDK core)' % edge_label, ek('cpu.dpdk.max'), edge_get,
        [jsonpath(SYS + '.cpu_usage.highest_cpu_core_usage_dpdk')], 'edge', value_type='FLOAT', units='%',
        entity=edge_entity),
    dep('%s: services CPU usage (avg non-DPDK core)' % edge_label, ek('cpu.service'), edge_get,
        [jsonpath(SYS + '.cpu_usage.avg_cpu_core_usage_non_dpdk')], 'edge', value_type='FLOAT', units='%',
        entity=edge_entity, proto=True, desc='Cores used by control plane and L7 services (routing daemons, '
                                             'load balancer, VPN, DHCP).',
        triggers=[util_trigger('services CPU', ek('cpu.service'), 'CPU.SVC', 'WARNING')]),
    dep('%s: memory utilization' % edge_label, ek('mem.util'), edge_get, [js(EDGE_MEM_JS)], 'edge',
        value_type='FLOAT', units='%', entity=edge_entity, proto=True,
        desc='edge_mem_usage.system_mem_usage; falls back to mem_used / mem_total on versions without it.',
        triggers=[util_trigger('memory utilization', ek('mem.util'), 'MEM', 'AVERAGE')]),
    dep('%s: uptime' % edge_label, ek('uptime'), edge_get, [jsonpath(SYS + '.uptime'), step('MULTIPLIER', '0.001')],
        'edge', units='uptime', entity=edge_entity, proto=True, desc='Reported in milliseconds.',
        triggers=[trigger('%s: has been restarted' % edge_label, 'last(%s)<10m' % ref(ek('uptime')), 'WARNING',
                          'edge', desc='Uptime is less than 10 minutes.', manual_close=True, entity=edge_entity,
                          deps=[edge_down])]),
]
edge_rule = lld(
    'NSX: Edge nodes discovery', 'nsx.edge.discovery', EC_GET, [js(EDGE_LLD_JS), heartbeat()], [],
    [http('%s: Get status' % edge_label, edge_get, TN_PATH % 'EDGE.ID', '{$NSX.INTERVAL.NODE}', 'raw',
          'GET /api/v1/transport-nodes/<id>/status of an Edge node (includes CPU/memory in node_status).',
          TN_QUERY, entity=edge_entity)] + edge_items,
    'Edge nodes: members of every edge cluster (one entry per member, flattened by JavaScript).',
    filters=name_filters('{#EDGE.NAME}', 'EDGE') + name_filters('{#EC.NAME}', 'EC'))

edge_cluster_items = [
    http('NSX: Get edge clusters', EC_GET, '/api/v1/edge-clusters', '{$NSX.INTERVAL.LLD}', 'raw',
         'List of edge clusters and their members, for discovery.'),
]

# ------------------------------------------------------------------ 3C. Tier-0 / Tier-1 gateways: HA
HA_COUNT_JS = '''var st = JSON.parse(value), s = st.%s || {}, nodes = s.per_node_status || [], n = 0;
for (var i = 0; i < nodes.length; i++) {
    if (%s) {
        n++;
    }
}
return n;'''
HA_ACTIVE_JS = '''var st = JSON.parse(value), s = st.%s || {}, nodes = s.per_node_status || [], ids = [];
for (var i = 0; i < nodes.length; i++) {
    if (nodes[i].high_availability_status === 'ACTIVE') {
        ids.push(nodes[i].transport_node_id || nodes[i].service_router_id);
    }
}
return ids.sort().join(',');'''
COND = {
    'active': "nodes[i].high_availability_status === 'ACTIVE'",
    'standby': "nodes[i].high_availability_status === 'STANDBY'",
    'notok': "['ACTIVE', 'STANDBY'].indexOf(nodes[i].high_availability_status) === -1",
    'total': 'true',
}


def gateway(tier, label, extra_desc):
    """Discovery of tier-0s or tier-1s with HA state from /policy/api/v1/infra/tier-Xs/<id>/state."""
    t = 'T%s' % tier
    st = 'tier%s_status' % tier
    get_list = 'nsx.t%s.get' % tier
    get_state = 'nsx.t%s.state.get[{#%s.ID}]' % (tier, t)
    k = lambda s: 'nsx.t%s.ha.%s[{#%s.ID}]' % (tier, s, t)
    name = '%s [{#%s.NAME}]' % (label, t)
    entity = {'gateway': '{#%s.NAME}' % t}
    counts = {}
    for what, title in (('active', 'SR nodes ACTIVE'), ('standby', 'SR nodes STANDBY'),
                        ('notok', 'SR nodes not ACTIVE/STANDBY'), ('total', 'SR nodes total')):
        counts[what] = dep('%s: %s' % (name, title), k(what), get_state, [js(HA_COUNT_JS % (st, COND[what]))],
                           'gateway', entity=entity,
                           desc='Counted over %s.per_node_status[].high_availability_status.' % st)
    total, active = ref(k('total')), ref(k('active'))
    failover = trigger('%s: HA failover, active SR moved' % name,
                       'last(%s,#1)<>last(%s,#2) and length(last(%s,#1))>0 and length(last(%s,#2))>0'
                       % ((ref(k('active.nodes')),) * 4), 'AVERAGE', 'gateway', manual_close=True, entity=entity,
                       opdata='Active now: {ITEM.LASTVALUE1}',
                       desc='The set of Edge transport nodes running the ACTIVE service router changed. Expected '
                            'after maintenance; otherwise check the edge that lost the role (BFD, edge health, '
                            'uplinks). With preemptive failover mode a second failover follows when it recovers.')
    items = [
        http('%s: Get state' % name, get_state, '/policy/api/v1/infra/tier-%ss/{#%s.ID}/state' % (tier, t),
             '{$NSX.INTERVAL.NODE}', 'raw', 'Realized state of the gateway; %s.per_node_status has the HA '
             'status of every service router (SR).' % st, entity=entity),
        dep('%s: active SR nodes' % name, k('active.nodes'), get_state, [js(HA_ACTIVE_JS % st)], 'gateway',
            value_type='CHAR', history='31d', entity=entity, proto=True, triggers=[failover],
            desc='Sorted transport node IDs whose SR is ACTIVE. Empty for a gateway without SR (DR only).'),
    ] + list(counts.values())
    counts['notok']['trigger_prototypes'] = [trigger(
        '%s: {ITEM.LASTVALUE1} SR node(s) DOWN or not in sync' % name, 'last(%s)>0' % ref(k('notok')), 'HIGH',
        'gateway', entity=entity, desc='An SR reports DOWN, SYNC or UNKNOWN: the edge is down or cannot reach '
        'the peer. For ACTIVE_STANDBY the gateway runs without redundancy; for ACTIVE_ACTIVE ECMP capacity is lost.')]
    rule_triggers = [trigger('%s: no ACTIVE service router' % name, 'last(%s)=0 and last(%s)>0' % (active, total),
                             'HIGH', 'gateway', entity=entity,
                             desc='The gateway has service routers but none is ACTIVE: its north-south traffic '
                                  'and stateful services are down.')]
    if tier == '0':
        split = trigger('%s: more than one ACTIVE SR in ACTIVE_STANDBY mode (split brain)' % name,
                        'last(%s)>1' % active, 'HIGH', 'gateway', entity=entity,
                        desc='Both edges consider themselves ACTIVE: BFD between the edges is broken.')
        counts['active']['trigger_prototypes'] = [split]
    macro_paths = [('{#%s.ID}' % t, '$.id'), ('{#%s.NAME}' % t, '$.display_name')]
    overrides = []
    if tier == '0':
        macro_paths.append(('{#T0.HA_MODE}', '$.ha_mode'))
        # In ACTIVE_ACTIVE every SR is ACTIVE: "moved" and "split brain" make no sense there.
        overrides = [{'name': 'ACTIVE_ACTIVE: no failover/split brain triggers', 'step': '1',
                      'filter': {'conditions': [{'macro': '{#T0.HA_MODE}', 'value': '^ACTIVE_ACTIVE$',
                                                 'formulaid': 'A'}]},
                      'operations': [{'operationobject': 'TRIGGER_PROTOTYPE', 'operator': 'REGEXP',
                                      'value': 'HA failover|split brain', 'discover': 'NO_DISCOVER'}]}]
    rule = lld('NSX: Tier-%s gateways discovery' % tier, 'nsx.t%s.discovery' % tier, get_list,
               [jsonpath('$.results'), heartbeat()], macro_paths, items,
               'Tier-%s gateways from /policy/api/v1/infra/tier-%ss. %s' % (tier, tier, extra_desc),
               filters=name_filters('{#%s.NAME}' % t, t), overrides=overrides, trigger_protos=rule_triggers)
    master = http('NSX: Get Tier-%s gateways' % tier, get_list, '/policy/api/v1/infra/tier-%ss' % tier,
                  '{$NSX.INTERVAL.LLD}', 'raw', 'List of Tier-%s gateways for discovery.' % tier)
    return master, rule


t0_master, t0_rule = gateway('0', 'Tier-0', 'ACTIVE_ACTIVE gateways skip the failover triggers (override).')
t1_master, t1_rule = gateway('1', 'Tier-1', 'Tier-1 without services has no SR: its counters stay 0.')

# ------------------------------------------------------------------ 3C. BGP neighbours (Script item)
BGP_GET = 'nsx.bgp.neighbors.get'
BGP_SCRIPT = r'''var p = JSON.parse(value);
var base = String(p.url).replace(/\/+$/, '');
var auth = 'Basic ' + btoa(p.user + ':' + p.password);

function get(path) {
    var req = new HttpRequest();
    req.addHeader('Accept: application/json');
    req.addHeader('Authorization: ' + auth);
    if (p.proxy) {
        req.setProxy(p.proxy);
    }
    var body = req.get(base + path);
    if (req.getStatus() !== 200) {
        throw 'GET ' + path + ': HTTP ' + req.getStatus() + ' ' + String(body).substring(0, 200);
    }
    return JSON.parse(body);
}

function enc(s) {
    return encodeURIComponent(s);
}

var out = [], errors = [];
var t0s = get('/policy/api/v1/infra/tier-0s').results || [];
for (var i = 0; i < t0s.length; i++) {
    var t0 = t0s[i];
    try {
        var lss = get('/policy/api/v1/infra/tier-0s/' + enc(t0.id) + '/locale-services').results || [];
        for (var j = 0; j < lss.length; j++) {
            var st = get('/policy/api/v1/infra/tier-0s/' + enc(t0.id) + '/locale-services/' + enc(lss[j].id)
                + '/bgp/neighbors/status');
            var rows = st.results || [];
            for (var k = 0; k < rows.length; k++) {
                var n = rows[k], tn = n.transport_node || {};
                var edge = tn.target_display_name || tn.target_id || n.edge_path || n.transport_node_id || '';
                var src = n.source_address || '';
                out.push({
                    uid: [t0.id, edge, src, n.neighbor_address].join('|'),
                    t0_id: t0.id,
                    t0_name: t0.display_name || t0.id,
                    edge: edge,
                    source: src,
                    neighbor: n.neighbor_address,
                    remote_as: String(n.remote_as_number || ''),
                    state: n.connection_state || 'UNKNOWN',
                    established: n.time_since_established || 0,
                    prefixes_in: n.total_in_prefix_count || 0,
                    prefixes_out: n.total_out_prefix_count || 0
                });
            }
        }
    }
    catch (e) {
        errors.push(t0.id + ': ' + e);
    }
}
return JSON.stringify({neighbors: out, errors: errors});'''
bgp_script = {
    'uuid': uid('item:' + BGP_GET), 'name': 'NSX: Get BGP neighbors (all Tier-0)', 'type': 'SCRIPT',
    'key': BGP_GET, 'delay': '{$NSX.INTERVAL.NODE}', 'history': '0', 'value_type': 'TEXT', 'trends': '0',
    'params': BGP_SCRIPT,
    'description': 'Walks tier-0s -> locale-services -> bgp/neighbors/status and returns one flat JSON list. '
                   'Script item instead of HTTP agent: neighbours are two discovery levels deep and Zabbix 7.0 '
                   'has no nested LLD. Per-gateway errors are returned in "errors" and do not stop the others.',
    'timeout': '{$NSX.SCRIPT.TIMEOUT}',
    'parameters': [{'name': 'password', 'value': '{$NSX.PASSWORD}'}, {'name': 'proxy', 'value': '{$NSX.HTTP.PROXY}'},
                   {'name': 'url', 'value': URL}, {'name': 'user', 'value': '{$NSX.USER}'}],
    'tags': tags('raw'),
}
ERRORS_JS = 'return JSON.parse(value).errors.join("\\n");'
bgp_items = [
    bgp_script,
    dep('NSX: BGP collection errors', 'nsx.bgp.errors', BGP_GET, [js(ERRORS_JS), heartbeat('1h')], 'bgp',
        value_type='TEXT', history='7d',
        triggers=[trigger('NSX: BGP status could not be read for some Tier-0 gateways',
                          'length(last(%s))>0' % ref('nsx.bgp.errors'), 'WARNING', 'bgp',
                          opdata='{ITEM.LASTVALUE1}')]),
]
NB = "$.neighbors[?(@.uid=='{#BGP.UID}')]"
bgp_entity = {'gateway': '{#T0.NAME}', 'bgp-neighbor': '{#BGP.NEIGHBOR}'}
bgp_label = 'BGP [{#T0.NAME}] {#BGP.SOURCE} -> {#BGP.NEIGHBOR} (AS {#BGP.REMOTE.AS}, {#BGP.EDGE})'
bgp_state = 'nsx.bgp.state["{#BGP.UID}"]'
bgp_down = trigger('%s: session is not ESTABLISHED' % bgp_label, 'last(%s)<>1' % ref(bgp_state), 'HIGH', 'bgp',
                   opdata='State: {ITEM.LASTVALUE1}', entity=bgp_entity,
                   desc='BGP peering of Tier-0 {#T0.NAME} on edge {#BGP.EDGE} is down. "UNKNOWN" means the neighbour '
                        'is no longer reported at all (edge down or neighbour removed from the configuration).')
bgp_rule = lld(
    'NSX: Tier-0 BGP neighbors discovery', 'nsx.bgp.discovery', BGP_GET, [jsonpath('$.neighbors'), heartbeat()],
    [('{#BGP.UID}', '$.uid'), ('{#T0.ID}', '$.t0_id'), ('{#T0.NAME}', '$.t0_name'), ('{#BGP.EDGE}', '$.edge'),
     ('{#BGP.SOURCE}', '$.source'), ('{#BGP.NEIGHBOR}', '$.neighbor'), ('{#BGP.REMOTE.AS}', '$.remote_as')],
    [
        dep('%s: state' % bgp_label, bgp_state, BGP_GET, status_steps(NB + '.state.first()', BGP, on_fail='UNKNOWN'),
            'bgp', valuemap='NSX BGP state', entity=bgp_entity, proto=True, triggers=[bgp_down]),
        dep('%s: time since established' % bgp_label, 'nsx.bgp.uptime["{#BGP.UID}"]', BGP_GET,
            [jsonpath(NB + '.established.first()', 'discard'), step('MULTIPLIER', '{$NSX.BGP.UPTIME.MULTIPLIER}')],
            'bgp', units='uptime', entity=bgp_entity, proto=True,
            desc='time_since_established: seconds in NSX 4.x, milliseconds in NSX-T 2.x/3.x '
                 '(set {$NSX.BGP.UPTIME.MULTIPLIER} to 0.001 there).',
            triggers=[trigger('%s: session flapped (re-established)' % bgp_label,
                              'last(%s)>0 and last(%s)<{$NSX.BGP.FLAP.PERIOD}' % (
                                  ref('nsx.bgp.uptime["{#BGP.UID}"]'), ref('nsx.bgp.uptime["{#BGP.UID}"]')),
                              'WARNING', 'bgp', manual_close=True, deps=[bgp_down], entity=bgp_entity,
                              desc='The session is up but was established less than {$NSX.BGP.FLAP.PERIOD} ago.')]),
        dep('%s: prefixes received' % bgp_label, 'nsx.bgp.prefixes.in["{#BGP.UID}"]', BGP_GET,
            [jsonpath(NB + '.prefixes_in.first()', 'discard')], 'bgp', entity=bgp_entity),
        dep('%s: prefixes advertised' % bgp_label, 'nsx.bgp.prefixes.out["{#BGP.UID}"]', BGP_GET,
            [jsonpath(NB + '.prefixes_out.first()', 'discard')], 'bgp', entity=bgp_entity),
    ],
    'One entry per Tier-0 / edge / source address / neighbour.', filters=name_filters('{#T0.NAME}', 'T0'))

# ------------------------------------------------------------------ 4. NSX alarms passthrough
AL_GET = 'nsx.alarms.get'
ALARM_LLD_JS = '''function cut(s) {
    s = String(s === undefined || s === null ? '' : s);
    return s.length > 120 ? s.substring(0, 117) + '...' : s;
}
var alarms = JSON.parse(value), out = [];
for (var i = 0; i < alarms.length; i++) {
    var a = alarms[i];
    out.push({
        '{#ALARM.ID}': a.id,
        '{#ALARM.SEVERITY}': String(a.severity).toUpperCase(),
        '{#ALARM.FEATURE}': cut(a.feature_display_name || a.feature_name),
        '{#ALARM.EVENT}': cut(a.event_type_display_name || a.event_type),
        '{#ALARM.NODE}': cut(a.node_display_name || a.node_id || a.entity_id),
        '{#ALARM.ENTITY}': cut(a.entity_id),
        '{#ALARM.SUMMARY}': cut(a.summary),
        '{#ALARM.DESCRIPTION}': String(a.description || '').substring(0, 1000),
        '{#ALARM.ACTION}': String(a.recommended_action || '').substring(0, 1000)
    });
}
out.sort(function (x, y) { return x['{#ALARM.ID}'] < y['{#ALARM.ID}'] ? -1 : 1; });
return JSON.stringify(out);'''
SEVERITIES = [('CRITICAL', 'HIGH'), ('HIGH', 'AVERAGE'), ('MEDIUM', 'WARNING'), ('LOW', 'INFO')]
alarm_status = 'nsx.alarm.status[{#ALARM.ID}]'
alarm_entity = {'nsx-feature': '{#ALARM.FEATURE}', 'nsx-severity': '{#ALARM.SEVERITY}'}
alarm_trigger = trigger('NSX alarm [{#ALARM.SEVERITY}]: {#ALARM.FEATURE} / {#ALARM.EVENT} on {#ALARM.NODE}',
                        'last(%s)=1' % ref(alarm_status), 'WARNING', 'alarm', entity=alarm_entity, scope='notice',
                        opdata='{#ALARM.SUMMARY}',
                        desc='{#ALARM.DESCRIPTION}\n\nRecommended action:\n{#ALARM.ACTION}\n\n'
                             'Entity: {#ALARM.ENTITY}, alarm ID: {#ALARM.ID}. The problem closes when the alarm is '
                             'resolved (or acknowledged) in NSX.')
alarm_rule = lld(
    'NSX: Alarms discovery', 'nsx.alarm.discovery', AL_GET,
    [jsonpath("$.results[?(@.status=='OPEN')]", '[]'), js(ALARM_LLD_JS), heartbeat()], [],
    [dep('NSX alarm {#ALARM.FEATURE} / {#ALARM.EVENT} on {#ALARM.NODE}: status', alarm_status, AL_GET,
         status_steps("$.results[?(@.id=='{#ALARM.ID}')].status.first()", ALARM, on_fail='RESOLVED'), 'alarm',
         valuemap='NSX alarm status', entity=alarm_entity, proto=True, triggers=[alarm_trigger],
         desc='OPEN while the alarm is active; RESOLVED once it disappears from the list of open alarms.')],
    'Open NSX alarms (JSONPath filter on status) -> one item and one trigger per alarm. The trigger severity is '
    'set by overrides from the NSX severity: CRITICAL -> High, HIGH -> Average, MEDIUM -> Warning, LOW -> Info.',
    filters=[('{#ALARM.SEVERITY}', 'MATCHES_REGEX', '{$NSX.ALARM.SEVERITY.MATCHES}'),
             ('{#ALARM.FEATURE}', 'NOT_MATCHES_REGEX', '{$NSX.ALARM.FEATURE.NOT_MATCHES}')],
    lifetime='1d', keep_enabled=True,
    overrides=[{'name': 'NSX severity %s -> %s' % (nsx, zbx), 'step': str(i + 1), 'stop': 'STOP',
                'filter': {'conditions': [{'macro': '{#ALARM.SEVERITY}', 'value': '^%s$' % nsx, 'formulaid': 'A'}]},
                'operations': [{'operationobject': 'TRIGGER_PROTOTYPE', 'operator': 'LIKE', 'value': 'NSX alarm',
                                'severity': zbx}]}
               for i, (nsx, zbx) in enumerate(SEVERITIES)])

alarm_items = [
    http('NSX: Get alarms', AL_GET, '/api/v1/alarms', '{$NSX.INTERVAL.STATUS}', 'raw',
         'Active system alarms of NSX (status filter {$NSX.ALARM.STATUS}, default OPEN).',
         [('status', '{$NSX.ALARM.STATUS}'), ('page_size', '1000')]),
    dep('NSX: Open alarms total', 'nsx.alarms.count[total]', AL_GET,
        [jsonpath("$.results[?(@.status=='OPEN')].length()", '0')], 'alarm'),
] + [
    dep('NSX: Open alarms %s' % sev, 'nsx.alarms.count[%s]' % sev.lower(), AL_GET,
        [jsonpath("$.results[?(@.status=='OPEN' && @.severity=='%s')].length()" % sev, '0')], 'alarm')
    for sev, _ in SEVERITIES
]

# ------------------------------------------------------------------ template
macros = [
    ('{$NSX.MANAGER.URL}', 'https://nsx-manager.local', 'TEXT',
     'NSX Manager URL (cluster VIP or FQDN), without trailing slash.'),
    ('{$NSX.USER}', 'zabbix-ro', 'TEXT', 'NSX user. The Auditor (read-only) role is sufficient.'),
    ('{$NSX.PASSWORD}', '', 'SECRET_TEXT', 'Password of {$NSX.USER}.'),
    ('{$NSX.DATA.TIMEOUT}', '15s', 'TEXT', 'Timeout of HTTP agent requests.'),
    ('{$NSX.SCRIPT.TIMEOUT}', '30s', 'TEXT', 'Timeout of the BGP Script item (several requests in a row).'),
    ('{$NSX.HTTP.PROXY}', '', 'TEXT', 'HTTP proxy, e.g. http://proxy:3128. Empty: direct connection.'),
    ('{$NSX.INTERVAL.STATUS}', '1m', 'TEXT', 'Polling interval of cluster status and alarms.'),
    ('{$NSX.INTERVAL.NODE}', '5m', 'TEXT', 'Polling interval of per-node, per-gateway and BGP status.'),
    ('{$NSX.INTERVAL.LLD}', '1h', 'TEXT', 'Polling interval of the lists used for discovery.'),
    ('{$NSX.NODATA.TIMEOUT}', '10m', 'TEXT', 'No data from the API for this long raises a problem.'),
    ('{$NSX.EDGE.CPU.DP.MAX}', '80', 'TEXT', 'Edge datapath (DPDK) CPU, %. Context: edge name.'),
    ('{$NSX.EDGE.CPU.SVC.MAX}', '90', 'TEXT', 'Edge services (non-DPDK) CPU, %. Context: edge name.'),
    ('{$NSX.EDGE.MEM.MAX}', '90', 'TEXT', 'Edge memory utilization, %. Context: edge name.'),
    ('{$NSX.EDGE.UTIL.PERIOD}', '15m', 'TEXT', 'Utilization must stay above the threshold for this long.'),
    ('{$NSX.BGP.FLAP.PERIOD}', '10m', 'TEXT', 'A BGP session younger than this is reported as a flap.'),
    ('{$NSX.BGP.UPTIME.MULTIPLIER}', '1', 'TEXT',
     'time_since_established -> seconds: 1 for NSX 4.x (seconds), 0.001 for NSX-T 2.x/3.x (milliseconds).'),
    ('{$NSX.ALARM.STATUS}', 'OPEN', 'TEXT', 'Status filter of /api/v1/alarms.'),
    ('{$NSX.ALARM.SEVERITY.MATCHES}', '^(CRITICAL|HIGH|MEDIUM)$', 'TEXT',
     'NSX alarm severities turned into Zabbix problems (LOW is skipped by default).'),
    ('{$NSX.ALARM.FEATURE.NOT_MATCHES}', 'CHANGE_IF_NEEDED', 'TEXT', 'NSX alarm features to ignore (regex).'),
]
for prefix, what in (('TN', 'host transport node'), ('EDGE', 'edge node'), ('EC', 'edge cluster'),
                     ('T0', 'Tier-0 gateway'), ('T1', 'Tier-1 gateway')):
    macros.append(('{$NSX.%s.NAME.MATCHES}' % prefix, '.*', 'TEXT', 'Discover a %s if its name matches.' % what))
    macros.append(('{$NSX.%s.NAME.NOT_MATCHES}' % prefix, 'CHANGE_IF_NEEDED', 'TEXT',
                   'Skip a %s if its name matches.' % what))


def macro(m, v, t, d):
    out = {'macro': m}
    if t != 'TEXT':
        out['type'] = t
    if v:
        out['value'] = v
    out['description'] = d
    return out


template = {
    'uuid': uid('template'),
    'template': T,
    'name': T,
    'description': (
        'Monitoring of VMware NSX (NSX-T 3.x, NSX 4.x) through the NSX Manager REST API: management cluster, '
        'host transport nodes, edge clusters and edge nodes (status, tunnels, CPU, memory), Tier-0/Tier-1 HA, '
        'Tier-0 BGP neighbours and NSX alarms.\n\n'
        'Create a host for the NSX Manager cluster (no interface needed), link this template and set '
        '{$NSX.MANAGER.URL}, {$NSX.USER}, {$NSX.PASSWORD}. A read-only NSX user (role Auditor) is enough.\n\n'
        'Generated by vmware/tools/gen_nsx_template.py.'),
    'vendor': {'name': 'Custom', 'version': '7.0-1.0.0'},
    'groups': [{'name': 'Templates/Applications'}],
    'items': cluster_items + transport_items + edge_cluster_items + [t0_master, t1_master] + bgp_items + alarm_items,
    'discovery_rules': [group_rule, host_rule, ec_rule, edge_rule, t0_rule, t1_rule, bgp_rule, alarm_rule],
    'tags': [{'tag': 'class', 'value': 'software'}, {'tag': 'target', 'value': 'nsx'}],
    'macros': [macro(*m) for m in sorted(macros)],
    'valuemaps': [{'uuid': uid('valuemap:' + n), 'name': n,
                   'mappings': [{'value': str(v), 'newvalue': k} for k, v in sorted(m.items(), key=lambda kv: kv[1])]}
                  for n, m in VALUEMAPS],
}

export = {'zabbix_export': {
    'version': '7.0',
    # UUID of the stock group in Zabbix 7.0, so the import reuses it instead of clashing.
    'template_groups': [{'uuid': 'a571c0d144b14fd4a87a9d9b2aa9fcd6', 'name': 'Templates/Applications'}],
    'templates': [template],
}}


class Dumper(yaml.SafeDumper):
    def increase_indent(self, flow=False, indentless=False):
        return super().increase_indent(flow, False)


def _str(dumper, data):
    style = '|' if '\n' in data else None
    return dumper.represent_scalar('tag:yaml.org,2002:str', data, style=style)


Dumper.add_representer(str, _str)
Dumper.ignore_aliases = lambda self, data: True

if __name__ == '__main__':
    out_dir = sys.argv[1]
    with open(os.path.join(out_dir, 'zbx_template_vmware_nsx.yaml'), 'w') as fh:
        yaml.dump(export, fh, Dumper=Dumper, sort_keys=False, allow_unicode=True, width=1000)
    with open(os.path.join(out_dir, 'zbx_template_vmware_nsx.json'), 'w') as fh:
        json.dump(export, fh, indent=4, ensure_ascii=False)
        fh.write('\n')
    protos = [p for r in template['discovery_rules'] for p in r['item_prototypes']]
    print('items:', len(template['items']), 'rules:', len(template['discovery_rules']), 'prototypes:', len(protos))
