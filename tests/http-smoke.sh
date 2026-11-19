#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_NAME:=demutranweb}"
: "${DB_USER:=root}"
: "${DB_PASS:=root}"
: "${INSTALL_TOKEN:=ci-install-secret}"
BASE_URL="http://127.0.0.1:8097/subdir"
COOKIE_JAR="$(mktemp)"
SERVER_LOG="$(mktemp)"
APP_URL="$BASE_URL" php -S 127.0.0.1:8097 -t public public/index.php >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
cleanup(){
  result=$?
  if [[ "$result" -ne 0 ]]; then echo "HTTP smoke failed; PHP server log:"; cat "$SERVER_LOG"; fi
  kill "$SERVER_PID" 2>/dev/null || true
  rm -f "$COOKIE_JAR" "$SERVER_LOG" /tmp/demutranweb-page.html /tmp/demutran-logo.png /tmp/demutran-logo-download.png
}
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
ADMIN_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM users WHERE email='ci-admin@example.invalid'")"
SERVER_ROLE_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM roles WHERE name='Servidor'")"
echo "Test identities: admin=$ADMIN_ID server_role=$SERVER_ROLE_ID"
test -n "$ADMIN_ID" && test -n "$SERVER_ROLE_ID"
mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "INSERT INTO notifications(user_id,title,message,target_url,created_at) VALUES($ADMIN_ID,'Notificação CI','Teste de leitura de notificação','/escala',NOW())"

status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" "$BASE_URL/install")"
test "$status" = "404"

for route in / /sinistro /escala /ordens /usuarios /adm /relatorios /termos-de-uso /politica-de-privacidade; do
  status="$(curl -sS -o /tmp/demutranweb-page.html -w '%{http_code}' -b "$COOKIE_JAR" "$BASE_URL$route")"
  if [[ "$status" != "200" ]]; then cat "$SERVER_LOG"; echo "Falha HTTP $status em $route"; exit 1; fi
  if grep -Eiq '(Warning|Fatal error|Parse error|Uncaught)' /tmp/demutranweb-page.html; then cat "$SERVER_LOG"; cat /tmp/demutranweb-page.html; echo "Erro PHP ao renderizar $route"; exit 1; fi
done

home="$(curl -fsS -b "$COOKIE_JAR" "$BASE_URL/")"
grep -q "Painel do departamento" <<<"$home"
grep -q "Notificação CI" <<<"$home"
NOTIFICATION_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM notifications WHERE user_id=$ADMIN_ID AND title='Notificação CI' ORDER BY id DESC LIMIT 1")"
csrf="$(grep -oE 'name="_csrf" value="[^"]+"' <<<"$home" | head -n1 | sed -E 's/.*value="([^"]+)"/\1/')"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" --data-urlencode "_csrf=$csrf" --data-urlencode "id=$NOTIFICATION_ID" --data-urlencode "target_url=/escala" "$BASE_URL/notificacoes/ler")"
test "$status" = "302"
read_at="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT read_at IS NOT NULL FROM notifications WHERE id=$NOTIFICATION_ID")"
test "$read_at" = "1"
csrf="$(grep -oE 'name="_csrf" value="[^"]+"' <<<"$home" | head -n1 | sed -E 's/.*value="([^"]+)"/\1/')"
test -n "$csrf"

# ADM: gravação de configurações e upload privado do brasão.
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/j5sAAAAASUVORK5CYII=' | base64 -d > /tmp/demutran-logo.png
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  -F "_csrf=$csrf" -F "institution_name=DEMUTRAN CI" -F "department_name=Departamento CI" \
  -F "contact_email=ci@example.invalid" -F "contact_phone=0000" -F "protocol_prefix=CI" \
  -F "upload_max_mb=8" -F "logo=@/tmp/demutran-logo.png;type=image/png" "$BASE_URL/adm/config")"
