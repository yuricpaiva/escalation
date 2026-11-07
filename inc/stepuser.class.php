<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

/**
 * Usuários específicos atribuídos à definição de uma etapa.
 * Resolvidos em PluginEscalationTicketFlow::resolveApprovers() no momento do disparo.
 */
class PluginEscalationStepUser extends CommonDBTM {
  static $rightname = 'plugin_escalation_manage';

  static function getTypeName($nb = 0) {
    return _n('Usuário da Etapa', 'Usuários da Etapa', $nb, 'escalation');
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_escalation_steps_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        UNIQUE KEY `step_user` (`plugin_escalation_steps_id`, `users_id`),
        KEY `users_id` (`users_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  static function canCreate(): bool { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }
  static function canView(): bool   { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }
  static function canPurge(): bool  { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }

  static function getUsersForStep(int $step_id): array {
    global $DB;
    return iterator_to_array($DB->request([
      'FROM'  => self::getTable(),
      'WHERE' => ['plugin_escalation_steps_id' => $step_id],
    ]));
  }

  static function showForStep(PluginEscalationStep $step, int $flow_id): void {
    $step_id  = $step->getID();
    $users    = self::getUsersForStep($step_id);
    $form_url = static::getFormURL();

    if (!empty($users)) {
      echo "<table class='table table-sm table-hover mb-3'>";
      echo "<tbody>";
      foreach ($users as $u) {
        $user = new User();
        $name = $user->getFromDB($u['users_id']) ? $user->getFriendlyName() : '#' . $u['users_id'];
        echo "<tr>";
        echo "<td class='align-middle'>" . htmlspecialchars($name) . "</td>";
        echo "<td class='text-end' style='width:48px'>";
        echo "<form style='display:inline' action='$form_url' method='post'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('id',                              ['value' => $u['id']]);
        echo Html::hidden('plugin_escalation_steps_id',     ['value' => $step_id]);
        echo Html::hidden('plugin_escalation_flows_id',     ['value' => $flow_id]);
        echo "<button name='purge' type='submit' class='btn btn-sm btn-ghost-danger me-1 pe-2' title='" . __('Remover', 'escalation') . "' onclick=\"return confirm('" . __('Remover este usuário?', 'escalation') . "')\">";
        echo "<i class='ti ti-x'></i></button>";
        Html::closeForm();
        echo "</td></tr>";
      }
      echo "</tbody></table>";
    }

    // Formulário de adicionar usuário
    echo "<form class='d-flex align-items-center gap-2' action='$form_url' method='post'>";
    echo Html::hidden('_glpi_csrf_token',             ['value' => Session::getNewCSRFToken()]);
    echo Html::hidden('plugin_escalation_steps_id',   ['value' => $step_id]);
    echo Html::hidden('plugin_escalation_flows_id',   ['value' => $flow_id]);
    echo "<div class='w-100'>";
    User::dropdown([
      'name'                    => 'users_id',
      'right'                   => 'all',
      'width'                   => '100%',
      'minimumResultsForSearch' => 0,
    ]);
    echo "</div>";
    echo "<button name='add' type='submit' class='btn btn-primary'>";
    echo "<i class='ti ti-plus'></i><span class='d-none d-xxl-block'>" . __('Adicionar usuário', 'escalation') . "</span></button>";
    Html::closeForm();
  }
}
