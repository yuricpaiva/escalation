<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/** Bridges the visual editor and the existing, versioned workflow engine. */
final class PluginEscalationVisualEditor {
  private const CONDITION_FIELDS = ['event','category','creator','requester','creator_group','requester_group','type','status'];
  private const CONDITION_OPERATORS = ['equals','not_equals','in','not_in','member_of','changed_from','changed_to'];
  private const APPROVER_SOURCES = ['user','group','supervisor','requester','assigned','observer'];
  private const TERMINALS = ['approved','rejected','expired','failed','pending_configuration'];
  private const LIFECYCLE_EVENTS = ['flow_started','step_approved','step_rejected','flow_approved','flow_rejected','flow_expired'];

  public static function showForFlow(PluginEscalationFlow $flow): bool {
    if (!PluginEscalationProfile::have(PluginEscalationProfile::MANAGE)) {
      echo "<div class='alert alert-danger'>" . __('Você não tem permissão para administrar fluxos.', 'escalation') . '</div>';
      return false;
    }
    $form = $flow->getFormURLWithID((int) $flow->getID());
    $config = [
      'flowId' => (int) $flow->getID(),
      'endpoint' => PluginEscalationConfig::getWebPath() . '/ajax/visualeditor.php',
      'csrfToken' => Session::getNewCSRFToken(),
      'canPublish' => PluginEscalationProfile::have(PluginEscalationProfile::PUBLISH),
      'screens' => [
        ['key' => 'form', 'label' => __('Fluxo', 'escalation'), 'href' => $form . '&forcetab=' . rawurlencode('PluginEscalationFlow$main')],
        ['key' => 'editor', 'label' => __('Editor visual', 'escalation'), 'href' => $form . '&forcetab=' . rawurlencode('PluginEscalationFlow$1'), 'current' => true],
        ['key' => 'advanced', 'label' => __('Modo avançado', 'escalation'), 'href' => $form . '&forcetab=' . rawurlencode('PluginEscalationFlow$2')],
        ['key' => 'steps', 'label' => __('Etapas', 'escalation'), 'href' => $form . '&forcetab=' . rawurlencode('PluginEscalationFlow$3')],
      ],
    ];
    echo "<div id='escalation-visual-editor' class='escalation-visual-editor-root' aria-live='polite'></div>";
    echo "<script type='application/json' id='escalation-visual-editor-config'>";
    echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    echo '</script>';
    return true;
  }

  public static function load(int $flow_id): array {
    global $DB;
    self::assertManage($flow_id);
    $version_id = PluginEscalationFlowVersion::ensureDraft($flow_id);
    $version = $DB->request(['FROM' => 'glpi_plugin_escalation_flowversions', 'WHERE' => ['id' => $version_id], 'LIMIT' => 1])->current();
    $layout_row = $DB->tableExists('glpi_plugin_escalation_visuallayouts')
      ? $DB->request(['FROM' => 'glpi_plugin_escalation_visuallayouts', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id], 'LIMIT' => 1])->current()
      : false;
    $layout = $layout_row ? json_decode((string) $layout_row['layout'], true) : [];
    if (!is_array($layout)) $layout = [];
    $positions = $layout['positions'] ?? [];
    $unsupported = [];

