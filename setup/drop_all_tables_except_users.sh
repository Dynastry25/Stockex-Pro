#!/usr/bin/env bash

set -euo pipefail

load_env() {
    local env_file

    for env_file in /etc/stockex/stockex.env "$(dirname "$0")/../.env"; do
        if [[ -f "$env_file" ]]; then
            # shellcheck disable=SC1090
            set -a
            source "$env_file"
            set +a
            return 0
        fi
    done
}

flag_is_true() {
    case "${1,,}" in
        1|true|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

load_env

if ! flag_is_true "${CONFIRM_DROP_ALL_TABLES:-}"; then
    echo "Set CONFIRM_DROP_ALL_TABLES=1 to run this script." >&2
    exit 1
fi

: "${DB_HOST:=localhost}"
: "${DB_NAME:=stockex_db}"
: "${DB_USERNAME:=stockex_user}"
: "${DB_PASSWORD:=}"

mysql_base=(mysql --protocol=tcp -h "$DB_HOST" -u "$DB_USERNAME" --database="$DB_NAME" --batch --skip-column-names)

if [[ -n "$DB_PASSWORD" ]]; then
    export MYSQL_PWD="$DB_PASSWORD"
fi

tables="$(${mysql_base[@]} -e "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name <> 'users' ORDER BY table_name;")"

if [[ -z "$tables" ]]; then
    echo "No tables to drop in $DB_NAME."
    exit 0
fi

table_count=$(printf '%s\n' "$tables" | wc -l | tr -d ' ')
echo "Dropping $table_count table(s) from $DB_NAME, keeping users."

sql_batch=$'SET FOREIGN_KEY_CHECKS = 0;\n'
while IFS= read -r table_name; do
    [[ -z "$table_name" ]] && continue
    escaped_table_name=$table_name
    escaped_table_name=${escaped_table_name//\`/\`\`}
    sql_batch+="DROP TABLE IF EXISTS \`${escaped_table_name}\`;"
    sql_batch+=$'\n'
done <<< "$tables"
sql_batch+=$'SET FOREIGN_KEY_CHECKS = 1;\n'

printf '%s' "$sql_batch" | "${mysql_base[@]}"

echo "Done. Kept table: users"
