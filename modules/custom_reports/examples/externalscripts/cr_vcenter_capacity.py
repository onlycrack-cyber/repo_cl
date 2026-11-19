#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#
# Report script for Custom Problem Reports: free resources of vCenter clusters.
#
# Reads the CSV of cluster_resources_csv.py (the full format, or the old 5-column one) from a file or by running
# a script, and prints a JSON report: totals, a summary per vCenter, clusters with free resources below the thresholds,
# failed vCenters and the age of the data.
#
# Usage (parameters of the schedule, one per line):
#   cr_vcenter_capacity.py <CSV file or script> [CPU threshold] [memory threshold] [storage threshold] [options]
#
#   A threshold is either a percentage of the capacity ("15%", needs the full format) or an absolute value:
#   GHz for CPU, GB for memory and storage. Defaults: 15% / 15% / 15% for the full format, 200 / 500 / 20000 for
#   the old format.
#
# Options:
#   --max-age=MINUTES   a CSV file older than this is reported as stale (default 60; 0 - do not check)
#   --timeout=SECONDS   time limit of a source script (default 50; the module stops report scripts after
#                       "script_timeout", 60 s by default)
#   --title=TEXT        report title
#
# Recommended: cron / systemd timer runs "cluster_resources_csv.py --outfile=/var/lib/zabbix/cluster_free.csv" every
# few minutes, the report reads the file: fast, and the web server needs no vCenter credentials.
#
# Exit code: 0 - report printed; 2 - bad parameters; 3 - no data (source failed, all vCenters failed, empty CSV).

import csv
import io
import json
import os
import subprocess
import sys
import time

LANG = os.environ.get("CR_LANGUAGE", "ru")

TEXTS = {
    "title": ("Свободные ресурсы кластеров vCenter", "Free resources of vCenter clusters"),
    "cpu": ("CPU", "CPU"), "memory": ("память", "memory"), "storage": ("хранилище", "storage"),
    "kpi_cpu": ("Свободно CPU, ГГц", "Free CPU, GHz"),
    "kpi_memory": ("Свободно памяти, ТБ", "Free memory, TB"),
    "kpi_storage": ("Свободно в хранилище, ТБ", "Free storage, TB"),
    "kpi_low": ("Кластеров с нехваткой", "Clusters low on resources"),
    "kpi_failed": ("vCenter с ошибкой", "Failed vCenters"),
    "by_vcenter": ("По vCenter", "By vCenter"),
    "clusters": ("Кластеры (выделены значения ниже порога)", "Clusters (values below the threshold highlighted)"),
    "errors": ("Ошибки сбора данных", "Collection errors"),
    "unparsed": ("Нераспознанные строки", "Lines not parsed"),
    "col_dc": ("Датацентр", "Datacenter"), "col_cluster": ("Кластер", "Cluster"),
    "col_hosts": ("Хостов", "Hosts"), "col_unavailable": ("недоступно", "unavailable"),
    "col_clusters": ("Кластеров", "Clusters"), "col_low": ("С нехваткой", "Low on resources"),
    "col_cpu": ("CPU свободно, ГГц", "Free CPU, GHz"), "col_memory": ("Память свободно, ГБ", "Free memory, GB"),
    "col_memory_tb": ("Память свободно, ТБ", "Free memory, TB"),
    "col_storage": ("Хранилище свободно, ТБ", "Free storage, TB"),
    "col_provisioned": ("Выделено, % ёмкости", "Provisioned, % of capacity"),
    "col_error": ("Ошибка", "Error"), "col_line": ("Строка", "Line"), "col_content": ("Содержимое", "Content"),
    "subject": ("кластеров с нехваткой ресурсов: %d из %d", "clusters low on resources: %d of %d"),
    "summary": ("Кластеров: %d, с нехваткой ресурсов: %d (CPU: %d, память: %d, хранилище: %d). Пороги: %s.",
                "Clusters: %d, low on resources: %d (CPU: %d, memory: %d, storage: %d). Thresholds: %s."),
    "ha_note": ("Свободные CPU и память — с учётом резерва HA.", "Free CPU and memory exclude the HA reserve."),
    "legacy_note": ("Старый формат CSV: нет ёмкости, резерва HA и итогов по vCenter, общие хранилища в итогах могут "
                    "быть посчитаны дважды.",
                    "Old CSV format: no capacity, HA reserve and vCenter totals; shared datastores may be counted "
                    "twice in the totals."),
    "failed": ("Не удалось собрать данные: %s.", "Data not collected: %s."),
    "stale": ("Данные устарели: файл обновлён %s назад (%s).", "Stale data: the file was updated %s ago (%s)."),
    "data_time": ("Данные: %s.", "Data: %s."),
    "unknown": ("нет данных", "no data"),
    "unparsed_note": ("Нераспознанных строк: %d.", "Lines not parsed: %d."),
    "repaired_note": ("Строки с пробелами вместо запятой разобраны автоматически: %s.",
                      "Lines with spaces instead of commas were split automatically: %s."),
    "partial": ("Нет данных о загрузке (%s): %s — итоги без них.", "No usage data (%s): %s - totals without them."),
    "all_failed": ("нет данных ни по одному кластеру", "no data for any cluster"),
}


