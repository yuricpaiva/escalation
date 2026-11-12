<?php

define('PLUGIN_ESCALATION_VERSION', '2.2.0');

define('PLUGIN_ESCALATION_MIN_GLPI', '11.0.0');
define('PLUGIN_ESCALATION_MAX_GLPI', '12.0.0');

if (!defined('PLUGINESCALATION_DIR')) {
  define('PLUGINESCALATION_DIR', Plugin::getPhpDir('escalation'));
}

function plugin_init_escalation() {
  global $PLUGIN_HOOKS;

  $PLUGIN_HOOKS['csrf_compliant']['escalation'] = true;

  $PLUGIN_HOOKS['config_page']['escalation'] = 'front/flow.php';
  $PLUGIN_HOOKS['menu_toadd']['escalation'] = ['admin' => 'PluginEscalationFlow'];

  $request_uri = $_SERVER['REQUEST_URI'] ?? '';
  if (str_contains($request_uri, '/plugins/escalation/front/flow.form.php')) {
    $PLUGIN_HOOKS['add_javascript_module']['escalation'][] = 'build/visual-editor-2.2.0.js';
    $PLUGIN_HOOKS['add_css']['escalation'][] = 'build/visual-editor-2.2.0.css';
  }
  if (str_contains($request_uri, '/front/ticket.form.php')) {
    $PLUGIN_HOOKS['add_javascript']['escalation'][] = 'js/solve-button.js';
  }

  $PLUGIN_HOOKS['item_add']['escalation']        = [
    'Ticket'       => ['PluginEscalationFlow', 'ticketAdd'],
    'ITILSolution' => ['PluginEscalationTicketFlow', 'solutionAdded'],
  ];
  $PLUGIN_HOOKS['pre_item_update']['escalation'] = ['Ticket' => ['PluginEscalationFlow', 'preTicketUpdate']];
  $PLUGIN_HOOKS['item_update']['escalation']     = [
    'Ticket'           => ['PluginEscalationFlow', 'ticketUpdate'],
    'TicketValidation' => ['PluginEscalationTicketFlow', 'validationUpdated'],
  ];
  $PLUGIN_HOOKS['pre_item_form']['escalation'] = ['PluginEscalationTicketFlow', 'preValidationForm'];

  // Adiciona aba "Aprovação" na tela do chamado
  Plugin::registerClass('PluginEscalationTicketFlow', ['addtabon' => 'Ticket']);

  // Adiciona aba "Escalation" em Configuração > Geral
  Plugin::registerClass('PluginEscalationConfig', ['addtabon' => 'Config']);
  Plugin::registerClass('PluginEscalationProfile', ['addtabon' => 'Profile']);

  $PLUGIN_HOOKS['item_get_events']['escalation'] = [
    'NotificationTargetTicket' => ['PluginEscalationFlow', 'notificationEvents'],
  ];
}

function plugin_version_escalation() {
  return [
    'name'         => 'Escalation',
    'version'      => PLUGIN_ESCALATION_VERSION,
    'author'       => 'Ampris',
    'homepage'     => 'https://github.com/plss-github/escalation',
    'license'      => 'GPLv2+',
    'requirements' => [
      'glpi' => [
        'min' => PLUGIN_ESCALATION_MIN_GLPI,
        'max' => PLUGIN_ESCALATION_MAX_GLPI,
      ],
      'php' => ['min' => '8.2'],
    ],
  ];
}

function plugin_escalation_check_prerequisites() {
  return version_compare(PHP_VERSION, '8.2.0', '>=')
    && version_compare(GLPI_VERSION, PLUGIN_ESCALATION_MIN_GLPI, '>=')
    && version_compare(GLPI_VERSION, PLUGIN_ESCALATION_MAX_GLPI, '<');
}
