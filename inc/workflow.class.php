<?php
if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

/** Trigger selection, publication validation and transition orchestration. */
final class PluginEscalationWorkflow {
  public static function handleTicket(Ticket $ticket, string $event): void {
    global $DB;
    if (!empty($ticket->input['_escalation_action'])) return;
    $old = $ticket->oldvalues ?? [];
    $candidates = [];
    foreach ($DB->request([
      'SELECT' => ['f.id', 'v.id AS version_id', 'v.entities_id', 'v.is_recursive', 'v.priority'],
      'FROM' => PluginEscalationFlow::getTable() . ' AS f',
      'INNER JOIN' => ['glpi_plugin_escalation_flowversions AS v' => ['ON' => ['f' => 'id', 'v' => 'plugin_escalation_flows_id']]],
      'WHERE' => ['f.is_active' => 1, 'v.status' => 'published'],
      'ORDERBY' => ['v.priority DESC', 'f.id ASC', 'v.version DESC'],
    ]) as $row) {
      if (!self::entityMatches($row, (int) $ticket->fields['entities_id'])) continue;
      if (countElementsInTable(PluginEscalationTicketFlow::getTable(), ['tickets_id' => $ticket->getID(), 'plugin_escalation_flows_id' => $row['id']]) > 0) continue;
      $match = PluginEscalationCondition::matchVersion((int) $row['version_id'], $ticket, $event, $old);
      if (!$match['matched']) continue;
      $row['entry_steps_id'] = (int) $match['entry_steps_id'];
      $candidates[] = $row;
    }
    if (!$candidates) return;
    usort($candidates, static function(array $a, array $b): int {
      $priority = (int) $b['priority'] <=> (int) $a['priority'];
      return $priority !== 0 ? $priority : ((int) $a['id'] <=> (int) $b['id']);
    });
    $winner = $candidates[0];
    PluginEscalationTicketFlow::startFlow($ticket, (int) $winner['id'], (int) $winner['version_id'], (int) ($winner['entry_steps_id'] ?? 0));
  }

  private static function entityMatches(array $flow, int $entity_id): bool {
    $owner = (int) ($flow['entities_id'] ?? 0);
    if ($owner === $entity_id) return true;
    if (empty($flow['is_recursive'])) return false;
    if ($owner === 0) return true;
    return function_exists('getAncestorsOf') && in_array($owner, array_map('intval', getAncestorsOf('glpi_entities', $entity_id)), true);
  }

