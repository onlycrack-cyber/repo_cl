#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#
# Free resources of vSphere clusters as CSV: Zabbix external check and data source of Custom Problem Reports.
#
# Usage:
#   cluster_resources_csv.py                       CSV for all vCenters of the config
#   cluster_resources_csv.py vc01                  CSV for one vCenter (manual testing)
#   cluster_resources_csv.py[]  / ["vc01"]         the same as an item key of type "External check"
#
# Options (before the vCenter name):
#   --outfile=PATH      also write the CSV to a file (atomically: unique temporary file + rename)
#   --config=PATH       config file (also env CLUSTER_RESOURCES_CONF); default: cluster_storage.conf next to the
#                       script, /etc/zabbix/externalscripts/, /usr/lib/zabbix/externalscripts/, ~/.zabbix/
#   --cpu-avg=MINUTES   CPU usage averaged over the last MINUTES (performance statistics of vCenter) instead of the
#                       20-second snapshot of quickStats; falls back to quickStats if statistics are not available
#   --include-local     count local (non-shared) datastores too
#   --timeout=SECONDS   network timeout per vCenter request (default 30)
#   --legacy            the old 5-column format: vcenter,cluster,cpu_free_ghz,memory_free_gb,storage_free_gb
#
# Output (one row per cluster plus one total row per vCenter, sorted):
#   type,vcenter,datacenter,cluster,hosts,hosts_unavailable,
#   cpu_total_ghz,cpu_free_ghz,cpu_ha_reserve_ghz,
#   memory_total_gb,memory_free_gb,memory_ha_reserve_gb,
#   storage_total_gb,storage_free_gb,storage_provisioned_gb,status,error
#
#   type     "cluster" or "vcenter" (total of the vCenter: shared datastores are counted once)
#   *_total  capacity of the hosts that can run VMs (connected, not in maintenance): summary.effectiveCpu/Memory
#   *_free   capacity - usage of those hosts; empty if the usage is unknown (no quickStats)
#   *_ha_reserve  capacity reserved by HA admission control (percentage, host failures or dedicated failover hosts)
#   storage  accessible shared datastores of the cluster, not in maintenance; provisioned = used + uncommitted
#   status   "ok" or "error" (a vCenter that failed: one "vcenter" row with the error text)
#   GB are GiB (2^30 bytes), as in the vSphere Client.
#
# Exit code: 0 if data of at least one vCenter was collected, 1 if all failed or the config is bad
# (errors are also printed to stderr).
#
# Config (JSON, keep it readable only by the users that run the script, e.g. chmod 640, group zabbix):
#   { "vcenters": { "vc01": { "url": "https://vc01.domain.local", "user": "svc-zabbix@vsphere.local",
#                             "password": "secret", "verify_ssl": true, "ca_file": "/etc/ssl/certs/corp-ca.pem" } } }
#   verify_ssl (default true): check the certificate of vCenter; false only for self-signed certificates in a trusted
#   network. ca_file: CA bundle for a corporate CA. A read-only vCenter role is enough.
#
# Requirements: python3, pyVmomi (pip install pyvmomi). For the external check: Timeout of the item >= the run time.

import argparse
import concurrent.futures
import csv
import datetime
import io
import json
import os
import socket
import ssl
import sys
import tempfile

# Python 3.12 removed ssl.wrap_socket(), which old pyVmomi packages (e.g. python3-pyvmomi 6.x of Debian/Ubuntu) still
# call. The replacement keeps the semantics of the removed function (including its defaults); a current pyVmomi from
# pip does not need it.
if not hasattr(ssl, "wrap_socket"):
    def _compat_wrap_socket(sock, keyfile=None, certfile=None, server_side=False, cert_reqs=ssl.CERT_NONE,
                            ssl_version=None, ca_certs=None, do_handshake_on_connect=True,
                            suppress_ragged_eofs=True, ciphers=None):
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER if server_side else ssl.PROTOCOL_TLS_CLIENT)
        context.check_hostname = False
        context.verify_mode = cert_reqs
        if ca_certs:
            context.load_verify_locations(ca_certs)
        if certfile:
            context.load_cert_chain(certfile, keyfile)
        if ciphers:
            context.set_ciphers(ciphers)
        return context.wrap_socket(sock, server_side=server_side, do_handshake_on_connect=do_handshake_on_connect,
                                   suppress_ragged_eofs=suppress_ragged_eofs)

    ssl.wrap_socket = _compat_wrap_socket

