<?php
if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

final class PluginEscalationActionExecutor {
  public static function cronInfo(string $name): array {
    return $name === 'processqueue' ? ['description' => __('Executa a fila idempotente de ações dos workflows.', 'escalation')] : [];
  }
  public static function cronProcessqueue(CronTask $task): int { return self::processQueue($task); }

  /** Processa a fila imediatamente (aprovação/web), sem esperar o cron. */
  public static function processDueNow(): int {
    $restore = self::enterAutomationContext();
    try {
      return self::processQueue(null);
    } finally {
      $restore();
    }
  }

  public static function types(): array {
    return ['assign_user','assign_group','change_status','add_followup','create_task','change_category','notify','create_related_ticket'];
  }

  /** @return string[] */
  public static function validateParameters(string $type, array $parameters): array {
    $required_ids = [
      'assign_user' => 'users_id', 'assign_group' => 'groups_id',
      'change_status' => 'status', 'change_category' => 'itilcategories_id',
    ];
    if (isset($required_ids[$type]) && (!isset($parameters[$required_ids[$type]]) || !is_int($parameters[$required_ids[$type]]) || $parameters[$required_ids[$type]] <= 0)) {
      return [sprintf(__('O parâmetro inteiro positivo "%s" é obrigatório.', 'escalation'), $required_ids[$type])];
    }
    if (in_array($type, ['add_followup','create_task'], true) && (!isset($parameters['content']) || !is_string($parameters['content']) || trim($parameters['content']) === '')) {
      return [__('O parâmetro de texto "content" é obrigatório.', 'escalation')];
    }
    if ($type === 'add_followup' && isset($parameters['is_private']) && !in_array($parameters['is_private'], [0,1,true,false], true)) {
      return [__('"is_private" deve ser booleano.', 'escalation')];
    }
    if (in_array($type, ['assign_user','assign_group'], true) && isset($parameters['replace_assignees']) && !in_array($parameters['replace_assignees'], [0,1,true,false], true)) {
      return [__('"replace_assignees" deve ser booleano.', 'escalation')];
    }
    if ($type === 'notify' && (!isset($parameters['event']) || !is_string($parameters['event']) || trim($parameters['event']) === '')) {
      return [__('O evento de notificação é obrigatório.', 'escalation')];
    }
    if ($type === 'create_related_ticket' && (!isset($parameters['name']) || !is_string($parameters['name']) || trim($parameters['name']) === '')) {
      return [__('O nome do chamado relacionado é obrigatório.', 'escalation')];
    }
    return [];
  }

  public static function queueForEvent(int $execution_id, string $event, int $step_id = 0): int {
    global $DB;
    $execution = $DB->request(['FROM' => PluginEscalationTicketFlow::getTable(), 'WHERE' => ['id' => $execution_id], 'LIMIT' => 1])->current();
    if (!$execution || !(int) $execution['plugin_escalation_flowversions_id']) return 0;
    $queued = 0;
    foreach ($DB->request([
      'FROM' => 'glpi_plugin_escalation_actions',
      'WHERE' => ['plugin_escalation_flowversions_id' => $execution['plugin_escalation_flowversions_id'], 'event' => $event, 'plugin_escalation_steps_id' => [0, $step_id]],
      'ORDERBY' => ['action_order ASC'],
    ]) as $action) {
      $key = $event . ':' . $step_id;
      $DB->doQuery(sprintf(
        "INSERT IGNORE INTO `glpi_plugin_escalation_actionruns` (`plugin_escalation_ticketflows_id`,`plugin_escalation_actions_id`,`event_key`,`status`,`next_attempt_at`,`date_creation`,`date_mod`) VALUES (%d,%d,%s,'queued',NOW(),NOW(),NOW())",
        $execution_id, $action['id'], $DB->quoteValue($key)
      ));
      if (!method_exists($DB, 'affectedRows') || $DB->affectedRows() > 0) $queued++;
    }
    if ($queued > 0) {
      $DB->update(PluginEscalationTicketFlow::getTable(), ['automation_status' => 'queued'], ['id' => $execution_id]);
    }
    return $queued;
  }

