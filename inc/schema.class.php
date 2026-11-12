<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/** Additive, idempotent schema and legacy-data migration for the 2.x engine. */
final class PluginEscalationSchema {
  public static function installBaseData(Migration $migration, $version): void {
    global $DB;
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign = DBConnection::getDefaultPrimaryKeySignOption();

    self::field(PluginEscalationFlow::getTable(), 'description', "text COLLATE $collation");
    self::field(PluginEscalationFlow::getTable(), 'entities_id', "int $sign NOT NULL DEFAULT '0'");
    self::field(PluginEscalationFlow::getTable(), 'is_recursive', "tinyint(1) NOT NULL DEFAULT '0'");
    self::field(PluginEscalationFlow::getTable(), 'priority', "int NOT NULL DEFAULT '0'");
    self::field(PluginEscalationStep::getTable(), 'plugin_escalation_flowversions_id', "int $sign NOT NULL DEFAULT '0'");
    self::field(PluginEscalationStep::getTable(), 'step_kind', "varchar(24) COLLATE $collation NOT NULL DEFAULT 'approval'");
    self::field(PluginEscalationStep::getTable(), 'prevent_self_approval', "tinyint(1) NOT NULL DEFAULT '1'");
    self::field(PluginEscalationStep::getTable(), 'allow_solve', "tinyint(1) NOT NULL DEFAULT '0'");
    self::field(PluginEscalationStep::getTable(), 'activation_mode', "varchar(16) COLLATE $collation NOT NULL DEFAULT 'automatic'");
    self::field(PluginEscalationStep::getTable(), 'fallback_users_id', "int $sign NOT NULL DEFAULT '0'");
    self::field(PluginEscalationTicketFlow::getTable(), 'engine_version', "tinyint NOT NULL DEFAULT '1'");
    self::field(PluginEscalationTicketFlow::getTable(), 'plugin_escalation_flowversions_id', "int $sign NOT NULL DEFAULT '0'");
    self::field(PluginEscalationTicketFlow::getTable(), 'state', "varchar(32) COLLATE $collation NOT NULL DEFAULT 'running'");
    self::field(PluginEscalationTicketFlow::getTable(), 'decision_status', "varchar(24) COLLATE $collation NOT NULL DEFAULT 'pending'");
    self::field(PluginEscalationTicketFlow::getTable(), 'automation_status', "varchar(24) COLLATE $collation NOT NULL DEFAULT 'idle'");
    self::field(PluginEscalationTicketFlow::getTable(), 'completed_at', 'timestamp NULL DEFAULT NULL');
    self::field(PluginEscalationTicketStep::getTable(), 'outcome', "varchar(32) COLLATE $collation DEFAULT NULL");
    self::field(PluginEscalationTicketStep::getTable(), 'due_at', 'timestamp NULL DEFAULT NULL');
    self::field(PluginEscalationTicketStep::getTable(), 'approver_snapshot', "longtext COLLATE $collation");

    $tables = [
      'glpi_plugin_escalation_flowversions' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_flows_id` int $sign NOT NULL DEFAULT '0',
        `version` int NOT NULL DEFAULT '1',
        `status` varchar(16) COLLATE $collation NOT NULL DEFAULT 'draft',
        `name` varchar(255) COLLATE $collation NOT NULL DEFAULT '',
        `description` text COLLATE $collation,
        `entities_id` int $sign NOT NULL DEFAULT '0',
        `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
        `priority` int NOT NULL DEFAULT '0',
        `published_at` timestamp NULL DEFAULT NULL,
        `date_creation` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `flow_version` (`plugin_escalation_flows_id`,`version`),
        KEY `status` (`status`)",
      'glpi_plugin_escalation_conditiongroups' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_flowversions_id` int $sign NOT NULL DEFAULT '0',
        `group_order` int NOT NULL DEFAULT '1',
        PRIMARY KEY (`id`), KEY `version` (`plugin_escalation_flowversions_id`)",
      'glpi_plugin_escalation_conditions' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_conditiongroups_id` int $sign NOT NULL DEFAULT '0',
        `field` varchar(48) COLLATE $collation NOT NULL,
        `operator` varchar(24) COLLATE $collation NOT NULL DEFAULT 'equals',
        `value` text COLLATE $collation,
        `condition_order` int NOT NULL DEFAULT '1',
        PRIMARY KEY (`id`), KEY `condition_group` (`plugin_escalation_conditiongroups_id`)",
      'glpi_plugin_escalation_stepapprovers' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_steps_id` int $sign NOT NULL DEFAULT '0',
        `source_type` varchar(32) COLLATE $collation NOT NULL,
        `items_id` int $sign NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`), UNIQUE KEY `step_source` (`plugin_escalation_steps_id`,`source_type`,`items_id`)",
      'glpi_plugin_escalation_transitions' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_steps_id` int $sign NOT NULL DEFAULT '0',
        `outcome` varchar(32) COLLATE $collation NOT NULL,
        `target_steps_id` int $sign NOT NULL DEFAULT '0',
        `terminal_state` varchar(32) COLLATE $collation DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `step_outcome` (`plugin_escalation_steps_id`,`outcome`)",
      'glpi_plugin_escalation_actions' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_flowversions_id` int $sign NOT NULL DEFAULT '0',
        `plugin_escalation_steps_id` int $sign NOT NULL DEFAULT '0',
        `name` varchar(255) COLLATE $collation NOT NULL,
        `event` varchar(32) COLLATE $collation NOT NULL,
        `type` varchar(32) COLLATE $collation NOT NULL,
        `parameters` longtext COLLATE $collation,
        `action_order` int NOT NULL DEFAULT '1',
        `error_policy` varchar(16) COLLATE $collation NOT NULL DEFAULT 'retry',
        `max_attempts` int NOT NULL DEFAULT '3',
        PRIMARY KEY (`id`), KEY `version_event` (`plugin_escalation_flowversions_id`,`event`)",
      'glpi_plugin_escalation_validationlinks' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_ticketsteps_id` int $sign NOT NULL DEFAULT '0',
        `ticketvalidations_id` int $sign NOT NULL DEFAULT '0',
        `users_id` int $sign NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`), UNIQUE KEY `validation` (`ticketvalidations_id`),
        UNIQUE KEY `step_user` (`plugin_escalation_ticketsteps_id`,`users_id`)",
      'glpi_plugin_escalation_executionlocks' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_flows_id` int $sign NOT NULL DEFAULT '0',
        `tickets_id` int $sign NOT NULL DEFAULT '0',
        `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `flow_ticket` (`plugin_escalation_flows_id`,`tickets_id`)",
      'glpi_plugin_escalation_actionruns' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_ticketflows_id` int $sign NOT NULL DEFAULT '0',
        `plugin_escalation_actions_id` int $sign NOT NULL DEFAULT '0',
        `event_key` varchar(191) COLLATE $collation NOT NULL,
        `status` varchar(16) COLLATE $collation NOT NULL DEFAULT 'queued',
        `attempts` int NOT NULL DEFAULT '0',
        `next_attempt_at` timestamp NULL DEFAULT NULL,
        `last_error` text COLLATE $collation,
        `result` longtext COLLATE $collation,
        `date_creation` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `idempotency` (`plugin_escalation_ticketflows_id`,`plugin_escalation_actions_id`,`event_key`),
        KEY `queue` (`status`,`next_attempt_at`)",
      'glpi_plugin_escalation_audits' => "
        `id` bigint $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_ticketflows_id` int $sign NOT NULL DEFAULT '0',
        `tickets_id` int $sign NOT NULL DEFAULT '0',
        `users_id` int $sign NOT NULL DEFAULT '0',
        `event` varchar(48) COLLATE $collation NOT NULL,
        `payload` longtext COLLATE $collation,
        `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `execution` (`plugin_escalation_ticketflows_id`,`date_creation`)",
      'glpi_plugin_escalation_visuallayouts' => "
        `id` int $sign NOT NULL AUTO_INCREMENT,
        `plugin_escalation_flowversions_id` int $sign NOT NULL DEFAULT '0',
        `layout` longtext COLLATE $collation,
        `revision` int NOT NULL DEFAULT '1',
        `date_creation` timestamp NULL DEFAULT NULL,
        `date_mod` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `version` (`plugin_escalation_flowversions_id`)",
    ];
    foreach ($tables as $table => $definition) {
      if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` ($definition) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation");
      }
    }
    self::field('glpi_plugin_escalation_flowversions', 'name', "varchar(255) COLLATE $collation NOT NULL DEFAULT ''");
    self::field('glpi_plugin_escalation_flowversions', 'description', "text COLLATE $collation");
    self::field('glpi_plugin_escalation_flowversions', 'entities_id', "int $sign NOT NULL DEFAULT '0'");
    self::field('glpi_plugin_escalation_flowversions', 'is_recursive', "tinyint(1) NOT NULL DEFAULT '0'");
    self::field('glpi_plugin_escalation_flowversions', 'priority', "int NOT NULL DEFAULT '0'");
    self::field('glpi_plugin_escalation_conditiongroups', 'entry_steps_id', "int $sign NOT NULL DEFAULT '0'");
    self::migrateLegacy();
  }

  private static function field(string $table, string $field, string $definition): void {
    global $DB;
    if ($DB->tableExists($table) && !$DB->fieldExists($table, $field)) {
      $DB->doQuery("ALTER TABLE `$table` ADD COLUMN `$field` $definition");
    }
  }

  private static function migrateLegacy(): void {
    global $DB;
    $flows = PluginEscalationFlow::getTable();
    $steps = PluginEscalationStep::getTable();
    foreach ($DB->request(['FROM' => $flows]) as $flow) {
      $version = $DB->request([
        'FROM' => 'glpi_plugin_escalation_flowversions',
        'WHERE' => ['plugin_escalation_flows_id' => $flow['id'], 'version' => 1],
        'LIMIT' => 1,
      ])->current();
      if (!$version) {
        $DB->insert('glpi_plugin_escalation_flowversions', [
          'plugin_escalation_flows_id' => $flow['id'], 'version' => 1,
          'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
          'name' => $flow['name'] ?? '', 'description' => $flow['description'] ?? '',
          'entities_id' => (int) ($flow['entities_id'] ?? 0),
          'is_recursive' => (int) ($flow['is_recursive'] ?? 0),
          'priority' => (int) ($flow['priority'] ?? 0),
          'date_creation' => date('Y-m-d H:i:s'), 'date_mod' => date('Y-m-d H:i:s'),
        ]);
        $version_id = (int) $DB->insertId();
      } else {
        $version_id = (int) $version['id'];
        if ($version['name'] === '') {
          $DB->update('glpi_plugin_escalation_flowversions', [
            'name' => $flow['name'] ?? '', 'description' => $flow['description'] ?? '',
            'entities_id' => (int) ($flow['entities_id'] ?? 0),
            'is_recursive' => (int) ($flow['is_recursive'] ?? 0),
            'priority' => (int) ($flow['priority'] ?? 0),
          ], ['id' => $version_id]);
        }
      }
      $DB->update($steps, ['plugin_escalation_flowversions_id' => $version_id], [
        'plugin_escalation_flows_id' => $flow['id'], 'plugin_escalation_flowversions_id' => 0,
      ]);
      if (countElementsInTable('glpi_plugin_escalation_conditiongroups', ['plugin_escalation_flowversions_id' => $version_id]) === 0) {
        $DB->insert('glpi_plugin_escalation_conditiongroups', ['plugin_escalation_flowversions_id' => $version_id, 'group_order' => 1]);
        $group_id = (int) $DB->insertId();
        foreach (PluginEscalationFlowCategory::getForFlow((int) $flow['id']) as $category_id) {
          $DB->insert('glpi_plugin_escalation_conditions', [
            'plugin_escalation_conditiongroups_id' => $group_id, 'field' => 'category',
            'operator' => 'equals', 'value' => (string) $category_id, 'condition_order' => 1,
          ]);
        }
      }
    }
    foreach ($DB->request(['FROM' => PluginEscalationTicketFlow::getTable()]) as $execution) {
      $DB->doQuery(sprintf(
        "INSERT IGNORE INTO `glpi_plugin_escalation_executionlocks` (`plugin_escalation_flows_id`,`tickets_id`,`date_creation`) VALUES (%d,%d,NOW())",
        (int) $execution['plugin_escalation_flows_id'],
        (int) $execution['tickets_id']
      ));
    }
    self::linkLegacyValidations();
  }

  private static function linkLegacyValidations(): void {
    global $DB;
    $validations = TicketValidation::getTable();
    if (!$DB->tableExists($validations) || !$DB->fieldExists($validations, 'itemtype_target')) return;
    foreach ($DB->request([
      'SELECT' => ['tsa.plugin_escalation_ticketsteps_id', 'tsa.users_id', 'ts.tickets_id', 'ts.plugin_escalation_ticketflows_id'],
      'FROM' => 'glpi_plugin_escalation_ticketstepapprovers AS tsa',
      'INNER JOIN' => [PluginEscalationTicketStep::getTable() . ' AS ts' => ['ON' => ['tsa' => 'plugin_escalation_ticketsteps_id', 'ts' => 'id']]],
    ]) as $approver) {
      if (countElementsInTable('glpi_plugin_escalation_validationlinks', ['plugin_escalation_ticketsteps_id' => $approver['plugin_escalation_ticketsteps_id'], 'users_id' => $approver['users_id']]) > 0) continue;
      $matches = iterator_to_array($DB->request([
        'SELECT' => ['id'], 'FROM' => $validations,
        'WHERE' => ['tickets_id' => $approver['tickets_id'], 'itemtype_target' => User::class, 'items_id_target' => $approver['users_id']],
      ]));
      if (count($matches) === 1) {
        $DB->doQuery(sprintf(
          "INSERT IGNORE INTO `glpi_plugin_escalation_validationlinks` (`plugin_escalation_ticketsteps_id`,`ticketvalidations_id`,`users_id`) VALUES (%d,%d,%d)",
          (int) $approver['plugin_escalation_ticketsteps_id'], (int) $matches[0]['id'], (int) $approver['users_id']
        ));
      } elseif (count($matches) > 1) {
        $execution_id = (int) $approver['plugin_escalation_ticketflows_id'];
        $DB->update(PluginEscalationTicketFlow::getTable(), ['state' => 'pending_configuration'], ['id' => $execution_id]);
        if (countElementsInTable('glpi_plugin_escalation_audits', ['plugin_escalation_ticketflows_id' => $execution_id, 'event' => 'migration.validation_ambiguous']) === 0) {
          $DB->insert('glpi_plugin_escalation_audits', [
            'plugin_escalation_ticketflows_id' => $execution_id, 'tickets_id' => (int) $approver['tickets_id'],
            'users_id' => 0, 'event' => 'migration.validation_ambiguous',
            'payload' => json_encode(['ticket_step_id' => (int) $approver['plugin_escalation_ticketsteps_id'], 'user_id' => (int) $approver['users_id'], 'matches' => count($matches)]),
            'date_creation' => date('Y-m-d H:i:s'),
          ]);
        }
      }
    }
  }
}