CONFIG_NAME = "cluster_storage.conf"
GB = 1073741824.0
MB = 1048576.0

HEADER = ["type", "vcenter", "datacenter", "cluster", "hosts", "hosts_unavailable",
          "cpu_total_ghz", "cpu_free_ghz", "cpu_ha_reserve_ghz",
          "memory_total_gb", "memory_free_gb", "memory_ha_reserve_gb",
          "storage_total_gb", "storage_free_gb", "storage_provisioned_gb", "status", "error"]
LEGACY_HEADER = ["vcenter", "cluster", "cpu_free_ghz", "memory_free_gb", "storage_free_gb"]


class ConfigError(Exception):
    pass


# --------------------------------------------------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------------------------------------------------

def config_candidates(explicit):
    if explicit:
        return [explicit]
    here = os.path.dirname(os.path.abspath(__file__))
    return [os.path.join(here, CONFIG_NAME),
            "/etc/zabbix/externalscripts/" + CONFIG_NAME,
            "/usr/lib/zabbix/externalscripts/" + CONFIG_NAME,
            os.path.join(os.path.expanduser("~"), ".zabbix", CONFIG_NAME)]


def load_config(explicit=None):
    """The first existing config; a broken one is an error (not "no config")."""
    for path in config_candidates(explicit):
        if not os.path.exists(path):
            continue
        try:
            with open(path, "r", encoding="utf-8-sig") as f:
                config = json.load(f)
        except (OSError, ValueError) as e:
            raise ConfigError("cannot read %s: %s" % (path, e))
        if not isinstance(config, dict) or not isinstance(config.get("vcenters"), dict) or not config["vcenters"]:
            raise ConfigError('%s: no "vcenters" object' % path)
        for name, info in config["vcenters"].items():
            missing = [key for key in ("url", "user", "password") if not isinstance(info, dict) or not info.get(key)]
            if missing:
                raise ConfigError("%s: vCenter %s has no %s" % (path, name, ", ".join(missing)))
        return config
    raise ConfigError("config not found: %s" % ", ".join(config_candidates(explicit)))


# --------------------------------------------------------------------------------------------------------------------
# Calculation (no vSphere objects: plain dictionaries, see collect())
# --------------------------------------------------------------------------------------------------------------------

def host_available(host):
    return host.get("connection_state") == "connected" and not host.get("maintenance")


def host_cpu_mhz(host):
    return (host.get("cpu_mhz") or 0) * (host.get("cpu_cores") or 0)


def ha_reserve(das, hosts, cpu_total_mhz, memory_total_bytes):
    """Capacity reserved by HA admission control: (cpu MHz, memory bytes)."""
    if not das or not das.get("enabled") or not das.get("admission_control"):
        return 0.0, 0.0

    policy = das.get("policy")
    available = [h for h in hosts if host_available(h)]

    if policy == "resources":
        return (cpu_total_mhz * (das.get("cpu_percent") or 0) / 100.0,
                memory_total_bytes * (das.get("memory_percent") or 0) / 100.0)

    if policy == "level":
        # Room for the failure of N hosts: the N biggest ones (the slot policy is approximated this way).
        level = das.get("failover_level") or 0
        cpu = sorted((host_cpu_mhz(h) for h in available), reverse=True)[:level]
        memory = sorted((h.get("memory_bytes") or 0 for h in available), reverse=True)[:level]
        return float(sum(cpu)), float(sum(memory))

    if policy == "hosts":
        failover = [h for h in available if h["id"] in set(das.get("failover_hosts") or [])]
        return (float(sum(host_cpu_mhz(h) for h in failover)),
                float(sum(h.get("memory_bytes") or 0 for h in failover)))

    return 0.0, 0.0


