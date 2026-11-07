<?php

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');
Session::checkLoginUser();

$ticket_id = (int) ($_GET['tickets_id'] ?? 0);
$users_id = (int) Session::getLoginUserID();
$found = $ticket_id > 0 && $users_id > 0
  ? PluginEscalationTicketFlow::findPendingApproverStep($ticket_id, $users_id, true)
  : null;

if (!$found) {
  echo json_encode(['ok' => false]);
  return;
}

echo json_encode([
  'ok' => true,
  'ticketstep_id' => (int) $found['ticketstep']['id'],
  'tickets_id' => $ticket_id,
  'step_name' => (string) $found['step']['name'],
  'solve_url' => PluginEscalationConfig::getWebPath() . '/front/solve.form.php',
  'csrf' => Session::getNewCSRFToken(),
  'label' => __('Solucionar', 'escalation'),
], JSON_UNESCAPED_UNICODE);
