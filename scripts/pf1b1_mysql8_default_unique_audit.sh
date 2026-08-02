#!/usr/bin/env bash
# Verificación aislada MySQL 8 de default_owner_key (PF-1B.1).
# No usa bases de aplicación compartidas; crea y destruye famedic_pf1b1_audit_*.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DB_NAME="famedic_pf1b1_audit_$$"
MYSQL=(docker compose exec -T mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-secret}")

# Preferir variables del contenedor mysql sin imprimir secretos.
if docker compose exec -T mysql printenv MYSQL_ROOT_PASSWORD >/tmp/pf1b1_mysql_pw 2>/dev/null; then
  PW="$(tr -d '\r' </tmp/pf1b1_mysql_pw)"
  rm -f /tmp/pf1b1_mysql_pw
  MYSQL=(docker compose exec -T mysql mysql -uroot -p"$PW")
fi

echo "=== MySQL version ==="
"${MYSQL[@]}" -e "SELECT VERSION() AS mysql_version;"

echo "=== Create isolated database ${DB_NAME} ==="
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\`;"

run_sql() {
  "${MYSQL[@]}" "$DB_NAME" -e "$1"
}

run_sql "
CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  deleted_at TIMESTAMP NULL
);
CREATE TABLE tax_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  default_owner_key BIGINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN is_default = 1 AND deleted_at IS NULL THEN customer_id ELSE NULL END
    ) STORED,
  UNIQUE KEY tax_profiles_default_owner_key_unique (default_owner_key),
  CONSTRAINT fk_tp_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);
INSERT INTO customers (id) VALUES (1), (2);
"

echo "=== SHOW CREATE TABLE tax_profiles ==="
run_sql "SHOW CREATE TABLE tax_profiles\G"

echo "=== 1-2: two customers one default each; multiple non-default ==="
run_sql "
INSERT INTO tax_profiles (customer_id, is_default, created_at, updated_at) VALUES
 (1, 1, NOW(), NOW()),
 (1, 0, NOW(), NOW()),
 (1, 0, NOW(), NOW()),
 (2, 1, NOW(), NOW());
SELECT customer_id, is_default, default_owner_key, deleted_at FROM tax_profiles ORDER BY id;
"

echo "=== 3: reject second active default same customer ==="
set +e
run_sql "INSERT INTO tax_profiles (customer_id, is_default, created_at, updated_at) VALUES (1, 1, NOW(), NOW());"
DUP_EC=$?
set -e
echo "duplicate_insert_exit_code=${DUP_EC}"
if [[ "$DUP_EC" -eq 0 ]]; then
  echo "FAIL: expected duplicate default to be rejected"
  run_sql "DROP DATABASE \`${DB_NAME}\`;"
  exit 1
fi

echo "=== 4-5: soft delete frees uniqueness; new default allowed ==="
run_sql "
UPDATE tax_profiles SET deleted_at = NOW() WHERE customer_id = 1 AND is_default = 1 AND deleted_at IS NULL LIMIT 1;
INSERT INTO tax_profiles (customer_id, is_default, created_at, updated_at) VALUES (1, 1, NOW(), NOW());
SELECT id, customer_id, is_default, default_owner_key, deleted_at IS NOT NULL AS is_trashed
FROM tax_profiles WHERE customer_id = 1 ORDER BY id;
"

echo "=== 6: multiple soft-deleted with is_default=true do not collide ==="
run_sql "
UPDATE tax_profiles SET deleted_at = NOW(), is_default = 1 WHERE customer_id = 1 AND deleted_at IS NOT NULL;
INSERT INTO tax_profiles (customer_id, is_default, deleted_at, created_at, updated_at) VALUES
 (1, 1, NOW(), NOW(), NOW()),
 (1, 1, NOW(), NOW(), NOW());
SELECT COUNT(*) AS trashed_defaults
FROM tax_profiles
WHERE customer_id = 1 AND is_default = 1 AND deleted_at IS NOT NULL;
"

echo "=== 7-8: generated expression and UNIQUE index ==="
run_sql "
SELECT COLUMN_NAME, GENERATION_EXPRESSION, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tax_profiles' AND COLUMN_NAME = 'default_owner_key';
SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tax_profiles' AND INDEX_NAME = 'tax_profiles_default_owner_key_unique';
"

echo "=== Cleanup ==="
run_sql "DROP DATABASE \`${DB_NAME}\`;"
echo "OK mysql8_pf1b1_audit"
