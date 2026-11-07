<?php

if (!defined('GLPI_ROOT')) include('../../../inc/includes.php');
if (!PluginEscalationProfile::have(PluginEscalationProfile::MANAGE)) {
  Html::displayErrorAndDie(__('Você não tem permissão para administrar fluxos de aprovação.', 'escalation'));
}

global $DB;
$flow_id = (int) ($_POST['flow_id'] ?? 0);
$step_id = (int) ($_POST['step_id'] ?? 0);
$step = new PluginEscalationStep();
if ($flow_id <= 0 || $step_id <= 0 || !$step->getFromDB($step_id) || (int) $step->fields['plugin_escalation_flows_id'] !== $flow_id) Html::back();
$version_id = (int) ($step->fields['plugin_escalation_flowversions_id'] ?? 0);
if (!PluginEscalationFlowVersion::isDraft($version_id)) Html::displayErrorAndDie(__('Versões publicadas não podem ser alteradas.', 'escalation'));

$changed = false;
if (isset($_POST['add_source'])) {
  $source = (string) ($_POST['source_type'] ?? '');
  $allowed = array_keys(PluginEscalationAdmin::sourceLabels());
  $item_id = $source === 'user' ? (int) ($_POST['source_users_id'] ?? 0) : ($source === 'group' ? (int) ($_POST['source_groups_id'] ?? 0) : 0);
  if (in_array($source, $allowed, true) && (!in_array($source, ['user','group'], true) || $item_id > 0)) {
    $DB->doQuery(sprintf(
      "INSERT IGNORE INTO `glpi_plugin_escalation_stepapprovers` (`plugin_escalation_steps_id`,`source_type`,`items_id`) VALUES (%d,%s,%d)",
      $step_id, $DB->quoteValue($source), $item_id
    ));
    $changed = true;
  } else {
    Session::addMessageAfterRedirect(__('Escolha uma pessoa, um grupo ou outra forma válida de encontrar os aprovadores.', 'escalation'), false, ERROR);
  }
} elseif (isset($_POST['delete_source'])) {
  $DB->delete('glpi_plugin_escalation_stepapprovers', ['id' => (int) ($_POST['source_id'] ?? 0), 'plugin_escalation_steps_id' => $step_id]);
  $changed = true;
} elseif (isset($_POST['save_transition'])) {
  $outcome = (string) ($_POST['outcome'] ?? '');
  $allowed_outcomes = ($step->fields['step_kind'] ?? 'approval') === 'action' ? ['success','failure'] : ['approved','rejected','expired','approver_missing'];
  $destination = (string) ($_POST['destination'] ?? 'default');
  $DB->delete('glpi_plugin_escalation_transitions', ['plugin_escalation_steps_id' => $step_id, 'outcome' => $outcome]);
  if (in_array($outcome, $allowed_outcomes, true) && $destination !== 'default') {
    $target_id = 0; $terminal = null;
    if (str_starts_with($destination, 'step:')) {
      $target_id = (int) substr($destination, 5);
      $target = $DB->request(['FROM' => PluginEscalationStep::getTable(), 'WHERE' => ['id' => $target_id, 'plugin_escalation_flowversions_id' => $version_id], 'LIMIT' => 1])->current();
      if (!$target || (int) $target['step_order'] <= (int) $step->fields['step_order']) Html::displayErrorAndDie(__('Escolha uma etapa posterior.', 'escalation'));
    } elseif (str_starts_with($destination, 'terminal:')) {
      $terminal = substr($destination, 9);
      if (!in_array($terminal, ['approved','rejected','expired','failed','pending_configuration'], true)) Html::displayErrorAndDie(__('Escolha um encerramento válido.', 'escalation'));
    }
    $DB->insert('glpi_plugin_escalation_transitions', ['plugin_escalation_steps_id' => $step_id, 'outcome' => $outcome, 'target_steps_id' => $target_id, 'terminal_state' => $terminal]);
  }
  $changed = true;
}

if ($changed) PluginEscalationFlowVersion::touch($version_id);
Html::redirect(PluginEscalationFlow::getFormURL() . '?id=' . $flow_id . '&forcetab=PluginEscalationFlow$3');