def t(key):
    ru, en = TEXTS[key]
    return ru if LANG == "ru" else en


def fail(message, code=3):
    sys.stderr.write(message.rstrip() + "\n")
    sys.exit(code)


def number(value):
    """Float or None for an empty / non-numeric value ("1 234,5" is accepted)."""
    value = (value or "").strip().replace(" ", "").replace(",", ".")
    try:
        return float(value)
    except ValueError:
        return None


def parse_threshold(text, default):
    """("percent", 15.0) or ("absolute", 200.0)."""
    text = (text or default).strip()
    if text.endswith("%"):
        value = number(text[:-1])
        kind = "percent"
    else:
        value = number(text)
        kind = "absolute"
    if value is None or value < 0:
        fail("bad threshold: %r" % text, 2)
    return kind, value


def duration(seconds):
    minutes = int(seconds // 60)
    if minutes < 60:
        return ("%d мин" if LANG == "ru" else "%d min") % minutes
    hours = minutes // 60
    if hours < 48:
        return ("%d ч %d мин" if LANG == "ru" else "%d h %d min") % (hours, minutes % 60)
    return ("%d дн" if LANG == "ru" else "%d days") % (hours // 24)


def read_source(source, timeout, max_age):
    """CSV text and notes about the data: (text, stale note or None, data time note or None)."""
    if os.path.isfile(source) and os.access(source, os.X_OK) and not source.endswith(".csv"):
        try:
            proc = subprocess.run([source], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)
        except subprocess.TimeoutExpired:
            fail("%s timed out after %d s" % (source, timeout))
        except OSError as e:
            fail("cannot run %s: %s" % (source, e))
        text = proc.stdout.decode("utf-8", "replace")
        stderr = proc.stderr.decode("utf-8", "replace").strip()
        if stderr:
            sys.stderr.write(stderr + "\n")
        # The collector exits with 1 if every vCenter failed: the rows still explain why.
        if proc.returncode != 0 and len(text.strip().splitlines()) < 2:
            fail("%s exited with code %d: %s" % (source, proc.returncode, stderr[-1000:]))
        return text, None, None

    try:
        with open(source, "r", encoding="utf-8-sig") as f:
            text = f.read()
        mtime = os.path.getmtime(source)
    except OSError as e:
        fail("cannot read %s: %s" % (source, e))

    age = time.time() - mtime
    when = time.strftime("%Y-%m-%d %H:%M", time.localtime(mtime))
    stale = t("stale") % (duration(age), when) if max_age > 0 and age > max_age * 60 else None
    return text, stale, t("data_time") % when


def parse_csv(text):
    """(rows as dicts, unparsed [(line, text)], repaired line numbers, full format?)."""
    reader = csv.reader(io.StringIO(text))
    header = None
    rows = []
    unparsed = []
    repaired = []
    for record in reader:
        if not record or all(not v.strip() for v in record):
            continue
        if header is None:
            header = [h.strip().lower() for h in record]
            continue
        if len(record) < len(header):
            # "cluster1   184.41": spaces instead of the comma.
            split = [part for value in record for part in (value.split() or [""])]
            if len(split) == len(header):
                record = split
                repaired.append(reader.line_num)
        if len(record) != len(header):
            unparsed.append((reader.line_num, ",".join(record)))
            continue
        rows.append({key: value.strip() for key, value in zip(header, record)})

    if header is None:
        fail("empty CSV")
    for key in ("vcenter", "cluster", "cpu_free_ghz", "memory_free_gb", "storage_free_gb"):
        if key not in header:
            fail("CSV has no column %r (columns: %s)" % (key, ", ".join(header)))
    return rows, unparsed, repaired, "type" in header


def cell(value, low=False, digits=1):
    text = t("unknown") if value is None else ("%.*f" % (digits, value))
    return {"value": text, "color": "high"} if low else text


def main(argv):
    options = {"max-age": "60", "timeout": "50", "title": t("title")}
    positional = []
    for arg in argv:
        if arg.startswith("--") and "=" in arg:
            key, value = arg[2:].split("=", 1)
            if key not in options:
                fail("unknown option --%s" % key, 2)
            options[key] = value
        else:
            positional.append(arg)

    if not positional:
        fail("usage: cr_vcenter_capacity.py <CSV file or script> [CPU] [memory] [storage] [--max-age=MIN] "
             "[--timeout=SEC] [--title=TEXT]", 2)

    max_age = number(options["max-age"])
    timeout = number(options["timeout"])
    if max_age is None or timeout is None:
        fail("--max-age and --timeout must be numbers", 2)

    text, stale, data_time = read_source(positional[0], int(timeout), max_age)
    rows, unparsed, repaired, full = parse_csv(text)

    defaults = ("15%", "15%", "15%") if full else ("200", "500", "20000")
    thresholds = {res: parse_threshold(positional[i + 1] if len(positional) > i + 1 else None, defaults[i])
                  for i, res in enumerate(("cpu", "memory", "storage"))}
    if not full and any(kind == "percent" for kind, _ in thresholds.values()):
        fail("percent thresholds need the full CSV format of cluster_resources_csv.py (capacity columns)", 2)

    # Errors: "error" rows of the full format, "ERROR: ..." clusters of the old one.
    errors = []
    clusters = []
    totals = {}
    for row in rows:
        if row.get("status") == "error":
            errors.append((row["vcenter"], row.get("error", "")))
        elif not full and row["cluster"].startswith("ERROR:"):
            errors.append((row["vcenter"], row["cluster"][len("ERROR:"):].strip()))
        elif full and row.get("type") == "vcenter":
            totals[row["vcenter"]] = row
        else:
            clusters.append(row)

    if not clusters:
        fail("%s. %s" % (t("all_failed"), "; ".join("%s: %s" % e for e in errors) or "empty CSV"))

    resources = {"cpu": ("cpu_free_ghz", "cpu_total_ghz", "cpu_ha_reserve_ghz"),
                 "memory": ("memory_free_gb", "memory_total_gb", "memory_ha_reserve_gb"),
                 "storage": ("storage_free_gb", "storage_total_gb", None)}

    def free(row, res):
        """Free value of a resource, minus the HA reserve; None if unknown."""
        value = number(row.get(resources[res][0]))
        reserve = number(row.get(resources[res][2])) if resources[res][2] else None
        if value is None:
            return None
        return max(0.0, value - (reserve or 0.0))

    def low(row, res):
        value = free(row, res)
        if value is None:
            return False
        kind, limit = thresholds[res]
        if kind == "percent":
            total = number(row.get(resources[res][1]))
            return bool(total) and value * 100.0 / total < limit
        return value < limit

    low_count = {res: 0 for res in resources}
    low_clusters = 0
    table = []
    by_vcenter = {}

    for row in sorted(clusters, key=lambda r: (r["vcenter"].lower(), r.get("datacenter", "").lower(),
                                               r["cluster"].lower())):
        flags = {res: low(row, res) for res in resources}
        for res, flag in flags.items():
            low_count[res] += flag
        low_clusters += any(flags.values())

        vc = by_vcenter.setdefault(row["vcenter"], {"clusters": 0, "low": 0, "cpu": 0.0, "memory": 0.0,
                                                    "storage": 0.0, "unknown": {}})
        vc["clusters"] += 1
        vc["low"] += any(flags.values())
        for res in resources:
            value = free(row, res)
            if value is None:
                vc["unknown"].setdefault(res, []).append(row["cluster"])
            else:
                vc[res] += value

        line = [row["vcenter"]]
        if full:
            line.append(row.get("datacenter", ""))
        line.append(row["cluster"])
        if full:
            unavailable = int(number(row.get("hosts_unavailable")) or 0)
            hosts = "%d" % int(number(row.get("hosts")) or 0)
            line.append({"value": "%s (%s: %d)" % (hosts, t("col_unavailable"), unavailable), "color": "warning"}
                        if unavailable else hosts)

        storage = free(row, "storage")
        line += [cell(free(row, "cpu"), flags["cpu"]), cell(free(row, "memory"), flags["memory"]),
                 cell(None if storage is None else storage / 1024, flags["storage"])]

        if full:
            total = number(row.get("storage_total_gb"))
            provisioned = number(row.get("storage_provisioned_gb"))
            if total and provisioned is not None:
                pct = provisioned * 100.0 / total
                line.append({"value": "%.0f%%" % pct, "color": "warning"} if pct > 100 else "%.0f%%" % pct)
            else:
                line.append(t("unknown"))
        table.append(line)

    # vCenter totals: from the "vcenter" rows (shared datastores counted once), otherwise sums of clusters.
    # A cluster without usage data does not hide the totals: they are summed over the known clusters and the
    # clusters without data are named in the summary.
    vc_rows = []
    sums = {"cpu": 0.0, "memory": 0.0, "storage": 0.0}
    missing = {}
    for name, vc in sorted(by_vcenter.items()):
        total_row = totals.get(name)
        values = {}
        for res in resources:
            value = free(total_row, res) if total_row is not None else None
            values[res] = value if value is not None else vc[res]
            sums[res] += values[res]
            for cluster in vc["unknown"].get(res, []):
                missing.setdefault(res, []).append("%s/%s" % (name, cluster))
        vc_rows.append([name, str(vc["clusters"]),
                        {"value": str(vc["low"]), "color": "warning"} if vc["low"] else "0",
                        cell(values["cpu"]), cell(None if values["memory"] is None else values["memory"] / 1024),
                        cell(None if values["storage"] is None else values["storage"] / 1024)])

    def describe(res, unit_ru, unit_en):
        kind, value = thresholds[res]
        label = t(res)
        if kind == "percent":
            return "%s < %g%%" % (label, value)
        return "%s < %g %s" % (label, value, unit_ru if LANG == "ru" else unit_en)

    summary = [t("summary") % (len(clusters), low_clusters, low_count["cpu"], low_count["memory"],
                               low_count["storage"], ", ".join([describe("cpu", "ГГц", "GHz"),
                                                                describe("memory", "ГБ", "GB"),
                                                                describe("storage", "ГБ", "GB")]))]
    if full:
        summary.append(t("ha_note"))
    else:
        summary.append(t("legacy_note"))
    if data_time:
        summary.append(data_time)
    for res, names in sorted(missing.items()):
        summary.append(t("partial") % (t(res), ", ".join(names)))
    if repaired:
        summary.append(t("repaired_note") % ", ".join(str(n) for n in repaired))
    if errors:
        summary.append(t("failed") % ", ".join(sorted({e[0] for e in errors})))
    if stale:
        summary.append(stale)
    if unparsed:
        summary.append(t("unparsed_note") % len(unparsed))

    # A failed vCenter is an error (data incomplete); stale data or clusters below the thresholds - a warning.
    status = "error" if errors else ("warning" if stale or low_clusters or unparsed else "ok")

    def kpi_value(res, divider):
        return "%.1f" % (sums[res] / divider)

    kpi = [{"label": t("kpi_cpu"), "value": kpi_value("cpu", 1)},
           {"label": t("kpi_memory"), "value": kpi_value("memory", 1024)},
           {"label": t("kpi_storage"), "value": kpi_value("storage", 1024)},
           {"label": t("kpi_low"), "value": "%d / %d" % (low_clusters, len(clusters)),
            "color": "warning" if low_clusters else "ok"}]
    if errors:
        kpi.append({"label": t("kpi_failed"), "value": str(len({e[0] for e in errors})), "color": "disaster"})

    columns = ["vCenter"] + ([t("col_dc")] if full else []) + [t("col_cluster")] + \
        ([t("col_hosts")] if full else []) + [t("col_cpu"), t("col_memory"), t("col_storage")] + \
        ([t("col_provisioned")] if full else [])

    tables = []
    if errors:
        tables.append({"title": t("errors"), "columns": ["vCenter", t("col_error")],
                       "rows": [[name, {"value": message, "color": "disaster"}] for name, message in errors]})
    tables.append({"title": t("by_vcenter"), "columns": ["vCenter", t("col_clusters"), t("col_low"), t("col_cpu"),
                                                          t("col_memory_tb"), t("col_storage")], "rows": vc_rows})
    tables.append({"title": t("clusters"), "columns": columns, "rows": table})
    if unparsed:
        tables.append({"title": t("unparsed"), "columns": [t("col_line"), t("col_content")],
                       "rows": [[str(n), line] for n, line in unparsed]})

    report = {
        "title": options["title"],
        "subject": t("subject") % (low_clusters, len(clusters)),
        "summary": " ".join(summary),
        "status": status,
        "kpi": kpi,
        "tables": tables
    }
    sys.stdout.write(json.dumps(report, ensure_ascii=False, indent=1) + "\n")


if __name__ == "__main__":
    main(sys.argv[1:])
