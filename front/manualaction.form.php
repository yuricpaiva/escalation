<?php

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkCSRF($_POST);

$ticket_id = (int) ($_POST['tickets_id'] ?? 0);
$ticketstep_id = (int) ($_POST['ticketstep_id'] ?? 0);
$users_id = (int) Session::getLoginUserID();

if (empty($_POST['trigger']) || !PluginEscalationTicketFlow::triggerManualAction($ticketstep_id, $ticket_id, $users_id)) {
  Session::addMessageAfterRedirect(__('A atividade não está mais disponível ou você não pode executá-la.', 'escalation'), false, ERROR);
} else {
  Session::addMessageAfterRedirect(__('Atividade adicionada com sucesso.', 'escalation'), false, INFO);
}

Html::redirect(Ticket::getFormURLWithID($ticket_id));
