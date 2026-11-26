#!/bin/sh
# Example report script for Custom Problem Reports (CSV output).
#
# Processes with the highest memory usage. The first line is the header.
# Usage: cr_top_processes.sh [number of processes]      (default: 15)

COUNT=${1:-15}

case "$COUNT" in
	''|*[!0-9]*) echo "the number of processes must be a number: $COUNT" >&2; exit 2 ;;
esac

echo "PID;User;CPU, %;Memory, %;RSS, MB;Command"
ps -eo pid=,user=,pcpu=,pmem=,rss=,comm= --sort=-rss | head -n "$COUNT" \
	| awk '{ printf "%s;%s;%s;%s;%.1f;%s\n", $1, $2, $3, $4, $5 / 1024, $6 }'
