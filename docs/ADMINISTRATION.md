# Administração do Escalation 2.0

## Permissões

Em **Administração → Perfis → Escalation**, conceda separadamente:

- **Visualizar detalhes**: mostra a aba operacional no chamado, sempre limitada aos chamados e entidades visíveis ao usuário.
- **Administrar definições**: cria e altera rascunhos, condições, etapas, aprovadores, transições e ações.
- **Publicar versões**: transforma um rascunho válido em versão imutável.
- **Reprocessar falhas**: recoloca uma execução de ação com falha na fila e registra a operação na auditoria.

O direito global de configuração continua funcionando como fallback administrativo durante a migração.

## Criar e publicar um workflow

1. Crie o fluxo, defina entidade, recursividade e prioridade.
2. Em **Workflow**, configure grupos de condições. Condições do mesmo grupo usam `AND`; grupos usam `OR`.
3. Adicione etapas em ordem. Etapas de aprovação devem ter uma fonte de aprovadores ou substituto; etapas automáticas usam ações `step_activated`.
4. Configure as saídas. Destinos só podem apontar para etapas posteriores; isso mantém o grafo acíclico.
5. Configure ações e a política de falha: continuar, parar ou repetir.
6. Corrija todos os erros mostrados em **Validação e publicação** e publique.

Editar uma definição publicada cria ou atualiza o próximo rascunho. Execuções já iniciadas permanecem ligadas à versão original.

## Importar o exemplo de pagamentos

Abra **Administração → Escalation → Importar workflow**, cole `examples/payment-approval.json` e informe um JSON de mapeamento. Exemplo:

```json
{
  "ENTITY": 1,
  "PAYMENT_CATEGORY": 4,
  "PAYMENT_REQUESTERS_GROUP": 8,
  "OPTIONAL_FALLBACK_USER": 15,
  "FINANCE_MANAGER": 21,
  "PAYMENT_OWNER": 27
}
```

A importação valida marcadores, condições, etapas, destinos e parâmetros das ações antes de criar um rascunho inativo. Revise e publique pela interface.

## Cron, falhas e auditoria

Habilite `checktimeouts` e `processqueue` em **Configuração → Ações automáticas**. Em produção, use o modo CLI/externo recomendado pelo GLPI, executando o cron do GLPI a cada minuto. A fila usa chaves idempotentes e retries de 5, 30 e 120 minutos.

A aba do chamado mostra versão, etapa, aprovadores congelados, votos, prazos, ações e linha do tempo. Uma falha posterior à aprovação não muda `decision_status=approved`; ela define `automation_status=failed`. Usuários com **Reprocessar falhas** podem reenfileirar a ação na própria aba. Início, decisão, timeout, ações e reprocessamentos ficam em `glpi_plugin_escalation_audits`.

## Limitações da versão 2.0

- Apenas `Ticket`; Changes e Problems não disparam workflows.
- Uma definição roda no máximo uma vez por chamado.
- Não há scripts, PHP, SQL, webhooks ou expressões arbitrárias.
- Aprovadores são congelados ao ativar a etapa.
- Rollback exige restaurar, em conjunto, o backup do banco e os arquivos 1.2.0.
