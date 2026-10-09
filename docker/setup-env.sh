#!/bin/sh
set -eu

if [ -e .env ]; then
    printf '%s\n' '.env already exists; it was left unchanged.'
    exit 0
fi

command -v openssl >/dev/null 2>&1 || {
    printf '%s\n' 'OpenSSL is required to generate local passwords.' >&2
    exit 1
}

umask 077
task_env_tmp=$(mktemp .env.setup.XXXXXX)
trap 'rm -f "$task_env_tmp"' EXIT HUP INT TERM
cp .env.example "$task_env_tmp"

for task_env_key in POSTGRES_PASSWORD RABBITMQ_PASSWORD ADMIN_PASSWORD; do
    task_env_password=$(openssl rand -hex 24)
    task_env_next=$(mktemp .env.setup.XXXXXX)
    awk -v key="$task_env_key" -v secret="$task_env_password" \
        'index($0, key "=") == 1 { print key "=" secret; next } { print }' \
        "$task_env_tmp" > "$task_env_next"
    mv "$task_env_next" "$task_env_tmp"
done

# A hard link creates the destination atomically without overwriting another setup.
if ln "$task_env_tmp" .env 2>/dev/null; then
    printf '%s\n' 'Created private .env. Review it and choose ADMIN_PASSWORD before first startup.'
else
    printf '%s\n' '.env was created by another process; it was left unchanged.'
fi
