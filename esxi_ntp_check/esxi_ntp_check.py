#!/opt/zabbix-venv/bin/python3
"""
Проверки здоровья ESXi-хостов через vCenter (NTP, VMkernel, Storage, DNS,
Power). LLD-режим отдаёт все хосты vCenter. Режим license отключён.

Режимы (1-м аргументом, вместо хоста — режим, 2-м — имя хоста):
    esxi_ntp_check.py["{#ESXINAME}",url,user,pwd]            (legacy-форма NTP)
    esxi_ntp_check.py["ntp","{#ESXINAME}",url,user,pwd]
        -> 1 если NTP настроен (протокол NTP + серверы) И служба ntpd Running
    esxi_ntp_check.py["vmk","{#ESXINAME}",url,user,pwd]
        -> 1 если настроен хотя бы один VMkernel adapter с IP
    esxi_ntp_check.py["storage","{#ESXINAME}",url,user,pwd]
        -> 0 если хоть один известный Storage Adapter не online (деградация:
           хост живёт на оставшемся порту); adapters со status=unknown не
           учитываются (1, если известных нет); нет данных -> ошибка
    esxi_ntp_check.py["dns","{#ESXINAME}",url,user,pwd,pref,alt]
        -> 1 если оба указанных DNS есть; без аргументов — наличие >=2 DNS
    esxi_ntp_check.py["power","{#ESXINAME}",url,user,pwd,policy]
        -> 1 если активная политика = заданной (по умолч. High performance)
    esxi_ntp_check.py["discover",url,user,pwd]
        -> {"data":[{"{#ESXINAME}":...,"{#ESXIADDR}":...},...]}
    esxi_ntp_check.py["hosts",url,user,pwd]
        -> число хостов в vCenter (самопроверка доступности vCenter)
    esxi_ntp_check.py["collect",url,user,pwd,dns_pref,dns_alt,power_policy]
        -> ОДИН компактный JSON по всем хостам (для шаблона "ESXi Health by
           vCenter": зависимые LLD и айтемы разбирают его JSONPath'ом).
           Недоступные значения в JSON опускаются (айтем -> unsupported).
           hosts.<имя>.alert = 0, если хост не connected или в Maintenance Mode
           (шаблон подавляет по нему все триггеры хоста).

Хост не в состоянии connected (disconnected/notResponding) -> ошибка
(unsupported), а не 0: нет ложных срабатываний по всем проверкам сразу.

Требование: /opt/zabbix-venv с pyVmomi 8.0.3+/9.x
Конфиг: по умолчанию из окружения ESXI_VCENTER/ESXI_USER/ESXI_PWD,
        либо аргументами.
Кэш: <tmp>/esxi_ntp_cache_<md5(url)>.json, TTL = ESXI_NTP_TTL (по умолч. 900 c,
     должен быть больше интервала опроса айтемов). Обновление кэша под flock:
     параллельные запуски ждут один опрос vCenter, а не делают свой.
     ESXI_NTP_REFRESH=1 — игнорировать кэш (разовый опрос vCenter).
ESXI_NTP_OFFSET=0 — не измерять смещение времени ESXi (по умолчанию измеряется:
     по одному SOAP-вызову QueryDateTime на хост, параллельно, ESXI_NTP_WORKERS=8).
ESXI_COLLECT_TTL — максимальный возраст кэша для режима collect (240 c).
ESXI_TIMEOUT — таймаут сокета (15 c); ESXI_DEADLINE — общий лимит работы (25 c,
     держите меньше таймаута айтема Zabbix).
ESXI_CA_BUNDLE — путь к CA/корню VMCA: включает проверку TLS-сертификата
     (по умолчанию проверка выключена, как раньше).
Хост ищется по точному имени или IP; короткое имя (esx01 == esx01.domain.local)
допускается только если однозначно.
"""

import fcntl
import hashlib
import json
import os
import re
import signal
import socket
import ssl
import sys
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import timezone

# Совместимость с Python 3.12: в нём удалён устаревший ssl.wrap_socket,
# который по-прежнему использует pyVmomi (SoapAdapter.py).
if not hasattr(ssl, "wrap_socket"):
    def _ssl_wrap_socket(sock, keyfile=None, certfile=None, server_side=False,
                         cert_reqs=ssl.CERT_NONE, ssl_version=ssl.PROTOCOL_TLS,
                         ca_certs=None, do_handshake_on_connect=True,
                         suppress_ragged_eofs=True, ciphers=None, **kwargs):
        context = ssl.SSLContext(ssl.PROTOCOL_TLS)
        if cert_reqs != ssl.CERT_NONE:
            context.verify_mode = cert_reqs
        if ca_certs:
            context.load_verify_locations(ca_certs)
        if certfile:
            context.load_cert_chain(certfile, keyfile)
        return context.wrap_socket(sock, server_side=server_side,
                                   do_handshake_on_connect=do_handshake_on_connect,
                                   suppress_ragged_eofs=suppress_ragged_eofs)
    ssl.wrap_socket = _ssl_wrap_socket

