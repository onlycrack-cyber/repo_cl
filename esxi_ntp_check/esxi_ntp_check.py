#!/opt/zabbix-venv/bin/python3
"""
Проверки здоровья ESXi-хостов через vCenter (NTP, VMkernel, Storage, DNS,
License, Power). LLD-режим отдаёт все хосты vCenter.

Режимы (1-м аргументом, вместо хоста — режим, 2-м — имя хоста):
    esxi_ntp_check.py["ntp","{#ESXINAME}",url,user,pwd]
        -> 1 если NTP настроен (протокол NTP + серверы) И служба ntpd Running
    esxi_ntp_check.py["vmk","{#ESXINAME}",url,user,pwd]
        -> 1 если настроен хотя бы один VMkernel adapter с IP
    esxi_ntp_check.py["storage","{#ESXINAME}",url,user,pwd]
        -> 1 если все Storage Adapters online
    esxi_ntp_check.py["dns","{#ESXINAME}",url,user,pwd,pref,alt]
        -> 1 если оба указанных DNS есть; без аргументов — наличие >=2 DNS
    esxi_ntp_check.py["power","{#ESXINAME}",url,user,pwd,policy]
        -> 1 если активная политика = заданной (по умолч. High performance)
    esxi_ntp_check.py["discover",url,user,pwd]
        -> {"data":[{"{#ESXINAME}":...,"{#ESXIADDR}":...},...]}

Требование: /opt/zabbix-venv с pyVmomi 8.0.3+/9.x
Конфиг: по умолчанию из окружения ESXI_VCENTER/ESXI_USER/ESXI_PWD,
        либо аргументами.
Кэш: <tmp>/esxi_ntp_cache_<md5(url)>.json, TTL = ESXI_NTP_TTL (по умолч. 600 c).
     ESXI_NTP_REFRESH=1 — игнорировать кэш (разовый опрос vCenter).
Хост ищется по полному имени, IP или короткому имени (esx01 == esx01.domain.local).
"""

import hashlib
import json
import os
import re
import socket
import ssl
import sys
import tempfile
import time

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
                                   suppress_ragged_eofs=suppress_ragged_eofs,
                                   ciphers=ciphers)
    ssl.wrap_socket = _ssl_wrap_socket

try:
    from pyVim.connect import SmartConnect, Disconnect
    from pyVmomi import vim, vmodl
except ImportError:
    sys.stderr.write("ERROR: pyVmomi не установлен. Выполните: python3 -m venv /opt/zabbix-venv && /opt/zabbix-venv/bin/pip install pyvmomi\n")
    sys.exit(3)

_DEF = (os.environ.get("ESXI_VCENTER", ""), os.environ.get("ESXI_USER", ""),
        os.environ.get("ESXI_PWD", ""))
_CACHE_TTL = int(os.environ.get("ESXI_NTP_TTL", "600"))
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
    h = hashlib.md5(vc.encode()).hexdigest()[:8]
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

    obj_specs — список (obj, skip) манеджмент-объектов; type_specs — список
    (type, [paths]). Возвращает словарь keyed по болееф. Если построение
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


def _fetch_slow(content, hosts):
    """Похостовое чтение (fallback для старых pyVmomi без batch-PropertyCollector).

    Полная копия рабочей логики исходного скрипта: каждый хост читается
    напрямую через configManager.*. Медленнее (число SOAP-вызовов ~N на хост),
    но полностью совместимо с любой версией pyVmomi.
    """
    out = {}
    for host in hosts:
        try:
            ip = host.summary.config.ipAddress
        except Exception:
            ip = None
        rec = {"configured": 0, "ip": ip, "ts": time.time(),
               "vmk": [], "storage": [], "dns": [], "power": ""}
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
            ip_addrs = [getattr(getattr(v, "spec", None), "ip", None)
                        for v in (vmk or [])]
            configured = [getattr(ip, "ipAddress", "") for ip in ip_addrs
                          if getattr(ip, "ipAddress", "")]
            rec["vmk"] = [a for a in configured if a not in ("0.0.0.0", "::")]
        except Exception:
            rec["vmk"] = []
        try:
            hbas = host.configManager.storageSystem.storageDeviceInfo.hostBusAdapter
            rec["storage"] = [getattr(hba, "status", "unknown")
                              for hba in (hbas or [])]
        except Exception:
            rec["storage"] = []
        try:
            dns_cfg = host.config.network.dnsConfig
            rec["dns"] = list(getattr(dns_cfg, "address", None) or [])
        except Exception:
            rec["dns"] = []
        # Power Management: активная политика (проверка лицензий отключена —
        # у хостов под vCenter licenseManager отсутствует, чтение лицензий
        # vCenter требует прав Global.Licenses).
        try:
            pp = host.config.powerSystemInfo.currentPolicy
            rec["power"] = _power_label(pp)
        except Exception:
            rec["power"] = ""
        rec["ip"] = ip or (rec["vmk"][0] if rec.get("vmk") else None)
        name = getattr(host, "name", None)
        out[name or ("host-%s" % id(host))] = rec
    return out


