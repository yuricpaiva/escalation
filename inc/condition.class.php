<?php
if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

final class PluginEscalationCondition {
  public static function matchesVersion(int $version_id, Ticket $ticket, string $event, array $old = []): bool {
    return self::matchVersion($version_id, $ticket, $event, $old)['matched'];
  }

  /** @return array{matched:bool,entry_steps_id:int} */
  public static function matchVersion(int $version_id, Ticket $ticket, string $event, array $old = []): array {
    global $DB;
    $groups = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_escalation_conditiongroups', 'WHERE' => ['plugin_escalation_flowversions_id' => $version_id], 'ORDERBY' => ['group_order ASC']]));
    if (!$groups) return ['matched' => true, 'entry_steps_id' => 0];
    $winner = null;
    $winner_score = -1;
    foreach ($groups as $group) {
      $conditions = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_escalation_conditions', 'WHERE' => ['plugin_escalation_conditiongroups_id' => $group['id']], 'ORDERBY' => ['condition_order ASC']]));
      $all = true;
      foreach ($conditions as $condition) {
        if (!self::matches($condition, $ticket, $event, $old)) { $all = false; break; }
      }
      if (!$all) continue;
      $score = self::specificity($conditions);
      if ($winner === null || $score > $winner_score) {
        $winner = $group;
        $winner_score = $score;
      }
    }
    if ($winner === null) return ['matched' => false, 'entry_steps_id' => 0];
    return ['matched' => true, 'entry_steps_id' => (int) ($winner['entry_steps_id'] ?? 0)];
  }

  /** Pessoa específica vence grupo quando os dois cards servem ao mesmo chamado. */
  private static function specificity(array $conditions): int {
    $score = 0;
    foreach ($conditions as $condition) {
      if (!in_array((string) ($condition['field'] ?? ''), ['requester', 'creator'], true)) continue;
      if (!in_array((string) ($condition['operator'] ?? ''), ['equals', 'in'], true)) continue;
      $value = self::decode((string) ($condition['value'] ?? ''));
      $ids = array_filter(array_map('intval', (array) $value));
      if ($ids) $score += 10;
    }
    return $score;
  }

  public static function matches(array $condition, Ticket $ticket, string $event, array $old = []): bool {
    $field = (string) $condition['field'];
    $operator = (string) $condition['operator'];
    $expected = self::decode((string) ($condition['value'] ?? ''));
    $actual = self::value($field, $ticket, $event, $old);
    return match ($operator) {
      'equals' => self::same($actual, $expected),
      'not_equals' => !self::same($actual, $expected),
      'in' => count(array_intersect((array) $actual, (array) $expected)) > 0,
      'not_in' => count(array_intersect((array) $actual, (array) $expected)) === 0,
      'member_of' => self::memberOf((array) $actual, (int) $expected),
      'changed_from' => self::same($old[self::ticketField($field)] ?? null, $expected),
      'changed_to' => array_key_exists(self::ticketField($field), $old) && self::same($actual, $expected),
      default => false,
    };
  }

  private static function value(string $field, Ticket $ticket, string $event, array $old): mixed {
    return match ($field) {
      'event' => $event,
      'category' => (int) ($ticket->fields['itilcategories_id'] ?? 0),
      'creator' => (int) ($ticket->fields['users_id_recipient'] ?? 0),
      'requester' => PluginEscalationTicketFlow::getTicketActors($ticket->getID(), CommonITILActor::REQUESTER),
      'creator_group' => [(int) ($ticket->fields['users_id_recipient'] ?? 0)],
      'requester_group' => PluginEscalationTicketFlow::getTicketActors($ticket->getID(), CommonITILActor::REQUESTER),
      'type' => (int) ($ticket->fields['type'] ?? 0),
      'status' => (int) ($ticket->fields['status'] ?? 0),
      default => $ticket->fields[self::ticketField($field)] ?? null,
    };
  }

  private static function memberOf(array $users, int $group_id): bool {
    global $DB;
    $users = array_values(array_filter(array_map('intval', $users)));
    return $group_id > 0 && $users && count($DB->request(['FROM' => Group_User::getTable(), 'WHERE' => ['groups_id' => $group_id, 'users_id' => $users]])) > 0;
  }

  private static function decode(string $value): mixed {
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
  }
  private static function same(mixed $a, mixed $b): bool {
    if (is_array($a)) return in_array((string) $b, array_map('strval', $a), true);
    if (is_array($b)) return in_array((string) $a, array_map('strval', $b), true);
    return (string) $a === (string) $b;
  }
  private static function ticketField(string $field): string { return ['category' => 'itilcategories_id'][$field] ?? $field; }
}
