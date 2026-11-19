# Instalação

## Requisitos
PHP 8.2+ com PDO MySQL, Fileinfo e sessões; MySQL/MariaDB; HTTPS em produção.

## Preparação
1. Crie banco e usuário MySQL no painel da hospedagem.
2. Copie .env.example para .env e preencha host, porta, banco, usuário, senha e URL.
3. Importe database/schema.sql pelo phpMyAdmin.
4. Configure o domínio para servir public/. Se o plano não permitir document root customizado, publique os arquivos privados fora de htdocs/public_html e mantenha somente o conteúdo de public/ na raiz web, ajustando BASE_PATH em public/index.php para a pasta privada.
5. Garanta que storage/ fique fora da raiz pública e com escrita restrita.
6. Habilite HTTPS e defina APP_ENV=production e APP_DEBUG=false.

## Primeiro administrador
Execute pelo terminal/SSH da hospedagem, na pasta do projeto:
php bin/create-admin.php

O script só funciona via CLI, exige nome, e-mail, matrícula opcional e senha com no mínimo 12 caracteres, e bloqueia a criação se já existir qualquer usuário. Após o primeiro acesso, remova o script do servidor. Não envie credenciais para o Git.

Se o plano gratuito não disponibilizar terminal/SSH, não exponha um instalador web improvisado. Faça o provisionamento localmente com PHP CLI ou utilize um ambiente de hospedagem que permita execução CLI.

## Migração InfinityFree → HostGator
1. Ative manutenção e faça dump SQL.
2. Copie storage/private e anexos privados.
3. Crie banco/usuário no destino e importe o dump.
4. Atualize somente as credenciais e APP_URL no .env.
5. Ajuste o document root e permissões.
6. Teste login, permissões, anexos, timezone e integridade dos relacionamentos.
7. Reabra o acesso após validação.

## Segurança operacional
Nunca versione .env, backups ou arquivos enviados. Configure backups externos periódicos e restrinja acesso ao phpMyAdmin.