def usable_datastores(datastore_ids, datastores, include_local):
    result = []
    for ds_id in datastore_ids:
        ds = datastores.get(ds_id)
        if ds is None or not ds.get("accessible"):
            continue
        if ds.get("maintenance") not in (None, "normal"):
            continue
        if not include_local and not ds.get("shared"):
            continue
        result.append(ds)
    return result


def storage_totals(datastores):
    total = sum(ds.get("capacity") or 0 for ds in datastores)
    free = sum(ds.get("free") or 0 for ds in datastores)
    provisioned = sum((ds.get("capacity") or 0) - (ds.get("free") or 0) + (ds.get("uncommitted") or 0)
                      for ds in datastores)
    return total / GB, free / GB, provisioned / GB


def cluster_row(vcenter, cluster, hosts, datastores, cpu_usage, include_local):
    """One cluster. cpu_usage: host id -> MHz (averaged), overrides quickStats."""
    members = [hosts[h] for h in cluster.get("hosts", []) if h in hosts]
    available = [h for h in members if host_available(h)]

    # Capacity: summary.effective* (hosts that can run VMs); computed from the hosts if vCenter gives nothing.
    cpu_total_mhz = cluster.get("effective_cpu_mhz") or sum(host_cpu_mhz(h) for h in available)
    memory_total = (cluster.get("effective_memory_mb") or 0) * MB or float(sum(h.get("memory_bytes") or 0
                                                                               for h in available))

    cpu_used = [cpu_usage.get(h["id"], h.get("cpu_usage_mhz")) for h in available]
    memory_used = [h.get("memory_usage_mb") for h in available]

    # 0 is a valid usage; unknown usage of a running host makes the free value unknown.
    if not available:
        cpu_free, memory_free = 0.0, 0.0
    else:
        cpu_free = None if any(v is None for v in cpu_used) else max(0.0, cpu_total_mhz - sum(cpu_used))
        memory_free = (None if any(v is None for v in memory_used)
                       else max(0.0, memory_total - sum(memory_used) * MB))

    cpu_reserve, memory_reserve = ha_reserve(cluster.get("das"), members, cpu_total_mhz, memory_total)
    storage_total, storage_free, provisioned = storage_totals(
        usable_datastores(cluster.get("datastores", []), datastores, include_local))

    return {
        "type": "cluster", "vcenter": vcenter, "datacenter": cluster.get("datacenter", ""),
        "cluster": cluster.get("name", ""), "hosts": len(members), "hosts_unavailable": len(members) - len(available),
        "cpu_total_ghz": cpu_total_mhz / 1000.0,
        "cpu_free_ghz": None if cpu_free is None else cpu_free / 1000.0,
        "cpu_ha_reserve_ghz": cpu_reserve / 1000.0,
        "memory_total_gb": memory_total / GB,
        "memory_free_gb": None if memory_free is None else memory_free / GB,
        "memory_ha_reserve_gb": memory_reserve / GB,
        "storage_total_gb": storage_total, "storage_free_gb": storage_free, "storage_provisioned_gb": provisioned,
        "status": "ok", "error": ""
    }


def vcenter_total(vcenter, clusters, rows, datastores, include_local):
    """Total of a vCenter: sums of the clusters, datastores shared between clusters counted once."""
    unique = []
    for cluster in clusters:
        unique.extend(cluster.get("datastores", []))
    storage_total, storage_free, provisioned = storage_totals(
        usable_datastores(sorted(set(unique)), datastores, include_local))

    def total(key):
        values = [row[key] for row in rows]
        return None if any(v is None for v in values) else sum(values)

    return {
        "type": "vcenter", "vcenter": vcenter, "datacenter": "", "cluster": "",
        "hosts": sum(r["hosts"] for r in rows), "hosts_unavailable": sum(r["hosts_unavailable"] for r in rows),
        "cpu_total_ghz": total("cpu_total_ghz"), "cpu_free_ghz": total("cpu_free_ghz"),
        "cpu_ha_reserve_ghz": total("cpu_ha_reserve_ghz"),
        "memory_total_gb": total("memory_total_gb"), "memory_free_gb": total("memory_free_gb"),
        "memory_ha_reserve_gb": total("memory_ha_reserve_gb"),
        "storage_total_gb": storage_total, "storage_free_gb": storage_free, "storage_provisioned_gb": provisioned,
        "status": "ok", "error": ""
    }