  public static function processQueue(?CronTask $task = null): int {
    global $DB;
    $count = 0;
    foreach ($DB->request([
      'FROM' => 'glpi_plugin_escalation_actionruns',
      'WHERE' => ['status' => ['queued','retry'], ['next_attempt_at' => ['<=', date('Y-m-d H:i:s')]]],
      'ORDERBY' => ['id ASC'], 'LIMIT' => 50,
    ]) as $run) {
      $count++;
      self::run((int) $run['id'], $task);
    }
    if ($task !== null) {
      $task->addVolume($count);
    }
    return $count ? 1 : 0;
  }
  public static function requeue(int $run_id): bool {
    global $DB;
    if (!PluginEscalationProfile::have(PluginEscalationProfile::REPROCESS)) return false;
    $run=$DB->request(['FROM'=>'glpi_plugin_escalation_actionruns','WHERE'=>['id'=>$run_id,'status'=>'failed'],'LIMIT'=>1])->current();
    if(!$run)return false;
    $DB->update('glpi_plugin_escalation_actionruns',['status'=>'queued','attempts'=>0,'next_attempt_at'=>date('Y-m-d H:i:s'),'last_error'=>null,'date_mod'=>date('Y-m-d H:i:s')],['id'=>$run_id]);
    $execution=$DB->request(['FROM'=>PluginEscalationTicketFlow::getTable(),'WHERE'=>['id'=>$run['plugin_escalation_ticketflows_id']],'LIMIT'=>1])->current();
    if($execution){$DB->update(PluginEscalationTicketFlow::getTable(),['automation_status'=>'queued'],['id'=>$execution['id']]);PluginEscalationAudit::record((int)$execution['id'],(int)$execution['tickets_id'],'action.requeued',['run_id'=>$run_id]);}
    return true;
  }

  private static function run(int $run_id, ?CronTask $task = null): void {
    global $DB;
    $run = $DB->request(['FROM' => 'glpi_plugin_escalation_actionruns', 'WHERE' => ['id' => $run_id], 'LIMIT' => 1])->current();
    if (!$run || !in_array($run['status'], ['queued','retry'], true)) return;
    $action = $DB->request(['FROM' => 'glpi_plugin_escalation_actions', 'WHERE' => ['id' => $run['plugin_escalation_actions_id']], 'LIMIT' => 1])->current();
    $execution = $DB->request(['FROM' => PluginEscalationTicketFlow::getTable(), 'WHERE' => ['id' => $run['plugin_escalation_ticketflows_id']], 'LIMIT' => 1])->current();
    if (!$action || !$execution) return;
    // Preserve configured order inside the same event.
    foreach ($DB->request([
      'SELECT' => ['r.status'],
      'FROM' => 'glpi_plugin_escalation_actionruns AS r',
      'INNER JOIN' => ['glpi_plugin_escalation_actions AS a' => ['ON' => ['r' => 'plugin_escalation_actions_id', 'a' => 'id']]],
      'WHERE' => ['r.plugin_escalation_ticketflows_id' => $execution['id'], 'r.event_key' => $run['event_key'], ['a.action_order' => ['<', $action['action_order']]]],
    ]) as $previous) {
      if (!in_array($previous['status'], ['done','failed'], true)) return;
    }
    $DB->update('glpi_plugin_escalation_actionruns', ['status' => 'running', 'attempts' => (int) $run['attempts'] + 1, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $run_id]);
    try {
      $result = self::execute($action, $execution);
      $DB->update('glpi_plugin_escalation_actionruns', ['status' => 'done', 'result' => json_encode($result), 'last_error' => null, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $run_id]);
      $DB->update(PluginEscalationTicketFlow::getTable(), ['automation_status' => 'running'], ['id' => $execution['id']]);
      PluginEscalationAudit::record((int) $execution['id'], (int) $execution['tickets_id'], 'action.completed', ['action_id' => $action['id'], 'type' => $action['type']]);
      self::completeEventIfReady($execution, (string) $run['event_key']);
    } catch (Throwable $e) {
      $attempt = (int) $run['attempts'] + 1;
      $retry = $action['error_policy'] === 'retry' && $attempt < (int) $action['max_attempts'];
      $delays = [5, 30, 120];
      $next = date('Y-m-d H:i:s', time() + 60 * ($delays[min($attempt - 1, 2)]));
      $DB->update('glpi_plugin_escalation_actionruns', ['status' => $retry ? 'retry' : 'failed', 'next_attempt_at' => $retry ? $next : null, 'last_error' => $e->getMessage(), 'date_mod' => date('Y-m-d H:i:s')], ['id' => $run_id]);
      $DB->update(PluginEscalationTicketFlow::getTable(), ['automation_status' => $retry ? 'retrying' : 'failed'], ['id' => $execution['id']]);
      PluginEscalationAudit::record((int) $execution['id'], (int) $execution['tickets_id'], 'action.failed', ['action_id' => $action['id'], 'error' => $e->getMessage(), 'will_retry' => $retry]);
      if (!$retry && $action['error_policy'] === 'stop') {
        foreach ($DB->request([
          'FROM' => 'glpi_plugin_escalation_actions',
          'WHERE' => [
            'plugin_escalation_flowversions_id' => $execution['plugin_escalation_flowversions_id'],
            'event' => $action['event'],
            ['action_order' => ['>', $action['action_order']]],
          ],
        ]) as $later) {
          $DB->update('glpi_plugin_escalation_actionruns',['status'=>'blocked','last_error'=>__('Bloqueada por falha anterior.','escalation')],['plugin_escalation_ticketflows_id'=>$execution['id'],'plugin_escalation_actions_id'=>$later['id'],'event_key'=>$run['event_key'],'status'=>'queued']);
        }
      }
      if (!$retry) self::completeEventIfReady($execution, (string) $run['event_key']);
      if ($task !== null) {
        $task->log($e->getMessage());
      }
    }
  }

