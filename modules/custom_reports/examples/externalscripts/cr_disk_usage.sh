#!/bin/sh
# Example report script for Custom Problem Reports (JSON output).
#
# Disk usage of the local file systems: KPI, a table with highlighted rows and the overall status.
# Usage: cr_disk_usage.sh [warning %] [critical %]      (defaults: 80 90)
# Copy to the ExternalScripts directory (e.g. /usr/lib/zabbix/externalscripts) and make it executable.

WARN=${1:-80}
CRIT=${2:-90}

case "$WARN$CRIT" in
	*[!0-9]*) echo "thresholds must be numbers: $WARN $CRIT" >&2; exit 2 ;;
esac

df -P -k -x tmpfs -x devtmpfs -x squashfs -x overlay 2>/dev/null | awk -v warn="$WARN" -v crit="$CRIT" \
		-v host="$(hostname)" -v date="${CR_DATE:-$(date +%F)}" '
	function esc(s) {
		gsub(/\\/, "\\\\", s); gsub(/"/, "\\\"", s); gsub(/\t/, " ", s)
		return s
	}
	NR == 1 { next }
	{
		pct = $5; sub(/%/, "", pct); pct += 0
		color = pct >= crit ? "disaster" : (pct >= warn ? "warning" : "")
		total++
		if (pct >= crit) { critical++ } else if (pct >= warn) { warning++ }
		row = sprintf("{\"Mount point\": \"%s\", \"File system\": \"%s\", \"Size, GB\": \"%.1f\", \"Used, GB\": \"%.1f\", " \
			"\"Free, GB\": \"%.1f\", \"Used\": {\"value\": \"%d%%\", \"color\": \"%s\"}}",
			esc($6), esc($1), $2 / 1048576, $3 / 1048576, $4 / 1048576, pct, color)
		rows = rows (rows == "" ? "" : ",\n    ") row
	}
	END {
		status = critical > 0 ? "problem" : (warning > 0 ? "warning" : "ok")
		printf "{\n  \"title\": \"Disk usage: %s\",\n", esc(host)
		printf "  \"summary\": \"%s: %d file systems, over %d%%: %d, over %d%%: %d.\",\n", date, total, warn,
			warning + critical, crit, critical + 0
		printf "  \"status\": \"%s\",\n", status
		printf "  \"kpi\": [\n    {\"label\": \"File systems\", \"value\": %d},\n", total
		printf "    {\"label\": \"Over %d%%\", \"value\": %d, \"color\": \"%s\"},\n", warn, warning + critical,
			((warning + critical) > 0 ? "warning" : "")
		printf "    {\"label\": \"Over %d%%\", \"value\": %d, \"color\": \"%s\"}\n  ],\n", crit, critical + 0,
			(critical > 0 ? "disaster" : "")
		printf "  \"tables\": [{\"title\": \"File systems\", \"rows\": [\n    %s\n  ]}]\n}\n", rows
	}'
