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
csrf="$(grep -oE 'name="_csrf" value="[^"]+"' <<<"$home" | head -n1 | sed -E 's/.*value="([^"]+)"/\\1/')"
test -n "$csrf"

# Sinistro: criação e edição com veículo, equipe e testemunha.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "occurred_date=$(date +%F)" \\
  --data-urlencode "occurred_time=10:15" --data-urlencode "accident_type=Colisão" \\
  --data-urlencode "location=Avenida de teste" --data-urlencode "description=Sinistro de integração" \\
  --data-urlencode "vehicles[0][id]=0" --data-urlencode "vehicles[0][model]=Veículo CI" \\
  --data-urlencode "vehicles[0][plate]=ABC1234" --data-urlencode "responsible_ids[]=1" \\
  --data-urlencode "witnesses[0][name]=Testemunha CI" "$BASE_URL/sinistro/salvar")"
test "$status" = "302"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/sinistro/1" | grep "Veículo CI" >/dev/null
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=1" \\
  --data-urlencode "occurred_date=$(date +%F)" --data-urlencode "occurred_time=10:20" \\
  --data-urlencode "accident_type=Colisão" --data-urlencode "location=Avenida atualizada" \\
  --data-urlencode "description=Sinistro editado" --data-urlencode "vehicles[0][id]=1" \\
  --data-urlencode "vehicles[0][model]=Veículo CI atualizado" --data-urlencode "vehicles[0][plate]=ABC1234" \\
  --data-urlencode "responsible_ids[]=1" --data-urlencode "witnesses[0][name]=Testemunha CI" "$BASE_URL/sinistro/salvar")"
test "$status" = "302"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/sinistro/1" | grep "Veículo CI atualizado" >/dev/null

# Escala: criação, edição e publicação.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "title=Escala CI" \\
  --data-urlencode "service_type=Operacional" --data-urlencode "service_date=$(date +%F)" \\
  --data-urlencode "start_time=08:00" --data-urlencode "end_time=09:00" \\
  --data-urlencode "location=Base" --data-urlencode "members[]=1" "$BASE_URL/escala/salvar")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=1" --data-urlencode "title=Escala CI editada" \\
  --data-urlencode "service_type=Operacional" --data-urlencode "service_date=$(date +%F)" \\
  --data-urlencode "start_time=08:00" --data-urlencode "end_time=09:00" \\
  --data-urlencode "location=Base" --data-urlencode "members[]=1" "$BASE_URL/escala/salvar")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=1" --data-urlencode "action=publish" "$BASE_URL/escala/acao")"
test "$status" = "302"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/escala/1" | grep "Escala CI editada" >/dev/null

# Ordem de serviço: criação, edição, transição, execução e conclusão.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "requester=Solicitante CI" \\
  --data-urlencode "service_type=Manutenção" --data-urlencode "description=O.S. de integração" \\
  --data-urlencode "location=Garagem" --data-urlencode "priority=Alta" \\
  --data-urlencode "responsible_id=1" --data-urlencode "executors[]=1" "$BASE_URL/ordens/salvar")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=1" --data-urlencode "requester=Solicitante CI" \\
  --data-urlencode "service_type=Manutenção atualizada" --data-urlencode "description=O.S. editada" \\
  --data-urlencode "location=Garagem" --data-urlencode "priority=Alta" \\
  --data-urlencode "responsible_id=1" --data-urlencode "executors[]=1" "$BASE_URL/ordens/salvar")"
test "$status" = "302"
for next_status in "Em análise" "Aprovada" "Em andamento"; do
  status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
    --data-urlencode "_csrf=$csrf" --data-urlencode "id=1" --data-urlencode "status=$next_status" \\
    --data-urlencode "note=Teste CI" "$BASE_URL/ordens/status")"
  test "$status" = "302"
done
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=1" --data-urlencode "update_type=Execução" \\
  --data-urlencode "description=Atividade de teste" "$BASE_URL/ordens/atualizacao")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \\
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=1" --data-urlencode "status=Concluída" \\
  --data-urlencode "note=Concluído no teste" "$BASE_URL/ordens/status")"
test "$status" = "302"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/ordens/1" | grep "Atividade de teste" >/dev/null
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/relatorios/exportar?type=ordens" | grep "Solicitante CI" >/dev/null

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