test "$status" = "302"
LOGO_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM file_attachments WHERE module='branding' ORDER BY id DESC LIMIT 1")"
test -n "$LOGO_ID"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/download?id=$LOGO_ID" -o /tmp/demutran-logo-download.png
cmp /tmp/demutran-logo.png /tmp/demutran-logo-download.png

# Sinistro: criação e edição com veículo, equipe e testemunha.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "occurred_date=$(date +%F)" \
  --data-urlencode "occurred_time=10:15" --data-urlencode "accident_type=Colisão" \
  --data-urlencode "location=Avenida de teste" --data-urlencode "description=Sinistro de integração" \
  --data-urlencode "vehicles[0][id]=0" --data-urlencode "vehicles[0][model]=Veículo CI" \
  --data-urlencode "vehicles[0][plate]=ABC1234" --data-urlencode "responsible_ids[]=$ADMIN_ID" \
  --data-urlencode "witnesses[0][name]=Testemunha CI" "$BASE_URL/sinistro/salvar")"
test "$status" = "302"
ACCIDENT_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM accidents WHERE description='Sinistro de integração' ORDER BY id DESC LIMIT 1")"
VEHICLE_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM accident_vehicles WHERE accident_id=$ACCIDENT_ID ORDER BY id DESC LIMIT 1")"
test -n "$ACCIDENT_ID" && test -n "$VEHICLE_ID"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/sinistro/$ACCIDENT_ID" | grep "Veículo CI" >/dev/null
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$ACCIDENT_ID" \
  --data-urlencode "occurred_date=$(date +%F)" --data-urlencode "occurred_time=10:20" \
  --data-urlencode "accident_type=Colisão" --data-urlencode "location=Avenida atualizada" \
  --data-urlencode "description=Sinistro editado" --data-urlencode "vehicles[0][id]=$VEHICLE_ID" \
  --data-urlencode "vehicles[0][model]=Veículo CI atualizado" --data-urlencode "vehicles[0][plate]=ABC1234" \
  --data-urlencode "responsible_ids[]=$ADMIN_ID" --data-urlencode "witnesses[0][name]=Testemunha CI" "$BASE_URL/sinistro/salvar")"
test "$status" = "302"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/sinistro/$ACCIDENT_ID" | grep "Veículo CI atualizado" >/dev/null

# Escala: criação, edição e publicação.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "title=Escala CI" \
  --data-urlencode "service_type=Operacional" --data-urlencode "service_date=$(date +%F)" \
  --data-urlencode "start_time=08:00" --data-urlencode "end_time=09:00" \
  --data-urlencode "location=Base" --data-urlencode "members[]=$ADMIN_ID" "$BASE_URL/escala/salvar")"
test "$status" = "302"
SHIFT_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM shifts WHERE title='Escala CI' ORDER BY id DESC LIMIT 1")"
test -n "$SHIFT_ID"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "title=Escala conflito CI" \
  --data-urlencode "service_type=Operacional" --data-urlencode "service_date=$(date +%F)" \
  --data-urlencode "start_time=08:30" --data-urlencode "end_time=09:30" \
  --data-urlencode "location=Base" --data-urlencode "members[]=$ADMIN_ID" "$BASE_URL/escala/salvar")"
test "$status" = "302"
conflicts="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT COUNT(*) FROM shifts WHERE title='Escala conflito CI'")"
test "$conflicts" = "0"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$SHIFT_ID" --data-urlencode "title=Escala CI editada" \
  --data-urlencode "service_type=Operacional" --data-urlencode "service_date=$(date +%F)" \
  --data-urlencode "start_time=08:00" --data-urlencode "end_time=09:00" \
  --data-urlencode "location=Base" --data-urlencode "members[]=$ADMIN_ID" "$BASE_URL/escala/salvar")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$SHIFT_ID" --data-urlencode "action=publish" "$BASE_URL/escala/acao")"
test "$status" = "302"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/escala/$SHIFT_ID" | grep "Escala CI editada" >/dev/null
for calendar_view in month week day; do
  curl -fsS -b "$COOKIE_JAR" "$BASE_URL/escala?view=$calendar_view&date=$(date +%F)" | grep "Agenda de escalas" >/dev/null