  /** @return string[] */
  public static function validateVersion(int $version_id): array {
    global $DB;
    $errors = [];
    $steps = iterator_to_array($DB->request(['FROM' => PluginEscalationStep::getTable(), 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id], 'ORDERBY' => ['step_order ASC']]));
    if (!$steps) $errors[] = __('O fluxo precisa de pelo menos uma etapa.', 'escalation');
    $by_id = [];
    foreach ($steps as $step) {
      $by_id[(int) $step['id']] = $step;
      if (($step['step_kind'] ?? 'approval') === 'approval') {
        $has_source = countElementsInTable('glpi_plugin_escalation_stepapprovers', ['plugin_escalation_steps_id' => $step['id']]) > 0
          || countElementsInTable(PluginEscalationStepUser::getTable(), ['plugin_escalation_steps_id' => $step['id']]) > 0
          || !empty($step['include_requesters']) || !empty($step['include_assigned']) || !empty($step['include_observers'])
          || ($step['type'] ?? '') === PluginEscalationStep::TYPE_HIERARCHY
          || (int) ($step['fallback_users_id'] ?? 0) > 0;
        if (!$has_source) $errors[] = sprintf(__('A etapa "%s" não possui fonte de aprovadores nem substituto.', 'escalation'), $step['name']);
      }
    }
    $terminal_states = ['approved','rejected','expired','failed','pending_configuration'];
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_transitions', 'WHERE' => ['plugin_escalation_steps_id' => array_keys($by_id) ?: [-1]]]) as $transition) {
      $source = $by_id[(int) $transition['plugin_escalation_steps_id']] ?? null;
      $target = $by_id[(int) $transition['target_steps_id']] ?? null;
      if ((int) $transition['target_steps_id'] > 0 && !$target) $errors[] = __('Uma transição aponta para uma etapa inexistente.', 'escalation');
      if ($source && $target && (int) $target['step_order'] <= (int) $source['step_order']) $errors[] = __('Transições devem apontar somente para etapas posteriores.', 'escalation');
      if ((int) $transition['target_steps_id'] === 0 && !empty($transition['terminal_state']) && !in_array($transition['terminal_state'], $terminal_states, true)) $errors[] = __('Uma transição possui estado terminal inválido.', 'escalation');
    }
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_conditiongroups', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id]]) as $group) {
      $entry = (int) ($group['entry_steps_id'] ?? 0);
      if ($entry > 0 && !isset($by_id[$entry])) $errors[] = __('Um início aponta para uma etapa que não existe mais.', 'escalation');
    }
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_actions', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id]]) as $action) {
      if (!in_array($action['type'], PluginEscalationActionExecutor::types(), true)) $errors[] = sprintf(__('Tipo de ação inválido: %s', 'escalation'), $action['type']);
      $parameters = json_decode($action['parameters'] ?: '{}', true);
      if (!is_array($parameters)) {
        $errors[] = sprintf(__('Parâmetros inválidos na ação "%s".', 'escalation'), $action['name']);
      } else {
        foreach (PluginEscalationActionExecutor::validateParameters((string) $action['type'], $parameters) as $error) {
          $errors[] = sprintf(__('Ação "%1$s": %2$s', 'escalation'), $action['name'], $error);
        }
      }
    }
    return array_values(array_unique($errors));
  }

  public static function publish(int $flow_id): array {
    global $DB;
    $version = $DB->request(['FROM' => 'glpi_plugin_escalation_flowversions', 'WHERE' => ['plugin_escalation_flows_id' => $flow_id, 'status' => 'draft'], 'ORDERBY' => ['version DESC'], 'LIMIT' => 1])->current();
    if (!$version) return [__('Nenhum rascunho encontrado.', 'escalation')];
    $errors = self::validateVersion((int) $version['id']);
    if ($errors) return $errors;
    $DB->update('glpi_plugin_escalation_flowversions', ['status' => 'archived'], ['plugin_escalation_flows_id' => $flow_id, 'status' => 'published']);
    $DB->update('glpi_plugin_escalation_flowversions', ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')], ['id' => $version['id']]);
    return [];
  }

  public static function transition(int $ticket_step_id, string $outcome): void {
    global $DB;
    $ts = $DB->request(['FROM' => PluginEscalationTicketStep::getTable(), 'WHERE' => ['id' => $ticket_step_id], 'LIMIT' => 1])->current();
    if (!$ts) return;
    $transition = $DB->request(['FROM' => 'glpi_plugin_escalation_transitions', 'WHERE' => ['plugin_escalation_steps_id' => $ts['plugin_escalation_steps_id'], 'outcome' => $outcome], 'LIMIT' => 1])->current();
    if (!$transition) {
      if ($outcome === 'approver_missing') return;
      if ($outcome === 'approved' || $outcome === 'success' || $outcome === 'solved') {
        if ($outcome === 'solved') {
          PluginEscalationTicketFlow::completeFlow((int) $ts['plugin_escalation_ticketflows_id'], PluginEscalationTicketFlow::STATUS_APPROVED);
        } else {
          PluginEscalationTicketFlow::advanceFlow((int) $ts['plugin_escalation_ticketflows_id']);
        }
      } else {
        PluginEscalationTicketFlow::completeFlow((int) $ts['plugin_escalation_ticketflows_id'], $outcome === 'expired' ? PluginEscalationTicketFlow::STATUS_TIMED_OUT : PluginEscalationTicketFlow::STATUS_REJECTED);
      }
      return;
    }
    if ((int) $transition['target_steps_id'] > 0) {
      PluginEscalationTicketFlow::activateStepByDefinition((int) $ts['plugin_escalation_ticketflows_id'], (int) $transition['target_steps_id']);
      return;
    }
    $terminal = $transition['terminal_state'] ?: (($outcome === 'approved' || $outcome === 'solved') ? 'approved' : 'rejected');
    if ($terminal === 'pending_configuration') {
      $DB->update(PluginEscalationTicketFlow::getTable(), ['state' => 'pending_configuration'], ['id' => $ts['plugin_escalation_ticketflows_id']]);
      return;
    }
    $flow_id = (int) $ts['plugin_escalation_ticketflows_id'];
    // Se alguma etapa já foi recusada, o fluxo não pode terminar como aprovado
    // (ex.: Recusado → Alterar situação → Fim).
    $had_rejection = countElementsInTable(PluginEscalationTicketStep::getTable(), [
      'plugin_escalation_ticketflows_id' => $flow_id,
      'outcome' => 'rejected',
    ]) > 0;
    if ($terminal === 'approved' && !$had_rejection) {
      PluginEscalationTicketFlow::completeFlow($flow_id, PluginEscalationTicketFlow::STATUS_APPROVED);
      return;
    }
    if ($terminal === 'expired') {
      PluginEscalationTicketFlow::completeFlow($flow_id, PluginEscalationTicketFlow::STATUS_TIMED_OUT);
      return;
    }
    PluginEscalationTicketFlow::completeFlow($flow_id, PluginEscalationTicketFlow::STATUS_REJECTED);
  }
}