def _fetch(vc, user, pwd):
    """{<имя>: {'configured','vmk','storage','dns','power','ip','ts'}} — все хосты vЦентра.

    Оптимизация: основной объём (name/ip/vmk/storage/dns/power + ссылки на
    dateTimeSystem/serviceSystem) забирается ОДНИМ batch-запросом
    PropertyCollector; NTP-детали (dateTimeInfo/service) вторым batch-запросом
    по собранным ссылкам, сеть (networkConfig) третьим. Число SOAP-вызовов не
    зависит от числа хостов (было ~N, стало ~2-3 на весь vCenter). Если
    batch-API недоступно — автоматический откат на _fetch_slow (похостовое
    чтение).
    """
    vc_host, vc_port = _parse_vc(vc)
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
                "name", "summary.config.name",
                "config.network.dnsConfig",
                "config.storageDevice.hostBusAdapter",
                "config.powerSystemInfo.currentPolicy",
                "configManager.dateTimeSystem", "configManager.serviceSystem",
                "configManager.networkSystem"])])

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

        out = {}
        for host in hosts:
            d = props.get(host) or {}
            ip = d.get("summary.config.ipAddress")
            rec = {"configured": 0, "ip": ip, "ts": time.time(),
                   "vmk": [], "storage": [], "dns": [], "power": ""}

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
                if not vmk:
                    vmk = d.get("config.network.vnic") or []
                ip_addrs = [getattr(getattr(v, "spec", None), "ip", None)
                            for v in (vmk or [])]
                configured = [getattr(ip, "ipAddress", "") for ip in ip_addrs
                              if getattr(ip, "ipAddress", "")]
                rec["vmk"] = [a for a in configured if a not in ("0.0.0.0", "::")]
            except Exception:
                rec["vmk"] = []

            # Storage adapters: статусы hostBusAdapter (online/offline/unknown)
            try:
                hbas = (d.get("config.storageDevice.hostBusAdapter")) or []
                rec["storage"] = [getattr(hba, "status", "unknown") for hba in hbas]
            except Exception:
                rec["storage"] = []

            # DNS: список DNS-серверов хоста (порядок: preferred, alternate, ...)
            try:
                dns_cfg = d.get("config.network.dnsConfig")
                rec["dns"] = list(getattr(dns_cfg, "address", None) or [])
            except Exception:
                rec["dns"] = []

            # Power Management: активная политика
            try:
                pp = d.get("config.powerSystemInfo.currentPolicy")
                rec["power"] = _power_label(pp)
            except Exception:
                rec["power"] = ""
            rec["ip"] = ip or (rec["vmk"][0] if rec.get("vmk") else None)
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


def _get_data(vc, user, pwd):
    """Данные хостов: из кэша, при устаревании — опрос vCenter с записью кэша."""
    cf = _cache_file(vc)
    fresh = {}
    if not os.environ.get("ESXI_NTP_REFRESH"):
        try:
            with open(cf) as f:
                now = time.time()
                fresh = {h: r for h, r in json.load(f).items()
                         if now - r.get("ts", 0) < _CACHE_TTL}
            # Кэш от старой версии скрипта (без новых полей) считаем невалидным.
            if not all("vmk" in r and "dns" in r for r in fresh.values()):
                fresh = {}
        except Exception:
            pass
    if not fresh:
        try:
            fresh = _fetch(vc, user, pwd)
        except BaseException:
            exc_type, exc, _ = sys.exc_info()
            sys.stderr.write("ERROR(connect): %s: %s | vc=%r parsed_host=%r parsed_port=%r\n"
                             % (exc_type.__name__, exc, vc, *_parse_vc(vc)))
            sys.exit(1)
        try:
            with open(cf + ".tmp", "w") as f:
                json.dump(fresh, f)
            os.replace(cf + ".tmp", cf)
        except Exception:
            pass
    return fresh


