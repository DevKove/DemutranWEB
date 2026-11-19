#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_NAME:=demutranweb}"
: "${DB_USER:=root}"
: "${DB_PASS:=root}"
: "${INSTALL_TOKEN:=ci-install-secret}"
BASE_URL="http://127.0.0.1:8097"
COOKIE_JAR="$(mktemp)"
SERVER_LOG="$(mktemp)"
php -S 127.0.0.1:8097 -t public public/index.php >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
cleanup(){ kill "$SERVER_PID" 2>/dev/null || true; rm -f "$COOKIE_JAR" "$SERVER_LOG"; }
trap cleanup EXIT

for _ in $(seq 1 30); do
  if curl -fsS "$BASE_URL/health" >/dev/null 2>&1; then break; fi
  sleep 1
done
health="$(curl -fsS "$BASE_URL/health")"
grep -q '"ok":true' <<<"$health"
grep -q '"database":true' <<<"$health"

install_page="$(curl -fsS -c "$COOKIE_JAR" "$BASE_URL/install")"
csrf="$(grep -oE 'name="_csrf" value="[^"]+"' <<<"$install_page" | head -n1 | sed -E 's/.*value="([^"]+)"/\1/')"
test -n "$csrf"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "install_token=$INSTALL_TOKEN" \
  --data-urlencode "name=Administrador CI" --data-urlencode "email=ci-admin@example.invalid" \
  --data-urlencode "registration=CI-001" --data-urlencode "password=ci-password-strong-123" \
  "$BASE_URL/install")"
test "$status" = "302"

status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" "$BASE_URL/install")"
test "$status" = "404"

for route in / /sinistro /escala /ordens /usuarios /adm /relatorios /termos-de-uso /politica-de-privacidade; do
  status="$(curl -sS -o /tmp/demutranweb-page.html -w '%{http_code}' -b "$COOKIE_JAR" "$BASE_URL$route")"
  if [[ "$status" != "200" ]]; then cat "$SERVER_LOG"; echo "Falha HTTP $status em $route"; exit 1; fi
  if grep -Eiq '(Warning|Fatal error|Parse error|Uncaught)' /tmp/demutranweb-page.html; then cat "$SERVER_LOG"; cat /tmp/demutranweb-page.html; echo "Erro PHP ao renderizar $route"; exit 1; fi
done

home="$(curl -fsS -b "$COOKIE_JAR" "$BASE_URL/")"
grep -q "Painel do departamento" <<<"$home"
logout_csrf="$(grep -oE 'name="_csrf" value="[^"]+"' <<<"$home" | tail -n1 | sed -E 's/.*value="([^"]+)"/\1/')"
test -n "$logout_csrf"
logout_status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" --data-urlencode "_csrf=$logout_csrf" "$BASE_URL/logout")"
test "$logout_status" = "302"

login_page="$(curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/")"
login_csrf="$(grep -oE 'name="_csrf" value="[^"]+"' <<<"$login_page" | head -n1 | sed -E 's/.*value="([^"]+)"/\1/')"
test -n "$login_csrf"
login_status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$login_csrf" --data-urlencode "email=ci-admin@example.invalid" \
  --data-urlencode "password=ci-password-strong-123" "$BASE_URL/login")"
test "$login_status" = "302"

echo "HTTP smoke test OK: instalação, sessão, login e 9 páginas autenticadas."
