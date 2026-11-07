<?php
if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

final class PluginEscalationImporter {
  public static function import(string $json, array $mapping): int {
    global $DB;
    $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    $resolve = static function($value) use (&$resolve, $mapping) {
      if (is_array($value)) return array_map($resolve, $value);
      if (!is_string($value)) return $value;
      if (preg_match('/^\$\{([A-Z0-9_]+)\}$/', $value, $match)) {
        if (!array_key_exists($match[1], $mapping)) throw new InvalidArgumentException('Mapeamento ausente: ' . $match[0]);
        return $mapping[$match[1]];
      }
      return preg_replace_callback('/\$\{([A-Z0-9_]+)\}/', static function(array $match) use ($mapping): string {
        if (!array_key_exists($match[1], $mapping)) throw new InvalidArgumentException('Mapeamento ausente: ' . $match[0]);
        return (string) $mapping[$match[1]];
      }, $value);
    };
    $data = $resolve($data);
    if (($data['schema'] ?? '') !== 'escalation-workflow/v1' || empty($data['name'])) throw new InvalidArgumentException('Modelo incompatível');
    $allowed_fields=['event','category','creator','requester','creator_group','requester_group','type','status'];
    $allowed_ops=['equals','not_equals','in','not_in','member_of','changed_from','changed_to'];
    foreach(($data['condition_groups']??[]) as $group)foreach(($group['all']??[]) as $condition)if(!in_array($condition['field']??'', $allowed_fields,true)||!in_array($condition['operator']??'', $allowed_ops,true))throw new InvalidArgumentException('Condição inválida no modelo');
    $keys=[]; foreach(($data['steps']??[]) as $step){$key=$step['key']??'';if($key===''||isset($keys[$key]))throw new InvalidArgumentException('Chave de etapa ausente ou duplicada');$keys[$key]=true;}
    $terminal=['approved','rejected','expired','failed','pending_configuration'];
    foreach(($data['steps']??[]) as $step)foreach(($step['transitions']??[]) as $target)if(!isset($keys[$target])&&!in_array($target,$terminal,true))throw new InvalidArgumentException('Destino de transição inválido: '.$target);
    foreach(($data['actions']??[]) as $action){$type=$action['type']??'';if(!in_array($type,PluginEscalationActionExecutor::types(),true)||PluginEscalationActionExecutor::validateParameters($type,$action['parameters']??[]))throw new InvalidArgumentException('Ação incompleta no modelo: '.$type);}
    $flow = new PluginEscalationFlow();
    $flow_id = (int) $flow->add(['name' => $data['name'], 'description' => $data['description'] ?? '', 'is_active' => 0, 'entities_id' => (int) ($data['entity'] ?? 0), 'is_recursive' => !empty($data['recursive']) ? 1 : 0, 'priority' => (int) ($data['priority'] ?? 0)]);
    if (!$flow_id) throw new RuntimeException('Falha ao criar fluxo');
    $version_id = PluginEscalationFlowVersion::ensureDraft($flow_id);
    foreach (($data['condition_groups'] ?? []) as $gindex => $group) {
      $DB->insert('glpi_plugin_escalation_conditiongroups', ['plugin_escalation_flowversions_id' => $version_id, 'group_order' => $gindex + 1]); $group_id = (int) $DB->insertId();
      foreach (($group['all'] ?? []) as $cindex => $c) $DB->insert('glpi_plugin_escalation_conditions', ['plugin_escalation_conditiongroups_id' => $group_id, 'field' => $c['field'], 'operator' => $c['operator'], 'value' => is_array($c['value']) ? json_encode($c['value']) : (string) $c['value'], 'condition_order' => $cindex + 1]);
    }
    $step_ids=[];
    foreach (($data['steps'] ?? []) as $index => $s) {
      $step = new PluginEscalationStep(); $id=(int)$step->add(['plugin_escalation_flows_id'=>$flow_id,'plugin_escalation_flowversions_id'=>$version_id,'name'=>$s['name'],'type'=>PluginEscalationStep::TYPE_PEOPLE,'step_kind'=>$s['kind']??'approval','step_order'=>$index+1,'timeout_days'=>0,'quorum_type'=>$s['quorum']??PluginEscalationStep::QUORUM_ALL,'generate_immediately'=>1,'prevent_self_approval'=>!empty($s['prevent_self_approval'])?1:0,'fallback_users_id'=>(int)($s['fallback_user']??0)]); $step_ids[$s['key']]=$id;
      foreach(($s['approvers']??[]) as $a)$DB->insert('glpi_plugin_escalation_stepapprovers',['plugin_escalation_steps_id'=>$id,'source_type'=>$a['source'],'items_id'=>(int)($a['value']??0)]);
    }
    foreach(($data['steps']??[]) as $s) foreach(($s['transitions']??[]) as $outcome=>$target) $DB->insert('glpi_plugin_escalation_transitions',['plugin_escalation_steps_id'=>$step_ids[$s['key']],'outcome'=>$outcome,'target_steps_id'=>$step_ids[$target]??0,'terminal_state'=>isset($step_ids[$target])?null:$target]);
    foreach(($data['actions']??[]) as $a)$DB->insert('glpi_plugin_escalation_actions',['plugin_escalation_flowversions_id'=>$version_id,'plugin_escalation_steps_id'=>0,'name'=>$a['type'],'event'=>$a['event'],'type'=>$a['type'],'parameters'=>json_encode($a['parameters'],JSON_UNESCAPED_UNICODE),'action_order'=>$a['order'],'error_policy'=>'retry','max_attempts'=>3]);
    return $flow_id;
  }
}
