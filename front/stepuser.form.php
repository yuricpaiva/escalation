<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

if (!PluginEscalationProfile::have(PluginEscalationProfile::MANAGE)) Html::displayErrorAndDie(__('Você não tem permissão para administrar workflows.', 'escalation'));

$step_id = (int) ($_POST['plugin_escalation_steps_id'] ?? 0);
$flow_id = (int) ($_POST['plugin_escalation_flows_id'] ?? 0);
$step = new PluginEscalationStep();
if (!$step->getFromDB($step_id) || (int) $step->fields['plugin_escalation_flows_id'] !== $flow_id || !PluginEscalationFlowVersion::isDraft((int) $step->fields['plugin_escalation_flowversions_id'])) {
  Html::displayErrorAndDie(__('A etapa não pertence ao rascunho atual.', 'escalation'));
}

// ---- Adicionar usuário específico ------------------------------------------
if (isset($_POST['add'])) {
  $users_id = (int) ($_POST['users_id'] ?? 0);
  if ($users_id > 0 && $step_id > 0) {
    $su = new PluginEscalationStepUser();
    $su->add(['plugin_escalation_steps_id' => $step_id, 'users_id' => $users_id]);
  }
  Html::redirect(PluginEscalationStep::getFormURL() . "?id=$step_id");
}

// ---- Remover usuário específico --------------------------------------------
if (isset($_POST['purge'])) {
  $id = (int) ($_POST['id'] ?? 0);
  if ($id > 0) {
    $su = new PluginEscalationStepUser();
    if ($su->getFromDB($id) && (int) $su->fields['plugin_escalation_steps_id'] === $step_id) $su->delete(['id' => $id], 1);
  }
  Html::redirect(PluginEscalationStep::getFormURL() . "?id=$step_id");
}

Html::back();
