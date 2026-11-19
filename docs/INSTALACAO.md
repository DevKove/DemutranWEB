# Instalação — DemutranWEB

## 1. Requisitos da hospedagem

- PHP 8.2 ou superior
- Extensões: PDO MySQL, Fileinfo e mbstring
- MySQL 8 ou MariaDB compatível
- Sessões PHP habilitadas
- Apache com mod_rewrite e suporte a .htaccess
- HTTPS habilitado
- Espaço de armazenamento privado para anexos

Antes de enviar o sistema, confirme no painel da hospedagem a versão do PHP, as extensões e os limites de upload.

## 2. Criar banco de dados

1. No painel InfinityFree, crie um banco MySQL e usuário.
2. Anote host, nome do banco, usuário e senha exatamente como exibidos.
3. Abra o phpMyAdmin do banco criado.
4. Importe **somente** o arquivo database/schema.sql.
5. Aguarde a conclusão e confira se as tabelas foram criadas.

> Para uma instalação limpa, não importe também database/migrations/001_initial.sql ou 002_operational_modules.sql. O schema.sql já contém a estrutura consolidada.

## 3. Enviar arquivos

### Opção recomendada quando não há document root personalizado

Envie o conteúdo completo do repositório para a pasta web da hospedagem (normalmente htdocs ou public_html), mantendo a estrutura de diretórios. O .htaccess da raiz encaminha as rotas para public/index.php, publica os assets de public/ e bloqueia o acesso web às pastas privadas.

Não remova nem mova app/, config/, database/, resources/, storage/ ou bin/.

### Se a hospedagem permitir configurar o document root

Aponte o domínio diretamente para a pasta public/. Mantenha app/, config/, database/, resources/ e storage/ fora da raiz pública.

## 4. Configurar .env

Copie .env.example para .env na raiz do projeto e ajuste:

- APP_ENV=production
- APP_DEBUG=false
- APP_URL=https://SEU-DOMINIO (sem barra no final)
- APP_TIMEZONE=America/Sao_Paulo
- DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS e DB_CHARSET=utf8mb4
- INSTALL_TOKEN: chave aleatória forte e temporária, preferencialmente 64 caracteres hexadecimais.

Gere uma chave em um computador com PHP CLI:

    php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

Não envie o .env para o GitHub nem compartilhe a senha do banco ou a chave de instalação.

## 5. Criar o primeiro administrador

1. Com o banco importado e .env configurado, acesse https://SEU-DOMINIO/install.
2. Informe a chave temporária INSTALL_TOKEN, nome, e-mail, matrícula opcional e uma senha com pelo menos 12 caracteres.
3. O sistema cria o primeiro usuário no perfil Administrador e inicia a sessão.
4. Remova INSTALL_TOKEN do .env imediatamente após a criação.
5. Confirme que /install retorna 404 depois de existir um usuário.

Alternativa: em hospedagem com terminal/SSH, execute php bin/create-admin.php na pasta do projeto. O script é exclusivo para CLI e deve ser removido do servidor depois do uso.

## 6. Armazenamento de anexos

O sistema grava fotos, documentos e assinaturas em storage/private/files, fora da pasta public/. Garanta permissão de escrita para o PHP nessa pasta. Não use public/uploads para documentos sigilosos. Se o repositório inteiro estiver dentro de htdocs/public_html, a proteção depende do .htaccess da raiz: valide que /storage/, /database/, /tests/ e /.env retornam HTTP 403. Se a hospedagem ignorar .htaccess, mova os diretórios privados para fora do document root ou use um plano com document root configurável.

Limite padrão por arquivo: 8 MB, configurável em ADM (respeitando também upload_max_filesize e post_max_size do PHP). São aceitos JPG, PNG, WEBP e PDF conforme o módulo; fotos de veículo aceitam até 10 imagens por veículo.

## 7. Verificações após instalação

- /health deve responder JSON com ok: true e database: true.
- Confirme que /storage/, /database/, /tests/ e /.env não podem ser acessados pela web (HTTP 403 ou 404).
- Login do administrador e encerramento da sessão.
- Cadastro, edição e inativação de usuário.
- Permissões de perfil e bloqueio individual de ações.
- Criar, editar e consultar sinistro; anexos e assinatura.
- Criar, publicar, editar e cancelar escala; verificar conflito de horários.
- Criar O.S., atribuir executores, registrar andamento e concluir.
- Exportar CSV e imprimir/Salvar como PDF.
- Acesso pelo celular, menu responsivo e instalação PWA.

Se uma verificação falhar, consulte o log de erros PHP do painel. Mantenha APP_DEBUG=false em produção.

## 8. Limites e compatibilidade InfinityFree

A aplicação não usa Node, npm, Supabase, tarefas permanentes ou backend separado. Mesmo assim, a hospedagem gratuita pode impor limites de execução, espaço, upload, conexões ou recursos PHP. A aprovação do CI no MySQL 8 não substitui o teste no plano real.

Se o plano não permitir escrita fora da raiz pública, não armazene documentos sigilosos em pasta acessível pela web. Nesse caso, solicite suporte ou use um plano que permita armazenamento privado.

## 9. Migração futura para HostGator

1. Ative manutenção e faça dump do banco.
2. Copie todos os arquivos e storage/private.
3. Crie banco/usuário no destino e importe o dump.
4. Atualize .env (host, banco, usuário, senha e APP_URL).
5. Ajuste o document root e permissões.
6. Teste login, perfis, anexos, timezone, relatórios e relacionamentos.
7. Reabra o acesso somente após validação.

## 10. Rotina de segurança

- Backups externos regulares do banco e de storage/private.
- Senhas fortes e contas individuais.
- Revise permissões sempre que houver mudança de função.
- Inative contas de servidores afastados/desligados conforme regra institucional.
- Revise os logs de auditoria.
- Valide os termos e a política de privacidade com o Município/assessoria jurídica.