try:
    from pyVim.connect import SmartConnect, Disconnect
    from pyVmomi import vim, vmodl
except ImportError:
    sys.stderr.write("ERROR: pyVmomi не установлен. Выполните: python3 -m venv /opt/zabbix-venv && /opt/zabbix-venv/bin/pip install pyvmomi\n")
    sys.exit(3)

_DEF = (os.environ.get("ESXI_VCENTER", ""), os.environ.get("ESXI_USER", ""),
        os.environ.get("ESXI_PWD", ""))
_CACHE_TTL = int(os.environ.get("ESXI_NTP_TTL", "900"))
_NTP_OFFSET = os.environ.get("ESXI_NTP_OFFSET", "1") != "0"
_NTP_WORKERS = int(os.environ.get("ESXI_NTP_WORKERS", "8"))
_COLLECT_TTL = int(os.environ.get("ESXI_COLLECT_TTL", "240"))
# Состояния HA-агента (vSphere HA), при которых хост не защищён HA.
_HA_BAD = ("networkisolated", "networkpartitionedfrommaster", "initializationerror",
           "uninitializationerror", "hostdown")
_DEADLINE = int(os.environ.get("ESXI_DEADLINE", "25"))
_CA_BUNDLE = os.environ.get("ESXI_CA_BUNDLE", "")
# Быстрый отказ вместо "Timeout while executing a shell script", если сеть зависла.
socket.setdefaulttimeout(float(os.environ.get("ESXI_TIMEOUT", "15")))

# Внутренний shortName политики питания -> отображаемая в vCenter метка
# (High performance = static, Balanced, Low power, Custom).
_POWER_LABELS = {
    "static": "High performance",
    "balanced": "Balanced",
    "lowPower": "Low power",
    "lowpower": "Low power",
    "custom": "Custom",
}


def _power_label(pp):
    """Возвращает отображаемую метку активной политики питания."""
    sn = getattr(pp, "shortName", "") or ""
    if sn:
        return _POWER_LABELS.get(sn, sn)
    return getattr(pp, "name", "") or str(getattr(pp, "key", ""))


def _cache_file(vc):
    h = hashlib.md5(vc.encode(), usedforsecurity=False).hexdigest()[:8]
    return os.path.join(tempfile.gettempdir(), "esxi_ntp_cache_%s.json" % h)


def _parse_vc(vc):
    """Из {$VMWARE.URL} извлекаем хост[:порт] (pyVmomi 9.x не ест полный URL)."""
    m = re.match(r"^(?:[a-z][a-z0-9+.-]*://)?([^/:]+)(?::(\d+))?(?=/|$)", vc, re.I)
    if not m:
        return vc, 443
    return m.group(1), int(m.group(2)) if m.group(2) else 443


# Разные версии pyVmomi по-разному открывают классы PropertyCollector:
# в 8.x и старше — vmodl.query.PropertyCollector.{PropertyFilterSpec,ObjectSpec,PropertySpec};
# в 9.x — vim.{PropertyFilterSpec,ObjectSpec,PropertySpec} (или как вложенные
# vim.PropertyCollector.{ObjectSpec,PropertySpec}). Собираем каждый класс
# независимо из возможных источников.
def _first(candidates):
    for c in candidates:
        if c is not None:
            return c
    return None

def _pc_attrs():
    pfs = _first([getattr(vim, "PropertyFilterSpec", None),
                  getattr(vmodl.query, "PropertyFilterSpec", None),
                  getattr(getattr(vmodl.query, "PropertyCollector", None),
                          "PropertyFilterSpec", None)])
    obj = _first([getattr(vim, "ObjectSpec", None),
                  getattr(getattr(vim, "PropertyCollector", None), "ObjectSpec", None),
                  getattr(getattr(vmodl.query, "PropertyCollector", None), "ObjectSpec", None)])
    prop = _first([getattr(vim, "PropertySpec", None),
                   getattr(getattr(vim, "PropertyCollector", None), "PropertySpec", None),
                   getattr(getattr(vmodl.query, "PropertyCollector", None), "PropertySpec", None)])
    return pfs, obj, prop

_PC, _PCS_obj, _PCS_prop = _pc_attrs()