done
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "title=Escala cancelamento CI" \
  --data-urlencode "service_type=Operacional" --data-urlencode "service_date=$(date -d tomorrow +%F)" \
  --data-urlencode "start_time=10:00" --data-urlencode "end_time=11:00" \
  --data-urlencode "location=Base" --data-urlencode "members[]=$ADMIN_ID" "$BASE_URL/escala/salvar")"
test "$status" = "302"
CANCEL_SHIFT_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM shifts WHERE title='Escala cancelamento CI' ORDER BY id DESC LIMIT 1")"
test -n "$CANCEL_SHIFT_ID"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$CANCEL_SHIFT_ID" --data-urlencode "action=cancel" "$BASE_URL/escala/acao")"
test "$status" = "302"
shift_status="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT status FROM shifts WHERE id=$CANCEL_SHIFT_ID")"
test "$shift_status" = "Rascunho"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$CANCEL_SHIFT_ID" --data-urlencode "action=cancel" \
  --data-urlencode "reason=Cancelamento justificado pelo teste de integração" "$BASE_URL/escala/acao")"
test "$status" = "302"
shift_status="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT status FROM shifts WHERE id=$CANCEL_SHIFT_ID")"
test "$shift_status" = "Cancelada"
cancel_reason="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT cancel_reason FROM shifts WHERE id=$CANCEL_SHIFT_ID")"
grep -q "Cancelamento justificado" <<<"$cancel_reason"

# Ordem de serviço: criação, edição, transição, execução e conclusão.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "requester=Solicitante CI" \
  --data-urlencode "service_type=Manutenção" --data-urlencode "description=O.S. de integração" \
  --data-urlencode "location=Garagem" --data-urlencode "priority=Alta" \
  --data-urlencode "responsible_id=$ADMIN_ID" --data-urlencode "executors[]=$ADMIN_ID" "$BASE_URL/ordens/salvar")"
test "$status" = "302"
ORDER_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM service_orders WHERE requester='Solicitante CI' ORDER BY id DESC LIMIT 1")"
test -n "$ORDER_ID"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$ORDER_ID" --data-urlencode "requester=Solicitante CI" \
  --data-urlencode "service_type=Manutenção atualizada" --data-urlencode "description=O.S. editada" \
  --data-urlencode "location=Garagem" --data-urlencode "priority=Alta" \
  --data-urlencode "responsible_id=$ADMIN_ID" --data-urlencode "executors[]=$ADMIN_ID" "$BASE_URL/ordens/salvar")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$ORDER_ID" --data-urlencode "status=Em análise" \
  --data-urlencode "note=Análise inicial do teste" "$BASE_URL/ordens/status")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$ORDER_ID" --data-urlencode "status=Aprovada" \
  --data-urlencode "note=" "$BASE_URL/ordens/status")"
test "$status" = "302"
order_status="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT status FROM service_orders WHERE id=$ORDER_ID")"
test "$order_status" = "Em análise"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$ORDER_ID" --data-urlencode "status=Aprovada" \
  --data-urlencode "note=Aprovada após análise técnica" "$BASE_URL/ordens/status")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$ORDER_ID" --data-urlencode "status=Em andamento" \
  --data-urlencode "note=Início da execução" "$BASE_URL/ordens/status")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$ORDER_ID" --data-urlencode "update_type=Execução" \
  --data-urlencode "description=Atividade de teste" "$BASE_URL/ordens/atualizacao")"
test "$status" = "302"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$ORDER_ID" --data-urlencode "status=Concluída" \
  --data-urlencode "note=Concluído no teste" "$BASE_URL/ordens/status")"
