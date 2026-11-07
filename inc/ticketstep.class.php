<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/**
 * Estado de uma etapa do fluxo para um chamado específico.
 */
class PluginEscalationTicketStep extends CommonDBTM {
  static $rightname = 'config';

  const STATUS_WAITING   = 0; // Etapa anterior ainda não concluída
  const STATUS_PENDING   = 1; // Ativa, aguardando aprovações
  const STATUS_APPROVED  = 2;
  const STATUS_REJECTED  = 3;
  const STATUS_TIMED_OUT = 4;
  const STATUS_SKIPPED   = 5; // Outro começo entrou mais adiante; esta etapa não abre

  static function getTypeName($nb = 0) {
    return _n('Etapa do Chamado', 'Etapas do Chamado', $nb, 'escalation');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `plugin_escalation_ticketflows_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `plugin_escalation_steps_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `status` tinyint(1) NOT NULL DEFAULT '0',
        `started_at` timestamp NULL DEFAULT NULL,
        `completed_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `tickets_id` (`tickets_id`),
        KEY `plugin_escalation_ticketflows_id` (`plugin_escalation_ticketflows_id`),
        KEY `status` (`status`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }

    // Tabela auxiliar: aprovadores resolvidos por etapa-chamado
    if (!$DB->tableExists('glpi_plugin_escalation_ticketstepapprovers')) {
      $DB->doQuery("CREATE TABLE `glpi_plugin_escalation_ticketstepapprovers` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_escalation_ticketsteps_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        UNIQUE KEY `step_approver` (`plugin_escalation_ticketsteps_id`, `users_id`),
        KEY `users_id` (`users_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable('glpi_plugin_escalation_ticketstepapprovers');
    $DB->dropTable(self::getTable());
    return true;
  }

  static function canCreate(): bool { return Session::haveRight(self::$rightname, UPDATE); }
  static function canView(): bool   { return true; }
  static function canUpdate(): bool { return Session::haveRight(self::$rightname, UPDATE); }

  static function getStatusLabel(int $status): string {
    $labels = [
      self::STATUS_WAITING   => '<span style="color:#9ca3af">— ' . __('Aguardando', 'escalation') . '</span>',
      self::STATUS_PENDING   => '<span style="color:#d97706">⏳ ' . __('Pendente', 'escalation') . '</span>',
      self::STATUS_APPROVED  => '<span style="color:#16a34a">✅ ' . __('Aprovado', 'escalation') . '</span>',
      self::STATUS_REJECTED  => '<span style="color:#dc2626">❌ ' . __('Reprovado', 'escalation') . '</span>',
      self::STATUS_TIMED_OUT => '<span style="color:#6b7280">⏰ ' . __('Reprovação automática', 'escalation') . '</span>',
      self::STATUS_SKIPPED   => '<span style="color:#9ca3af">— ' . __('Não percorrida', 'escalation') . '</span>',
    ];
    return $labels[$status] ?? (string) $status;
  }
}
