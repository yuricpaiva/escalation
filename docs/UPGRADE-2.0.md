# Atualização para Escalation 2.0

## Antes da atualização

1. Coloque o GLPI em manutenção e interrompa o cron.
2. Faça backup consistente do banco e de `config`, `files` e `marketplace/escalation`.
3. Confirme GLPI 11, PHP 8.2+ e espaço para as novas tabelas de auditoria.

## Atualização

Substitua os arquivos, execute a atualização do plugin pela interface ou por `php bin/console glpi:plugin:install --username=glpi escalation` e reative o cron. As migrações são aditivas e idempotentes. Cada fluxo legado recebe uma versão 1 publicada; o editor abre uma cópia rascunho.

## Verificação

- Confirme que fluxos, categorias e históricos antigos continuam visíveis.
- Abra cada rascunho, corrija erros de publicação e publique somente após validação.
- Ative as tarefas automáticas `checktimeouts` e `processqueue`.
- Valide permissões de aprovação e o fluxo de pagamentos em uma entidade de teste.

## Rollback

Não tente remover colunas manualmente. Restaure o backup do banco e os arquivos 1.2.0 como uma unidade. Execuções iniciadas pelo motor 2.0 não são compatíveis com o runtime 1.2.0.

## Estados seguros

Falta de aprovador produz `pending_configuration`. Falha de ação preserva a decisão e marca `automation_status=failed`; o histórico fica em `glpi_plugin_escalation_audits`.
