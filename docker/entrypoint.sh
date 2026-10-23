#!/bin/bash
set -euo pipefail

# --- MariaDB: socket only (no TCP), data on a volume ---
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
if [ ! -d /var/lib/mysql/mysql ]; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql >/dev/null
fi
mysqld_safe --skip-networking --user=mysql >/dev/null 2>&1 &

for _ in $(seq 1 30); do
  mariadb-admin ping --silent 2>/dev/null && break
  sleep 1
done

# Random DB password every start; app user gets the bare minimum privileges
DB_PASS="$(openssl rand -hex 16)"
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS scanner;
CREATE USER IF NOT EXISTS 'scanner'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'scanner'@'localhost' IDENTIFIED BY '${DB_PASS}';
-- DELETE is only used by delete.php (remove one scan or clear the history)
GRANT SELECT, INSERT, DELETE ON scanner.* TO 'scanner'@'localhost';
USE scanner;
CREATE TABLE IF NOT EXISTS scans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sha256 CHAR(64) NOT NULL,
  mime VARCHAR(32) NOT NULL,
  bytes INT NOT NULL,
  verdict VARCHAR(64) NOT NULL,
  signals TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- Upgrade older volumes in place. Rows from before the rule-based detector stay 'legacy'.
ALTER TABLE scans
  ADD COLUMN IF NOT EXISTS level VARCHAR(16) NOT NULL DEFAULT 'legacy',
  ADD COLUMN IF NOT EXISTS score TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS width INT NULL,
  ADD COLUMN IF NOT EXISTS height INT NULL,
  ADD COLUMN IF NOT EXISTS filename VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS owner CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS score_source VARCHAR(8) NULL;
SQL

# Hand the password to PHP via Apache config (readable by root + www-data only)
CONF=/etc/apache2/conf-enabled/db-env.conf
printf 'SetEnv DB_PASS "%s"\n' "$DB_PASS" > "$CONF"

# Optional Sightengine pixel check. Preferred: mount a .env file read-only at
# /run/secrets/scanner.env, so the keys never appear in `docker inspect`
# (variables passed with -e or --env-file do). The file is parsed as plain
# KEY=VALUE text and never sourced, so nothing in it can run as code.
SECRETS_FILE=/run/secrets/scanner.env
if [ -f "$SECRETS_FILE" ]; then
  while IFS='=' read -r KEY VAL || [ -n "$KEY" ]; do
    KEY="${KEY#export }"
    KEY="${KEY//[[:space:]]/}"
    VAL="${VAL%$'\r'}"
    VAL="${VAL#\"}"; VAL="${VAL%\"}"; VAL="${VAL#\'}"; VAL="${VAL%\'}"
    case "$KEY" in
      SIGHTENGINE_API_USER | API_USER) SIGHTENGINE_API_USER="$VAL" ;;
      SIGHTENGINE_API_SECRET | SIGHTENGINE_API_KEY) SIGHTENGINE_API_SECRET="$VAL" ;;
      SIGHTENGINE_ENDPOINT | SIGHTENGINE_API_URL | API_URL) SIGHTENGINE_ENDPOINT="$VAL" ;;
    esac
  done < "$SECRETS_FILE"
fi

# Values are written into Apache config, so only plain key characters are accepted.
for VAR in SIGHTENGINE_API_USER SIGHTENGINE_API_SECRET; do
  VAL="${!VAR:-}"
  if [ -n "$VAL" ]; then
    if [[ "$VAL" =~ ^[A-Za-z0-9_-]+$ ]]; then
      printf 'SetEnv %s "%s"\n' "$VAR" "$VAL" >> "$CONF"
    else
      echo "entrypoint: ignoring $VAR (unexpected characters)" >&2
    fi
  fi
done
# API URL, also from the .env file. HTTPS only, so the secret is never sent in
# clear text; plain HTTP is accepted for localhost (a test mock) and nothing else.
ENDPOINT="${SIGHTENGINE_ENDPOINT:-}"
if [[ "$ENDPOINT" =~ ^https://[A-Za-z0-9._:/-]+$ || "$ENDPOINT" =~ ^http://(127\.0\.0\.1|localhost)(:[0-9]+)?/[A-Za-z0-9._/-]*$ ]]; then
  printf 'SetEnv SIGHTENGINE_ENDPOINT "%s"\n' "$ENDPOINT" >> "$CONF"
elif [ -n "$ENDPOINT" ]; then
  echo "entrypoint: ignoring SIGHTENGINE_ENDPOINT (must be an https:// URL)" >&2
fi
chown root:www-data "$CONF" && chmod 640 "$CONF"

exec apache2ctl -D FOREGROUND