  /** Marca o contexto como cron/automação para o GLPI liberar checagens de direito. */
  private static function enterAutomationContext(): callable {
    $previous = null;
    if (method_exists(Session::class, 'isCron') && method_exists(Session::class, 'setIsCron')) {
      $previous = Session::isCron();
      Session::setIsCron(true);
      return static function () use ($previous): void {
        Session::setIsCron((bool) $previous);
      };
    }

    try {
      $ref = new ReflectionClass(Session::class);
      if ($ref->hasProperty('is_cron')) {
        $prop = $ref->getProperty('is_cron');
        $prop->setAccessible(true);
        $previous = (bool) $prop->getValue();
        $prop->setValue(null, true);
        return static function () use ($prop, $previous): void {
          $prop->setValue(null, $previous);
        };
      }
    } catch (Throwable $e) {
      // Sem bypass disponível: segue com a sessão atual.
    }

    return static function (): void {};
  }

  private static function completeEventIfReady(array $execution, string $event_key): void {
    global $DB;
    $runs=iterator_to_array($DB->request(['FROM'=>'glpi_plugin_escalation_actionruns','WHERE'=>['plugin_escalation_ticketflows_id'=>$execution['id'],'event_key'=>$event_key]]));
    foreach($runs as $run) if(in_array($run['status'],['queued','retry','running'],true)) return;
    $failed=(bool)array_filter($runs,fn($run)=>in_array($run['status'],['failed','blocked'],true));
    $DB->update(PluginEscalationTicketFlow::getTable(),['automation_status'=>$failed?'failed':'done'],['id'=>$execution['id']]);
    if(str_starts_with($event_key,'step_activated:')){
      $step_id=(int)substr($event_key,strlen('step_activated:'));
      $ts=$DB->request(['FROM'=>PluginEscalationTicketStep::getTable(),'WHERE'=>['plugin_escalation_ticketflows_id'=>$execution['id'],'plugin_escalation_steps_id'=>$step_id,'status'=>PluginEscalationTicketStep::STATUS_PENDING],'LIMIT'=>1])->current();
      if($ts){$outcome=$failed?'failure':'success';$DB->update(PluginEscalationTicketStep::getTable(),['status'=>$failed?PluginEscalationTicketStep::STATUS_REJECTED:PluginEscalationTicketStep::STATUS_APPROVED,'outcome'=>$outcome,'completed_at'=>date('Y-m-d H:i:s')],['id'=>$ts['id']]);PluginEscalationWorkflow::transition((int)$ts['id'],$outcome);}
    }
  }

