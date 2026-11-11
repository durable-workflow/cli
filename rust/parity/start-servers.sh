#!/usr/bin/env bash
# Start the published Servers pinned in parity/servers.json for the Server
# parity cases, and print the DW_PARITY_SERVERS value for them. Each Compose
# file is checksum-verified and each image is pinned by digest.
#   parity/start-servers.sh            # start (waits until healthy)
#   parity/start-servers.sh down       # stop and remove their volumes
set -euo pipefail

crate="$(cd "$(dirname "$0")/.." && pwd)"
work="$crate/target/parity-servers"
mkdir -p "$work"
export DW_AUTH_TOKEN="${DW_PARITY_TOKEN:-parity-token}"

servers=()
while read -r label version image compose sha256 port; do
  file="$work/$label.yml"
  if [ "${1:-up}" = down ]; then
    [ -f "$file" ] && docker compose -p "cli-parity-$label" -f "$file" down -v --remove-orphans >/dev/null 2>&1 || true
    continue
  fi
  download="$(mktemp "$work/$label.XXXXXX")"
  curl --proto '=https' --tlsv1.2 -fsSL --retry 3 -o "$download" "$compose"
  echo "${sha256}  ${download}" | sha256sum -c - >/dev/null
  mv "$download" "$file"
  DW_SERVER_IMAGE="$image" SERVER_PORT="$port" \
    docker compose -p "cli-parity-$label" -f "$file" up -d --wait --wait-timeout 600 >&2
  servers+=("$label=http://127.0.0.1:$port")
  echo "Started $label Server $version on port $port" >&2
done < <(python3 -c '
import json, sys
for label, server in json.load(open(sys.argv[1]))["servers"].items():
    print(label, server["version"], server["image"], server["compose"], server["compose_sha256"], server["port"])
' "$crate/parity/servers.json")

if [ "${1:-up}" != down ]; then
  (IFS=,; echo "${servers[*]}")
fi
