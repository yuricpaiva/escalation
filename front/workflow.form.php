<?php
if(!defined('GLPI_ROOT'))include('../../../inc/includes.php');
if(!PluginEscalationProfile::have(PluginEscalationProfile::MANAGE))Html::displayErrorAndDie(__('Você não tem permissão para administrar fluxos de aprovação.','escalation'));
global $DB;
$flow_id=(int)($_POST['flow_id']??0);if($flow_id<=0)Html::back();
$version_id=PluginEscalationFlowVersion::ensureDraft($flow_id);
$changed=false;
$redirect=PluginEscalationFlow::getFormURL().'?id='.$flow_id.'&forcetab=PluginEscalationFlow$2';

if(isset($_POST['add_group'])){
  $row=$DB->request(['SELECT'=>['MAX'=>'group_order AS max_order'],'FROM'=>'glpi_plugin_escalation_conditiongroups','WHERE'=>['plugin_escalation_flowversions_id'=>$version_id]])->current();
  $DB->insert('glpi_plugin_escalation_conditiongroups',['plugin_escalation_flowversions_id'=>$version_id,'group_order'=>(int)($row['max_order']??0)+1]);
  $changed=true;
}elseif(isset($_POST['delete_group'])){
  $group_id=(int)$_POST['group_id'];
  $group=$DB->request(['FROM'=>'glpi_plugin_escalation_conditiongroups','WHERE'=>['id'=>$group_id,'plugin_escalation_flowversions_id'=>$version_id],'LIMIT'=>1])->current();
  if($group&&countElementsInTable('glpi_plugin_escalation_conditiongroups',['plugin_escalation_flowversions_id'=>$version_id])>1){
    foreach($DB->request(['SELECT'=>['id'],'FROM'=>'glpi_plugin_escalation_conditions','WHERE'=>['plugin_escalation_conditiongroups_id'=>$group_id]])as $condition)$DB->delete('glpi_plugin_escalation_conditions',['id'=>$condition['id']]);
    $DB->delete('glpi_plugin_escalation_conditiongroups',['id'=>$group_id]);
    $changed=true;
  }
}elseif(isset($_POST['add_condition'])){
  $field=(string)($_POST['field']??'');$operator=(string)($_POST['operator']??'');$group_id=(int)($_POST['group_id']??0);
  $group=$DB->request(['FROM'=>'glpi_plugin_escalation_conditiongroups','WHERE'=>['id'=>$group_id,'plugin_escalation_flowversions_id'=>$version_id],'LIMIT'=>1])->current();
  $allowed_fields=array_keys(PluginEscalationAdmin::conditionFields());$allowed_ops=array_keys(PluginEscalationAdmin::operatorLabels());
  $value=$_POST['value_'.$field]??null;
  if($group&&in_array($field,$allowed_fields,true)&&in_array($operator,$allowed_ops,true)&&$value!==null&&$value!==''){
    $row=$DB->request(['SELECT'=>['MAX'=>'condition_order AS max_order'],'FROM'=>'glpi_plugin_escalation_conditions','WHERE'=>['plugin_escalation_conditiongroups_id'=>$group_id]])->current();
    $DB->insert('glpi_plugin_escalation_conditions',['plugin_escalation_conditiongroups_id'=>$group_id,'field'=>$field,'operator'=>$operator,'value'=>is_array($value)?json_encode($value):Toolbox::cleanNewLines((string)$value),'condition_order'=>(int)($row['max_order']??0)+1]);
    $changed=true;
  }else Session::addMessageAfterRedirect(__('Escolha a informação, a comparação e o valor da condição.','escalation'),false,ERROR);
}elseif(isset($_POST['delete_condition'])){
  $condition=$DB->request(['SELECT'=>['c.id'],'FROM'=>'glpi_plugin_escalation_conditions AS c','INNER JOIN'=>['glpi_plugin_escalation_conditiongroups AS g'=>['ON'=>['c'=>'plugin_escalation_conditiongroups_id','g'=>'id']]],'WHERE'=>['c.id'=>(int)$_POST['condition_id'],'g.plugin_escalation_flowversions_id'=>$version_id],'LIMIT'=>1])->current();if($condition)$DB->delete('glpi_plugin_escalation_conditions',['id'=>$condition['id']]);
  $changed=(bool)$condition;
}elseif(isset($_POST['add_action'])){
  $type=(string)($_POST['type']??'');$event=(string)($_POST['event']??'');
  $parameters=match($type){
    'assign_user'=>['users_id'=>(int)($_POST['action_users_id']??0)],
    'assign_group'=>['groups_id'=>(int)($_POST['action_groups_id']??0)],
    'change_status'=>['status'=>(int)($_POST['action_status']??0)],
    'change_category'=>['itilcategories_id'=>(int)($_POST['action_itilcategories_id']??0)],
    'add_followup'=>['content'=>trim((string)($_POST['action_followup_content']??'')),'is_private'=>(int)($_POST['action_followup_private']??0)],
    'create_task'=>['content'=>trim((string)($_POST['action_task_content']??'')),'users_id'=>(int)($_POST['action_task_users_id']??0),'groups_id'=>0],
    'notify'=>['event'=>(string)($_POST['action_notification_event']??'')],
    'create_related_ticket'=>['name'=>trim((string)($_POST['action_related_name']??'')),'content'=>trim((string)($_POST['action_related_content']??'')),'itilcategories_id'=>(int)($_POST['action_related_category']??0),'entities_id'=>(int)($_SESSION['glpiactive_entity']??0),'type'=>Ticket::DEMAND_TYPE],
    default=>[],
  };
  $errors=!isset(PluginEscalationAdmin::actionLabels()[$type])||!isset(PluginEscalationAdmin::eventLabels()[$event])?[__('Escolha quando e o que o GLPI deve fazer.','escalation')]:PluginEscalationActionExecutor::validateParameters($type,$parameters);
  if(!$errors){
    $row=$DB->request(['SELECT'=>['MAX'=>'action_order AS max_order'],'FROM'=>'glpi_plugin_escalation_actions','WHERE'=>['plugin_escalation_flowversions_id'=>$version_id,'event'=>$event]])->current();
    $policy=in_array($_POST['error_policy']??'', ['continue','stop','retry'],true)?$_POST['error_policy']:'retry';
    $DB->insert('glpi_plugin_escalation_actions',['plugin_escalation_flowversions_id'=>$version_id,'plugin_escalation_steps_id'=>0,'name'=>PluginEscalationAdmin::actionLabels()[$type],'event'=>$event,'type'=>$type,'parameters'=>json_encode($parameters,JSON_UNESCAPED_UNICODE),'action_order'=>(int)($row['max_order']??0)+1,'error_policy'=>$policy,'max_attempts'=>max(1,min(10,(int)($_POST['max_attempts']??3)))]);
    $changed=true;
  }else Session::addMessageAfterRedirect(implode(' ',$errors),false,ERROR);
}elseif(isset($_POST['delete_action'])){
  $DB->delete('glpi_plugin_escalation_actions',['id'=>(int)$_POST['action_id'],'plugin_escalation_flowversions_id'=>$version_id]);
  $changed=true;
}elseif(isset($_POST['publish'])){
  if(!PluginEscalationProfile::have(PluginEscalationProfile::PUBLISH))Html::displayErrorAndDie(__('Você não tem permissão para publicar alterações.','escalation'));
  $errors=PluginEscalationWorkflow::publish($flow_id);Session::addMessageAfterRedirect($errors?implode(' ',$errors):__('Alterações publicadas.','escalation'),false,$errors?ERROR:INFO);
}
if($changed)PluginEscalationFlowVersion::touch($version_id);
Html::redirect($redirect);
