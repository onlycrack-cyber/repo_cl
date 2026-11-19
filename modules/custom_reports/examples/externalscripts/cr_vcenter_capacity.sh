#!/bin/sh
# Report script for Custom Problem Reports: free resources of vCenter clusters.
#
# Runs a script that prints CSV "vcenter,cluster,cpu_free_ghz,memory_free_gb,storage_free_gb" (the first line is
# the header) and turns it into a JSON report: totals, a summary per vCenter and clusters with low free resources
# highlighted.
#
# Usage: cr_vcenter_capacity.sh <script or CSV file> [CPU min, GHz] [memory min, GB] [storage min, GB]
#        defaults: 200 500 20000
# Example (parameters of the schedule, one per line):
#        /usr/lib/zabbix/externalscripts/vcenter_free.py
#        150
#        512
#        10000

SOURCE=$1
CPU_MIN=${2:-200}
MEM_MIN=${3:-500}
STORAGE_MIN=${4:-20000}

if [ -z "$SOURCE" ]; then
	echo "usage: $0 <script or CSV file> [CPU min, GHz] [memory min, GB] [storage min, GB]" >&2
	exit 2
fi

case "$CPU_MIN$MEM_MIN$STORAGE_MIN" in
	*[!0-9.]*) echo "thresholds must be numbers: $CPU_MIN $MEM_MIN $STORAGE_MIN" >&2; exit 2 ;;
esac

if [ -x "$SOURCE" ] && [ ! -d "$SOURCE" ]; then
	DATA=$("$SOURCE") || { echo "$SOURCE failed (exit code $?)" >&2; exit 3; }
elif [ -r "$SOURCE" ]; then
	DATA=$(cat "$SOURCE")
else
	echo "cannot run or read $SOURCE" >&2
	exit 2
fi

