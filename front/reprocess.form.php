<?php
if(!defined('GLPI_ROOT'))include('../../../inc/includes.php');
Session::checkLoginUser();
$run_id=(int)($_POST['run_id']??0);$ticket_id=(int)($_POST['ticket_id']??0);
if($run_id&&PluginEscalationActionExecutor::requeue($run_id))Session::addMessageAfterRedirect(__('Ação recolocada na fila.','escalation'));else Session::addMessageAfterRedirect(__('Ação não pode ser reprocessada.','escalation'),false,ERROR);
Html::redirect(Ticket::getFormURLWithID($ticket_id));
