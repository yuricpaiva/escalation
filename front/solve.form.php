<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!isset($_POST['solve'])) {
  Html::back();
}

$ticketstep_id = (int) ($_POST['ticketstep_id'] ?? 0);
$ticket_id     = (int) ($_POST['tickets_id'] ?? 0);
$comment       = trim(strip_tags((string) ($_POST['comment'] ?? '')));
if ($comment === '') {
  $comment = trim(strip_tags((string) ($_POST['comment_validation'] ?? '')));
}
$users_id      = (int) Session::getLoginUserID();

if ($ticketstep_id <= 0 || $users_id <= 0) {
  Session::addMessageAfterRedirect(__('Não foi possível solucionar esta etapa.', 'escalation'), false, ERROR);
  Html::back();
}

$ts = new PluginEscalationTicketStep();
if (!$ts->getFromDB($ticketstep_id)) {
  Session::addMessageAfterRedirect(__('Etapa não encontrada.', 'escalation'), false, ERROR);
  Html::back();
}

$ticket_id = $ticket_id > 0 ? $ticket_id : (int) ($ts->fields['tickets_id'] ?? 0);
if ($ticket_id <= 0) {
  $tf = new PluginEscalationTicketFlow();
  if ($tf->getFromDB((int) $ts->fields['plugin_escalation_ticketflows_id'])) {
    $ticket_id = (int) $tf->fields['tickets_id'];
  }
}

$ok = PluginEscalationTicketFlow::solveFromApprover($ticket_id, $users_id, $comment, true);
if ($ok) {
  Session::addMessageAfterRedirect(__('Chamado solucionado e fluxo encerrado.', 'escalation'));
} else {
  Session::addMessageAfterRedirect(__('Não foi possível solucionar. Confira se a etapa está pendente e se você é o aprovador.', 'escalation'), false, ERROR);
}

if ($ticket_id > 0) {
  Html::redirect(Ticket::getFormURLWithID($ticket_id));
}
Html::back();