test "$status" = "302"
order_detail="$(curl -fsS -b "$COOKIE_JAR" "$BASE_URL/ordens/$ORDER_ID")"
grep -q "Atividade de teste" <<<"$order_detail"
grep -q "Aprovado por" <<<"$order_detail"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "requester=Solicitante cancelamento CI" \
  --data-urlencode "service_type=Teste" --data-urlencode "description=O.S. cancelada" \
  --data-urlencode "location=Garagem" --data-urlencode "priority=Baixa" "$BASE_URL/ordens/salvar")"
test "$status" = "302"
CANCEL_ORDER_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM service_orders WHERE requester='Solicitante cancelamento CI' ORDER BY id DESC LIMIT 1")"
test -n "$CANCEL_ORDER_ID"
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$CANCEL_ORDER_ID" --data-urlencode "status=Cancelada" \
  --data-urlencode "note=Cancelamento justificado no teste de integração" "$BASE_URL/ordens/status")"
test "$status" = "302"
order_reason="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT cancellation_reason FROM service_orders WHERE id=$CANCEL_ORDER_ID")"
grep -q "Cancelamento justificado" <<<"$order_reason"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/relatorios/exportar?type=ordens" | grep "Solicitante CI" >/dev/null
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/relatorios/imprimir?type=ordens" | grep "Relatório" >/dev/null

# Usuários: cadastro, edição, situação e permissão individual.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "name=Servidor CI" \
  --data-urlencode "registration=CI-USER-02" --data-urlencode "email=ci-user@example.invalid" \
  --data-urlencode "role_id=$SERVER_ROLE_ID" --data-urlencode "password=ci-user-password-123" "$BASE_URL/usuarios/salvar")"
test "$status" = "302"
USER_ID="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id FROM users WHERE email='ci-user@example.invalid'")"
test -n "$USER_ID"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/usuarios?edit=$USER_ID" | grep "Editar usuário" >/dev/null
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "id=$USER_ID" --data-urlencode "name=Servidor CI Editado" \
  --data-urlencode "registration=CI-USER-02" --data-urlencode "email=ci-user@example.invalid" \
  --data-urlencode "role_id=$SERVER_ROLE_ID" --data-urlencode "password=" "$BASE_URL/usuarios/salvar")"
test "$status" = "302"
for user_status in "Afastado" "Inativo" "Ativo"; do
  status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "_csrf=$csrf" --data-urlencode "id=$USER_ID" --data-urlencode "status=$user_status" "$BASE_URL/usuarios/status")"
  test "$status" = "302"
done
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "user_id=$USER_ID" \
  --data-urlencode "permission_override[dashboard.view]=0" \
  --data-urlencode "permission_override[sinistros.view]=1" "$BASE_URL/usuarios/permissoes")"
test "$status" = "302"
curl -fsS -b "$COOKIE_JAR" "$BASE_URL/usuarios?permissions=$USER_ID" | grep "Permissões individuais" >/dev/null

logout_csrf="$(grep -oE 'name="_csrf" value="[^"]+"' <<<"$home" | tail -n1 | sed -E 's/.*value="([^"]+)"/\1/')"
test -n "$logout_csrf"
invalid_logout_status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -X POST "$BASE_URL/logout")"
test "$invalid_logout_status" = "419"
logout_status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" --data-urlencode "_csrf=$logout_csrf" "$BASE_URL/logout")"
test "$logout_status" = "302"

login_page="$(curl -fsS -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/")"
login_csrf="$(grep -oE 'name="_csrf" value="[^"]+"' <<<"$login_page" | head -n1 | sed -E 's/.*value="([^"]+)"/\1/')"
test -n "$login_csrf"
login_status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "_csrf=$login_csrf" --data-urlencode "email=ci-admin@example.invalid" \
  --data-urlencode "password=ci-password-strong-123" "$BASE_URL/login")"
test "$login_status" = "302"

echo "HTTP smoke test OK: instalação/login, configurações e upload do brasão, notificações, sinistros/filtros, calendário e histórico de escalas, cancelamentos justificados, aprovação/execução de O.S., usuários, permissões e relatórios."