  private static function execute(array $action, array $execution): array {
    global $DB;
    $p = json_decode($action['parameters'] ?: '{}', true, 32, JSON_THROW_ON_ERROR);
    $ticket_id = (int) $execution['tickets_id'];
    $ticket = new Ticket();
    if (!$ticket->getFromDB($ticket_id)) throw new RuntimeException('Ticket not found');
    switch ($action['type']) {
      case 'assign_user':
        if (!empty($p['replace_assignees'])) self::clearAssignees($ticket_id);
        $relation = new Ticket_User();
        $id = $relation->add(['tickets_id' => $ticket_id, 'users_id' => (int) ($p['users_id'] ?? 0), 'type' => CommonITILActor::ASSIGN, '_escalation_action' => 1]);
        break;
      case 'assign_group':
        if (!empty($p['replace_assignees'])) self::clearAssignees($ticket_id);
        $relation = new Group_Ticket();
        $id = $relation->add(['tickets_id' => $ticket_id, 'groups_id' => (int) ($p['groups_id'] ?? 0), 'type' => CommonITILActor::ASSIGN, '_escalation_action' => 1]);
        break;
      case 'change_status': $id = $ticket->update(['id' => $ticket_id, 'status' => (int) ($p['status'] ?? 0), '_escalation_action' => 1]); break;
      case 'change_category': $id = $ticket->update(['id' => $ticket_id, 'itilcategories_id' => (int) ($p['itilcategories_id'] ?? 0), '_escalation_action' => 1]); break;
      case 'add_followup':
        $obj = new ITILFollowup(); $id = $obj->add(['itemtype' => Ticket::class, 'items_id' => $ticket_id, 'content' => (string) ($p['content'] ?? ''), 'is_private' => (int) ($p['is_private'] ?? 0)]); break;
      case 'create_task':
        $obj = new TicketTask(); $id = $obj->add(['tickets_id' => $ticket_id, 'content' => (string) ($p['content'] ?? ''), 'users_id_tech' => (int) ($p['users_id'] ?? 0), 'groups_id_tech' => (int) ($p['groups_id'] ?? 0)]); break;
      case 'notify':
        $id = NotificationEvent::raiseEvent((string) ($p['event'] ?? 'escalation_workflow'), $ticket, ['workflow_execution_id' => $execution['id']]); break;
      case 'create_related_ticket':
        $related = new Ticket();
        $id = $related->add(['name' => (string) ($p['name'] ?? ''), 'content' => (string) ($p['content'] ?? ''), 'entities_id' => (int) ($p['entities_id'] ?? $ticket->fields['entities_id']), 'itilcategories_id' => (int) ($p['itilcategories_id'] ?? 0), 'type' => (int) ($p['type'] ?? Ticket::DEMAND_TYPE), '_escalation_action' => 1]);
        if ($id) { $link = new Ticket_Ticket(); $link->add(['tickets_id_1' => $ticket_id, 'tickets_id_2' => $id, 'link' => Ticket_Ticket::LINK_TO]); }
        break;
      default: throw new InvalidArgumentException('Unsupported action type');
    }
    if (!$id) throw new RuntimeException('GLPI rejected action ' . $action['type']);
    return ['item_id' => $id];
  }

  private static function clearAssignees(int $ticket_id): void {
    global $DB;
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Ticket_User::getTable(), 'WHERE' => ['tickets_id' => $ticket_id, 'type' => CommonITILActor::ASSIGN]]) as $row) {
      $relation = new Ticket_User();
      $relation->delete(['id' => (int) $row['id']]);
    }
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Group_Ticket::getTable(), 'WHERE' => ['tickets_id' => $ticket_id, 'type' => CommonITILActor::ASSIGN]]) as $row) {
      $relation = new Group_Ticket();
      $relation->delete(['id' => (int) $row['id']]);
    }
  }
}
