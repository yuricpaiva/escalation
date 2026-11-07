export const blockCatalog = [
  { kind: 'approval', icon: 'ti-user-check', title: 'Solicitar aprovação', description: 'Pede a validação de pessoas ou grupos.' },
  { kind: 'assign_user', icon: 'ti-user-share', title: 'Atribuir chamado', description: 'Encaminha o chamado para um técnico.' },
  { kind: 'assign_group', icon: 'ti-users-group', title: 'Atribuir a um grupo', description: 'Encaminha o chamado para um grupo técnico.' },
  { kind: 'create_task', icon: 'ti-list-check', title: 'Criar tarefa', description: 'Inclui uma tarefa no chamado.' },
  { kind: 'add_followup', icon: 'ti-message-circle', title: 'Adicionar acompanhamento', description: 'Registra uma mensagem no chamado.' },
  { kind: 'change_status', icon: 'ti-progress-check', title: 'Alterar situação', description: 'Atualiza a situação do chamado.' },
  { kind: 'change_category', icon: 'ti-category', title: 'Alterar categoria', description: 'Move o chamado para outra categoria.' },
  { kind: 'notify', icon: 'ti-bell', title: 'Enviar notificação', description: 'Dispara um aviso configurado no GLPI.' },
  { kind: 'create_related_ticket', icon: 'ti-copy-plus', title: 'Criar chamado relacionado', description: 'Abre e relaciona um novo chamado.' },
  { kind: 'condition', icon: 'ti-filter', title: 'Quando iniciar', description: 'Define regras para o fluxo começar (não é decisão no meio).' },
];

export const actionLabels = {
  assign_user: 'Atribuir chamado', assign_group: 'Atribuir a um grupo', create_task: 'Criar tarefa',
  add_followup: 'Adicionar acompanhamento', change_status: 'Alterar situação', change_category: 'Alterar categoria',
  notify: 'Enviar notificação', create_related_ticket: 'Criar chamado relacionado',
};

export const conditionFields = {
  event: 'Momento do chamado', category: 'Categoria', creator: 'Criado por', requester: 'Solicitante',
  creator_group: 'Grupo de quem criou', requester_group: 'Grupo do solicitante', type: 'Tipo', status: 'Situação',
};

export const operatorLabels = {
  equals: 'é', not_equals: 'não é', in: 'é um destes', not_in: 'não é nenhum destes',
  member_of: 'faz parte de', changed_from: 'mudou de', changed_to: 'mudou para',
};

export const sourceLabels = {
  user: 'Pessoa específica', group: 'Grupo', supervisor: 'Responsável direto do solicitante',
  requester: 'Solicitantes do chamado', assigned: 'Técnicos atribuídos', observer: 'Observadores',
};

export function defaultAction(type) {
  const parameters = type === 'add_followup' ? { content: '', is_private: 0 }
    : type === 'create_task' ? { content: '', users_id: 0, groups_id: 0 }
    : type === 'notify' ? { event: 'escalation_workflow' }
    : type === 'create_related_ticket' ? { name: '', content: '', entities_id: 0, itilcategories_id: 0, type: 2 }
    : {};
  return { type, parameters, errorPolicy: 'retry', maxAttempts: 3 };
}

export function defaultApproval() {
  return { sources: [], quorum: 'min1', immediate: true, timeoutDays: 0, templateId: 0, message: '', preventSelfApproval: true, allowSolve: false, fallbackUserId: 0, hierarchyLevel: 1, dynamicHierarchy: false };
}