def _batch_props(content, obj_specs, type_specs):
    """Один batch-запрос PropertyCollector: {obj_moref: {prop: val}}.

    obj_specs — список (obj, skip) managed-объектов; type_specs — список
    (type, [paths]). Возвращает словарь, ключ — moref. Если построение
    запроса невозможно, возвращает {}. Вызывающий код при пустом результате
    безопасно откатывается к похостовому чтению.
    """
    try:
        if _PC is None or _PCS_obj is None or _PCS_prop is None:
            return {}
        spec = _PC(
            objectSet=[_PCS_obj(obj=o, skip=(s or False)) for o, s in obj_specs],
            propSet=[_PCS_prop(type=t, pathSet=paths, all=False)
                     for t, paths in type_specs])
        result = {}
        for obj in content.propertyCollector.RetrieveProperties([spec]):
            result[obj.obj] = dict((p.name, p.val) for p in obj.propSet)
        return result
    except Exception as exc:
        if os.environ.get("ESXI_NTP_DEBUG"):
            sys.stderr.write("DEBUG batch error: %r: %s\n" % (exc, str(exc)))
        return {}


def _vmk_addrs(vnics):
    """([все IP vmk кроме 0.0.0.0/::], IP управляющего vmk0 или None)."""
    pairs = []
    for v in (vnics or []):
        ip = getattr(getattr(getattr(v, "spec", None), "ip", None), "ipAddress", "")
        if ip and ip not in ("0.0.0.0", "::"):
            pairs.append((getattr(v, "device", ""), ip))
    mgmt = [a for d, a in pairs if d == "vmk0"]
    return [a for _, a in pairs], (mgmt[0] if mgmt else None)


def _net_summary(pnics, vswitches, proxies):
    """Физические порты, используемые как аплинки vSwitch/DVS, и сводка по свитчам.

    Возвращает ({vmnic: {"up": 0/1, "mb": скорость}}, {switch: {"n": всего, "up": живых}}).
    Порты, не входящие ни в один свитч (свободные), игнорируются — иначе
    неподключённый порт давал бы вечную тревогу.
    """
    info = {}
    for n in (pnics or []):
        ls = getattr(n, "linkSpeed", None)
        mb = int(getattr(ls, "speedMb", 0) or 0) if ls is not None else 0
        info[getattr(n, "key", None)] = (getattr(n, "device", ""), mb)
    nics, sws = {}, {}
    groups = [(getattr(v, "name", ""), getattr(v, "pnic", None)) for v in (vswitches or [])]
    groups += [(getattr(p, "dvsName", ""), getattr(p, "pnic", None)) for p in (proxies or [])]
    for name, keys in groups:
        devs = [info[k] for k in (keys or []) if k in info]
        if not devs:
            continue            # внутренний свитч без аплинков
        for dev, mb in devs:
            nics[dev] = {"up": 1 if mb > 0 else 0, "mb": mb}
        sws[name] = {"n": len(devs), "up": sum(1 for _, mb in devs if mb > 0)}
    return nics, sws


def _mp_dead(luns):
    """Число путей в состоянии dead по всем LUN."""
    return sum(1 for lun in (luns or []) for p in (getattr(lun, "path", None) or [])
               if str(getattr(p, "pathState", "")).lower() == "dead")


def _ds_bad(mounts):
    """Имена томов/датасторов, недоступных хосту (accessible=False)."""
    bad = []
    for m in (mounts or []):
        mi = getattr(m, "mountInfo", None)
        if mi is not None and getattr(mi, "accessible", None) is False:
            bad.append(str(getattr(getattr(m, "volume", None), "name", "?")))
    return sorted(bad)


def _utc(dt):
    return dt.replace(tzinfo=timezone.utc) if dt.tzinfo is None else dt


def _ntp_offsets(vc_now, items, workers=8, clock=time.monotonic):
    """Смещение времени ESXi относительно vCenter, секунды (+ = ESXi впереди).

    vc_now — время vCenter (datetime), снятое один раз; к нему прибавляется
    прошедшее по монотонным часам, так что часы машины с Zabbix не важны.
    items — [(ключ, dateTimeSystem)]. Хост, не ответивший, в результат не попадает.
    Погрешность ~ половина RTT до хоста (десятки мс) — для порогов в секундах не важно.
    """
    if not items:
        return {}
    t0 = clock()
    base = _utc(vc_now)

    def one(item):
        key, dts = item
        try:
            host_dt = _utc(dts.QueryDateTime())
            expected = base.timestamp() + (clock() - t0)
            return key, round(host_dt.timestamp() - expected, 3)
        except Exception:
            return key, None

    with ThreadPoolExecutor(max_workers=max(1, workers)) as ex:
        return {k: v for k, v in ex.map(one, items) if v is not None}


