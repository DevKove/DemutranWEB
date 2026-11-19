# DemutranWEB

Sistema web de gestão administrativa e operacional do Departamento Municipal de Trânsito.

## Módulos entregues

- **Início:** indicadores reais, sinistros e ordens recentes, notificações internas e atalhos.
- **Sinistro:** protocolo sequencial, filtros por tipo, período, vítimas, óbitos e apoio externo, veículos, pessoas, até quatro testemunhas, equipe, assinatura interna e anexos privados.
- **Escala:** calendário mensal/semanal/diário, cadastro e edição, equipes, conflitos inclusive em turnos que atravessam a meia-noite, histórico de alterações, publicação e cancelamento justificado.
- **Ordem de Serviço:** numeração sequencial, solicitante, prioridade, responsável, executores, anexos, aprovação com permissão própria, justificativas, histórico e notificações.
- **Usuários:** cadastro, edição, foto, matrícula, perfil, senha, situação (Ativo/Afastado/Inativo) e permissões individuais. A inativação preserva o histórico.
- **ADM:** configurações institucionais, upload do brasão/logotipo, perfis, permissões por ação e trilha de auditoria.
- **Relatórios:** filtros por período/situação, exportação CSV protegida contra fórmulas e impressão/Salvar como PDF pelo navegador.

## Tecnologia

- PHP 8.2+ (sem framework e sem processo Node em produção)
- MySQL 8 / MariaDB com PDO
- HTML5, CSS3 e JavaScript
- Sessões seguras, CSRF, RBAC, permissões individuais, auditoria e armazenamento privado de anexos
- Layout responsivo e PWA instalável

## Instalação

Consulte **docs/INSTALACAO.md**. Para instalação nova, importe somente **database/schema.sql**. Para banco existente que já aplicou 001 e 002, faça backup e execute somente **database/migrations/003_workflow_calendar.sql**.

Resumo:
1. Crie o banco MySQL na hospedagem.
2. Configure **.env** com os dados fornecidos pelo painel.
3. Importe **database/schema.sql** pelo phpMyAdmin.
4. Configure uma chave aleatória em **INSTALL_TOKEN** e acesse **/install** para criar o primeiro administrador.
5. Remova **INSTALL_TOKEN** do **.env** após a criação.
6. Teste login, permissões, uploads e relatórios em HTTPS.

## Compatibilidade de hospedagem

Preparado para hospedagem compartilhada com PHP 8.2+, PDO MySQL, Fileinfo, mbstring, sessões e Apache mod_rewrite/.htaccess. O projeto não depende de Node.js, npm, Supabase ou serviço externo de backend.

A compatibilidade final depende dos limites e extensões habilitados no plano InfinityFree. A futura migração para HostGator requer copiar os arquivos, importar o dump MySQL, ajustar .env e validar os anexos.

## Segurança e operação

- Não publique .env, backups, dumps SQL ou arquivos privados.
- storage/private deve ter escrita pelo PHP e permanecer fora da pasta public/. Se todo o projeto ficar em htdocs/public_html, confirme que o .htaccess bloqueia /storage/, /database/, /tests/ e /.env; prefira document root em public/ quando a hospedagem permitir.
- Use HTTPS, APP_ENV=production e APP_DEBUG=false.
- Remova a chave INSTALL_TOKEN depois de provisionar o administrador.
- Faça backups periódicos do banco e de storage/private.
- Termos de uso e política de privacidade são textos-base e precisam de validação institucional/jurídica antes da publicação oficial.

## Qualidade

O GitHub Actions executa lint PHP, validação sintática JavaScript, importação do schema em MySQL 8, verificação das tabelas/permissões e teste HTTP dos principais fluxos dos módulos.

O GitHub Pages não é utilizado: a aplicação executa PHP e depende de MySQL.
