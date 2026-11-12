<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/**
 * Acompanha o estado de um fluxo de aprovação em andamento para um chamado.
 * Implementa a aba "Aprovação" dentro da tela do chamado.
 */
class PluginEscalationTicketFlow extends CommonDBTM {
  static $rightname = 'config';

  /** Evita reentrada quando a própria automação cria a solução. */
  private static bool $solving = false;

  static function addDebugFollowup(int $ticket_id, string $content): void {
    if (isset($_SESSION['glpi_use_mode']) && $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
      $fup = new ITILFollowup();
      $fup->add(['itemtype' => 'Ticket', 'items_id' => $ticket_id, 'content' => $content, 'is_private' => 1]);
    }
  }

  private static function isGlpi10(): bool {
    return version_compare(GLPI_VERSION, '11', '<');
  }

  const STATUS_PENDING    = 0;
  const STATUS_APPROVED   = 1;
  const STATUS_REJECTED   = 2;
  const STATUS_TIMED_OUT  = 3;

  static function getTypeName($nb = 0) {
    return _n('Fluxo do Chamado', 'Fluxos do Chamado', $nb, 'escalation');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `plugin_escalation_flows_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `status` tinyint(1) NOT NULL DEFAULT '0',
        `current_step` int NOT NULL DEFAULT '1',
        `started_at` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `tickets_id` (`tickets_id`),
        KEY `status` (`status`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return PluginEscalationProfile::have(PluginEscalationProfile::VIEW); }
  static function canUpdate(): bool { return Session::haveRight(self::$rightname, UPDATE); }

  // -------------------------------------------------------------------------
  // Aba no chamado
  // -------------------------------------------------------------------------

  public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item->getType() === 'Ticket' && self::canAccessApprovalTab($item)) {
      $count = self::countActiveFlows($item->getID());
      return [1 => self::createTabEntry(__('Aprovação', 'escalation'), $count, null, 'ti ti-sitemap')];
    }
    return '';
  }

  public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item->getType() === 'Ticket' && $tabnum == 1 && self::canAccessApprovalTab($item)) {
      self::showApprovalTab($item);
    }
    return true;
  }

  /** Admin do plugin (com visão do chamado) ou aprovador pendente (inclui personificação). */
  static function canAccessApprovalTab(Ticket $ticket): bool {
    $users_id = (int) Session::getLoginUserID();
    if ($users_id > 0 && self::findPendingApproverStep($ticket->getID(), $users_id) !== null) {
      return true;
    }
    if ($users_id > 0 && self::findPendingManualAction($ticket->getID(), $users_id) !== null) {
      return true;
    }
    return self::canView() && $ticket->canViewItem();
  }

  /** @return array{ticketstep:array,step:array,flow:array}|null */
  static function findPendingManualAction(int $ticket_id, int $users_id): ?array {
    global $DB;
    if ($ticket_id <= 0 || $users_id <= 0 || !self::isAssignedTechnician($ticket_id, $users_id)) return null;
    $row = $DB->request([
      'SELECT' => [
        'ts.id AS ticketstep_id', 'ts.plugin_escalation_ticketflows_id',
        's.id AS step_id', 's.name AS step_name', 'tf.current_step',
      ],
      'FROM' => PluginEscalationTicketStep::getTable() . ' AS ts',
      'INNER JOIN' => [
        self::getTable() . ' AS tf' => ['ON' => ['ts' => 'plugin_escalation_ticketflows_id', 'tf' => 'id']],
        PluginEscalationStep::getTable() . ' AS s' => ['ON' => ['ts' => 'plugin_escalation_steps_id', 's' => 'id']],
      ],
      'WHERE' => [
        'tf.tickets_id' => $ticket_id,
        'tf.status' => self::STATUS_PENDING,
        'tf.state' => 'running',
        'ts.status' => PluginEscalationTicketStep::STATUS_PENDING,
        's.step_kind' => 'action',
        's.activation_mode' => 'manual',
      ],
      'LIMIT' => 1,
    ])->current();
    if (!$row) return null;
    return [
      'ticketstep' => ['id' => (int) $row['ticketstep_id']],
      'step' => ['id' => (int) $row['step_id'], 'name' => (string) $row['step_name']],
      'flow' => ['id' => (int) $row['plugin_escalation_ticketflows_id']],
    ];
  }

  private static function isAssignedTechnician(int $ticket_id, int $users_id): bool {
    global $DB;
    if (countElementsInTable('glpi_tickets_users', [
      'tickets_id' => $ticket_id, 'users_id' => $users_id, 'type' => CommonITILActor::ASSIGN,
    ]) > 0) return true;
    $groups = array_map('intval', array_column(iterator_to_array($DB->request([
      'SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $users_id],
    ])), 'groups_id'));
    return $groups && countElementsInTable('glpi_groups_tickets', [
      'tickets_id' => $ticket_id, 'groups_id' => $groups, 'type' => CommonITILActor::ASSIGN,
    ]) > 0;
  }

  static function triggerManualAction(int $ticketstep_id, int $ticket_id, int $users_id): bool {
    global $DB;
    $available = self::findPendingManualAction($ticket_id, $users_id);
    if (!$available || (int) $available['ticketstep']['id'] !== $ticketstep_id) return false;
    $queued = PluginEscalationActionExecutor::queueForEvent(
      (int) $available['flow']['id'], 'step_activated', (int) $available['step']['id']
    );
    if ($queued <= 0) return false;
    PluginEscalationAudit::record((int) $available['flow']['id'], $ticket_id, 'step.manual_triggered', [
      'ticket_step_id' => $ticketstep_id,
      'step_id' => (int) $available['step']['id'],
      'users_id' => $users_id,
    ]);
    PluginEscalationActionExecutor::processDueNow();
    return true;
  }

  /**
   * Injeta o botão Solucionar no pedido de aprovação nativo (ao lado de Aprovar/Recusar).
   * Hook PRE_ITEM_FORM chamado de dentro do formulário de validação do GLPI.
   */
  static function preValidationForm($params): void {
    // Mantido por compatibilidade; o botão visível ao lado de Aprovar/Recusar
    // é injetado por public/js/solve-button.js na tela do chamado.
  }

  /** @return array{ticketstep:array,step:array}|null */
  static function findPendingApproverStep(int $ticket_id, int $users_id, bool $require_allow_solve = false): ?array {
    global $DB;
    if ($ticket_id <= 0 || $users_id <= 0) return null;
    foreach ($DB->request([
      'SELECT' => [
        'ts.id AS ticketstep_id',
        'ts.status AS ticketstep_status',
        'ts.plugin_escalation_steps_id',
        's.name AS step_name',
        's.allow_solve',
        'tf.status AS flow_status',
      ],
      'FROM' => PluginEscalationTicketStep::getTable() . ' AS ts',
      'INNER JOIN' => [
        self::getTable() . ' AS tf' => ['ON' => ['ts' => 'plugin_escalation_ticketflows_id', 'tf' => 'id']],
        PluginEscalationStep::getTable() . ' AS s' => ['ON' => ['ts' => 'plugin_escalation_steps_id', 's' => 'id']],
        'glpi_plugin_escalation_ticketstepapprovers AS tsa' => ['ON' => ['ts' => 'id', 'tsa' => 'plugin_escalation_ticketsteps_id']],
      ],
      'WHERE' => [
        'tf.tickets_id' => $ticket_id,
        'tf.status' => self::STATUS_PENDING,
        'ts.status' => PluginEscalationTicketStep::STATUS_PENDING,
        'tsa.users_id' => $users_id,
      ],
      'LIMIT' => 1,
    ]) as $row) {
      if ($require_allow_solve && empty($row['allow_solve'])) continue;
      return [
        'ticketstep' => ['id' => (int) $row['ticketstep_id'], 'status' => (int) $row['ticketstep_status']],
        'step' => [
          'id' => (int) $row['plugin_escalation_steps_id'],
          'name' => (string) $row['step_name'],
          'allow_solve' => (int) $row['allow_solve'],
        ],
      ];
    }
    return null;
  }

  static function countActiveFlows(int $ticket_id): int {
    return countElementsInTable(self::getTable(), ['tickets_id' => $ticket_id]);
  }

  // -------------------------------------------------------------------------
  // Lógica do fluxo
  // -------------------------------------------------------------------------

  /**
   * Inicia um fluxo de aprovação para um chamado.
   * Chamado por PluginEscalationFlow::ticketAdd.
   */
  static function startFlow(Ticket $ticket, int $flow_id, int $version_id = 0, int $entry_steps_id = 0): void {
    global $DB;

    $ticket_id = $ticket->getID();

    // Verificar se já existe um fluxo ativo para este flow_id neste chamado
    if (countElementsInTable(self::getTable(), ['tickets_id' => $ticket_id, 'plugin_escalation_flows_id' => $flow_id]) > 0) return;

    // Verificar se o fluxo tem etapas
    $where = ['plugin_escalation_flows_id' => $flow_id];
    if ($version_id > 0) $where['plugin_escalation_flowversions_id'] = $version_id;
    $steps = $DB->request([
      'FROM'    => PluginEscalationStep::getTable(),
      'WHERE'   => $where,
      'ORDERBY' => ['step_order ASC'],
    ]);
    $steps_array = array_values(iterator_to_array($steps));
    if (empty($steps_array)) return;
    $entry_index = 0;
    if ($entry_steps_id > 0) {
      foreach ($steps_array as $index => $step_data) {
        if ((int) $step_data['id'] === $entry_steps_id) { $entry_index = $index; break; }
      }
    }

    // Atomic guard: concurrent create/update hooks cannot start the same definition twice.
    $DB->doQuery(sprintf(
      "INSERT IGNORE INTO `glpi_plugin_escalation_executionlocks` (`plugin_escalation_flows_id`,`tickets_id`,`date_creation`) VALUES (%d,%d,NOW())",
      $flow_id,
      $ticket_id
    ));
    if (method_exists($DB, 'affectedRows') && $DB->affectedRows() === 0) return;

    // Criar registro do fluxo no chamado
    $tf = new self();
    $tf_id = $tf->add([
      'tickets_id'                  => $ticket_id,
      'plugin_escalation_flows_id'  => $flow_id,
      'status'                      => self::STATUS_PENDING,
      'current_step'                => (int) ($steps_array[$entry_index]['step_order'] ?? ($entry_index + 1)),
      'started_at'                  => $_SESSION['glpi_currenttime'],
      'engine_version'              => $version_id > 0 ? 2 : 1,
      'plugin_escalation_flowversions_id' => $version_id,
      'state'                       => 'running',
      'decision_status'             => 'pending',
      'automation_status'           => 'idle',
    ]);
    if (!$tf_id) {
      $DB->delete('glpi_plugin_escalation_executionlocks', ['plugin_escalation_flows_id' => $flow_id, 'tickets_id' => $ticket_id]);
      return;
    }

    // Criar registros de ticket_step para todas as etapas (aguardando)
    $entry_id = (int) $steps_array[$entry_index]['id'];
    $reached = self::stepsReachedFrom($entry_id, $steps_array);
    foreach ($steps_array as $i => $step_data) {
      $skipped = !isset($reached[(int) $step_data['id']]);
      $ts = new PluginEscalationTicketStep();
      $ts->add([
        'tickets_id'                      => $ticket_id,
        'plugin_escalation_ticketflows_id' => $tf_id,
        'plugin_escalation_steps_id'      => $step_data['id'],
        'status'                          => $skipped
          ? PluginEscalationTicketStep::STATUS_SKIPPED
          : ($i === $entry_index ? PluginEscalationTicketStep::STATUS_PENDING : PluginEscalationTicketStep::STATUS_WAITING),
        'outcome'                         => $skipped ? 'skipped' : null,
        'started_at'                      => $i === $entry_index ? $_SESSION['glpi_currenttime'] : null,
        'completed_at'                    => $skipped ? $_SESSION['glpi_currenttime'] : null,
      ]);
    }

    // Ativar a etapa de entrada deste começo. Etapas anteriores ficam fora do caminho.
    $first_step_data = $steps_array[$entry_index];
    $first_ts = self::getTicketStepByOrder($tf_id, (int) ($first_step_data['step_order'] ?? ($entry_index + 1)));
    if ($first_ts) {
      self::activateStep($first_ts['id'], $ticket, $first_step_data);
    }
    PluginEscalationAudit::record((int) $tf_id, $ticket_id, 'workflow.started', ['flow_id' => $flow_id, 'version_id' => $version_id]);
    if (PluginEscalationActionExecutor::queueForEvent((int) $tf_id, 'flow_started') > 0) {
      PluginEscalationActionExecutor::processDueNow();
    }
  }

  /**
   * Avança para a próxima etapa ou conclui o fluxo como aprovado.
   * Chamado por PluginEscalationApproval::submitApproval quando uma etapa é aprovada.
   */
  static function advanceFlow(int $ticket_flow_id): void {
    global $DB;

    $tf = new self();
    if (!$tf->getFromDB($ticket_flow_id) || (int) $tf->fields['status'] !== self::STATUS_PENDING) return;

    $next_step = $tf->fields['current_step'] + 1;

    // Buscar próxima etapa de ticket_step (WAITING → PENDING)
    $next_ts = self::getTicketStepByOrder($ticket_flow_id, $next_step);

    if (!$next_ts) {
      // Todas as etapas concluídas: fluxo aprovado
      self::completeFlow($ticket_flow_id, self::STATUS_APPROVED);
      return;
    }

    // Atualizar current_step
    $tf->update(['id' => $ticket_flow_id, 'current_step' => $next_step]);

    // Ativar próxima etapa
    $step_def = new PluginEscalationStep();
    $step_def->getFromDB($next_ts['plugin_escalation_steps_id']);
    $ticket = new Ticket();
    $ticket->getFromDB($tf->fields['tickets_id']);
    self::activateStep($next_ts['id'], $ticket, $step_def->fields);
  }

  /**
   * Rejeita o fluxo inteiro.
   * Chamado por PluginEscalationApproval::submitApproval quando uma etapa é rejeitada.
   */
  static function rejectFlow(int $ticket_flow_id): void {
    self::completeFlow($ticket_flow_id, self::STATUS_REJECTED);
  }

  static function completeFlow(int $ticket_flow_id, int $final_status): void {
    global $DB;

    $tf = new self();
    if (!$tf->getFromDB($ticket_flow_id)) return;

    if ((int) $tf->fields['status'] !== self::STATUS_PENDING) return;
    $decision = $final_status === self::STATUS_APPROVED ? 'approved' : ($final_status === self::STATUS_TIMED_OUT ? 'expired' : 'rejected');
    $tf->update(['id' => $ticket_flow_id, 'status' => $final_status, 'state' => 'completed', 'decision_status' => $decision, 'completed_at' => date('Y-m-d H:i:s')]);
    $DB->update(PluginEscalationTicketStep::getTable(), [
      'status' => PluginEscalationTicketStep::STATUS_SKIPPED,
      'outcome' => 'skipped',
      'completed_at' => date('Y-m-d H:i:s'),
    ], [
      'plugin_escalation_ticketflows_id' => $ticket_flow_id,
      'status' => PluginEscalationTicketStep::STATUS_WAITING,
    ]);

    $ticket = new Ticket();
    if (!$ticket->getFromDB($tf->fields['tickets_id'])) return;

    $flow = new PluginEscalationFlow();
    $flow_name = $flow->getFromDB($tf->fields['plugin_escalation_flows_id'])
      ? $flow->fields['name']
      : '#' . $tf->fields['plugin_escalation_flows_id'];

    switch ($final_status) {
      case self::STATUS_APPROVED:
        $msg = sprintf(__('✅ Fluxo de aprovação "%s" concluído. Chamado aprovado.', 'escalation'), $flow_name);
        break;
      case self::STATUS_TIMED_OUT:
        $msg = sprintf(__('⏰ Fluxo de aprovação "%s" atingiu o prazo de reprovação automática. Chamado reprovado automaticamente.', 'escalation'), $flow_name);
        break;
      default:
        $msg = sprintf(__('❌ Fluxo de aprovação "%s" concluído. Chamado reprovado.', 'escalation'), $flow_name);
    }

    $fup = new ITILFollowup();
    $fup->add(['itemtype' => 'Ticket', 'items_id' => $tf->fields['tickets_id'], 'content' => $msg, 'is_private' => 0]);
    PluginEscalationAudit::record($ticket_flow_id, (int) $tf->fields['tickets_id'], 'workflow.' . $decision);
    if (PluginEscalationActionExecutor::queueForEvent($ticket_flow_id, 'flow_' . $decision) > 0) {
      PluginEscalationActionExecutor::processDueNow();
    }
  }

  // -------------------------------------------------------------------------
  // Helpers internos
  // -------------------------------------------------------------------------

  /** Etapas alcançáveis a partir da entrada. Os outros começos ficam de fora. */
  private static function stepsReachedFrom(int $entry_id, array $steps_array): array {
    global $DB;
    $by_id = [];
    $by_order = [];
    foreach ($steps_array as $step) {
      $id = (int) $step['id'];
      $by_id[$id] = $step;
      $by_order[(int) $step['step_order']] = $id;
    }
    if (!isset($by_id[$entry_id])) $entry_id = (int) ($steps_array[0]['id'] ?? 0);
    $transitions = [];
    if ($by_id) {
      foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_transitions', 'WHERE' => ['plugin_escalation_steps_id' => array_keys($by_id)]]) as $row) {
        $transitions[(int) $row['plugin_escalation_steps_id']][] = $row;
      }
    }
    $seen = [];
    $stack = $entry_id > 0 ? [$entry_id] : [];
    while ($stack) {
      $id = array_pop($stack);
      if (isset($seen[$id]) || !isset($by_id[$id])) continue;
      $seen[$id] = true;
      $followed = false;
      foreach ($transitions[$id] ?? [] as $out) {
        $target = (int) ($out['target_steps_id'] ?? 0);
        if ($target > 0) { $stack[] = $target; $followed = true; }
      }
      if (!$followed) {
        $next = $by_order[((int) $by_id[$id]['step_order']) + 1] ?? 0;
        if ($next > 0) $stack[] = $next;
      }
    }
    return $seen;
  }

  static function getTicketStepByOrder(int $ticket_flow_id, int $order): ?array {
    global $DB;

    // Busca o ticket_step correspondente à N-ésima etapa (em step_order)
    $row = $DB->request([
      'SELECT' => ['ts.*'],
      'FROM'   => PluginEscalationTicketStep::getTable() . ' AS ts',
      'INNER JOIN' => [
        PluginEscalationStep::getTable() . ' AS s' => [
          'ON' => ['ts' => 'plugin_escalation_steps_id', 's' => 'id']
        ]
      ],
      'WHERE'   => ['ts.plugin_escalation_ticketflows_id' => $ticket_flow_id, 's.step_order' => $order],
      'LIMIT'   => 1,
    ])->current();

    return $row ?: null;
  }

  static function activateStep(int $ticketstep_id, Ticket $ticket, array $step_data): void {
    global $DB;

    $DB->update(PluginEscalationTicketStep::getTable(), [
      'status'     => PluginEscalationTicketStep::STATUS_PENDING,
      'started_at' => $_SESSION['glpi_currenttime'],
    ], ['id' => $ticketstep_id]);

    if (($step_data['step_kind'] ?? 'approval') === 'action') {
      $ts = new PluginEscalationTicketStep();
      if ($ts->getFromDB($ticketstep_id)) {
        if (($step_data['activation_mode'] ?? 'automatic') === 'manual') {
          PluginEscalationAudit::record((int) $ts->fields['plugin_escalation_ticketflows_id'], $ticket->getID(), 'step.manual_available', [
            'ticket_step_id' => $ticketstep_id,
            'step_id' => (int) $step_data['id'],
          ]);
          return;
        }
        $queued = PluginEscalationActionExecutor::queueForEvent((int) $ts->fields['plugin_escalation_ticketflows_id'], 'step_activated', (int) $step_data['id']);
        if ($queued === 0) {
          $DB->update(PluginEscalationTicketStep::getTable(), ['status' => PluginEscalationTicketStep::STATUS_APPROVED, 'outcome' => 'success', 'completed_at' => date('Y-m-d H:i:s')], ['id' => $ticketstep_id]);
          PluginEscalationWorkflow::transition($ticketstep_id, 'success');
        } else {
          // Atribuição/ações devem ocorrer na hora da ativação (ex.: após última aprovação),
          // sem depender do cron interno que pode ficar horas sem rodar.
          PluginEscalationActionExecutor::processDueNow();
        }
      }
      return;
    }

    if (empty($step_data['generate_immediately'])) {
      self::addDebugFollowup($ticket->getID(),
        sprintf(__('⏳ Etapa "%s" aguardando submissão de solução do chamado.', 'escalation'), $step_data['name'] ?? '')
      );
      return;
    }

    self::generateValidationsForStep($ticketstep_id, $ticket, $step_data);
  }

  /**
   * Resolve aprovadores e cria TicketValidation para a etapa.
   * Usado tanto em ativação imediata quanto após submissão de solução.
   */
  static function generateValidationsForStep(int $ticketstep_id, Ticket $ticket, array $step_data): void {
    global $DB;

    $step_name = $step_data['name'] ?? '';
    $step_type = $step_data['type'] ?? PluginEscalationStep::TYPE_PEOPLE;
    $approvers = [];
    $skip      = false;

    if ($step_type === PluginEscalationStep::TYPE_HIERARCHY) {
      if (!empty($step_data['auto_hierarchy_dynamic'])) {
        // Dinâmico: parte do requerente, sobe via users_id_supervisor
        $requesters = self::getTicketActors($ticket->getID(), CommonITILActor::REQUESTER);
        foreach ($requesters as $req_id) {
          $req_level = PluginEscalationHierarchyLevel::getUserLevel((int) $req_id);
          if ($req_level !== null && $req_level > 1) {
            $found = self::traverseHierarchy((int) $req_id, $req_level - 1);
            if ($found !== null && $found !== (int) $req_id) {
              $approvers[] = $found;
            }
          }
        }
          if (empty($approvers)) $skip = true;
      } else {
        // Nível fixo: parte do requerente e sobe via supervisor até o nível alvo
        $target_level = (int) ($step_data['hierarchy_level'] ?? 1);
        $requesters   = self::getTicketActors($ticket->getID(), CommonITILActor::REQUESTER);
        foreach ($requesters as $req_id) {
          $found = self::traverseHierarchy((int) $req_id, $target_level);
          if ($found !== null) {
            $approvers[] = $found;
          }
        }
        $approvers = array_values(array_unique(array_filter($approvers)));
        if (empty($approvers)) $skip = true;
      }
    } else {
      // PEOPLE: fontes configuradas (usuários específicos, solicitantes, responsáveis, observadores)
      $approvers = self::resolveApprovers($step_data, $ticket);
    }
    $DB->update(PluginEscalationTicketStep::getTable(), [
      'approver_snapshot' => json_encode(array_values($approvers), JSON_UNESCAPED_UNICODE),
    ], ['id' => $ticketstep_id]);

    // Criar TicketValidation e anexar documentos do modelo
    if (!$skip && !empty($approvers)) {
      $submission_content = trim($step_data['content'] ?? '');
      $tmpl_doc_ids       = [];
      $tmpl_id = (int) ($step_data['plugin_escalation_templates_id'] ?? 0);
      if ($tmpl_id > 0) {
        $tmpl = new PluginEscalationTemplate();
        if ($tmpl->getFromDB($tmpl_id)) {
          // Template tem prioridade no conteúdo caso o step não tenha texto próprio
          if (empty($submission_content)) {
            $submission_content = trim($tmpl->fields['content'] ?? '');
          }
          $tmpl_doc_ids = PluginEscalationTemplate::getDocuments($tmpl_id);
        }
      }
      if (!empty($step_data['allow_solve'])) {
        $hint = __('Se puder resolver sozinho, use Solucionar na aba Aprovação deste chamado.', 'escalation');
        $submission_content = trim($submission_content) === ''
          ? $hint
          : rtrim($submission_content) . "\n\n" . $hint;
      }
      $glpi10 = self::isGlpi10();
      foreach ($approvers as $user_id) {
        $validation = new TicketValidation();
        $val_data = ['tickets_id' => $ticket->getID(), 'comment_submission' => $submission_content];
        if ($glpi10) {
          $val_data['users_id_validate'] = (int) $user_id;
        } else {
          $val_data['itemtype_target'] = 'User';
          $val_data['items_id_target'] = (int) $user_id;
        }
        $validation_id = $validation->add($val_data);
        if (!$validation_id) continue;
        // Registra o aprovador na etapa para controle de quórum
        $DB->doQuery(sprintf(
          "INSERT IGNORE INTO `glpi_plugin_escalation_ticketstepapprovers`
           (`plugin_escalation_ticketsteps_id`, `users_id`) VALUES (%d, %d)",
          (int) $ticketstep_id, (int) $user_id
        ));
        $DB->doQuery(sprintf(
          "INSERT IGNORE INTO `glpi_plugin_escalation_validationlinks` (`plugin_escalation_ticketsteps_id`,`ticketvalidations_id`,`users_id`) VALUES (%d,%d,%d)",
          (int) $ticketstep_id, (int) $validation_id, (int) $user_id
        ));
      }
      foreach ($tmpl_doc_ids as $doc_id) {
        $doc_item = new Document_Item();
        $doc_item->add([
          'itemtype'         => 'Ticket',
          'items_id'         => $ticket->getID(),
          'documents_id'     => (int) $doc_id,
          '_no_message_link' => true,
        ]);
      }
    }

    // Sem aprovadores: segue a transição "approver_missing" se existir (ex.: pular no fluxograma);
    // caso contrário pausa em pending_configuration para o admin corrigir.
    if ($skip || empty($approvers)) {
      $ts = new PluginEscalationTicketStep();
      $ts->getFromDB($ticketstep_id);
      $transition = $DB->request([
        'FROM' => 'glpi_plugin_escalation_transitions',
        'WHERE' => [
          'plugin_escalation_steps_id' => (int) $ts->fields['plugin_escalation_steps_id'],
          'outcome' => 'approver_missing',
        ],
        'LIMIT' => 1,
      ])->current();
      $can_skip = $transition
        && (
          (int) ($transition['target_steps_id'] ?? 0) > 0
          || (!empty($transition['terminal_state']) && $transition['terminal_state'] !== 'pending_configuration')
        );
      $DB->update(PluginEscalationTicketStep::getTable(), [
        'status' => $can_skip ? PluginEscalationTicketStep::STATUS_APPROVED : PluginEscalationTicketStep::STATUS_PENDING,
        'outcome' => 'approver_missing',
        'completed_at' => $can_skip ? date('Y-m-d H:i:s') : null,
      ], ['id' => $ticketstep_id]);
      if (!$can_skip) {
        $DB->update(self::getTable(), ['state' => 'pending_configuration'], ['id' => $ts->fields['plugin_escalation_ticketflows_id']]);
      }
      PluginEscalationAudit::record((int) $ts->fields['plugin_escalation_ticketflows_id'], $ticket->getID(), 'step.approver_missing', [
        'step_id' => $step_data['id'],
        'name' => $step_name,
        'skipped' => $can_skip,
      ]);
      PluginEscalationWorkflow::transition($ticketstep_id, 'approver_missing');
    } else {
      if (isset($_SESSION['glpi_use_mode']) && $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
        $user_names = [];
        foreach ($approvers as $uid) {
          $u = new User();
          if ($u->getFromDB($uid)) $user_names[] = $u->getFriendlyName();
        }
        self::addDebugFollowup($ticket->getID(), sprintf(
          __('⚡ Etapa "%s": aprovação gerada. Aguardando validação de: %s.', 'escalation'),
          $step_name, implode(', ', $user_names)
        ));
      }
    }
  }

  /**
   * Chamado quando o chamado vai para solução (status SOLVED).
   * Se a etapa atual permite solucionar, encerra o fluxo.
   * Caso contrário, ativa etapas com generate_immediately = 0 que estão aguardando.
   */
  static function ticketSolved(Ticket $ticket): void {
    global $DB;

    if (self::$solving) return;
    $users_id = (int) Session::getLoginUserID();
    if ($users_id > 0 && self::solveFromApprover($ticket->getID(), $users_id, '', false)) return;

    $tf_row = $DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['tickets_id' => $ticket->getID(), 'status' => self::STATUS_PENDING],
      'LIMIT' => 1,
    ])->current();
    if (!$tf_row) return;

    $current_ts = self::getTicketStepByOrder($tf_row['id'], $tf_row['current_step']);
    if (!$current_ts || (int) $current_ts['status'] !== PluginEscalationTicketStep::STATUS_PENDING) return;

    $step_def = new PluginEscalationStep();
    if (!$step_def->getFromDB($current_ts['plugin_escalation_steps_id'])) return;

    // Uma atividade opcional não utilizada não bloqueia a conclusão normal do chamado.
    if (($step_def->fields['step_kind'] ?? '') === 'action' && ($step_def->fields['activation_mode'] ?? 'automatic') === 'manual') {
      $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
      $DB->update(PluginEscalationTicketStep::getTable(), [
        'status' => PluginEscalationTicketStep::STATUS_SKIPPED,
        'outcome' => 'skipped',
        'completed_at' => $now,
      ], ['id' => (int) $current_ts['id'], 'status' => PluginEscalationTicketStep::STATUS_PENDING]);
      PluginEscalationAudit::record((int) $tf_row['id'], $ticket->getID(), 'step.manual_skipped', [
        'ticket_step_id' => (int) $current_ts['id'],
        'step_id' => (int) $current_ts['plugin_escalation_steps_id'],
      ]);
      self::completeFlow((int) $tf_row['id'], self::STATUS_APPROVED);
      return;
    }

    // Só age se este passo estava aguardando solução (generate_immediately = 0)
    if (!empty($step_def->fields['generate_immediately'])) return;

    self::generateValidationsForStep($current_ts['id'], $ticket, $step_def->fields);
  }

  /** Atalho: solução nativa do GLPI na etapa com “Permitir solucionar”. */
  static function solutionAdded(CommonDBTM $item): void {
    if (self::$solving || !$item instanceof ITILSolution) return;
    if (($item->fields['itemtype'] ?? '') !== Ticket::class && ($item->fields['itemtype'] ?? '') !== 'Ticket') return;
    $users_id = (int) Session::getLoginUserID();
    if ($users_id <= 0) return;
    $comment = trim(strip_tags((string) ($item->fields['content'] ?? '')));
    self::solveFromApprover((int) $item->fields['items_id'], $users_id, $comment, false);
  }

  /**
   * Aprovador escolhe Solucionar: atribui a si, soluciona e encerra o fluxo.
   * @param bool $create_solution false quando a solução já foi criada pelo GLPI
   */
  static function solveFromApprover(int $ticket_id, int $users_id, string $comment = '', bool $create_solution = true): bool {
    global $DB;

    if ($ticket_id <= 0 || $users_id <= 0 || self::$solving) return false;
    $tf_row = $DB->request([
      'FROM' => self::getTable(),
      'WHERE' => ['tickets_id' => $ticket_id, 'status' => self::STATUS_PENDING],
      'LIMIT' => 1,
    ])->current();
    if (!$tf_row) return false;

    $current_ts = $DB->request([
      'FROM' => PluginEscalationTicketStep::getTable(),
      'WHERE' => [
        'plugin_escalation_ticketflows_id' => $tf_row['id'],
        'status' => PluginEscalationTicketStep::STATUS_PENDING,
      ],
      'LIMIT' => 1,
    ])->current();
    if (!$current_ts) return false;

    $step_def = new PluginEscalationStep();
    if (!$step_def->getFromDB((int) $current_ts['plugin_escalation_steps_id'])) return false;
    if (empty($step_def->fields['allow_solve'])) return false;
    if (!PluginEscalationApproval::isAuthorizedApprover((int) $current_ts['id'], $users_id)) return false;

    $comment = trim($comment);
    if ($create_solution && $comment === '') return false;

    self::$solving = true;
    try {
      $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
      $updated = $DB->update(PluginEscalationTicketStep::getTable(), [
        'status' => PluginEscalationTicketStep::STATUS_APPROVED,
        'outcome' => 'solved',
        'completed_at' => $now,
      ], [
        'id' => (int) $current_ts['id'],
        'status' => PluginEscalationTicketStep::STATUS_PENDING,
      ]);
      if (method_exists($DB, 'affectedRows') && $DB->affectedRows() === 0 && !$updated) return false;

      $approval = new PluginEscalationApproval();
      $approval->add([
        'plugin_escalation_ticketsteps_id' => (int) $current_ts['id'],
        'users_id' => $users_id,
        'action' => PluginEscalationApproval::ACTION_SOLVE,
        'comment' => Toolbox::cleanNewLines($comment),
        'date_creation' => $now,
      ]);

      foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_validationlinks', 'WHERE' => ['plugin_escalation_ticketsteps_id' => (int) $current_ts['id']]]) as $link) {
        $validation = new TicketValidation();
        if (!$validation->getFromDB((int) $link['ticketvalidations_id'])) continue;
        if ((int) $validation->fields['status'] !== CommonITILValidation::WAITING) continue;
        $validation->update([
          'id' => (int) $validation->getID(),
          'status' => CommonITILValidation::ACCEPTED,
          'comment_validation' => $comment !== '' ? $comment : __('Solucionou o chamado', 'escalation'),
          '_disablenotif' => true,
        ]);
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticket_id)) return false;

      $already_assigned = in_array($users_id, array_map('intval', self::getTicketActors($ticket_id, CommonITILActor::ASSIGN)), true);
      if (!$already_assigned) {
        $relation = new Ticket_User();
        $relation->add([
          'tickets_id' => $ticket_id,
          'users_id' => $users_id,
          'type' => CommonITILActor::ASSIGN,
          '_escalation_action' => 1,
          '_disablenotif' => true,
        ]);
      }

      if ($create_solution) {
        $solution = new ITILSolution();
        $solution->add([
          'itemtype' => Ticket::class,
          'items_id' => $ticket_id,
          'content' => $comment,
          '_escalation_action' => 1,
          '_disablenotif' => true,
        ]);
      } elseif ((int) $ticket->fields['status'] !== Ticket::SOLVED && (int) $ticket->fields['status'] !== Ticket::CLOSED) {
        $ticket->update([
          'id' => $ticket_id,
          'status' => Ticket::SOLVED,
          '_escalation_action' => 1,
          '_disablenotif' => true,
        ]);
      }

      PluginEscalationAudit::record((int) $tf_row['id'], $ticket_id, 'step.solved', [
        'step_id' => (int) $current_ts['plugin_escalation_steps_id'],
        'users_id' => $users_id,
      ]);
      PluginEscalationWorkflow::transition((int) $current_ts['id'], 'solved');
      return true;
    } finally {
      self::$solving = false;
    }
  }

  /**
   * Chamado via hook item_update quando um TicketValidation é atualizado.
   * Se o chamado tem um fluxo ativo cuja etapa atual é AUTO, avança ou rejeita conforme o resultado.
   */
  static function validationUpdated(CommonDBTM $item): void {
    global $DB;

    if (!$item instanceof TicketValidation) return;

    $status    = (int) $item->fields['status'];
    $accepted  = CommonITILValidation::ACCEPTED;
    $refused   = CommonITILValidation::REFUSED;
    $waiting   = CommonITILValidation::WAITING;
    if (!in_array($status, [$accepted, $refused])) return;

    $link = $DB->request(['FROM' => 'glpi_plugin_escalation_validationlinks', 'WHERE' => ['ticketvalidations_id' => $item->getID()], 'LIMIT' => 1])->current();
    if (!$link) return; // validation outside this engine
    $current_ts = $DB->request(['FROM' => PluginEscalationTicketStep::getTable(), 'WHERE' => ['id' => $link['plugin_escalation_ticketsteps_id']], 'LIMIT' => 1])->current();
    if (!$current_ts || (int) $current_ts['status'] !== PluginEscalationTicketStep::STATUS_PENDING) return;
    $step_def = new PluginEscalationStep();
    if (!$step_def->getFromDB($current_ts['plugin_escalation_steps_id'])) return;
    $links = iterator_to_array($DB->request(['SELECT' => ['l.ticketvalidations_id'], 'FROM' => 'glpi_plugin_escalation_validationlinks AS l', 'WHERE' => ['l.plugin_escalation_ticketsteps_id' => $current_ts['id']]]));
    $ids = array_column($links, 'ticketvalidations_id');
    $total = count($ids);
    $approvals = countElementsInTable(TicketValidation::getTable(), ['id' => $ids, 'status' => $accepted]);
    $rejections = countElementsInTable(TicketValidation::getTable(), ['id' => $ids, 'status' => $refused]);
    $result = PluginEscalationApproval::evaluateQuorum((string) $step_def->fields['quorum_type'], $total, $approvals, $rejections);
    if ($result === 'pending') return;
    $approved = $result === 'approved';
    $DB->update(PluginEscalationTicketStep::getTable(), ['status' => $approved ? PluginEscalationTicketStep::STATUS_APPROVED : PluginEscalationTicketStep::STATUS_REJECTED, 'outcome' => $approved ? 'approved' : 'rejected', 'completed_at' => date('Y-m-d H:i:s')], ['id' => $current_ts['id'], 'status' => PluginEscalationTicketStep::STATUS_PENDING]);
    if (method_exists($DB, 'affectedRows') && $DB->affectedRows() === 0) return;
    PluginEscalationAudit::record((int) $current_ts['plugin_escalation_ticketflows_id'], (int) $item->fields['tickets_id'], 'step.' . ($approved ? 'approved' : 'rejected'), ['step_id' => $current_ts['plugin_escalation_steps_id'], 'approvals' => $approvals, 'rejections' => $rejections, 'total' => $total]);
    if (PluginEscalationActionExecutor::queueForEvent((int) $current_ts['plugin_escalation_ticketflows_id'], 'step_' . ($approved ? 'approved' : 'rejected'), (int) $current_ts['plugin_escalation_steps_id']) > 0) {
      PluginEscalationActionExecutor::processDueNow();
    }
    PluginEscalationWorkflow::transition((int) $current_ts['id'], $approved ? 'approved' : 'rejected');
  }

  static function resolveApprovers(array $step_data, Ticket $ticket): array {
    global $DB;

    $user_ids = [];

    // Usuários específicos da definição da etapa
    $specific = PluginEscalationStepUser::getUsersForStep($step_data['id']);
    foreach ($specific as $su) {
      $user_ids[] = (int) $su['users_id'];
    }

    $ticket_id = $ticket->getID();

    // Fontes 2.x. A lista final é um snapshot imutável desta ativação.
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_stepapprovers', 'WHERE' => ['plugin_escalation_steps_id' => $step_data['id']]]) as $source) {
      switch ($source['source_type']) {
        case 'user': $user_ids[] = (int) $source['items_id']; break;
        case 'group':
          foreach (TicketValidation::getGroupUserHaveRights([
            'groups_id' => (int) $source['items_id'],
            'entity' => (int) $ticket->fields['entities_id'],
          ]) as $member) {
            $user_ids[] = (int) ($member['users_id'] ?? $member['id'] ?? 0);
          }
          break;
        case 'supervisor':
          foreach (self::getTicketActors($ticket_id, CommonITILActor::REQUESTER) as $requester) {
            $user = new User(); if ($user->getFromDB($requester) && (int) ($user->fields['users_id_supervisor'] ?? 0) > 0) $user_ids[] = (int) $user->fields['users_id_supervisor'];
          }
          break;
        case 'requester': $user_ids = array_merge($user_ids, self::getTicketActors($ticket_id, CommonITILActor::REQUESTER)); break;
        case 'assigned': $user_ids = array_merge($user_ids, self::getTicketActors($ticket_id, CommonITILActor::ASSIGN)); break;
        case 'observer': $user_ids = array_merge($user_ids, self::getTicketActors($ticket_id, CommonITILActor::OBSERVER)); break;
      }
    }

    // Solicitantes (type = 1)
    if (!empty($step_data['include_requesters'])) {
      foreach (self::getTicketActors($ticket_id, CommonITILActor::REQUESTER) as $uid) {
        $user_ids[] = $uid;
      }
    }

    // Responsáveis (type = 2)
    if (!empty($step_data['include_assigned'])) {
      foreach (self::getTicketActors($ticket_id, CommonITILActor::ASSIGN) as $uid) {
        $user_ids[] = $uid;
      }
    }

    // Observadores (type = 3)
    if (!empty($step_data['include_observers'])) {
      foreach (self::getTicketActors($ticket_id, CommonITILActor::OBSERVER) as $uid) {
        $user_ids[] = $uid;
      }
    }

    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    $user_ids = array_values(array_filter($user_ids, static fn(int $user_id): bool => self::canUserValidate($user_id, $ticket)));
    if (!empty($step_data['prevent_self_approval'])) {
      $requesters = array_map('intval', self::getTicketActors($ticket_id, CommonITILActor::REQUESTER));
      $user_ids = array_values(array_diff($user_ids, $requesters));
    }
    $fallback = (int) ($step_data['fallback_users_id'] ?? 0);
    if (!$user_ids && $fallback > 0 && self::canUserValidate($fallback, $ticket)) $user_ids[] = $fallback;
    return $user_ids;
  }

  private static function canUserValidate(int $user_id, Ticket $ticket): bool {
    $user = new User();
    if ($user_id <= 0 || !$user->getFromDB($user_id) || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) return false;
    $right = (int) $ticket->fields['type'] === Ticket::INCIDENT_TYPE
      ? TicketValidation::VALIDATEINCIDENT
      : TicketValidation::VALIDATEREQUEST;
    return Profile::haveUserRight($user_id, TicketValidation::$rightname, $right, (int) $ticket->fields['entities_id']);
  }

  static function activateStepByDefinition(int $ticket_flow_id, int $step_id): void {
    global $DB;
    $tf = new self(); if (!$tf->getFromDB($ticket_flow_id) || (int) $tf->fields['status'] !== self::STATUS_PENDING) return;
    $step = new PluginEscalationStep(); if (!$step->getFromDB($step_id)) return;
    $ts = $DB->request(['FROM' => PluginEscalationTicketStep::getTable(), 'WHERE' => ['plugin_escalation_ticketflows_id' => $ticket_flow_id, 'plugin_escalation_steps_id' => $step_id], 'LIMIT' => 1])->current();
    if (!$ts) return;
    $tf->update(['id' => $ticket_flow_id, 'current_step' => (int) $step->fields['step_order'], 'state' => 'running']);
    $ticket = new Ticket(); if ($ticket->getFromDB($tf->fields['tickets_id'])) self::activateStep((int) $ts['id'], $ticket, $step->fields);
  }

  static function getTicketActors(int $ticket_id, int $actor_type): array {
    global $DB;

    $rows = $DB->request([
      'SELECT' => ['users_id'],
      'FROM'   => 'glpi_tickets_users',
      'WHERE'  => ['tickets_id' => $ticket_id, 'type' => $actor_type, ['users_id' => ['>', 0]]],
    ]);
    return array_column(iterator_to_array($rows), 'users_id');
  }

  // -------------------------------------------------------------------------
  // Resolução de aprovadores por hierarquia
  // -------------------------------------------------------------------------

  /**
   * Sobe a cadeia de supervisores a partir de $user_id até encontrar
   * alguém com nível hierárquico <= $required_level.
   *
   * Retorna o users_id do aprovador encontrado, ou null se não houver.
   */
  static function traverseHierarchy(int $user_id, int $required_level, int $depth = 0): ?int {
    if ($depth > 20 || $user_id <= 0) return null;

    $level = PluginEscalationHierarchyLevel::getUserLevel($user_id);
    if ($level !== null && $level <= $required_level) {
      return $user_id;
    }

    $user = new User();
    if (!$user->getFromDB($user_id)) return null;

    $supervisor_id = (int) ($user->fields['users_id_supervisor'] ?? 0);
    if ($supervisor_id <= 0 || $supervisor_id === $user_id) return null;

    return self::traverseHierarchy($supervisor_id, $required_level, $depth + 1);
  }

  // -------------------------------------------------------------------------
  // UI da aba "Aprovação" no chamado
  // -------------------------------------------------------------------------

  /**
   * Nomes de exibição de vários usuários em uma única consulta.
   *
   * @param int[] $user_ids
   * @return array<int, string> users_id => nome
   */
  private static function getUserNames(array $user_ids): array {
    global $DB;
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    if (empty($user_ids)) return [];
    $names = [];
    foreach ($DB->request([
      'SELECT' => ['id', 'name', 'realname', 'firstname'],
      'FROM'   => User::getTable(),
      'WHERE'  => ['id' => $user_ids],
    ]) as $row) {
      $names[(int) $row['id']] = formatUserName($row['id'], $row['name'], $row['realname'], $row['firstname']);
    }
    return $names;
  }

  static function showApprovalTab(Ticket $ticket): void {
    global $DB;

    $ticket_id = $ticket->getID();

    $flows = $DB->request([
      'SELECT' => ['tf.*', 'f.name AS flow_name', 'f.timeout_days AS flow_timeout'],
      'FROM'   => self::getTable() . ' AS tf',
      'INNER JOIN' => [
        PluginEscalationFlow::getTable() . ' AS f' => ['ON' => ['tf' => 'plugin_escalation_flows_id', 'f' => 'id']]
      ],
      'WHERE'  => ['tf.tickets_id' => $ticket_id],
    ]);

    $flows_array = iterator_to_array($flows);

    if (empty($flows_array)) {
      echo "<p class='center'>" . __('Nenhum fluxo de aprovação ativo para este chamado.', 'escalation') . "</p>";
      return;
    }

    $solvable = self::findPendingApproverStep($ticket_id, (int) Session::getLoginUserID(), true);
    if ($solvable) {
      $action = PluginEscalationConfig::getWebPath() . '/front/solve.form.php';
      echo "<div class='alert alert-success'>";
      echo "<h3 class='alert-heading'>" . sprintf(__('Você pode solucionar a etapa "%s"', 'escalation'), htmlspecialchars($solvable['step']['name'])) . "</h3>";
      echo "<p>" . __('O chamado será atribuído a você, solucionado e o fluxo terminará sem as próximas etapas.', 'escalation') . "</p>";
      echo "<form method='post' action='" . htmlescape($action) . "'>";
      echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
      echo Html::hidden('ticketstep_id', ['value' => (int) $solvable['ticketstep']['id']]);
      echo Html::hidden('tickets_id', ['value' => $ticket_id]);
      echo "<label class='form-label'>" . __('Descreva a solução', 'escalation') . "</label>";
      echo "<textarea class='form-control mb-2' name='comment' rows='3' required></textarea>";
      echo "<button class='btn btn-success' name='solve' value='1'><i class='ti ti-circle-check'></i> " . __('Solucionar', 'escalation') . "</button>";
      echo "</form></div>";
    }

    $manual = self::findPendingManualAction($ticket_id, (int) Session::getLoginUserID());
    if ($manual) {
      $action = PluginEscalationConfig::getWebPath() . '/front/manualaction.form.php';
      echo "<div class='card mb-4'><div class='card-body d-flex flex-wrap align-items-center gap-3'>";
      echo "<div><h3 class='card-title mb-1'>" . __('Atividade opcional', 'escalation') . "</h3>";
      echo "<p class='text-muted mb-0'>" . __('Use somente quando este chamado precisar de uma atividade adicional.', 'escalation') . "</p></div>";
      echo "<div class='dropdown ms-auto'>";
      echo "<button class='btn btn-primary dropdown-toggle' type='button' data-bs-toggle='dropdown' aria-expanded='false'><i class='ti ti-plus me-1'></i> " . __('Adicionar atividade', 'escalation') . "</button>";
      echo "<ul class='dropdown-menu dropdown-menu-end'><li><form method='post' action='" . htmlescape($action) . "'>";
      echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
      echo Html::hidden('ticketstep_id', ['value' => (int) $manual['ticketstep']['id']]);
      echo Html::hidden('tickets_id', ['value' => $ticket_id]);
      echo "<button class='dropdown-item' name='trigger' value='1'><i class='ti ti-hand-click me-2'></i>" . htmlescape($manual['step']['name']) . "</button>";
      echo "</form></li></ul></div></div></div>";
    }

    foreach ($flows_array as $tf_data) {
      $tf_id     = $tf_data['id'];
      $status    = $tf_data['status'];
      $flow_name = $tf_data['flow_name'];

      echo "<div class='spaced'>";
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='headerRow'><th colspan='4'>" . htmlspecialchars($flow_name) . " — " . self::getStatusLabel($status) . "</th></tr>";

      // Etapas
      $steps_data = $DB->request([
        'SELECT' => ['ts.*', 's.name AS step_name', 's.type', 's.quorum_type', 's.step_order', 's.allow_solve', 's.step_kind', 's.activation_mode'],
        'FROM'   => PluginEscalationTicketStep::getTable() . ' AS ts',
        'INNER JOIN' => [
          PluginEscalationStep::getTable() . ' AS s' => ['ON' => ['ts' => 'plugin_escalation_steps_id', 's' => 'id']]
        ],
        'WHERE'   => ['ts.plugin_escalation_ticketflows_id' => $tf_id],
        'ORDERBY' => ['s.step_order ASC'],
      ]);

      $current_user = (int) Session::getLoginUserID();
      foreach ($steps_data as $i => $ts) {
        $ts_status = $ts['status'];
        echo "<tr class='tab_bg_" . (($i % 2) + 1) . "'>";
        echo "<td><b>#" . ($i + 1) . " " . htmlspecialchars($ts['step_name']) . "</b></td>";
        $is_manual = ($ts['step_kind'] ?? '') === 'action' && ($ts['activation_mode'] ?? 'automatic') === 'manual';
        echo "<td>" . ($is_manual && (int) $ts_status === PluginEscalationTicketStep::STATUS_PENDING
          ? "<span class='badge bg-blue-lt'>" . __('Disponível', 'escalation') . "</span>"
          : PluginEscalationTicketStep::getStatusLabel($ts_status)) . "</td>";
        echo "<td>" . ($is_manual ? __('Atividade opcional', 'escalation') : PluginEscalationStep::getTypeName_($ts['type']));
        if ($ts['type'] === PluginEscalationStep::TYPE_PEOPLE) {
          echo " / " . PluginEscalationStep::getQuorumName($ts['quorum_type']);
        } elseif ($ts['type'] === PluginEscalationStep::TYPE_AUTO) {
          echo " — " . __('Auto-executado', 'escalation');
        }
        if (!empty($ts['allow_solve'])) {
          echo " — " . __('Pode solucionar', 'escalation');
        }
        echo "</td>";
        echo "<td>";

        // Votos registrados pela aba em versões anteriores (somente histórico).
        // Aprovação e recusa seguem pelas validações nativas do GLPI.
        // Solucionar usa o formulário desta aba quando a etapa permite.
        $votes = PluginEscalationApproval::getVotesForStep($ts['id']);
        $voter_names = self::getUserNames(array_column($votes, 'users_id'));
        foreach ($votes as $v) {
          $uname = $voter_names[(int) $v['users_id']] ?? '#' . $v['users_id'];
          $icon = match ((int) $v['action']) {
            PluginEscalationApproval::ACTION_SOLVE => '✔',
            PluginEscalationApproval::ACTION_REJECT => '❌',
            default => '✅',
          };
          $suffix = ((int) $v['action'] === PluginEscalationApproval::ACTION_SOLVE)
            ? ' (' . __('Solucionou', 'escalation') . ')'
            : '';
          echo "<small>$icon " . htmlspecialchars($uname . $suffix) . "</small><br>";
        }
        $snapshot = json_decode($ts['approver_snapshot'] ?? '[]', true) ?: [];
        if ($snapshot) {
          $snapshot_names = self::getUserNames($snapshot);
          echo "<small class='text-muted'>" . __('Aprovadores congelados:', 'escalation') . ' ' . htmlescape(implode(', ', $snapshot_names)) . '</small>';
        }

        $can_solve = (int) $status === self::STATUS_PENDING
          && (int) $ts_status === PluginEscalationTicketStep::STATUS_PENDING
          && !empty($ts['allow_solve'])
          && $current_user > 0
          && PluginEscalationApproval::isAuthorizedApprover((int) $ts['id'], $current_user);
        if ($can_solve) {
          $action = PluginEscalationConfig::getWebPath() . '/front/solve.form.php';
          echo "<form class='mt-2' method='post' action='" . htmlescape($action) . "'>";
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('ticketstep_id', ['value' => (int) $ts['id']]);
          echo Html::hidden('tickets_id', ['value' => $ticket_id]);
          echo "<label class='form-label'>" . __('Descreva a solução', 'escalation') . "</label>";
          echo "<textarea class='form-control mb-2' name='comment' rows='3' required></textarea>";
          echo "<button class='btn btn-success' name='solve' value='1'><i class='ti ti-circle-check'></i> " . __('Solucionar', 'escalation') . "</button>";
          echo "</form>";
        }

        echo "</td></tr>";
      }

      echo "</table>";
      echo "<h4 class='mt-4 mb-2'>" . __('Auditoria e automações', 'escalation') . "</h4>";
      echo "<div class='table-responsive'><table class='table table-sm table-hover'><thead><tr><th>" . __('Data', 'escalation') . "</th><th>" . __('Evento', 'escalation') . "</th><th>" . __('Detalhes', 'escalation') . "</th></tr></thead><tbody>";
      $audit_rows = $DB->request(['FROM' => 'glpi_plugin_escalation_audits', 'WHERE' => ['plugin_escalation_ticketflows_id' => $tf_id], 'ORDERBY' => ['id DESC'], 'LIMIT' => 100]);
      $has_audit = false;
      foreach ($audit_rows as $audit) { $has_audit = true; echo '<tr><td class="text-nowrap">' . htmlescape($audit['date_creation']) . '</td><td>' . htmlescape($audit['event']) . '</td><td><code class="text-wrap">' . htmlescape($audit['payload'] ?: '{}') . '</code></td></tr>'; }
      if (!$has_audit) echo "<tr><td colspan='3' class='text-center text-muted'>" . __('Nenhum evento auditável registrado nesta execução legada.', 'escalation') . "</td></tr>";
      echo "</tbody></table></div>";
      $failed_runs=$DB->request(['FROM'=>'glpi_plugin_escalation_actionruns','WHERE'=>['plugin_escalation_ticketflows_id'=>$tf_id,'status'=>'failed']]);
      foreach($failed_runs as $run){echo "<div class='alert alert-danger d-flex align-items-center gap-2'><i class='ti ti-alert-triangle'></i><div><strong>".__('Falha de automação','escalation')."</strong><div>".htmlescape($run['last_error'])."</div></div>";if(PluginEscalationProfile::have(PluginEscalationProfile::REPROCESS)){echo "<form class='ms-auto' method='post' action='".htmlescape(PluginEscalationConfig::getWebPath().'/front/reprocess.form.php')."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('run_id',['value'=>$run['id']]).Html::hidden('ticket_id',['value'=>$ticket_id])."<button class='btn btn-danger' name='reprocess'>".__('Reprocessar','escalation')."</button></form>";}echo '</div>';}
      echo "</div>";
    }
  }

  // -------------------------------------------------------------------------
  // CronTask: verificação de timeouts
  // -------------------------------------------------------------------------

  /**
   * Metadados da tarefa expostos na interface de Ações Automáticas do GLPI.
   */
  static function cronInfo(string $name): array {
    switch ($name) {
      case 'checktimeouts':
        return ['description' => __('Verifica e reprova automaticamente etapas/fluxos de aprovação que ultrapassaram o prazo configurado.', 'escalation')];
    }
    return [];
  }

  /**
   * Executa a verificação de timeouts.
   * Retorno: 1 = processou itens, 0 = nada a fazer, -1 = erro.
   */
  /** Máximo de itens expirados processados por execução (o restante fica para a próxima). */
  const CRON_BATCH_SIZE = 50;

  /**
   * Condição SQL "started_at + timeout_days dias <= agora", com a mesma
   * referência de hora usada pelo PHP (GLPI 10: QueryExpression global; 11: namespaced).
   */
  private static function expiredCriteria(string $started_col, string $days_col): array {
    global $DB;
    $now   = date('Y-m-d H:i:s');
    $class = class_exists('Glpi\\DBAL\\QueryExpression') ? 'Glpi\\DBAL\\QueryExpression' : 'QueryExpression';
    return [new $class(sprintf(
      'DATE_ADD(%s, INTERVAL %s DAY) <= %s',
      $DB->quoteName($started_col),
      $DB->quoteName($days_col),
      $DB->quoteValue($now)
    ))];
  }

  static function cronChecktimeouts(CronTask $task): int {
    global $DB;

    $volume  = 0;

    // ------------------------------------------------------------------
    // 1. Timeouts de etapas individuais
    // ------------------------------------------------------------------
    $step_iterator = $DB->request([
      'SELECT'     => [
        'ts.id',
        'ts.tickets_id',
        'ts.plugin_escalation_ticketflows_id',
        'ts.started_at',
        's.timeout_days',
        's.name AS step_name',
      ],
      'FROM'       => PluginEscalationTicketStep::getTable() . ' AS ts',
      'INNER JOIN' => [
        PluginEscalationStep::getTable() . ' AS s' => [
          'ON' => ['ts' => 'plugin_escalation_steps_id', 's' => 'id'],
        ],
      ],
      'WHERE'      => array_merge([
        'ts.status' => PluginEscalationTicketStep::STATUS_PENDING,
        ['s.timeout_days' => ['>', 0]],
        ['NOT' => ['ts.started_at' => null]],
      ], self::expiredCriteria('ts.started_at', 's.timeout_days')),
      'ORDERBY'    => ['ts.started_at ASC'],
      'LIMIT'      => self::CRON_BATCH_SIZE,
    ]);

    foreach ($step_iterator as $row) {

      // Marcar etapa como expirada
      $ts_obj = new PluginEscalationTicketStep();
      $ts_obj->update([
        'id'           => $row['id'],
        'status'       => PluginEscalationTicketStep::STATUS_TIMED_OUT,
        'completed_at' => date('Y-m-d H:i:s'),
      ]);

      PluginEscalationAudit::record((int) $row['plugin_escalation_ticketflows_id'], (int) $row['tickets_id'], 'step.expired', ['ticket_step_id' => $row['id']]);
      PluginEscalationWorkflow::transition((int) $row['id'], 'expired');

      $task->log(sprintf(
        __('Etapa "%s" (ID %d) sofreu reprovação automática após %d dia(s).', 'escalation'),
        $row['step_name'],
        $row['id'],
        $row['timeout_days']
      ));

      $volume++;
    }

    // ------------------------------------------------------------------
    // 2. Timeout global do fluxo (para fluxos ainda pendentes após o passo 1)
    // ------------------------------------------------------------------
    $flow_iterator = $DB->request([
      'SELECT'     => [
        'tf.id',
        'tf.started_at',
        'f.timeout_days',
        'f.name AS flow_name',
      ],
      'FROM'       => self::getTable() . ' AS tf',
      'INNER JOIN' => [
        PluginEscalationFlow::getTable() . ' AS f' => [
          'ON' => ['tf' => 'plugin_escalation_flows_id', 'f' => 'id'],
        ],
      ],
      'WHERE'      => array_merge([
        'tf.status'       => self::STATUS_PENDING,
        ['f.timeout_days' => ['>', 0]],
        ['NOT' => ['tf.started_at' => null]],
      ], self::expiredCriteria('tf.started_at', 'f.timeout_days')),
      'ORDERBY'    => ['tf.started_at ASC'],
      'LIMIT'      => self::CRON_BATCH_SIZE,
    ]);

    foreach ($flow_iterator as $row) {

      // Marcar etapas WAITING/PENDING como expiradas antes de fechar o fluxo
      $DB->update(PluginEscalationTicketStep::getTable(), [
        'status'       => PluginEscalationTicketStep::STATUS_TIMED_OUT,
        'completed_at' => date('Y-m-d H:i:s'),
      ], [
        'plugin_escalation_ticketflows_id' => $row['id'],
        'status' => [
          PluginEscalationTicketStep::STATUS_WAITING,
          PluginEscalationTicketStep::STATUS_PENDING,
        ],
      ]);

      self::completeFlow((int) $row['id'], self::STATUS_TIMED_OUT);

      $task->log(sprintf(
        __('Fluxo "%s" (ID %d) sofreu reprovação automática global após %d dia(s).', 'escalation'),
        $row['flow_name'],
        $row['id'],
        $row['timeout_days']
      ));

      $volume++;
    }

    $task->addVolume($volume);
    return $volume > 0 ? 1 : 0;
  }

  static function getStatusLabel(int $status): string {
    $labels = [
      self::STATUS_PENDING   => '<span style="color:#d97706">⏳ ' . __('Pendente', 'escalation') . '</span>',
      self::STATUS_APPROVED  => '<span style="color:#16a34a">✅ ' . __('Aprovado', 'escalation') . '</span>',
      self::STATUS_REJECTED  => '<span style="color:#dc2626">❌ ' . __('Reprovado', 'escalation') . '</span>',
      self::STATUS_TIMED_OUT => '<span style="color:#6b7280">⏰ ' . __('Reprovação automática', 'escalation') . '</span>',
    ];
    return $labels[$status] ?? $status;
  }
}
