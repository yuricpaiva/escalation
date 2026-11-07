<?php
if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

final class PluginEscalationFlowVersion {
  public static function isDraft(int $version_id): bool {
    global $DB;
    return $version_id > 0 && countElementsInTable('glpi_plugin_escalation_flowversions', ['id' => $version_id, 'status' => 'draft']) === 1;
  }

  public static function ensureDraft(int $flow_id): int {
    global $DB;
    $draft = $DB->request(['FROM' => 'glpi_plugin_escalation_flowversions', 'WHERE' => ['plugin_escalation_flows_id' => $flow_id, 'status' => 'draft'], 'ORDERBY' => ['version DESC'], 'LIMIT' => 1])->current();
    if ($draft) return (int) $draft['id'];
    $source = $DB->request(['FROM' => 'glpi_plugin_escalation_flowversions', 'WHERE' => ['plugin_escalation_flows_id' => $flow_id, 'status' => 'published'], 'ORDERBY' => ['version DESC'], 'LIMIT' => 1])->current();
    $flow = $DB->request(['FROM' => PluginEscalationFlow::getTable(), 'WHERE' => ['id' => $flow_id], 'LIMIT' => 1])->current();
    $next = $source ? (int) $source['version'] + 1 : 1;
    $snapshot = $source ?: $flow;
    $DB->insert('glpi_plugin_escalation_flowversions', [
      'plugin_escalation_flows_id' => $flow_id, 'version' => $next, 'status' => 'draft',
      'name' => $snapshot['name'] ?? '', 'description' => $snapshot['description'] ?? '',
      'entities_id' => (int) ($snapshot['entities_id'] ?? 0),
      'is_recursive' => (int) ($snapshot['is_recursive'] ?? 0),
      'priority' => (int) ($snapshot['priority'] ?? 0),
      'date_creation' => date('Y-m-d H:i:s'), 'date_mod' => date('Y-m-d H:i:s'),
    ]);
    $draft_id = (int) $DB->insertId();
    if (!$source) return $draft_id;

    $group_entries = [];
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_conditiongroups', 'WHERE' => ['plugin_escalation_flowversions_id' => $source['id']]]) as $group) {
      $DB->insert('glpi_plugin_escalation_conditiongroups', [
        'plugin_escalation_flowversions_id' => $draft_id,
        'group_order' => $group['group_order'],
        'entry_steps_id' => 0,
      ]);
      $new_group = (int) $DB->insertId();
      $group_entries[$new_group] = (int) ($group['entry_steps_id'] ?? 0);
      foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_conditions', 'WHERE' => ['plugin_escalation_conditiongroups_id' => $group['id']]]) as $condition) {
        unset($condition['id']); $condition['plugin_escalation_conditiongroups_id'] = $new_group; $DB->insert('glpi_plugin_escalation_conditions', $condition);
      }
    }
    $map = [];
    foreach ($DB->request(['FROM' => PluginEscalationStep::getTable(), 'WHERE' => ['plugin_escalation_flowversions_id' => $source['id']], 'ORDERBY' => ['step_order ASC']]) as $step) {
      $old = (int) $step['id']; unset($step['id']); $step['plugin_escalation_flowversions_id'] = $draft_id; $DB->insert(PluginEscalationStep::getTable(), $step); $map[$old] = (int) $DB->insertId();
      foreach ($DB->request(['FROM' => PluginEscalationStepUser::getTable(), 'WHERE' => ['plugin_escalation_steps_id' => $old]]) as $user) { unset($user['id']); $user['plugin_escalation_steps_id'] = $map[$old]; $DB->insert(PluginEscalationStepUser::getTable(), $user); }
      foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_stepapprovers', 'WHERE' => ['plugin_escalation_steps_id' => $old]]) as $approver) { unset($approver['id']); $approver['plugin_escalation_steps_id'] = $map[$old]; $DB->insert('glpi_plugin_escalation_stepapprovers', $approver); }
    }
    foreach ($group_entries as $new_group => $old_step) {
      $mapped = $map[$old_step] ?? 0;
      if ($mapped > 0) $DB->update('glpi_plugin_escalation_conditiongroups', ['entry_steps_id' => $mapped], ['id' => $new_group]);
    }
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_transitions', 'WHERE' => ['plugin_escalation_steps_id' => array_keys($map) ?: [-1]]]) as $transition) {
      unset($transition['id']); $transition['plugin_escalation_steps_id'] = $map[(int) $transition['plugin_escalation_steps_id']]; $transition['target_steps_id'] = $map[(int) $transition['target_steps_id']] ?? 0; $DB->insert('glpi_plugin_escalation_transitions', $transition);
    }
    foreach ($DB->request(['FROM' => 'glpi_plugin_escalation_actions', 'WHERE' => ['plugin_escalation_flowversions_id' => $source['id']]]) as $action) {
      unset($action['id']); $action['plugin_escalation_flowversions_id'] = $draft_id; $action['plugin_escalation_steps_id'] = $map[(int) $action['plugin_escalation_steps_id']] ?? 0; $DB->insert('glpi_plugin_escalation_actions', $action);
    }
    $layout = $DB->request(['FROM' => 'glpi_plugin_escalation_visuallayouts', 'WHERE' => ['plugin_escalation_flowversions_id' => $source['id']], 'LIMIT' => 1])->current();
    if ($layout) {
      $DB->insert('glpi_plugin_escalation_visuallayouts', [
        'plugin_escalation_flowversions_id' => $draft_id,
        'layout' => $layout['layout'],
        'revision' => 1,
        'date_creation' => date('Y-m-d H:i:s'),
        'date_mod' => date('Y-m-d H:i:s'),
      ]);
    }
    return $draft_id;
  }

  public static function touch(int $version_id): void {
    global $DB;
    if (!self::isDraft($version_id)) return;
    $DB->update('glpi_plugin_escalation_flowversions', ['date_mod' => date('Y-m-d H:i:s')], ['id' => $version_id]);
    if ($DB->tableExists('glpi_plugin_escalation_visuallayouts')) {
      $DB->doQuery(sprintf(
        "INSERT INTO `glpi_plugin_escalation_visuallayouts` (`plugin_escalation_flowversions_id`,`layout`,`revision`,`date_creation`,`date_mod`) VALUES (%d,'{}',1,NOW(),NOW()) ON DUPLICATE KEY UPDATE `revision`=`revision`+1,`date_mod`=NOW()",
        $version_id
      ));
    }
  }

  public static function syncDraftMetadata(int $flow_id): void {
    global $DB;
    $flow = $DB->request(['FROM' => PluginEscalationFlow::getTable(), 'WHERE' => ['id' => $flow_id], 'LIMIT' => 1])->current();
    if (!$flow) return;
    $draft_id = self::ensureDraft($flow_id);
    $DB->update('glpi_plugin_escalation_flowversions', [
      'name' => $flow['name'] ?? '', 'description' => $flow['description'] ?? '',
      'entities_id' => (int) ($flow['entities_id'] ?? 0),
      'is_recursive' => (int) ($flow['is_recursive'] ?? 0),
      'priority' => (int) ($flow['priority'] ?? 0), 'date_mod' => date('Y-m-d H:i:s'),
    ], ['id' => $draft_id]);
  }
}
