<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

if (!PluginEscalationProfile::have(PluginEscalationProfile::MANAGE)) Html::displayErrorAndDie(__('Você não tem permissão para administrar workflows.', 'escalation'));

$step    = new PluginEscalationStep();
$flow_id = (int) ($_POST['plugin_escalation_flows_id'] ?? $_GET['plugin_escalation_flows_id'] ?? 0);
$step_id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($step_id > 0 && $step->getFromDB($step_id) && !PluginEscalationFlowVersion::isDraft((int) ($step->fields['plugin_escalation_flowversions_id'] ?? 0))) {
  Html::displayErrorAndDie(__('Versões publicadas são imutáveis. Edite o rascunho atual.', 'escalation'));
}

// ---- Adicionar etapa -------------------------------------------------------
if (isset($_POST['add'])) {
  if (!PluginEscalationStep::canCreate()) Html::displayErrorAndDie(__('Você não tem permissão para criar etapas.', 'escalation'));

  $order = PluginEscalationStep::getNextOrder($flow_id);
  $input = [
    'plugin_escalation_flows_id'     => $flow_id,
    'plugin_escalation_flowversions_id' => PluginEscalationFlowVersion::ensureDraft($flow_id),
    'name'                           => $_POST['name'],
    'type'                           => in_array($_POST['type'] ?? '', ['people', 'hierarchy']) ? $_POST['type'] : 'people',
    'step_order'                     => $order,
    'timeout_days'                   => (int) ($_POST['timeout_days'] ?? 0),
    'hierarchy_level'                => max(1, (int) ($_POST['hierarchy_level'] ?? 1)),
    'auto_hierarchy_dynamic'         => (int)($_POST['auto_hierarchy_dynamic'] ?? 0),
    'generate_immediately'           => (int)($_POST['generate_immediately'] ?? 0),
    'quorum_type'                    => $_POST['quorum_type'] ?? PluginEscalationStep::QUORUM_MIN1,
    'include_requesters'             => in_array('requesters', (array)($_POST['sources'] ?? [])) ? 1 : 0,
    'include_assigned'               => in_array('assigned',   (array)($_POST['sources'] ?? [])) ? 1 : 0,
    'include_observers'              => in_array('observers',  (array)($_POST['sources'] ?? [])) ? 1 : 0,
    'plugin_escalation_templates_id' => (int) ($_POST['plugin_escalation_templates_id'] ?? 0),
    'content'                        => $_POST['content'] ?? '',
    'prevent_self_approval'          => (int)($_POST['prevent_self_approval'] ?? 1),
    'fallback_users_id'              => (int)($_POST['fallback_users_id'] ?? 0),
  ];

  if ($new_id = $step->add($input)) {
    PluginEscalationFlowVersion::touch((int) $input['plugin_escalation_flowversions_id']);
    Html::redirect(PluginEscalationFlow::getFormURL() . "?id=$flow_id&forcetab=PluginEscalationFlow$3");
  }
  Html::back();
}

// ---- Atualizar etapa -------------------------------------------------------
if (isset($_POST['update'])) {
  $step->check($step_id, UPDATE);
  $step->update([
    'id'                             => $step_id,
    'name'                           => $_POST['name'],
    'type'                           => in_array($_POST['type'] ?? '', ['people', 'hierarchy']) ? $_POST['type'] : 'people',
    'timeout_days'                   => (int) ($_POST['timeout_days'] ?? 0),
    'hierarchy_level'                => max(1, (int) ($_POST['hierarchy_level'] ?? 1)),
    'auto_hierarchy_dynamic'         => (int)($_POST['auto_hierarchy_dynamic'] ?? 0),
    'generate_immediately'           => (int)($_POST['generate_immediately'] ?? 0),
    'quorum_type'                    => $_POST['quorum_type'] ?? PluginEscalationStep::QUORUM_MIN1,
    'include_requesters'             => in_array('requesters', (array)($_POST['sources'] ?? [])) ? 1 : 0,
    'include_assigned'               => in_array('assigned',   (array)($_POST['sources'] ?? [])) ? 1 : 0,
    'include_observers'              => in_array('observers',  (array)($_POST['sources'] ?? [])) ? 1 : 0,
    'plugin_escalation_templates_id' => (int) ($_POST['plugin_escalation_templates_id'] ?? 0),
    'content'                        => $_POST['content'] ?? '',
    'prevent_self_approval'          => (int)($_POST['prevent_self_approval'] ?? 1),
    'fallback_users_id'              => (int)($_POST['fallback_users_id'] ?? 0),
  ]);
  PluginEscalationFlowVersion::touch((int) $step->fields['plugin_escalation_flowversions_id']);
  Html::back();
}

// ---- Excluir etapa ---------------------------------------------------------
if (isset($_POST['purge'])) {
  $version_id = (int) ($step->fields['plugin_escalation_flowversions_id'] ?? 0);
  $step->check($step_id, PURGE);
  $step->delete(['id' => $step_id], 1);
  PluginEscalationFlowVersion::touch($version_id);
  Html::redirect(PluginEscalationFlow::getFormURL() . "?id=$flow_id&forcetab=PluginEscalationFlow$3");
}

// ---- Mover para cima -------------------------------------------------------
if (isset($_POST['move_up'])) {
  $step->getFromDB($step_id);
  $adjacent = PluginEscalationStep::getAdjacentStep($step_id, 'up');
  if ($adjacent) {
    PluginEscalationStep::swapOrder($step_id, $adjacent['id']);
    PluginEscalationFlowVersion::touch((int) $step->fields['plugin_escalation_flowversions_id']);
  }
  Html::redirect(PluginEscalationFlow::getFormURL() . "?id=$flow_id&forcetab=PluginEscalationFlow$3");
}

// ---- Mover para baixo ------------------------------------------------------
if (isset($_POST['move_down'])) {
  $step->getFromDB($step_id);
  $adjacent = PluginEscalationStep::getAdjacentStep($step_id, 'down');
  if ($adjacent) {
    PluginEscalationStep::swapOrder($step_id, $adjacent['id']);
    PluginEscalationFlowVersion::touch((int) $step->fields['plugin_escalation_flowversions_id']);
  }
  Html::redirect(PluginEscalationFlow::getFormURL() . "?id=$flow_id&forcetab=PluginEscalationFlow$3");
}

// ---- Exibir formulário de edição -------------------------------------------
Html::header(PluginEscalationStep::getTypeName(1), $_SERVER['PHP_SELF'], "admin", "pluginescalationflow", "flow");
$step->display(['id' => $step_id]);
Html::footer();