printf '%s\n' "$DATA" | LC_ALL=C awk -v cpu_min="$CPU_MIN" -v mem_min="$MEM_MIN" -v st_min="$STORAGE_MIN" \
		-v lang="${CR_LANGUAGE:-ru}" -v date="${CR_DATE:-$(date +%F)}" '
	function esc(s) {
		gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); gsub(/\t/, " ", s); gsub(/\r/, "", s)
		return s
	}
	function t(ru, en) { return lang == "ru" ? ru : en }
	function cell(value, low) { return low ? sprintf("{\"value\": \"%s\", \"color\": \"high\"}", value) : "\"" value "\"" }
	function isnum(s) { return s ~ /^[0-9]+(\.[0-9]+)?$/ }

	NR == 1 { next }
	/^[[:space:]]*$/ { next }
	{
		line = $0
		sub(/\r$/, "", line)
		# A space instead of the comma ("cluster1   184.41") is repaired; other broken lines are reported.
		n = split(line, f, ",")
		if (n < 5) {
			gsub(/[[:space:]]+/, ",", line)
			n = split(line, f, ",")
		}
		for (i = 1; i <= n; i++) { gsub(/^[[:space:]]+|[[:space:]]+$/, "", f[i]) }

		if (n != 5 || !isnum(f[3]) || !isnum(f[4]) || !isnum(f[5])) {
			bad = bad (bad == "" ? "" : ",\n    ") sprintf("[\"%d\", \"%s\"]", NR, esc($0))
			bad_count++
			next
		}

		vc = f[1]; cl = f[2]; cpu = f[3] + 0; mem = f[4] + 0; st = f[5] + 0
		total++
		sum_cpu += cpu; sum_mem += mem; sum_st += st

		if (!(vc in vc_count)) { vc_order[++vcs] = vc }
		vc_count[vc]++; vc_cpu[vc] += cpu; vc_mem[vc] += mem; vc_st[vc] += st

		low_cpu = cpu < cpu_min; low_mem = mem < mem_min; low_st = st < st_min
		if (low_cpu || low_mem || low_st) { low++; vc_low[vc]++ }
		if (low_cpu) { low_cpu_count++ }
		if (low_mem) { low_mem_count++ }
		if (low_st) { low_st_count++ }

		row = sprintf("[\"%s\", \"%s\", %s, %s, %s]", esc(vc), esc(cl),
			cell(sprintf("%.1f", cpu), low_cpu), cell(sprintf("%.1f", mem), low_mem),
			cell(sprintf("%.1f", st / 1024), low_st))
		rows = rows (rows == "" ? "" : ",\n    ") row
	}
	END {
		status = total == 0 ? "error" : (low > 0 ? "warning" : "ok")
		printf "{\n  \"title\": \"%s\",\n", t("Свободные ресурсы кластеров vCenter", "Free resources of vCenter clusters")
		printf "  \"subject\": \"%s\",\n", sprintf(t("кластеров с нехваткой ресурсов: %d из %d", \
			"clusters low on resources: %d of %d"), low, total)
		printf "  \"summary\": \"%s\",\n", esc(sprintf(t("%s. Кластеров: %d, с нехваткой ресурсов: %d " \
			"(CPU: %d, память: %d, хранилище: %d). Пороги: CPU < %s ГГц, память < %s ГБ, хранилище < %s ГБ.", \
			"%s. Clusters: %d, low on resources: %d (CPU: %d, memory: %d, storage: %d). " \
			"Thresholds: CPU < %s GHz, memory < %s GB, storage < %s GB."), date, total, low, low_cpu_count, \
			low_mem_count, low_st_count, cpu_min, mem_min, st_min) \
			(bad_count > 0 ? sprintf(t(" Не разобрано строк: %d.", " Lines not parsed: %d."), bad_count) : ""))
		printf "  \"status\": \"%s\",\n", status
		printf "  \"kpi\": [\n"
		printf "    {\"label\": \"%s\", \"value\": \"%.1f\"},\n", t("Свободно CPU, ГГц", "Free CPU, GHz"), sum_cpu
		printf "    {\"label\": \"%s\", \"value\": \"%.1f\"},\n", t("Свободно памяти, ТБ", "Free memory, TB"), sum_mem / 1024
		printf "    {\"label\": \"%s\", \"value\": \"%.1f\"},\n", t("Свободно в хранилище, ТБ", "Free storage, TB"),
			sum_st / 1024
		printf "    {\"label\": \"%s\", \"value\": \"%d / %d\", \"color\": \"%s\"}\n  ],\n",
			t("Кластеров с нехваткой", "Clusters low on resources"), low, total, (low > 0 ? "warning" : "ok")

		printf "  \"tables\": [\n"
		printf "    {\"title\": \"%s\", \"columns\": [\"vCenter\", \"%s\", \"%s\", \"%s\", \"%s\", \"%s\"], \"rows\": [\n    ",
			t("По vCenter", "By vCenter"), t("Кластеров", "Clusters"), t("С нехваткой", "Low on resources"),
			t("CPU, ГГц", "CPU, GHz"), t("Память, ТБ", "Memory, TB"), t("Хранилище, ТБ", "Storage, TB")
		for (i = 1; i <= vcs; i++) {
			vc = vc_order[i]
			printf "%s[\"%s\", \"%d\", %s, \"%.1f\", \"%.1f\", \"%.1f\"]", (i > 1 ? ",\n    " : ""), esc(vc), vc_count[vc],
				(vc_low[vc] > 0 ? sprintf("{\"value\": \"%d\", \"color\": \"warning\"}", vc_low[vc]) : "\"0\""),
				vc_cpu[vc], vc_mem[vc] / 1024, vc_st[vc] / 1024
		}
		printf "\n    ]},\n"
		printf "    {\"title\": \"%s\", \"columns\": [\"vCenter\", \"%s\", \"%s\", \"%s\", \"%s\"], \"rows\": [\n    %s\n    ]}",
			t("Кластеры (выделены значения ниже порога)", "Clusters (values below the threshold highlighted)"), t("Кластер", "Cluster"),
			t("CPU, ГГц", "CPU, GHz"), t("Память, ГБ", "Memory, GB"), t("Хранилище, ТБ", "Storage, TB"), rows
		if (bad_count > 0) {
			printf ",\n    {\"title\": \"%s\", \"columns\": [\"%s\", \"%s\"], \"rows\": [\n    %s\n    ]}",
				t("Не разобранные строки", "Lines not parsed"), t("Строка", "Line"), t("Содержимое", "Content"), bad
		}
		printf "\n  ]\n}\n"
	}'
