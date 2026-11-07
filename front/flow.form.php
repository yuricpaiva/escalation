<?php

if (!defined('GLPI_ROOT')) {
  include ('../../../inc/includes.php');
}

$flow = new PluginEscalationFlow();

if (isset($_POST["add"])) {
  if (!PluginEscalationFlow::canCreate()) Html::displayErrorAndDie(__('Você não tem permissão para criar fluxos.', 'escalation'));
  if ($newID = $flow->add($_POST)) {
    PluginEscalationVisualEditor::applyStarterTemplate((int) $newID, (string) ($_POST['visual_template'] ?? 'simple'));
    Html::redirect($flow->getFormURL()."?id=".$newID."&forcetab=PluginEscalationFlow$1");
  }
  Html::back();
} else if (isset($_POST["update"])) {
  $flow->check($_POST["id"], UPDATE);
  $flow->update($_POST);
  Html::back();
} else if (isset($_POST["purge"])) {
  $flow->check($_POST["id"], PURGE);
  $flow->delete($_POST, 1);
  $flow->redirectToList();
}

Html::header(PluginEscalationFlow::getTypeName(Session::getPluralNumber()), $_SERVER["PHP_SELF"], "admin", "pluginescalationflow", "flow");

$flow->display([
  'id' => $_GET["id"] ?? -1
]);

Html::footer();