def _fetch_slow(content, hosts):
    """Похостовое чтение (fallback для старых pyVmomi без batch-PropertyCollector).

    Полная копия рабочей логики исходного скрипта: каждый хост читается
    напрямую через configManager.*. Медленнее (число SOAP-вызовов ~N на хост),
    но полностью совместимо с любой версией pyVmomi.
    """
    out = {}
    for host in hosts:
        try:
            state = str(host.runtime.connectionState)
        except Exception:
            state = "unknown"
        rec = {"configured": 0, "ip": None, "mgmt_ip": None, "ts": time.time(),
               "state": state, "vmk": [], "storage": [], "dns": [], "power": "",
                   "pnic": None, "sw": None, "mp_dead": None, "ds_bad": None, "ha": None,
                   "ntp_offset": None, "mm": None}
        try:
            rec["mm"] = bool(host.runtime.inMaintenanceMode)
        except Exception:
            rec["mm"] = None
        if state != "connected":
            rec["error"] = "состояние хоста: %s" % state
        try:
            info = host.configManager.dateTimeSystem.dateTimeInfo
            ntp = info.ntpConfig
            cfg = 1 if (ntp and getattr(ntp, "server", None)) else 0
            running = None
            try:
                for s in (host.configManager.serviceSystem.service or []):
                    if getattr(s, "key", "") == "ntpd":
                        running = bool(getattr(s, "running", False))
                        break
            except Exception:
                pass
            rec["configured"] = cfg if running is None else (1 if (cfg and running) else 0)
        except Exception as exc:
            rec["configured"] = None
            rec["error"] = str(exc)
        try:
            vmk = host.configManager.networkSystem.networkConfig.vnic
            rec["vmk"], rec["mgmt_ip"] = _vmk_addrs(vmk)
        except Exception:
            rec["vmk"] = None
        try:
            hbas = host.configManager.storageSystem.storageDeviceInfo.hostBusAdapter
            rec["storage"] = [getattr(hba, "status", "unknown")
                              for hba in (hbas or [])]
        except Exception:
            rec["storage"] = None
        try:
            dns_cfg = host.config.network.dnsConfig
            rec["dns"] = list(getattr(dns_cfg, "address", None) or [])
        except Exception:
            rec["dns"] = None
        # Power Management: активная политика (проверка лицензий отключена —
        # у хостов под vCenter licenseManager отсутствует, чтение лицензий
        # vCenter требует прав Global.Licenses).
        try:
            pp = host.config.powerSystemInfo.currentPolicy
            rec["power"] = _power_label(pp)
        except Exception:
            rec["power"] = None
        rec["ip"] = rec["mgmt_ip"] or (rec["vmk"][0] if rec.get("vmk") else None)
        name = getattr(host, "name", None)
        out[name or ("host-%s" % id(host))] = rec
    return out


