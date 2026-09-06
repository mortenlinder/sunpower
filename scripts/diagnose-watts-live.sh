#!/bin/sh
set -u

host=${1:-192.168.1.163}

echo "--- reachability ---"
ping -c 2 -W 1 "$host" || true
echo "--- neighbor ---"
ip neigh show "$host" || true
echo "--- ports ---"
for port in 80 443 1883 8883 8080 8081 9000; do
    if timeout 1 bash -c "</dev/tcp/$host/$port" >/dev/null 2>&1; then
        echo "$port open"
    fi
done
