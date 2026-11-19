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
O provisionamento do administrador ainda precisa ser implementado. Não crie usuário manualmente com senha em texto puro. Até existir o comando/instalador protegido, a aplicação não está pronta para autenticação inicial em produção.

## Migração InfinityFree → HostGator
1. Ative manutenção e faça dump SQL.
2. Copie storage/private e anexos.
3. Crie banco/usuário no destino e importe o dump.
4. Atualize somente as credenciais e APP_URL no .env.
5. Ajuste o document root e permissões.
6. Teste login, permissões, anexos, timezone e integridade dos relacionamentos.
7. Reabra o acesso após validação.

## Segurança operacional
Nunca versione .env, backups ou arquivos enviados. Configure backups externos periódicos e restrinja acesso ao phpMyAdmin.