def build_rows(vcenter, data, include_local):
    """Rows of one vCenter from the collected data (see collect())."""
    rows = [cluster_row(vcenter, c, data["hosts"], data["datastores"], data.get("cpu_usage", {}), include_local)
            for c in data["clusters"]]
    rows.sort(key=lambda r: (r["datacenter"].lower(), r["cluster"].lower()))
    return rows + [vcenter_total(vcenter, data["clusters"], rows, data["datastores"], include_local)]


def error_row(vcenter, message):
    row = {key: "" for key in HEADER}
    row.update({"type": "vcenter", "vcenter": vcenter, "status": "error", "error": message})
    return row


# --------------------------------------------------------------------------------------------------------------------
# vCenter
# --------------------------------------------------------------------------------------------------------------------

def connect_vcenter(info, timeout):
    from pyVim import connect

    host = info["url"].strip()
    if "://" in host:
        host = host.split("://", 1)[1]
    host = host.rstrip("/")
    port = 443
    if ":" in host:
        host, port = host.rsplit(":", 1)
        port = int(port)

    context = ssl.create_default_context(cafile=info.get("ca_file") or None)
    if not info.get("verify_ssl", True):
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE

    socket.setdefaulttimeout(timeout)
    return connect.SmartConnect(host=host, port=port, user=info["user"], pwd=info["password"], sslContext=context)


def retrieve(content, obj_type, paths):
    """Properties of all objects of a type in one PropertyCollector call (with paging): {moId: {path: value}}."""
    from pyVmomi import vim, vmodl

    pc = vmodl.query.PropertyCollector
    view = content.viewManager.CreateContainerView(content.rootFolder, [obj_type], True)
    try:
        spec = pc.FilterSpec(
            objectSet=[pc.ObjectSpec(obj=view, skip=True, selectSet=[
                pc.TraversalSpec(name="view", path="view", skip=False, type=vim.view.ContainerView)])],
            propSet=[pc.PropertySpec(type=obj_type, pathSet=paths, all=False)])
        result = {}
        response = content.propertyCollector.RetrievePropertiesEx([spec], pc.RetrieveOptions(maxObjects=1000))
        while response:
            for obj in response.objects:
                props = {prop.name: prop.val for prop in (obj.propSet or [])}
                props["_obj"] = obj.obj
                result[obj.obj._moId] = props
            if not response.token:
                break
            response = content.propertyCollector.ContinueRetrievePropertiesEx(response.token)
        return result
    finally:
        view.Destroy()


def das_info(config):
    from pyVmomi import vim

    das = getattr(config, "dasConfig", None) if config is not None else None
    if das is None:
        return None
    policy = das.admissionControlPolicy
    info = {"enabled": bool(das.enabled), "admission_control": bool(das.admissionControlEnabled), "policy": None}
    if isinstance(policy, vim.cluster.FailoverResourcesAdmissionControlPolicy):
        info.update(policy="resources", cpu_percent=policy.cpuFailoverResourcesPercent or 0,
                    memory_percent=policy.memoryFailoverResourcesPercent or 0)
    elif isinstance(policy, vim.cluster.FailoverLevelAdmissionControlPolicy):
        info.update(policy="level", failover_level=policy.failoverLevel or 0)
    elif isinstance(policy, vim.cluster.FailoverHostAdmissionControlPolicy):
        info.update(policy="hosts", failover_hosts=[h._moId for h in (policy.failoverHosts or [])])
    return info


def datacenter_of(parent, entities):
    """Name of the datacenter of an entity: walk up the folders."""
    seen = set()
    while parent is not None and parent._moId not in seen:
        seen.add(parent._moId)
        entity = entities.get(parent._moId)
        if entity is None:
            return ""
        if entity["_type"] == "datacenter":
            return entity.get("name", "")
        parent = entity.get("parent")
    return ""


