#!/bin/sh
# Wrapper start preview PHP bengkel.
# Bebaskan port 3000 dari proses lain (mis. frontend React yang auto-start
# saat pod resume) agar server PHP bisa mengikat port 3000.
PIDS=$(ss -ltnp 2>/dev/null | grep ':3000 ' | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u)
for p in $PIDS; do kill "$p" 2>/dev/null; done
sleep 1
exec /usr/bin/php -S 0.0.0.0:3000 -t /app/bengkel