def _fetch(vc, user, pwd):
    """{<имя>: {'configured','vmk','storage','dns','power','ip','ts'}} — все хосты vCenter.

    Оптимизация: основной объём (name/ip/vmk/storage/dns/power + ссылки на
    dateTimeSystem/serviceSystem) забирается ОДНИМ batch-запросом
    PropertyCollector; NTP-детали (dateTimeInfo/service) вторым batch-запросом
    по собранным ссылкам, сеть (networkConfig) третьим. Число SOAP-вызовов не
    зависит от числа хостов (было ~N, стало ~2-3 на весь vCenter). Если
    batch-API недоступно — автоматический откат на _fetch_slow (похостовое
    чтение).
    """
    vc_host, vc_port = _parse_vc(vc)
    if _CA_BUNDLE:
        # Проверка цепочки по заданному CA/корню VMCA (имя не сверяем:
        # к vCenter часто обращаются по IP).
        ctx = ssl.create_default_context(cafile=_CA_BUNDLE)
        ctx.check_hostname = False
    else:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    si = SmartConnect(host=vc_host, port=vc_port, user=user, pwd=pwd, sslContext=ctx)
    try:
        content = si.RetrieveContent()
        view = content.viewManager.CreateContainerView(
            content.rootFolder, [vim.HostSystem], True)
        try:
            hosts = list(view.view)
        finally:
            view.Destroy()

        # Batch №1: инвентарные поля всех хостов + ссылки на managed-объекты.
        props = _batch_props(
            content,
            [(h, False) for h in hosts],
            [(vim.HostSystem, [
                "name", "summary.config.name", "runtime.connectionState",
                "runtime.inMaintenanceMode",
                "config.network.dnsConfig",
                "config.storageDevice.hostBusAdapter",
                "config.powerSystemInfo.currentPolicy",
                "configManager.dateTimeSystem", "configManager.serviceSystem",
                "configManager.networkSystem",
                "config.network.pnic", "config.network.vswitch",
                "config.network.proxySwitch",
                "config.storageDevice.multipathInfo",
                "config.fileSystemVolume.mountInfo",
                "runtime.dasHostState"])])

        # Fallback: если batch-PropertyCollector недоступен (старая версия
        # pyVmomi) и мы не собрали свойства ни для одного хоста — используем
        # похостовое чтение (как в исходной рабочей версии скрипта).
        if not props and hosts:
            return _fetch_slow(content, hosts)

        # Собираем все ссылки на managed-объекты для второго batch'а.
        refs = {}
        for h in hosts:
            d = props.get(h) or {}
            for key, t in (("configManager.dateTimeSystem", vim.host.DateTimeSystem),
                           ("configManager.serviceSystem", vim.host.ServiceSystem),
                           ("configManager.networkSystem", vim.host.NetworkSystem)):
                mo = d.get(key)
                if mo is not None:
                    refs[id(mo)] = (mo, t, key)
        dts_objs = [v[0] for k, v in refs.items() if v[2] == "configManager.dateTimeSystem"]
        svc_objs = [v[0] for k, v in refs.items() if v[2] == "configManager.serviceSystem"]
        net_objs = [v[0] for k, v in refs.items() if v[2] == "configManager.networkSystem"]

        # Batch №2a: NTP-детали (dateTimeInfo) и службы по ссылкам.
        ntp_props = {}
        if dts_objs or svc_objs:
            specs = []
            if dts_objs:
                specs.append((vim.host.DateTimeSystem, ["dateTimeInfo"]))
            if svc_objs:
                specs.append((vim.host.ServiceSystem, ["service"]))
            ntp_props = _batch_props(
                content,
                [(o, False) for o in (dts_objs + svc_objs)],
                specs)

        # Batch №2b: сетевой конфиг (vmknic + dns) по ссылкам networkSystem.
        net_props = {}
        if net_objs:
            net_props = _batch_props(
                content,
                [(o, False) for o in net_objs],
                [(vim.host.NetworkSystem, ["networkConfig"])])

        # Смещение времени: QueryDateTime() - метод, а не свойство, поэтому
        # по одному вызову на подключённый хост (параллельно).
        offsets = {}
        if _NTP_OFFSET:
            try:
                items = [(h, props[h]["configManager.dateTimeSystem"]) for h in hosts
                         if str((props.get(h) or {}).get("runtime.connectionState")) == "connected"
                         and (props.get(h) or {}).get("configManager.dateTimeSystem") is not None]
                offsets = _ntp_offsets(si.CurrentTime(), items, _NTP_WORKERS)
            except Exception:
                offsets = {}

        out = {}
        for host in hosts:
            d = props.get(host) or {}
            state = str(d.get("runtime.connectionState") or "unknown")
            rec = {"configured": 0, "ip": None, "mgmt_ip": None, "ts": time.time(),
                   "state": state, "vmk": [], "storage": [], "dns": [], "power": "",
                   "pnic": None, "sw": None, "mp_dead": None, "ds_bad": None, "ha": None,
                   "ntp_offset": None, "mm": None}
            mm = d.get("runtime.inMaintenanceMode")
            rec["mm"] = None if mm is None else bool(mm)
            if state != "connected":
                # Данных config.* нет: проверки вернут ошибку, а не 0.
                rec["error"] = "состояние хоста: %s" % state

            # NTP: protocol NTP + серверы + служба ntpd.
            running = None
            try:
                dts = d.get("configManager.dateTimeSystem")
                cfg = 0
                if dts is not None:
                    info = (ntp_props.get(dts) or {}).get("dateTimeInfo")
                    ntp = getattr(info, "ntpConfig", None) if info is not None else None
                    if ntp is None or not getattr(ntp, "server", None):
                        # Ленивый доступ: batch-запрос может недодать вложенные
                        # поля (как spec.ip у vmk), дочитываем напрямую.
                        try:
                            info = dts.dateTimeInfo
                            ntp = getattr(info, "ntpConfig", None)
                        except Exception:
                            ntp = None
                    cfg = 1 if (ntp and getattr(ntp, "server", None)) else 0
            except Exception:
                cfg = 0
            try:
                svc = d.get("configManager.serviceSystem")
                if svc is not None:
                    services = (ntp_props.get(svc) or {}).get("service") or []
                    if not services:
                        try:
                            services = getattr(svc, "service", None) or []
                        except Exception:
                            services = []
                    for s in services:
                        if getattr(s, "key", "") == "ntpd":
                            running = bool(getattr(s, "running", False))
                            break
            except Exception:
                pass
            rec["configured"] = cfg if running is None else (1 if (cfg and running) else 0)

            # VMkernel adapters: настроенные интерфейсы с IP (не 0.0.0.0)
            try:
                ns = d.get("configManager.networkSystem")
                vmk = None
                if ns is not None:
                    net_cfg = (net_props.get(ns) or {}).get("networkConfig")
                    if net_cfg is not None:
                        vmk = getattr(net_cfg, "vnic", None)
                    if not vmk:
                        vmk = getattr(getattr(ns, "networkConfig", None),
                                      "vnic", None)
                if vmk is None:
                    rec["vmk"] = None
                else:
                    rec["vmk"], rec["mgmt_ip"] = _vmk_addrs(vmk)
            except Exception:
                rec["vmk"] = None

            # Storage adapters: статусы hostBusAdapter (online/offline/unknown)
            try:
                hbas = d.get("config.storageDevice.hostBusAdapter")
                rec["storage"] = (None if hbas is None else
                                  [getattr(hba, "status", "unknown") for hba in hbas])
            except Exception:
                rec["storage"] = None

            # DNS: список DNS-серверов хоста (порядок: preferred, alternate, ...)
            try:
                dns_cfg = d.get("config.network.dnsConfig")
                rec["dns"] = (None if dns_cfg is None else
                              list(getattr(dns_cfg, "address", None) or []))
            except Exception:
                rec["dns"] = None

            # Power Management: активная политика
            try:
                pp = d.get("config.powerSystemInfo.currentPolicy")
                rec["power"] = None if pp is None else _power_label(pp)
            except Exception:
                rec["power"] = None
            rec["ntp_offset"] = offsets.get(host)
            # Сеть: аплинки vSwitch/DVS, мультипасинг, датасторы, HA-агент.
            try:
                if d.get("config.network.pnic") is not None:
                    rec["pnic"], rec["sw"] = _net_summary(
                        d.get("config.network.pnic"), d.get("config.network.vswitch"),
                        d.get("config.network.proxySwitch"))
            except Exception:
                rec["pnic"] = rec["sw"] = None
            try:
                mp = d.get("config.storageDevice.multipathInfo")
                rec["mp_dead"] = None if mp is None else _mp_dead(getattr(mp, "lun", None))
            except Exception:
                rec["mp_dead"] = None
            try:
                mounts = d.get("config.fileSystemVolume.mountInfo")
                rec["ds_bad"] = None if mounts is None else _ds_bad(mounts)
            except Exception:
                rec["ds_bad"] = None
            try:
                das = d.get("runtime.dasHostState")
                # Хост вне HA-кластера: состояния нет, это не проблема.
                rec["ha"] = str(getattr(das, "state", "") or "none") if das is not None else "none"
            except Exception:
                rec["ha"] = None
            rec["ip"] = rec["mgmt_ip"] or (rec["vmk"][0] if rec.get("vmk") else None)
            name = d.get("summary.config.name") or d.get("name") or host.name
            out[name] = rec
        if os.environ.get("ESXI_NTP_DEBUG"):
            sys.stderr.write("DEBUG hosts:\n%s\n" % json.dumps(
                out, ensure_ascii=False, indent=1))
        return out
    finally:
        Disconnect(si)


