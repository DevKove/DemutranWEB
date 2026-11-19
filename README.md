# DemutranWEB

Sistema institucional para gestão administrativa e operacional do Departamento Municipal de Trânsito.

## Tecnologia
- PHP 8.2+ e PDO
- MySQL/MariaDB
- HTML5, CSS3 e JavaScript
- Compatível com hospedagem compartilhada (InfinityFree/HostGator), sem processo Node permanente

## Estado atual
Fundação inicial versionada: autenticação por sessão, proteção CSRF, controle RBAC por perfil/permissão individual, auditoria básica, schema relacional, cadastro inicial de usuários, painel com contagens reais e layout responsivo. Os módulos operacionais ainda estão em desenvolvimento e não devem ser tratados como concluídos.

## Instalação
Siga [docs/INSTALACAO.md](docs/INSTALACAO.md). Importe [database/schema.sql](database/schema.sql), configure o arquivo local .env e crie o primeiro administrador via CLI.

## Desenvolvimento
Consulte [docs/ROADMAP.md](docs/ROADMAP.md). A validação automática de sintaxe PHP está configurada em GitHub Actions.

## Segurança
Nunca publique .env, credenciais, backups ou arquivos privados. Em produção, utilize HTTPS, mantenha storage fora da raiz pública e defina APP_DEBUG=false.