def average_cpu(si, content, host_ids, minutes):
    """Average cpu.usagemhz of the hosts over the last minutes: {host moId: MHz}; {} if not available."""
    from pyVmomi import vim

    perf = content.perfManager
    counter = next((c.key for c in perf.perfCounter if c.groupInfo.key == "cpu" and c.nameInfo.key == "usagemhz"
                    and c.rollupType == "average"), None)
    if counter is None or not host_ids:
        return {}

    # Real-time statistics are kept for one hour, then 5-minute, 30-minute and 2-hour intervals.
    interval = 20 if minutes <= 60 else 300 if minutes <= 1440 else 1800 if minutes <= 10080 else 7200
    end = si.CurrentTime()
    start = end - datetime.timedelta(minutes=minutes)
    specs = [vim.PerformanceManager.QuerySpec(entity=host_obj, metricId=[vim.PerformanceManager.MetricId(
        counterId=counter, instance="")], intervalId=interval, startTime=start, endTime=end)
        for host_obj in host_ids.values()]

    result = {}
    for metric in perf.QueryPerf(querySpec=specs) or []:
        values = [v for series in metric.value for v in series.value if v is not None and v >= 0]
        if values:
            result[metric.entity._moId] = sum(values) / float(len(values))
    return result


def collect(si, cpu_avg_minutes=0):
    """Everything the calculation needs, in a few PropertyCollector calls (no per-object round trips)."""
    from pyVmomi import vim

    content = si.RetrieveContent()

    entities = {}
    for obj_type, kind in ((vim.Folder, "folder"), (vim.Datacenter, "datacenter")):
        for moid, props in retrieve(content, obj_type, ["name", "parent"]).items():
            entities[moid] = {"_type": kind, "name": props.get("name", ""), "parent": props.get("parent")}

    hosts = {}
    host_objs = {}
    for moid, p in retrieve(content, vim.HostSystem, [
            "runtime.connectionState", "runtime.inMaintenanceMode", "summary.quickStats.overallCpuUsage",
            "summary.quickStats.overallMemoryUsage", "summary.hardware.cpuMhz", "summary.hardware.numCpuCores",
            "summary.hardware.memorySize"]).items():
        hosts[moid] = {
            "id": moid, "connection_state": str(p.get("runtime.connectionState", "")),
            "maintenance": bool(p.get("runtime.inMaintenanceMode")),
            "cpu_usage_mhz": p.get("summary.quickStats.overallCpuUsage"),
            "memory_usage_mb": p.get("summary.quickStats.overallMemoryUsage"),
            "cpu_mhz": p.get("summary.hardware.cpuMhz"), "cpu_cores": p.get("summary.hardware.numCpuCores"),
            "memory_bytes": p.get("summary.hardware.memorySize")
        }
        host_objs[moid] = p["_obj"]

    datastores = {}
    for moid, p in retrieve(content, vim.Datastore, [
            "summary.accessible", "summary.capacity", "summary.freeSpace", "summary.uncommitted",
            "summary.multipleHostAccess", "summary.maintenanceMode"]).items():
        datastores[moid] = {
            "accessible": bool(p.get("summary.accessible")), "capacity": p.get("summary.capacity"),
            "free": p.get("summary.freeSpace"), "uncommitted": p.get("summary.uncommitted"),
            "shared": bool(p.get("summary.multipleHostAccess")), "maintenance": p.get("summary.maintenanceMode")
        }

    clusters = []
    for moid, p in retrieve(content, vim.ClusterComputeResource, [
            "name", "parent", "host", "datastore", "summary", "configurationEx"]).items():
        summary = p.get("summary")
        clusters.append({
            "id": moid, "name": p.get("name", ""), "datacenter": datacenter_of(p.get("parent"), entities),
            "hosts": [h._moId for h in (p.get("host") or [])],
            "datastores": [d._moId for d in (p.get("datastore") or [])],
            "effective_cpu_mhz": getattr(summary, "effectiveCpu", None),
            "effective_memory_mb": getattr(summary, "effectiveMemory", None),
            "das": das_info(p.get("configurationEx"))
        })

    data = {"clusters": clusters, "hosts": hosts, "datastores": datastores, "cpu_usage": {}}

    if cpu_avg_minutes > 0:
        running = {moid: obj for moid, obj in host_objs.items() if host_available(hosts[moid])}
        try:
            data["cpu_usage"] = average_cpu(si, content, running, cpu_avg_minutes)
        except Exception as e:  # statistics are optional: quickStats are used instead
            sys.stderr.write("CPU statistics are not available (%s), using quickStats\n" % e)

    return data