    $conditions = [];
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_conditiongroups', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id], 'ORDERBY' => ['group_order ASC']]) as $group) {
      $key = 'condition-' . (int) $group['group_order'];
      $all = [];
      foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_conditions', 'WHERE' => ['plugin_escalation_conditiongroups_id' => $group['id']], 'ORDERBY' => ['condition_order ASC']]) as $condition) {
        if (!in_array($condition['field'], self::CONDITION_FIELDS, true) || !in_array($condition['operator'], self::CONDITION_OPERATORS, true)) {
          $unsupported[] = sprintf(__('A condição "%s" só pode ser alterada no Modo avançado.', 'escalation'), $condition['field']);
        }
        $value = json_decode((string) $condition['value'], true);
        if (json_last_error() !== JSON_ERROR_NONE) $value = (string) $condition['value'];
        $all[] = ['field' => $condition['field'], 'operator' => $condition['operator'], 'value' => $value];
      }
      $conditions[] = ['key' => $key, 'all' => $all, 'entry_steps_id' => (int) ($group['entry_steps_id'] ?? 0), 'position' => $positions[$key] ?? null];
    }

    $steps = [];
    $step_rows = iterator_to_array($DB->request(['FROM' => PluginEscalationStep::getTable(), 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id], 'ORDERBY' => ['step_order ASC']]));
    $key_by_id = [];
    foreach ($step_rows as $step) $key_by_id[(int) $step['id']] = 'step-' . (int) $step['step_order'];
    foreach ($conditions as &$condition) {
      $entry_id = (int) ($condition['entry_steps_id'] ?? 0);
      $condition['entry'] = $entry_id > 0 ? ($key_by_id[$entry_id] ?? '') : '';
      unset($condition['entry_steps_id']);
    }
    unset($condition);
    foreach ($step_rows as $step) {
      $key = $key_by_id[(int) $step['id']];
      $kind = ($step['step_kind'] ?? 'approval') === 'action' ? 'action' : 'approval';
      $sources = [];
      foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_stepapprovers', 'WHERE' => ['plugin_escalation_steps_id' => $step['id']]]) as $source) {
        if (!in_array($source['source_type'], self::APPROVER_SOURCES, true)) {
          $unsupported[] = sprintf(__('A fonte de aprovador "%s" só pode ser alterada no Modo avançado.', 'escalation'), $source['source_type']);
        }
        $sources[] = ['source' => $source['source_type'], 'value' => (int) $source['items_id']];
      }
      $transitions = [];
      foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_transitions', 'WHERE' => ['plugin_escalation_steps_id' => $step['id']]]) as $transition) {
        $transitions[$transition['outcome']] = (int) $transition['target_steps_id'] > 0
          ? ($key_by_id[(int) $transition['target_steps_id']] ?? '')
          : 'terminal:' . $transition['terminal_state'];
      }
      $node = [
        'key' => $key,
        'kind' => $kind,
        'name' => (string) $step['name'],
        'position' => $positions[$key] ?? null,
        'transitions' => $transitions,
      ];
      if ($kind === 'approval') {
        $node['approval'] = [
          'sources' => $sources,
          'quorum' => $step['quorum_type'] ?? PluginEscalationStep::QUORUM_MIN1,
          'immediate' => !empty($step['generate_immediately']),
          'timeoutDays' => (int) ($step['timeout_days'] ?? 0),
          'templateId' => (int) ($step['plugin_escalation_templates_id'] ?? 0),
          'message' => (string) ($step['content'] ?? ''),
          'preventSelfApproval' => !empty($step['prevent_self_approval']),
          'allowSolve' => !empty($step['allow_solve']),
          'fallbackUserId' => (int) ($step['fallback_users_id'] ?? 0),
          'hierarchyLevel' => (int) ($step['hierarchy_level'] ?? 1),
          'dynamicHierarchy' => !empty($step['auto_hierarchy_dynamic']),
        ];
      } else {
        $action = $DB->request(['FROM' => 'glpi_plugin_escalation_actions', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id, 'plugin_escalation_steps_id' => $step['id'], 'event' => 'step_activated'], 'ORDERBY' => ['action_order ASC'], 'LIMIT' => 1])->current();
        $parameters = $action ? json_decode((string) $action['parameters'], true) : [];
        if (!is_array($parameters)) $parameters = [];
        $node['action'] = $action ? [
          'type' => $action['type'], 'parameters' => $parameters,
          'errorPolicy' => $action['error_policy'], 'maxAttempts' => (int) $action['max_attempts'],
          'manual' => ($step['activation_mode'] ?? 'automatic') === 'manual',
        ] : ['type' => '', 'parameters' => [], 'errorPolicy' => 'retry', 'maxAttempts' => 3];
        if ($action && !in_array($action['type'], PluginEscalationActionExecutor::types(), true)) {
          $unsupported[] = sprintf(__('A ação "%s" só pode ser alterada no Modo avançado.', 'escalation'), $action['type']);
        }
      }
      $steps[] = $node;
    }

    $lifecycle = [];
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_actions', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id, 'plugin_escalation_steps_id' => 0], 'ORDERBY' => ['event ASC','action_order ASC']]) as $action) {
      $parameters = json_decode((string) $action['parameters'], true);
      $lifecycle[] = [
        'key' => 'lifecycle-' . $action['event'] . '-' . (int) $action['action_order'],
        'name' => $action['name'], 'event' => $action['event'], 'type' => $action['type'],
        'parameters' => is_array($parameters) ? $parameters : [],
        'errorPolicy' => $action['error_policy'], 'maxAttempts' => (int) $action['max_attempts'],
      ];
      if (!in_array($action['event'], self::LIFECYCLE_EVENTS, true) || !in_array($action['type'], PluginEscalationActionExecutor::types(), true)) {
        $unsupported[] = sprintf(__('A automação "%s" contém uma configuração que só pode ser alterada no Modo avançado.', 'escalation'), $action['name']);
      }
    }

    $payload = [
      'flowId' => $flow_id,
      'versionId' => $version_id,
      'version' => (int) $version['version'],
      'revision' => (int) ($layout_row['revision'] ?? 0),
      'settings' => [
        'name' => $version['name'], 'description' => $version['description'],
        'entityId' => (int) $version['entities_id'], 'recursive' => !empty($version['is_recursive']),
        'priority' => (int) $version['priority'],
      ],
      'conditions' => $conditions,
      'steps' => $steps,
      'lifecycleActions' => $lifecycle,
      'anchors' => [
        'start' => (isset($positions['start']['x'], $positions['start']['y']) ? ['x' => (float) $positions['start']['x'], 'y' => (float) $positions['start']['y']] : null),
        'finish' => (isset($positions['finish']['x'], $positions['finish']['y']) ? ['x' => (float) $positions['finish']['x'], 'y' => (float) $positions['finish']['y']] : null),
      ],
      'viewport' => $layout['viewport'] ?? ['x' => 0, 'y' => 0, 'zoom' => 1],
      'unsupported' => array_values(array_unique($unsupported)),
      'readOnly' => !empty($unsupported),
    ];
    $payload['labels'] = self::collectLabels($payload);
    return $payload;
  }

  public static function applyStarterTemplate(int $flow_id, string $template): void {
    global $DB;
    self::assertManage($flow_id);
    if (!in_array($template, ['empty','simple','double','assignment'], true)) $template = 'simple';
    $version_id = PluginEscalationFlowVersion::ensureDraft($flow_id);
    if (countElementsInTable(PluginEscalationStep::getTable(), ['plugin_escalation_flowversions_id' => $version_id]) > 0) return;
    $DB->insert('glpi_plugin_escalation_conditiongroups', ['plugin_escalation_flowversions_id' => $version_id, 'group_order' => 1]);
    $group_id = (int) $DB->insertId();
    $DB->insert('glpi_plugin_escalation_conditions', ['plugin_escalation_conditiongroups_id' => $group_id, 'field' => 'event', 'operator' => 'equals', 'value' => 'created', 'condition_order' => 1]);
    if ($template === 'empty') return;
    $names = $template === 'simple' ? [__('Aprovação', 'escalation')] : [__('Primeira aprovação', 'escalation'), __('Segunda aprovação', 'escalation')];
    $order = 0;
    foreach ($names as $name) {
      $order++;
      $DB->insert(PluginEscalationStep::getTable(), [
        'plugin_escalation_flows_id' => $flow_id, 'plugin_escalation_flowversions_id' => $version_id,
        'name' => $name, 'type' => PluginEscalationStep::TYPE_PEOPLE, 'step_kind' => 'approval', 'step_order' => $order,
        'timeout_days' => 0, 'quorum_type' => PluginEscalationStep::QUORUM_MIN1, 'hierarchy_level' => 1,
        'auto_hierarchy_dynamic' => 0, 'generate_immediately' => 1, 'include_requesters' => 0,
        'include_assigned' => 0, 'include_observers' => 0, 'plugin_escalation_templates_id' => 0,
        'content' => '', 'prevent_self_approval' => 1, 'allow_solve' => 0, 'fallback_users_id' => 0,
      ]);
    }
    if ($template === 'assignment') {
      $order++;
      $DB->insert(PluginEscalationStep::getTable(), [
        'plugin_escalation_flows_id' => $flow_id, 'plugin_escalation_flowversions_id' => $version_id,
        'name' => __('Encaminhar para execução', 'escalation'), 'type' => PluginEscalationStep::TYPE_PEOPLE,
        'step_kind' => 'action', 'step_order' => $order, 'timeout_days' => 0,
        'quorum_type' => PluginEscalationStep::QUORUM_MIN1, 'hierarchy_level' => 1,
        'auto_hierarchy_dynamic' => 0, 'generate_immediately' => 1, 'include_requesters' => 0,
        'include_assigned' => 0, 'include_observers' => 0, 'plugin_escalation_templates_id' => 0,
        'content' => '', 'prevent_self_approval' => 0, 'allow_solve' => 0, 'fallback_users_id' => 0,
      ]);
      self::insertAction($version_id, (int) $DB->insertId(), 'step_activated', __('Atribuir chamado', 'escalation'), ['type' => 'assign_user', 'parameters' => ['users_id' => 0], 'errorPolicy' => 'retry', 'maxAttempts' => 3], 1);
    }
  }

  /** @return array<int,array{nodeKey:string,message:string}> */
  public static function validateDefinition(int $flow_id, array $definition): array {
    self::assertManage($flow_id);
    $errors = [];
    $settings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];
    if ((int) ($settings['entityId'] ?? 0) > 0 && !in_array((int) $settings['entityId'], self::activeEntities(), true)) {
      $errors[] = ['nodeKey' => 'start', 'message' => __('Escolha uma entidade que você tenha permissão para administrar.', 'escalation')];
    }
    $conditions = is_array($definition['conditions'] ?? null) ? $definition['conditions'] : [];
    $has_event = false;
    foreach ($conditions as $group_index => $group) {
      $key = (string) ($group['key'] ?? 'condition-' . ($group_index + 1));
      $all = is_array($group['all'] ?? null) ? $group['all'] : [];
      if (!$all) $errors[] = ['nodeKey' => $key, 'message' => __('Adicione pelo menos uma regra nesta alternativa.', 'escalation')];
      foreach ($all as $condition) {
        $field = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? '');
        if (!in_array($field, self::CONDITION_FIELDS, true) || !in_array($operator, self::CONDITION_OPERATORS, true)) {
          $errors[] = ['nodeKey' => $key, 'message' => __('Esta condição não é aceita pelo motor.', 'escalation')];
        }
        if ($field === 'event' && in_array($condition['value'] ?? null, ['created','updated'], true)) $has_event = true;
        if (!array_key_exists('value', $condition) || $condition['value'] === '' || $condition['value'] === null) {
          $errors[] = ['nodeKey' => $key, 'message' => __('Escolha o valor desta condição.', 'escalation')];
        } elseif (!self::validConditionReference($field, $condition['value'])) {
          $errors[] = ['nodeKey' => $key, 'message' => __('O item escolhido não existe mais ou não está disponível nesta entidade.', 'escalation')];
        }
      }
    }
    if (!$has_event) $errors[] = ['nodeKey' => 'start', 'message' => __('Escolha se o fluxo começa na abertura ou na atualização do chamado.', 'escalation')];

    $steps = is_array($definition['steps'] ?? null) ? array_values($definition['steps']) : [];
    if (!$steps) $errors[] = ['nodeKey' => 'start', 'message' => __('Adicione pelo menos uma aprovação ou ação automática.', 'escalation')];
    $order = [];
    foreach ($steps as $index => $step) {
      $key = trim((string) ($step['key'] ?? ''));
      if ($key === '' || isset($order[$key])) {
        $errors[] = ['nodeKey' => $key ?: 'start', 'message' => __('Há um bloco sem identificação ou repetido.', 'escalation')];
        continue;
      }
      $order[$key] = $index;
      if (trim((string) ($step['name'] ?? '')) === '') $errors[] = ['nodeKey' => $key, 'message' => __('Dê um nome claro para este bloco.', 'escalation')];
      $kind = (string) ($step['kind'] ?? '');
      if ($kind === 'approval') {
        $approval = is_array($step['approval'] ?? null) ? $step['approval'] : [];
        $sources = is_array($approval['sources'] ?? null) ? $approval['sources'] : [];
        if (!$sources && empty($approval['fallbackUserId'])) $errors[] = ['nodeKey' => $key, 'message' => __('Escolha quem precisa aprovar esta solicitação.', 'escalation')];
        foreach ($sources as $source) {
          if (!in_array($source['source'] ?? '', self::APPROVER_SOURCES, true)) $errors[] = ['nodeKey' => $key, 'message' => __('Esta forma de escolher aprovadores não é válida.', 'escalation')];
          if (in_array($source['source'] ?? '', ['user','group'], true) && (int) ($source['value'] ?? 0) <= 0) $errors[] = ['nodeKey' => $key, 'message' => __('Selecione a pessoa ou o grupo que deverá aprovar.', 'escalation')];
          if (($source['source'] ?? '') === 'user' && !self::referenceExists(User::class, (int) ($source['value'] ?? 0))) $errors[] = ['nodeKey' => $key, 'message' => __('A pessoa escolhida não está mais disponível.', 'escalation')];
          if (($source['source'] ?? '') === 'group' && !self::referenceExists(Group::class, (int) ($source['value'] ?? 0), true)) $errors[] = ['nodeKey' => $key, 'message' => __('O grupo escolhido não está disponível nesta entidade.', 'escalation')];
        }
        if (!in_array($approval['quorum'] ?? '', ['min1','majority','all'], true)) $errors[] = ['nodeKey' => $key, 'message' => __('Escolha quantas pessoas precisam aprovar.', 'escalation')];
        if ((int) ($approval['fallbackUserId'] ?? 0) > 0 && !self::referenceExists(User::class, (int) $approval['fallbackUserId'])) $errors[] = ['nodeKey' => $key, 'message' => __('O aprovador substituto não está mais disponível.', 'escalation')];
        if ((int) ($approval['templateId'] ?? 0) > 0 && !self::referenceExists(PluginEscalationTemplate::class, (int) $approval['templateId'])) $errors[] = ['nodeKey' => $key, 'message' => __('O modelo de mensagem escolhido não está mais disponível.', 'escalation')];
      } elseif ($kind === 'action') {
        $action = is_array($step['action'] ?? null) ? $step['action'] : [];
        $type = (string) ($action['type'] ?? '');
        if (!in_array($type, PluginEscalationActionExecutor::types(), true)) {
          $errors[] = ['nodeKey' => $key, 'message' => __('Escolha o que o GLPI deve fazer.', 'escalation')];
        } else {
          $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
          foreach (PluginEscalationActionExecutor::validateParameters($type, $parameters) as $message) {
            $errors[] = ['nodeKey' => $key, 'message' => self::friendlyParameterError($type, $message)];
          }
          if (!self::validActionReferences($type, $parameters)) $errors[] = ['nodeKey' => $key, 'message' => __('O cadastro escolhido para esta ação não está mais disponível.', 'escalation')];
        }
      } else {
        $errors[] = ['nodeKey' => $key, 'message' => __('Este tipo de bloco não é aceito.', 'escalation')];
      }
    }
    foreach ($steps as $index => $step) {
      $key = (string) ($step['key'] ?? '');
      foreach ((array) ($step['transitions'] ?? []) as $target) {
        if (str_starts_with((string) $target, 'terminal:')) {
          if (!in_array(substr((string) $target, 9), self::TERMINALS, true)) $errors[] = ['nodeKey' => $key, 'message' => __('O encerramento escolhido não é válido.', 'escalation')];
        } elseif (!isset($order[$target]) || $order[$target] <= $index) {
          $errors[] = ['nodeKey' => $key, 'message' => __('Este caminho precisa apontar para um bloco posterior.', 'escalation')];
        }
      }
    }
    foreach ((array) ($definition['conditions'] ?? []) as $index => $group) {
      $entry = (string) ($group['entry'] ?? '');
      if ($entry !== '' && !isset($order[$entry])) {
        $errors[] = ['nodeKey' => (string) ($group['key'] ?? 'condition-' . ($index + 1)), 'message' => __('Escolha uma etapa existente para este começo.', 'escalation')];
      }
    }
    foreach ((array) ($definition['lifecycleActions'] ?? []) as $action) {
      $key = (string) ($action['key'] ?? 'start');
      if (!in_array($action['event'] ?? '', self::LIFECYCLE_EVENTS, true) || !in_array($action['type'] ?? '', PluginEscalationActionExecutor::types(), true)) {
        $errors[] = ['nodeKey' => $key, 'message' => __('Uma automação do fluxo possui uma configuração inválida.', 'escalation')];
        continue;
      }
      foreach (PluginEscalationActionExecutor::validateParameters($action['type'], is_array($action['parameters'] ?? null) ? $action['parameters'] : []) as $message) {
        $errors[] = ['nodeKey' => $key, 'message' => self::friendlyParameterError($action['type'], $message)];
      }
      if (!self::validActionReferences($action['type'], (array) ($action['parameters'] ?? []))) $errors[] = ['nodeKey' => $key, 'message' => __('O cadastro escolhido para esta ação não está mais disponível.', 'escalation')];
    }
    return $errors;
  }

  public static function save(int $flow_id, array $definition, int $expected_revision): array {
    global $DB;
    self::assertManage($flow_id);
    $version_id = PluginEscalationFlowVersion::ensureDraft($flow_id);
    if (!empty($definition['unsupported'])) throw new RuntimeException(__('O fluxo possui configurações avançadas. Ajuste-as no Modo avançado antes de salvar pelo editor visual.', 'escalation'));
    $errors = self::validateDraftStructure($flow_id, $definition);
    if ($errors) return ['ok' => false, 'errors' => $errors];
    $layout_row = $DB->request(['FROM' => 'glpi_plugin_escalation_visuallayouts', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id], 'LIMIT' => 1])->current();
    $current_revision = (int) ($layout_row['revision'] ?? 0);
    if ($current_revision !== $expected_revision) {
      return ['ok' => false, 'conflict' => true, 'message' => __('Este rascunho foi alterado em outra tela. Recarregue o editor para continuar sem perder dados.', 'escalation')];
    }

    $DB->beginTransaction();
    try {
      $old_steps = array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => PluginEscalationStep::getTable(), 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id]])), 'id'));
      if ($old_steps) {
        $DB->delete('glpi_plugin_escalation_transitions', ['plugin_escalation_steps_id' => $old_steps]);
        $DB->delete('glpi_plugin_escalation_stepapprovers', ['plugin_escalation_steps_id' => $old_steps]);
        $DB->delete(PluginEscalationStepUser::getTable(), ['plugin_escalation_steps_id' => $old_steps]);
      }
      $DB->delete('glpi_plugin_escalation_actions', ['plugin_escalation_flowversions_id' => $version_id]);
      $DB->delete(PluginEscalationStep::getTable(), ['plugin_escalation_flowversions_id' => $version_id]);
      $groups = iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_escalation_conditiongroups', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id]]));
      if ($groups) $DB->delete('glpi_plugin_escalation_conditions', ['plugin_escalation_conditiongroups_id' => array_column($groups, 'id')]);
      $DB->delete('glpi_plugin_escalation_conditiongroups', ['plugin_escalation_flowversions_id' => $version_id]);

      $step_ids = [];
      foreach (array_values($definition['steps']) as $index => $node) {
        $approval = is_array($node['approval'] ?? null) ? $node['approval'] : [];
        $kind = $node['kind'];
        $DB->insert(PluginEscalationStep::getTable(), [
          'plugin_escalation_flows_id' => $flow_id, 'plugin_escalation_flowversions_id' => $version_id,
          'name' => trim((string) $node['name']), 'type' => PluginEscalationStep::TYPE_PEOPLE,
          'step_kind' => $kind, 'step_order' => $index + 1,
          'activation_mode' => $kind === 'action' && !empty($node['action']['manual']) ? 'manual' : 'automatic',
          'timeout_days' => max(0, (int) ($approval['timeoutDays'] ?? 0)),
          'quorum_type' => $approval['quorum'] ?? PluginEscalationStep::QUORUM_MIN1,
          'hierarchy_level' => max(1, (int) ($approval['hierarchyLevel'] ?? 1)),
          'auto_hierarchy_dynamic' => !empty($approval['dynamicHierarchy']) ? 1 : 0,
          'generate_immediately' => !array_key_exists('immediate', $approval) || !empty($approval['immediate']) ? 1 : 0,
          'include_requesters' => 0, 'include_assigned' => 0, 'include_observers' => 0,
          'plugin_escalation_templates_id' => (int) ($approval['templateId'] ?? 0),
          'content' => (string) ($approval['message'] ?? ''),
          'prevent_self_approval' => !empty($approval['preventSelfApproval']) ? 1 : 0,
          'allow_solve' => !empty($approval['allowSolve']) ? 1 : 0,
          'fallback_users_id' => (int) ($approval['fallbackUserId'] ?? 0),
        ]);
        $step_id = (int) $DB->insertId();
        $step_ids[$node['key']] = $step_id;
        foreach ((array) ($approval['sources'] ?? []) as $source) {
          $DB->insert('glpi_plugin_escalation_stepapprovers', ['plugin_escalation_steps_id' => $step_id, 'source_type' => $source['source'], 'items_id' => (int) ($source['value'] ?? 0)]);
        }
        if ($kind === 'action') self::insertAction($version_id, $step_id, 'step_activated', $node['name'], $node['action'], 1);
      }
      foreach (array_values($definition['conditions'] ?? []) as $group_index => $group) {
        $entry_key = (string) ($group['entry'] ?? '');
        $DB->insert('glpi_plugin_escalation_conditiongroups', [
          'plugin_escalation_flowversions_id' => $version_id,
          'group_order' => $group_index + 1,
          'entry_steps_id' => isset($step_ids[$entry_key]) ? $step_ids[$entry_key] : 0,
        ]);
        $group_id = (int) $DB->insertId();
        foreach (array_values($group['all'] ?? []) as $condition_index => $condition) {
          $value = is_array($condition['value']) ? json_encode($condition['value'], JSON_UNESCAPED_UNICODE) : (string) $condition['value'];
          $DB->insert('glpi_plugin_escalation_conditions', [
            'plugin_escalation_conditiongroups_id' => $group_id, 'field' => $condition['field'],
            'operator' => $condition['operator'], 'value' => $value, 'condition_order' => $condition_index + 1,
          ]);
        }
      }
      foreach ($definition['steps'] as $node) {
        foreach ((array) ($node['transitions'] ?? []) as $outcome => $target) {
          $terminal = str_starts_with((string) $target, 'terminal:') ? substr((string) $target, 9) : null;
          $DB->insert('glpi_plugin_escalation_transitions', [
            'plugin_escalation_steps_id' => $step_ids[$node['key']], 'outcome' => $outcome,
            'target_steps_id' => $terminal === null ? $step_ids[$target] : 0, 'terminal_state' => $terminal,
          ]);
        }
      }
      foreach (array_values($definition['lifecycleActions'] ?? []) as $index => $action) {
        self::insertAction($version_id, 0, $action['event'], $action['name'] ?? PluginEscalationAdmin::actionLabels()[$action['type']], $action, $index + 1);
      }

      $settings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];
      $metadata = [
        'name' => trim((string) ($settings['name'] ?? '')), 'description' => (string) ($settings['description'] ?? ''),
        'entities_id' => (int) ($settings['entityId'] ?? 0), 'is_recursive' => !empty($settings['recursive']) ? 1 : 0,
        'priority' => (int) ($settings['priority'] ?? 0), 'date_mod' => date('Y-m-d H:i:s'),
      ];
      $DB->update('glpi_plugin_escalation_flowversions', $metadata, ['id' => $version_id]);
      unset($metadata['date_mod']);
      $DB->update(PluginEscalationFlow::getTable(), $metadata, ['id' => $flow_id]);

      $positions = [];
      foreach (array_values($definition['conditions'] ?? []) as $index => $node) {
        if (isset($node['position']['x'], $node['position']['y'])) $positions['condition-' . ($index + 1)] = ['x' => (float) $node['position']['x'], 'y' => (float) $node['position']['y']];
      }
      foreach (array_values($definition['steps'] ?? []) as $index => $node) {
        if (isset($node['position']['x'], $node['position']['y'])) $positions['step-' . ($index + 1)] = ['x' => (float) $node['position']['x'], 'y' => (float) $node['position']['y']];
      }
      $anchors = is_array($definition['anchors'] ?? null) ? $definition['anchors'] : [];
      foreach (['start', 'finish'] as $anchor) {
        if (isset($anchors[$anchor]['x'], $anchors[$anchor]['y'])) {
          $positions[$anchor] = ['x' => (float) $anchors[$anchor]['x'], 'y' => (float) $anchors[$anchor]['y']];
        }
      }
      $layout = json_encode(['positions' => $positions, 'viewport' => $definition['viewport'] ?? ['x' => 0, 'y' => 0, 'zoom' => 1]], JSON_UNESCAPED_UNICODE);
      $new_revision = $current_revision + 1;
      if ($layout_row) {
        $DB->update('glpi_plugin_escalation_visuallayouts', ['layout' => $layout, 'revision' => $new_revision, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $layout_row['id'], 'revision' => $current_revision]);
      } else {
        $DB->insert('glpi_plugin_escalation_visuallayouts', ['plugin_escalation_flowversions_id' => $version_id, 'layout' => $layout, 'revision' => $new_revision, 'date_creation' => date('Y-m-d H:i:s'), 'date_mod' => date('Y-m-d H:i:s')]);
      }
      $DB->commit();
      return ['ok' => true, 'revision' => $new_revision, 'versionId' => $version_id];
    } catch (Throwable $e) {
      $DB->rollBack();
      throw $e;
    }
  }

  public static function publish(int $flow_id, array $definition, int $revision): array {
    if (!PluginEscalationProfile::have(PluginEscalationProfile::PUBLISH)) throw new RuntimeException(__('Você não tem permissão para publicar fluxos.', 'escalation'));
    $definition_errors = self::validateDefinition($flow_id, $definition);
    if ($definition_errors) return ['ok' => false, 'errors' => $definition_errors];
    $saved = self::save($flow_id, $definition, $revision);
    if (empty($saved['ok'])) return $saved;
    $errors = PluginEscalationWorkflow::publish($flow_id);
    return $errors ? ['ok' => false, 'errors' => array_map(static fn($message) => ['nodeKey' => 'start', 'message' => $message], $errors)] : ['ok' => true, 'published' => true];
  }

  public static function options(string $kind, string $search, array $ids = []): array {
    global $DB;
    if (!PluginEscalationProfile::have(PluginEscalationProfile::MANAGE)) throw new RuntimeException(__('Acesso negado.', 'escalation'));
    $search = trim(Toolbox::substr($search, 0, 80));
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    if ($kind === 'statuses') {
      $all = array_map(static fn($id) => ['id' => $id, 'text' => Ticket::getStatus($id)], array_keys(Ticket::getAllStatusArray()));
      return $ids ? array_values(array_filter($all, static fn($item) => in_array((int) $item['id'], $ids, true))) : $all;
    }
    if ($kind === 'types') {
      $all = [['id' => Ticket::INCIDENT_TYPE, 'text' => Ticket::getTicketTypeName(Ticket::INCIDENT_TYPE)], ['id' => Ticket::DEMAND_TYPE, 'text' => Ticket::getTicketTypeName(Ticket::DEMAND_TYPE)]];
      return $ids ? array_values(array_filter($all, static fn($item) => in_array((int) $item['id'], $ids, true))) : $all;
    }
    $map = [
      'users' => [User::getTable(), User::class], 'groups' => [Group::getTable(), Group::class],
      'categories' => [ITILCategory::getTable(), ITILCategory::class], 'entities' => [Entity::getTable(), Entity::class],
      'templates' => [PluginEscalationTemplate::getTable(), PluginEscalationTemplate::class],
    ];
    if (!isset($map[$kind])) return [];
    [$table, $class] = $map[$kind];
    $where = [];
    if ($ids) {
      $where['id'] = $ids;
    } else {
      if ($search !== '') $where['name'] = ['LIKE', '%' . $search . '%'];
      if ($kind !== 'users' && $kind !== 'templates' && $kind !== 'entities' && $DB->fieldExists($table, 'entities_id')) $where['entities_id'] = self::activeEntities();
      if ($DB->fieldExists($table, 'is_deleted')) $where['is_deleted'] = 0;
      if ($DB->fieldExists($table, 'is_active')) $where['is_active'] = 1;
    }
    $items = [];
    $limit = $ids ? max(30, count($ids)) : 30;
    foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDERBY' => ['name ASC'], 'LIMIT' => $limit]) as $row) {
      if (!$ids && $kind === 'entities' && !in_array((int) $row['id'], self::activeEntities(), true)) continue;
      $text = self::optionText($kind, $class, $row);
      $items[] = ['id' => (int) $row['id'], 'text' => $text];
    }
    return $items;
  }

  /** @return array<string,array<string,string>> */
  private static function collectLabels(array $definition): array {
    $refs = [
      'entities' => [], 'users' => [], 'groups' => [], 'categories' => [], 'templates' => [], 'statuses' => [], 'types' => [],
    ];
    $entity_id = (int) ($definition['settings']['entityId'] ?? 0);
    if ($entity_id > 0) $refs['entities'][] = $entity_id;
    foreach ((array) ($definition['conditions'] ?? []) as $group) {
      foreach ((array) ($group['all'] ?? []) as $condition) {
        $field = (string) ($condition['field'] ?? '');
        $values = is_array($condition['value'] ?? null) ? $condition['value'] : [$condition['value'] ?? null];
        $kind = match ($field) {
          'category' => 'categories',
          'creator', 'requester' => 'users',
          'creator_group', 'requester_group' => 'groups',
          'status' => 'statuses',
          'type' => 'types',
          default => null,
        };
        if ($kind === null) continue;
        foreach ($values as $value) {
          $id = (int) $value;
          if ($id > 0) $refs[$kind][] = $id;
        }
      }
    }
    foreach ((array) ($definition['steps'] ?? []) as $step) {
      if (($step['kind'] ?? '') === 'approval') {
        $approval = (array) ($step['approval'] ?? []);
        if ((int) ($approval['templateId'] ?? 0) > 0) $refs['templates'][] = (int) $approval['templateId'];
        if ((int) ($approval['fallbackUserId'] ?? 0) > 0) $refs['users'][] = (int) $approval['fallbackUserId'];
        foreach ((array) ($approval['sources'] ?? []) as $source) {
          if (($source['source'] ?? '') === 'user' && (int) ($source['value'] ?? 0) > 0) $refs['users'][] = (int) $source['value'];
          if (($source['source'] ?? '') === 'group' && (int) ($source['value'] ?? 0) > 0) $refs['groups'][] = (int) $source['value'];
        }
      } elseif (($step['kind'] ?? '') === 'action') {
        $parameters = (array) (($step['action']['parameters'] ?? []) ?: []);
        foreach (['users_id' => 'users', 'groups_id' => 'groups', 'itilcategories_id' => 'categories', 'status' => 'statuses'] as $key => $kind) {
          if ((int) ($parameters[$key] ?? 0) > 0) $refs[$kind][] = (int) $parameters[$key];
        }
      }
    }
    $labels = [];
    foreach ($refs as $kind => $ids) {
      $ids = array_values(array_unique(array_filter($ids)));
      if (!$ids) continue;
      foreach (self::options($kind, '', $ids) as $item) {
        $labels[$kind][(string) $item['id']] = (string) $item['text'];
      }
    }
    return $labels;
  }

  private static function optionText(string $kind, string $class, array $row): string {
    $object = new $class();
    $object->fields = $row;
    $text = $object instanceof User ? $object->getFriendlyName() : $object->getName();
    if ($kind === 'categories' && method_exists($object, 'getCompleteName')) $text = $object->getCompleteName();
    return $text ?: ('#' . $row['id']);
  }

  private static function insertAction(int $version_id, int $step_id, string $event, string $name, array $action, int $order): void {
    global $DB;
    $DB->insert('glpi_plugin_escalation_actions', [
      'plugin_escalation_flowversions_id' => $version_id, 'plugin_escalation_steps_id' => $step_id,
      'name' => trim($name), 'event' => $event, 'type' => $action['type'],
      'parameters' => json_encode($action['parameters'] ?? [], JSON_UNESCAPED_UNICODE),
      'action_order' => $order, 'error_policy' => in_array($action['errorPolicy'] ?? '', ['continue','stop','retry'], true) ? $action['errorPolicy'] : 'retry',
      'max_attempts' => max(1, min(10, (int) ($action['maxAttempts'] ?? 3))),
    ]);
  }

  /** Drafts may be incomplete, but must remain safe and representable. */
  private static function validateDraftStructure(int $flow_id, array $definition): array {
    self::assertManage($flow_id);
    $errors = [];
    $settings = is_array($definition['settings'] ?? null) ? $definition['settings'] : [];
    if ((int) ($settings['entityId'] ?? 0) > 0 && !in_array((int) $settings['entityId'], self::activeEntities(), true)) {
      $errors[] = ['nodeKey' => 'start', 'message' => __('Escolha uma entidade que você tenha permissão para administrar.', 'escalation')];
    }
    foreach ((array) ($definition['conditions'] ?? []) as $index => $group) {
      $key = (string) ($group['key'] ?? 'condition-' . ($index + 1));
      foreach ((array) ($group['all'] ?? []) as $condition) {
        $field = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? '');
        if (!in_array($field, self::CONDITION_FIELDS, true) || !in_array($operator, self::CONDITION_OPERATORS, true)) {
          $errors[] = ['nodeKey' => $key, 'message' => __('Esta condição não é aceita pelo motor.', 'escalation')];
        }
        $value = $condition['value'] ?? null;
        if ($value !== null && $value !== '' && !self::validConditionReference($field, $value)) {
          $errors[] = ['nodeKey' => $key, 'message' => __('O item escolhido não existe mais ou não está disponível nesta entidade.', 'escalation')];
        }
      }
    }
    $steps = array_values((array) ($definition['steps'] ?? []));
    $order = [];
    foreach ($steps as $index => $step) {
      $key = trim((string) ($step['key'] ?? ''));
      if ($key === '' || isset($order[$key])) {
        $errors[] = ['nodeKey' => $key ?: 'start', 'message' => __('Há um bloco sem identificação ou repetido.', 'escalation')];
        continue;
      }
      $order[$key] = $index;
      $kind = (string) ($step['kind'] ?? '');
      if (!in_array($kind, ['approval','action'], true)) {
        $errors[] = ['nodeKey' => $key, 'message' => __('Este tipo de bloco não é aceito.', 'escalation')];
        continue;
      }
      if ($kind === 'approval') {
        foreach ((array) ($step['approval']['sources'] ?? []) as $source) {
          $source_type = (string) ($source['source'] ?? '');
          if (!in_array($source_type, self::APPROVER_SOURCES, true)) $errors[] = ['nodeKey' => $key, 'message' => __('Esta forma de escolher aprovadores não é válida.', 'escalation')];
          if ($source_type === 'user' && !self::referenceExists(User::class, (int) ($source['value'] ?? 0))) $errors[] = ['nodeKey' => $key, 'message' => __('A pessoa escolhida não está mais disponível.', 'escalation')];
          if ($source_type === 'group' && !self::referenceExists(Group::class, (int) ($source['value'] ?? 0), true)) $errors[] = ['nodeKey' => $key, 'message' => __('O grupo escolhido não está disponível nesta entidade.', 'escalation')];
        }
      } else {
        $type = (string) ($step['action']['type'] ?? '');
        if (!in_array($type, PluginEscalationActionExecutor::types(), true)) $errors[] = ['nodeKey' => $key, 'message' => __('Escolha uma ação suportada pelo GLPI.', 'escalation')];
      }
    }
    foreach ($steps as $index => $step) {
      $key = (string) ($step['key'] ?? '');
      foreach ((array) ($step['transitions'] ?? []) as $target) {
        if (str_starts_with((string) $target, 'terminal:')) {
          if (!in_array(substr((string) $target, 9), self::TERMINALS, true)) $errors[] = ['nodeKey' => $key, 'message' => __('O encerramento escolhido não é válido.', 'escalation')];
        } elseif (!isset($order[$target]) || $order[$target] <= $index) {
          $errors[] = ['nodeKey' => $key, 'message' => __('Este caminho precisa apontar para um bloco posterior.', 'escalation')];
        }
      }
    }
    foreach ((array) ($definition['conditions'] ?? []) as $index => $group) {
      $entry = (string) ($group['entry'] ?? '');
      if ($entry !== '' && !isset($order[$entry])) {
        $errors[] = ['nodeKey' => (string) ($group['key'] ?? 'condition-' . ($index + 1)), 'message' => __('Escolha uma etapa existente para este começo.', 'escalation')];
      }
    }
    return $errors;
  }

  private static function friendlyParameterError(string $type, string $fallback): string {
    return match ($type) {
      'assign_user' => __('Selecione o técnico que receberá o chamado.', 'escalation'),
      'assign_group' => __('Selecione o grupo que receberá o chamado.', 'escalation'),
      'change_status' => __('Escolha a nova situação do chamado.', 'escalation'),
      'change_category' => __('Escolha a nova categoria do chamado.', 'escalation'),
      'add_followup' => __('Escreva o conteúdo do acompanhamento.', 'escalation'),
      'create_task' => __('Escreva a descrição da tarefa.', 'escalation'),
      'notify' => __('Escolha o aviso que o GLPI deve enviar.', 'escalation'),
      'create_related_ticket' => __('Informe o título do chamado relacionado.', 'escalation'),
      default => $fallback,
    };
  }

  private static function activeEntities(): array {
    $entities = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [$_SESSION['glpiactive_entity'] ?? 0]));
    return $entities ?: [0];
  }

  private static function validConditionReference(string $field, mixed $value): bool {
    if (in_array($field, ['event','type','status'], true)) return true;
    $values = is_array($value) ? $value : [$value];
    $class = match ($field) {
      'category' => ITILCategory::class,
      'creator','requester' => User::class,
      'creator_group','requester_group' => Group::class,
      default => null,
    };
    if ($class === null) return true;
    foreach ($values as $id) if (!self::referenceExists($class, (int) $id, in_array($class, [Group::class, ITILCategory::class], true))) return false;
    return true;
  }

  private static function referenceExists(string $class, int $id, bool $check_entity = false): bool {
    if ($id <= 0 || !class_exists($class)) return false;
    $item = new $class();
    if (!$item->getFromDB($id)) return false;
    if (isset($item->fields['is_deleted']) && !empty($item->fields['is_deleted'])) return false;
    if (isset($item->fields['is_active']) && empty($item->fields['is_active'])) return false;
    return !$check_entity || !isset($item->fields['entities_id']) || in_array((int) $item->fields['entities_id'], self::activeEntities(), true);
  }

  private static function validActionReferences(string $type, array $parameters): bool {
    return match ($type) {
      'assign_user' => self::referenceExists(User::class, (int) ($parameters['users_id'] ?? 0)),
      'assign_group' => self::referenceExists(Group::class, (int) ($parameters['groups_id'] ?? 0), true),
      'change_category' => self::referenceExists(ITILCategory::class, (int) ($parameters['itilcategories_id'] ?? 0), true),
      'create_task' => empty($parameters['users_id']) || self::referenceExists(User::class, (int) $parameters['users_id']),
      'create_related_ticket' => empty($parameters['itilcategories_id']) || self::referenceExists(ITILCategory::class, (int) $parameters['itilcategories_id'], true),
      default => true,
    };
  }

  private static function assertManage(int $flow_id): void {
    if (!PluginEscalationProfile::have(PluginEscalationProfile::MANAGE)) throw new RuntimeException(__('Você não tem permissão para administrar fluxos.', 'escalation'));
    $flow = new PluginEscalationFlow();
    if ($flow_id <= 0 || !$flow->getFromDB($flow_id)) throw new RuntimeException(__('Fluxo não encontrado.', 'escalation'));
    $entity = (int) ($flow->fields['entities_id'] ?? 0);
    if ($entity > 0 && !in_array($entity, self::activeEntities(), true)) throw new RuntimeException(__('Este fluxo pertence a uma entidade que você não pode administrar.', 'escalation'));
  }
}
