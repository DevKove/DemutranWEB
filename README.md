# DemutranWEB

Sistema institucional de gestão administrativa e operacional do Departamento Municipal de Trânsito.

## Stack

- PHP 8.2+ (compatível com hospedagem compartilhada)
- MySQL/MariaDB via PDO
- HTML5, CSS3 e JavaScript sem processo Node permanente

## Estado do projeto

Fundação inicial em implementação. Consulte `docs/ROADMAP.md` para as etapas e `docs/INSTALACAO.md` para preparar o ambiente.

## Requisitos

- PHP 8.2+ com PDO MySQL, Fileinfo e sessões
- MySQL 5.7+/MariaDB 10.4+
- HTTPS recomendado/obrigatório em produção

## Segurança

Nunca publique `.env`, credenciais, backups ou arquivos privados. O diretório `storage/` deve ficar fora da raiz pública. Configure `APP_ENV=production` antes de disponibilizar o sistema.
