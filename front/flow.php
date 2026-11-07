<?php

if (!defined('GLPI_ROOT')) {
  include ('../../../inc/includes.php');
}

if (!PluginEscalationFlow::canView()) Html::displayErrorAndDie(__('Você não tem permissão para visualizar workflows.', 'escalation'));

Html::header(PluginEscalationFlow::getTypeName(Session::getPluralNumber()), $_SERVER["PHP_SELF"], "admin", "pluginescalationflow", "flow");

Search::show('PluginEscalationFlow');

Html::footer();