def vcenter_rows(name, info, args):
    from pyVim import connect

    si = connect_vcenter(info, args.timeout)
    try:
        return build_rows(name, collect(si, args.cpu_avg), args.include_local)
    finally:
        connect.Disconnect(si)


# --------------------------------------------------------------------------------------------------------------------
# Output
# --------------------------------------------------------------------------------------------------------------------

def format_value(value):
    if value is None:
        return ""
    if isinstance(value, float):
        return "%.2f" % value
    return str(value)


def to_csv(rows, legacy):
    out = io.StringIO()
    writer = csv.writer(out, lineterminator="\n")
    if legacy:
        # The old format: clusters only; a failed vCenter is an "ERROR: ..." row as before.
        writer.writerow(LEGACY_HEADER)
        for row in rows:
            if row["status"] == "error":
                writer.writerow([row["vcenter"], "ERROR: " + row["error"], "", "", ""])
            elif row["type"] == "cluster":
                writer.writerow([format_value(row[key]) for key in LEGACY_HEADER])
    else:
        writer.writerow(HEADER)
        for row in rows:
            writer.writerow([format_value(row[key]) for key in HEADER])
    return out.getvalue()


def write_atomically(path, content):
    directory = os.path.dirname(os.path.abspath(path))
    fd, tmp = tempfile.mkstemp(prefix="." + os.path.basename(path) + ".", dir=directory)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="") as f:
            f.write(content)
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)
    except BaseException:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise


def parse_args(argv):
    parser = argparse.ArgumentParser(description="Free resources of vSphere clusters as CSV.")
    parser.add_argument("--outfile")
    parser.add_argument("--config", default=os.environ.get("CLUSTER_RESOURCES_CONF"))
    parser.add_argument("--cpu-avg", type=int, default=0, metavar="MINUTES")
    parser.add_argument("--include-local", action="store_true")
    parser.add_argument("--timeout", type=int, default=30)
    parser.add_argument("--legacy", action="store_true")
    parser.add_argument("vcenter", nargs="?", default="")
    return parser.parse_args(argv)


def main(argv=None):
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")

    args = parse_args(sys.argv[1:] if argv is None else argv)

    try:
        config = load_config(args.config)
        vcenters = config["vcenters"]
        if args.vcenter and args.vcenter not in vcenters:
            raise ConfigError("no vCenter %r in the config" % args.vcenter)
        names = [args.vcenter] if args.vcenter else sorted(vcenters)
        import pyVmomi  # noqa: F401 - clear message before any connection
    except ConfigError as e:
        sys.stderr.write("%s\n" % e)
        return 1
    except ImportError:
        sys.stderr.write("pyVmomi is not installed. Run: pip install pyvmomi\n")
        return 1

    rows = []
    failed = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=min(8, len(names))) as pool:
        futures = {name: pool.submit(vcenter_rows, name, vcenters[name], args) for name in names}
        for name in names:
            try:
                rows.extend(futures[name].result())
            except Exception as e:
                message = ("%s: %s" % (type(e).__name__, getattr(e, "msg", None) or e)).replace("\n", " ")
                sys.stderr.write("vCenter %s failed: %s\n" % (name, message))
                failed.append(name)
                rows.append(error_row(name, message))

    content = to_csv(rows, args.legacy)
    if args.outfile:
        write_atomically(args.outfile, content)
    sys.stdout.write(content)

    return 1 if len(failed) == len(names) else 0


if __name__ == "__main__":
    sys.exit(main())
