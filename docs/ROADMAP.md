# Roadmap e estado de entrega

## Fundação e infraestrutura
- [x] Estrutura PHP modular e configuração por .env
- [x] PDO MySQL, sessão protegida e timeout
- [x] Login/logout, CSRF, bloqueio temporário após falhas e auditoria
- [x] RBAC por perfil e sobrescrita individual por usuário
- [x] Criação segura do primeiro administrador por chave temporária ou CLI
- [x] Layout responsivo, PWA, assets e fallback offline sem cache de páginas autenticadas
- [x] Armazenamento privado de anexos
- [x] Termos e política de privacidade como textos-base
- [x] CI: lint PHP/JS, importação MySQL 8 e smoke test do schema

## Módulos
- [x] Início com indicadores reais, registros recentes e notificações
- [x] Sinistro: protocolo, envolvidos, veículos, testemunhas (máximo 4), equipe, apoio externo, assinatura interna e anexos
- [x] Escala: cadastro/edição, equipes, conflito de horários, publicação, cancelamento e notificações
- [x] Ordem de Serviço: numeração sequencial, responsável, executores, anexos, transições, histórico e apontamentos
- [x] Usuários: cadastro, edição, foto, matrícula, perfil, status e permissões individuais
- [x] ADM: configurações, perfis, permissões e auditoria
- [x] Relatórios: filtros, CSV e impressão/Salvar como PDF

## Pendências externas antes de produção

- [ ] Criar o banco e configurar credenciais no painel InfinityFree
- [ ] Validar PHP/extensões/limites e .htaccess no plano real
- [ ] Criar o primeiro administrador e remover INSTALL_TOKEN
- [ ] Executar roteiro de testes no navegador e celular
- [ ] Validar textos legais com o Município e assessoria jurídica
- [ ] Definir rotina de backup externo e retenção de documentos
- [ ] Realizar teste de migração para HostGator antes da troca de hospedagem

> Os módulos estão implementados no código, mas a homologação no servidor real ainda não foi realizada. Não considerar o sistema pronto para uso institucional até concluir as pendências externas acima.
