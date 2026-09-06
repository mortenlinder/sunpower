#!/bin/sh
set -eu

source_file=${1:-/home/ml/solportalen-deploy/watts}
env_file=${2:-/opt/solportalen/.env}

if [ "$(id -u)" -ne 0 ]; then
    echo "Kør scriptet som root." >&2
    exit 1
fi

client_id=$(sed -n 's/^[Cc]lient id:[[:space:]]*//p' "$source_file" | head -n 1)
client_secret=$(sed -n 's/^[Ss]ecret:[[:space:]]*//p' "$source_file" | head -n 1)
test -n "$client_id"
test -n "$client_secret"

temporary=$(mktemp /opt/solportalen/.env.watts.XXXXXX)
trap 'rm -f "$temporary"' EXIT HUP INT TERM

grep -v '^WATTS_CLIENT_ID=' "$env_file" \
    | grep -v '^WATTS_CLIENT_SECRET=' \
    | grep -v '^WATTS_API_BASE_URL=' > "$temporary"
printf 'WATTS_CLIENT_ID=%s\n' "$client_id" >> "$temporary"
printf 'WATTS_CLIENT_SECRET=%s\n' "$client_secret" >> "$temporary"
printf 'WATTS_API_BASE_URL=https://p.watts-energy.dk/api\n' >> "$temporary"

chown root:solportal-app "$temporary"
chmod 0640 "$temporary"
mv "$temporary" "$env_file"
trap - EXIT HUP INT TERM

loaded=$(runuser -u solportal -- php -r 'require "/opt/solportalen/bootstrap.php"; echo getenv("WATTS_CLIENT_ID") !== false && getenv("WATTS_CLIENT_SECRET") !== false ? "ok" : "missing";')
test "$loaded" = ok

rm -f "$source_file"
echo "Watts credentials: installeret og læsbare for Solportalen"
stat -c 'env mode=%a owner=%U:%G' "$env_file"
test ! -e "$source_file"
echo "Ubeskyttet kildefil: fjernet"