def _run_discover(vc, user, pwd):
    """LLD-вывод: все ESXi-хосты vCenter."""
    data = {"data": []}
    for name, rec in sorted(_get_data(vc, user, pwd).items()):
        data["data"].append({
            "{#ESXINAME}": name,
            "{#ESXIADDR}": str(rec.get("ip") or name),
        })
    sys.stdout.write(json.dumps(data))
    return


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
    if a1 == "discover":
        return "discover", "", 2
    return "ntp", a1, 2


def _check_value(mode, rec, extra):
    """Вычисляет 1/0 для режима по данным хоста (rec) и доп. аргументам."""
    if mode == "ntp":
        return rec.get("configured")
    if mode == "vmk":
        addrs = rec.get("vmk") or []
        return 1 if addrs else 0
    if mode == "storage":
        statuses = rec.get("storage") or []
        # unknown (напр. встроенные/SAS-контроллеры, которые vCenter не
        # показывает) не считаем проблемой; алерт — только при явном offline.
        known = [s for s in statuses if s != "unknown"]
        if not known:
            return 0 if not statuses else 0
        return 1 if all(s == "online" for s in known) else 0
    if mode == "dns":
        expect_pref = (extra[0] if len(extra) > 0 else "").strip().lower()
        expect_alt = (extra[1] if len(extra) > 1 else "").strip().lower()
        dns = [str(x).strip().lower() for x in (rec.get("dns") or [])]
        if expect_pref or expect_alt:
            # Режим "конкретные IP": должны присутствовать оба указанных.
            if expect_pref and expect_pref not in dns:
                return 0
            if expect_alt and expect_alt not in dns:
                return 0
        else:
            # Режим "наличие": Preferred = 1-й сервер, Alternate = 2-й.
            if len(dns) < 2:
                return 0
        return 1
    if mode == "power":
        expect = (extra[0] if len(extra) > 0 else "High performance").strip().lower()
        actual = str(rec.get("power") or "").strip().lower()
        return 1 if actual == expect else 0
    return None


def _find_host(fresh, key):
    """Ищет хост в fresh по имени/IP/короткому имени. Возвращает (name, rec)."""
    keyl = key.lower().rstrip(".")
    for name, rec in fresh.items():
        ip = str(rec.get("ip") or "").lower().rstrip(".")
        n = name.lower().rstrip(".")
        if (n == keyl or ip == keyl
                or (not _is_ip(keyl) and _short(n) == _short(keyl))):
            return name, rec
    # Fallback: если хост один — берём его; иначе по подстроке.
    if len(fresh) == 1:
        n, r = next(iter(fresh.items()))
        return n, r
    if not _is_ip(keyl):
        sub = [(n, r) for n, r in fresh.items()
               if keyl in n.lower() or keyl in str(r.get("ip") or "").lower()]
        if len(sub) == 1:
            return sub[0]
    return None, None


def main():
    if len(sys.argv) < 2:
        sys.stderr.write("usage: %s <host|discover|ntp|vmk|storage|dns|power> "
                         "[<key>] [<url> <user> <pwd>] [extra...]\n" % sys.argv[0])
        sys.exit(2)

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

    fresh = _get_data(url, user, pwd)
    name, rec = _find_host(fresh, key)
    if rec is None:
        sys.stderr.write("ERROR: хост %r не найден в %s. Известные хосты: %s\n"
                         % (key, url, ", ".join(sorted(fresh)) or "<пусто>"))
        sys.exit(1)
    v = _check_value(mode, rec, extra)
    if v is None:
        sys.stderr.write("ERROR: %s: не удалось прочитать данные (%s)\n"
                         % (name, rec.get("error") or "нет данных"))
        sys.exit(1)
    sys.stdout.write("%d\n" % v)


if __name__ == "__main__":
    main()