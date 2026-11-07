<?php
if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

final class PluginEscalationAudit {
  public static function record(int $execution_id, int $ticket_id, string $event, array $payload = [], ?int $user_id = null): void {
    global $DB;
    $DB->insert('glpi_plugin_escalation_audits', [
      'plugin_escalation_ticketflows_id' => $execution_id,
      'tickets_id' => $ticket_id,
      'users_id' => $user_id ?? (int) (Session::getLoginUserID() ?: 0),
      'event' => $event,
      'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'date_creation' => date('Y-m-d H:i:s'),
    ]);
  }
}
