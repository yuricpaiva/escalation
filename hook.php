<?php

function plugin_escalation_install() {
  $plugin = new Plugin();
  $plugin->getFromDBbyDir('escalation');
  $version = $plugin->fields['version'];

  $migration = new Migration($version);
  if (isCommandLine()) {
    echo __('MySQL instalação das tables', 'escalation') . "\n";
  } else {
    echo '<center>';
    echo "<table class='tab_cadre_fixe'>";
    echo '<tr><th>' . __('MySQL instalação das tables', 'escalation') . '<th></tr>';
    echo "<tr class='tab_bg_1'><td align='center'>";
  }

  $classesToInstall = [
    PluginEscalationTemplate::class,
    PluginEscalationFlow::class,
    PluginEscalationFlowCategory::class,
    PluginEscalationStep::class,
    PluginEscalationStepUser::class,
    PluginEscalationTicketFlow::class,
    PluginEscalationTicketStep::class,
    PluginEscalationApproval::class,
    PluginEscalationHierarchyLevel::class,
  ];

  foreach ($classesToInstall as $class) {
    if (method_exists($class, 'installBaseData')) {
      $class::installBaseData($migration, $version);
    }
  }
  $migration->executeMigration();

  // Migra itilcategories_id da tabela de fluxos para a tabela de junção
  PluginEscalationFlowCategory::migrateFromFlows();

  // O esquema 2.x é instalado após a migração de categorias e é estritamente aditivo.
  PluginEscalationSchema::installBaseData($migration, $version);
  foreach (PluginEscalationProfile::getAllRights() as $right) {
    if (countElementsInTable('glpi_profilerights', ['name' => $right['field']]) === 0) {
      ProfileRight::addProfileRights([$right['field']]);
    }
  }

  // Inicializa valores padrão das configurações globais do plugin
  PluginEscalationConfig::setDefaults();

  // Registra a tarefa de cron para verificar timeouts de aprovação.
  // MODE_INTERNAL = executado pelo agendador interno do GLPI (sem cron externo necessário).
  // STATE_DISABLE = desativado por padrão; o admin habilita em Configuração > Ações automáticas.
  CronTask::register(
    'PluginEscalationTicketFlow',
    'checktimeouts',
    HOUR_TIMESTAMP,
    [
      'comment' => 'Verifica timeouts de etapas e fluxos de aprovação pendentes',
      'mode'    => CronTask::MODE_INTERNAL,
      'state'   => CronTask::STATE_DISABLE,
    ]
  );
  CronTask::register(
    'PluginEscalationActionExecutor',
    'processqueue',
    MINUTE_TIMESTAMP,
    [
      'comment' => 'Executa ações idempotentes dos workflows Escalation',
      // CLI/externo: mesmo padrão de queuednotification — não depende de tráfego web.
      'mode' => CronTask::MODE_EXTERNAL,
      'state' => CronTask::STATE_WAITING,
    ]
  );

  if (!isCommandLine()) {
    echo '</td></tr></table></center>';
  }

  return true;
}

function plugin_escalation_uninstall() {
  $_SESSION['uninstall_escalation'] = true;

  echo '<center>';
  echo "<table class='tab_cadre_fixe'>";
  echo '<tr><th>' . __('MySQL desinstalação das tables', 'escalation') . '<th></tr>';
  echo "<tr class='tab_bg_1'><td align='center'>";

  $classesToUninstall = [
    'PluginEscalationHierarchyLevel',
    'PluginEscalationApproval',
    'PluginEscalationTicketStep',
    'PluginEscalationTicketFlow',
    'PluginEscalationStepUser',
    'PluginEscalationStep',
    'PluginEscalationFlowCategory',
    'PluginEscalationFlow',
    'PluginEscalationTemplate',
  ];

  foreach ($classesToUninstall as $class) {
    if ($plug = isPluginItemType($class)) {
      $dir  = PLUGINESCALATION_DIR . '/inc/';
      $item = strtolower($plug['class']);
      if (file_exists("$dir$item.class.php")) {
        include_once("$dir$item.class.php");
        if (!call_user_func([$class, 'uninstall'])) {
          return false;
        }
      }
    }
  }

  echo '</td></tr></table></center>';
  unset($_SESSION['uninstall_escalation']);

  // Remove todas as tarefas de cron registradas pelo plugin
  CronTask::unregister('escalation');

  // Remove configurações globais do plugin
  PluginEscalationConfig::removeConfig();
  foreach (PluginEscalationProfile::getAllRights() as $right) {
    ProfileRight::deleteProfileRights([$right['field']]);
  }

  $pref = new DisplayPreference();
  $pref->deleteByCriteria(['itemtype' => ['LIKE', 'PluginEscalation%']]);

  return true;
}