def _short(s):
    """esx01.domain.local -> esx01"""
    return s.split(".", 1)[0]


def _is_ip(s):
    return s.replace(".", "").isdigit() and s.count(".") == 3


def _read_cache(cf, ttl=None):
    """Свежие записи кэша ({} если кэша нет, он устарел или от старой версии)."""
    try:
        with open(cf) as f:
            now = time.time()
            fresh = {h: r for h, r in json.load(f).items()
                     if now - r.get("ts", 0) < (ttl or _CACHE_TTL)}
    except Exception:
        return {}
    # Кэш от старой версии скрипта (без новых полей) считаем невалидным.
    if not all("vmk" in r and "dns" in r and "state" in r and "sw" in r
               and "ntp_offset" in r and "mm" in r for r in fresh.values()):
        return {}
    return fresh


def _get_data(vc, user, pwd, ttl=None):
    """Данные хостов: из кэша, при устаревании — опрос vCenter с записью кэша.

    Обновление идёт под flock: параллельные запуски ждут первого, затем
    читают уже свежий кэш (один login в vCenter вместо N).
    """
    cf = _cache_file(vc)
    refresh = bool(os.environ.get("ESXI_NTP_REFRESH"))
    if not refresh:
        fresh = _read_cache(cf, ttl)
        if fresh:
            return fresh
    lock = open(cf + ".lock", "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if not refresh:
            fresh = _read_cache(cf, ttl)  # кто-то обновил, пока ждали блокировку
            if fresh:
                return fresh
        try:
            fresh = _fetch(vc, user, pwd)
        except BaseException:
            exc_type, exc, _ = sys.exc_info()
            sys.stderr.write("ERROR(connect): %s: %s | vc=%r parsed_host=%r parsed_port=%r\n"
                             % (exc_type.__name__, exc, vc, *_parse_vc(vc)))
            sys.exit(1)
        try:
            fd, tmp = tempfile.mkstemp(prefix=os.path.basename(cf) + ".",
                                       dir=os.path.dirname(cf))
            with os.fdopen(fd, "w") as f:
                json.dump(fresh, f)
            os.replace(tmp, cf)
        except Exception:
            pass
        return fresh
    finally:
        lock.close()


def _run_discover(vc, user, pwd):
    """LLD-вывод: все ESXi-хосты vCenter."""
    data = {"data": []}
    for name, rec in sorted(_get_data(vc, user, pwd).items()):
        data["data"].append({
            "{#ESXINAME}": name,
            "{#ESXIADDR}": str(rec.get("mgmt_ip") or rec.get("ip") or name),
        })
    sys.stdout.write(json.dumps(data))
    return


def _build_collect(fresh, extra):
    """Компактный JSON для шаблона. Недоступные значения опускаются (не null)."""
    dns_x, pwr_x = list(extra[0:2]), list(extra[2:3])
    out = {"v": 2, "ts": int(time.time()), "cnt": len(fresh),
           "hosts": {}, "pnic": {}, "sw": {},
           "lld": {"hosts": [], "pnic": [], "sw": []}}
    for name, rec in sorted(fresh.items()):
        out["lld"]["hosts"].append({
            "{#ESXINAME}": name,
            "{#ESXIADDR}": str(rec.get("mgmt_ip") or rec.get("ip") or name)})
        # alert: 1 — хосту можно слать алерты; 0 — не connected или Maintenance
        # Mode (вывод из кластера, обслуживание). Подавитель для всех триггеров.
        if rec.get("state") not in (None, "connected"):
            out["hosts"][name] = {"state": rec["state"], "alert": 0}
            continue
        h = {"state": "connected", "alert": 0 if rec.get("mm") else 1}
        for key, mode, ex in (("ntp", "ntp", []), ("vmk", "vmk", []),
                              ("stor", "storage", []), ("dns", "dns", dns_x),
                              ("pwr", "power", pwr_x)):
            v = _check_value(mode, rec, ex)
            if v is not None:
                h[key] = int(v)
        if rec.get("ntp_offset") is not None:
            h["ntp_off"] = rec["ntp_offset"]
        if rec.get("mp_dead") is not None:
            h["mp_dead"] = rec["mp_dead"]
        if rec.get("ds_bad") is not None:
            h["ds_bad"] = len(rec["ds_bad"])
            h["ds_names"] = ", ".join(rec["ds_bad"])
        if rec.get("ha") is not None:
            h["ha"] = rec["ha"]
            h["ha_ok"] = 0 if rec["ha"].lower() in _HA_BAD else 1
        out["hosts"][name] = h
        if rec.get("pnic"):
            out["pnic"][name] = rec["pnic"]
            out["lld"]["pnic"] += [{"{#ESXINAME}": name, "{#NIC}": n}
                                   for n in sorted(rec["pnic"])]
        if rec.get("sw"):
            out["sw"][name] = rec["sw"]
            out["lld"]["sw"] += [{"{#ESXINAME}": name, "{#VSWITCH}": n}
                                 for n in sorted(rec["sw"])]
    return out


def _resolve_mode(argv):
    """Определяет режим (+offset url/user/pwd) по 1-му аргументу.

    Возвращает (mode, key, arg_offset). arg_offset — с какого индекса в
    argv начинаются url/user/pwd: для ntp/discover это 2; для новых режимов
    с ключом хоста 2-м аргументом — 3 (argv[2] занят ключом).
    """
    a1 = argv[1].strip().lower().rstrip(".")
    modes = ("ntp", "vmk", "storage", "dns", "power")
    if a1 == "license":
        sys.stderr.write("ERROR: режим 'license' отключён: у хостов под vCenter "
                         "licenseManager отсутствует, а чтение лицензий vCenter "
                         "требует прав Global.Licenses\n")
        sys.exit(2)
    if a1 in modes:
        if len(argv) < 4:
            sys.stderr.write("ERROR: режиму %r нужен ключ хоста 2-м аргументом\n" % a1)
            sys.exit(2)
        return a1, argv[2].strip().lower().rstrip("."), 3
    if a1 in ("discover", "hosts", "collect"):
        return a1, "", 2
    return "ntp", a1, 2


def _check_value(mode, rec, extra):
    """Вычисляет 1/0 для режима по данным хоста (rec) и доп. аргументам.

    None = данные недоступны (хост не connected, свойство не прочитано):
    вызывающий код отдаёт ошибку (unsupported), а не ложный 0.
    """
    if rec.get("state") not in (None, "connected"):
        return None
    if mode == "ntp":
        return rec.get("configured")
    if mode == "vmk":
        addrs = rec.get("vmk")
        if addrs is None:
            return None
        return 1 if addrs else 0
    if mode == "storage":
        statuses = rec.get("storage")
        if statuses is None or not statuses:
            return None      # адаптеры не прочитаны
        # unknown (напр. встроенные/SAS-контроллеры, которые vCenter не
        # показывает) не считаем проблемой. Если хоть один известный адаптер
        # не online (offline/unbound) — 0: хост работает на оставшемся порту,
        # избыточность потеряна. Нет известных адаптеров — проблемы нет.
        known = [s for s in statuses if s != "unknown"]
        return 1 if all(s == "online" for s in known) else 0
    if mode == "dns":
        if rec.get("dns") is None:
            return None
        expect_pref = (extra[0] if len(extra) > 0 else "").strip().lower()
        expect_alt = (extra[1] if len(extra) > 1 else "").strip().lower()
        dns = [str(x).strip().lower() for x in rec["dns"]]
        if expect_pref or expect_alt:
            # Режим "конкретные IP": должны присутствовать оба указанных.
            if expect_pref and expect_pref not in dns:
                return 0
            if expect_alt and expect_alt not in dns:
                return 0
        else:
            # Режим "наличие": нужно не менее 2 серверов.
            if len(dns) < 2:
                return 0
        return 1
    if mode == "power":
        if rec.get("power") is None:
            return None      # политика недоступна (напр. управляется BIOS)
        expect = (extra[0] if len(extra) > 0 and extra[0].strip()
                  else "High performance").strip().lower()
        return 1 if str(rec["power"]).strip().lower() == expect else 0
    return None


def _find_host(fresh, key):
    """Ищет хост в fresh: точное имя или IP; короткое имя — только если
    однозначно. Подстроки и "единственный хост" не допускаются: иначе
    удалённый хост получал бы данные соседнего. Возвращает (name, rec)."""
    keyl = key.lower().rstrip(".")
    for name, rec in fresh.items():
        ip = str(rec.get("ip") or "").lower().rstrip(".")
        mip = str(rec.get("mgmt_ip") or "").lower().rstrip(".")
        if name.lower().rstrip(".") == keyl or keyl in (ip, mip):
            return name, rec
    if not _is_ip(keyl):
        short = [(n, r) for n, r in fresh.items()
                 if _short(n.lower().rstrip(".")) == _short(keyl)]
        if len(short) == 1:
            return short[0]
    return None, None


def _on_deadline(signum, frame):
    sys.stderr.write("ERROR: превышен общий лимит времени %d c (ESXI_DEADLINE)\n"
                     % _DEADLINE)
    sys.exit(1)


def main():
    if len(sys.argv) < 2:
        sys.stderr.write("usage: %s <host|discover|hosts|collect|ntp|vmk|storage|dns|power> "
                         "[<key>] [<url> <user> <pwd>] [extra...]\n" % sys.argv[0])
        sys.exit(2)

    if _DEADLINE > 0 and hasattr(signal, "SIGALRM"):
        signal.signal(signal.SIGALRM, _on_deadline)
        signal.alarm(_DEADLINE)

    mode, key, off = _resolve_mode(sys.argv)
    url = sys.argv[off] if len(sys.argv) > off else _DEF[0]
    user = sys.argv[off + 1] if len(sys.argv) > off + 1 else _DEF[1]
    pwd = sys.argv[off + 2] if len(sys.argv) > off + 2 else _DEF[2]
    extra = sys.argv[off + 3:]
    if not (url and user and pwd):
        sys.stderr.write("ERROR: задайте vCenter/учётку (аргументы или ESXI_*)\n")
        sys.exit(2)

    if mode == "discover":
        _run_discover(url, user, pwd)
        return

    fresh = _get_data(url, user, pwd, _COLLECT_TTL if mode == "collect" else None)
    if mode == "collect":
        sys.stdout.write(json.dumps(_build_collect(fresh, extra),
                                    ensure_ascii=False, separators=(",", ":")))
        return
    if mode == "hosts":
        sys.stdout.write("%d\n" % len(fresh))
        return
    name, rec = _find_host(fresh, key)
    if rec is None:
        sys.stderr.write("ERROR: хост %r не найден в %s. Известные хосты: %s\n"
                         % (key, url, ", ".join(sorted(fresh)) or "<пусто>"))
        sys.exit(1)
    v = _check_value(mode, rec, extra)
    if v is None:
        sys.stderr.write("ERROR: %s: не удалось прочитать данные (%s)\n"
                         % (name, rec.get("error") or "нет данных (свойство не прочитано)"))
        sys.exit(1)
    sys.stdout.write("%d\n" % v)


if __name__ == "__main__":
    main()