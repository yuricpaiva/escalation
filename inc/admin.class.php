<?php
if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

/** Interface administrativa em linguagem de negócio, usando seletores nativos do GLPI. */
final class PluginEscalationAdmin {
  public static function conditionFields(): array { return [
    'event'=>__('Momento do chamado','escalation'), 'category'=>__('Categoria do chamado','escalation'),
    'creator'=>__('Criado por','escalation'), 'requester'=>__('Solicitante','escalation'),
    'creator_group'=>__('Grupo de quem criou','escalation'), 'requester_group'=>__('Grupo do solicitante','escalation'),
    'type'=>__('Tipo do chamado','escalation'), 'status'=>__('Status do chamado','escalation'),
  ]; }
  public static function operatorLabels(): array { return [
    'equals'=>__('é','escalation'), 'not_equals'=>__('não é','escalation'),
    'member_of'=>__('faz parte de','escalation'), 'changed_from'=>__('mudou de','escalation'),
    'changed_to'=>__('mudou para','escalation'), 'in'=>__('é um destes','escalation'),
    'not_in'=>__('não é nenhum destes','escalation'),
  ]; }
  public static function eventLabels(): array { return [
    'flow_started'=>__('Ao iniciar o fluxo','escalation'),
    'step_activated'=>__('Ao iniciar uma etapa automática','escalation'),
    'step_approved'=>__('Quando uma etapa for aprovada','escalation'),
    'step_rejected'=>__('Quando uma etapa for recusada','escalation'),
    'flow_approved'=>__('Quando o fluxo for aprovado','escalation'),
    'flow_rejected'=>__('Quando o fluxo for recusado','escalation'),
    'flow_expired'=>__('Quando o prazo do fluxo terminar','escalation'),
  ]; }
  public static function actionLabels(): array { return [
    'assign_user'=>__('Atribuir a um técnico','escalation'), 'assign_group'=>__('Atribuir a um grupo','escalation'),
    'change_status'=>__('Alterar o status do chamado','escalation'),
    'add_followup'=>__('Adicionar um acompanhamento','escalation'), 'create_task'=>__('Criar uma tarefa','escalation'),
    'change_category'=>__('Alterar a categoria do chamado','escalation'),
    'notify'=>__('Enviar uma notificação do GLPI','escalation'),
    'create_related_ticket'=>__('Criar um chamado relacionado','escalation'),
  ]; }
  public static function sourceLabels(): array { return [
    'user'=>__('Uma pessoa específica','escalation'), 'group'=>__('Todas as pessoas de um grupo','escalation'),
    'supervisor'=>__('Responsável direto do solicitante','escalation'),
    'requester'=>__('Solicitantes do chamado','escalation'),
    'assigned'=>__('Técnicos atribuídos ao chamado','escalation'),
    'observer'=>__('Observadores do chamado','escalation'),
  ]; }
  public static function outcomeLabels(): array { return [
    'approved'=>__('Se a etapa for aprovada','escalation'), 'rejected'=>__('Se a etapa for recusada','escalation'),
    'expired'=>__('Se o prazo da etapa terminar','escalation'),
    'approver_missing'=>__('Se nenhum aprovador for encontrado','escalation'),
    'success'=>__('Se a ação for concluída','escalation'), 'failure'=>__('Se a ação falhar','escalation'),
  ]; }

  private static function itemName(string $class,int $id): string {
    if ($id<=0) return __('Não selecionado','escalation');
    $item=new $class();
    if(!$item->getFromDB($id)) return sprintf(__('Item removido (#%d)','escalation'),$id);
    return $item instanceof User ? $item->getFriendlyName() : $item->getName();
  }
  public static function conditionValueLabel(string $field,string $value): string {
    $decoded=json_decode($value,true);
    if(json_last_error()===JSON_ERROR_NONE&&is_array($decoded))return implode(', ',array_map(fn($v)=>self::conditionValueLabel($field,(string)$v),$decoded));
    return match($field){
      'event'=>['created'=>__('Criação do chamado','escalation'),'updated'=>__('Atualização do chamado','escalation')][$value]??$value,
      'category'=>self::itemName(ITILCategory::class,(int)$value),
      'creator','requester'=>self::itemName(User::class,(int)$value),
      'creator_group','requester_group'=>self::itemName(Group::class,(int)$value),
      'type'=>(string)Ticket::getTicketTypeName((int)$value), 'status'=>(string)Ticket::getStatus((int)$value), default=>$value,
    };
  }
  private static function actionDetails(string $type,array $p): string { return match($type){
    'assign_user'=>self::itemName(User::class,(int)($p['users_id']??0)),
    'assign_group'=>self::itemName(Group::class,(int)($p['groups_id']??0)),
    'change_status'=>(string)Ticket::getStatus((int)($p['status']??0)),
    'change_category'=>self::itemName(ITILCategory::class,(int)($p['itilcategories_id']??0)),
    'add_followup','create_task'=>Toolbox::substr(strip_tags((string)($p['content']??'')),0,120),
    'notify'=>['escalation_workflow'=>__('Atualização do fluxo','escalation'),'escalation_approved'=>__('Fluxo aprovado','escalation'),'escalation_rejected'=>__('Fluxo recusado','escalation'),'escalation_failed'=>__('Falha na automação','escalation')][$p['event']??'']??__('Notificação do fluxo','escalation'),
    'create_related_ticket'=>(string)($p['name']??__('Novo chamado relacionado','escalation')), default=>'',
  }; }

  public static function showForFlow(PluginEscalationFlow $flow): bool {
    global $DB;
    $flow_id=$flow->getID(); $version_id=PluginEscalationFlowVersion::ensureDraft($flow_id);
    $version=$DB->request(['FROM'=>'glpi_plugin_escalation_flowversions','WHERE'=>['id'=>$version_id],'LIMIT'=>1])->current();
    $url=PluginEscalationConfig::getWebPath().'/front/workflow.form.php';
    echo "<div class='card mx-auto my-3' style='max-width:1100px'><div class='card-header d-flex flex-wrap align-items-center gap-2'><h2 class='card-title mb-0'>".__('Configuração do fluxo de aprovação','escalation')."</h2><span class='badge bg-azure-lt ms-auto'>".sprintf(__('Alterações não publicadas · versão %d','escalation'),$version['version'])."</span></div><div class='card-body'>";
    echo "<div class='alert alert-info d-flex' role='status'><i class='ti ti-lock me-2' aria-hidden='true'></i><div>".__('Chamados que já iniciaram este fluxo não serão alterados.','escalation')."</div></div>";
    self::showConditions($version_id,$flow_id,$url); self::showActions($version_id,$flow_id,$url);
    $errors=PluginEscalationWorkflow::validateVersion($version_id);
    echo "<section class='mt-5' aria-labelledby='publish-title'><h3 id='publish-title' class='h3'>".__('Publicar alterações','escalation')."</h3>";
    if($errors){echo "<div class='alert alert-danger'><strong>".__('Revise estes pontos antes de publicar:','escalation')."</strong><ul class='mb-0 mt-2'>";foreach($errors as $error)echo '<li>'.htmlescape($error).'</li>';echo '</ul></div>';}
    else echo "<div class='alert alert-success'><i class='ti ti-circle-check me-2' aria-hidden='true'></i>".__('Tudo certo. As alterações estão prontas para uso.','escalation')."</div>";
    echo "<form method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('flow_id',['value'=>$flow_id])."<button class='btn btn-primary' name='publish'".($errors?' disabled':'')."><i class='ti ti-send me-1'></i>".__('Publicar alterações','escalation')."</button></form></section></div></div>";
    return true;
  }

  private static function showConditions(int $version_id,int $flow_id,string $url): void {
    global $DB;
    $groups=iterator_to_array($DB->request(['FROM'=>'glpi_plugin_escalation_conditiongroups','WHERE'=>['plugin_escalation_flowversions_id'=>$version_id],'ORDERBY'=>['group_order ASC']]));
    if(!$groups){$DB->insert('glpi_plugin_escalation_conditiongroups',['plugin_escalation_flowversions_id'=>$version_id,'group_order'=>1]);$groups=[['id'=>$DB->insertId(),'group_order'=>1]];}
    echo "<section aria-labelledby='conditions-title'><h3 id='conditions-title' class='h3'>".__('Quando este fluxo deve começar?','escalation')."</h3><p class='text-muted'>".__('Em cada alternativa, o chamado precisa atender a todas as condições. Se houver mais de uma alternativa, basta atender a uma delas.','escalation')."</p>";
    foreach($groups as $index=>$group){
      echo "<div class='border rounded mb-3'><div class='d-flex align-items-center bg-light px-3 py-2'><strong>".sprintf(__('Alternativa %d','escalation'),$index+1)."</strong>";
      if(count($groups)>1)echo "<form class='ms-auto' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('flow_id',['value'=>$flow_id]).Html::hidden('group_id',['value'=>$group['id']])."<button class='btn btn-sm btn-ghost-danger' name='delete_group' aria-label='".__('Excluir alternativa','escalation')."'><i class='ti ti-trash'></i></button></form>";
      echo "</div><div class='px-3'>";
      $conditions=iterator_to_array($DB->request(['FROM'=>'glpi_plugin_escalation_conditions','WHERE'=>['plugin_escalation_conditiongroups_id'=>$group['id']],'ORDERBY'=>['condition_order ASC']]));
      if(!$conditions)echo "<div class='text-muted py-3'>".__('Adicione a primeira condição desta alternativa.','escalation')."</div>";
      foreach($conditions as $position=>$condition){$sentence=(self::conditionFields()[$condition['field']]??$condition['field']).' '.(self::operatorLabels()[$condition['operator']]??$condition['operator']).' '.self::conditionValueLabel($condition['field'],$condition['value']);echo "<div class='d-flex align-items-center border-bottom py-3'>".($position>0?"<span class='badge bg-secondary-lt me-2'>".__('e','escalation')."</span>":'')."<span>".htmlescape($sentence)."</span><form class='ms-auto' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('flow_id',['value'=>$flow_id]).Html::hidden('condition_id',['value'=>$condition['id']])."<button class='btn btn-sm btn-ghost-danger' name='delete_condition' aria-label='".__('Excluir condição','escalation')."'><i class='ti ti-trash'></i></button></form></div>";}
      echo "</div></div>";
    }
    echo "<form class='mb-3' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('flow_id',['value'=>$flow_id])."<button class='btn btn-sm btn-outline-secondary' name='add_group'><i class='ti ti-plus me-1'></i>".__('Adicionar outra alternativa','escalation')."</button></form>";
    echo "<form id='escalation-condition-form' class='row g-3 align-items-end p-3 bg-light rounded' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('flow_id',['value'=>$flow_id]);
    echo "<div class='col-lg-3'><label class='form-label'>".__('Adicionar em','escalation')."</label><select class='form-select' name='group_id'>";foreach($groups as $i=>$group)echo '<option value="'.(int)$group['id'].'">'.sprintf(__('Alternativa %d','escalation'),$i+1).'</option>';echo "</select></div>";
    echo "<div class='col-lg-3'><label class='form-label'>".__('Informação do chamado','escalation')."</label>";Dropdown::showFromArray('field',self::conditionFields(),['value'=>'category','width'=>'100%','on_change'=>'escalationConditionFieldChanged(this.value)']);echo "</div>";
    echo "<div class='col-lg-2'><label class='form-label'>".__('Comparação','escalation')."</label><select class='form-select' name='operator'><option value='equals' data-fields='event,category,creator,requester,type,status'>".__('é','escalation')."</option><option value='not_equals' data-fields='event,category,creator,requester,type,status'>".__('não é','escalation')."</option><option value='member_of' data-fields='creator_group,requester_group'>".__('faz parte de','escalation')."</option><option value='changed_from' data-fields='status'>".__('mudou de','escalation')."</option><option value='changed_to' data-fields='status'>".__('mudou para','escalation')."</option></select></div>";
    echo "<div class='col-lg-3'><label class='form-label'>".__('Valor','escalation')."</label>";
    self::conditionValueDropdown('event');self::conditionValueDropdown('category');self::conditionValueDropdown('creator');self::conditionValueDropdown('requester');self::conditionValueDropdown('creator_group');self::conditionValueDropdown('requester_group');self::conditionValueDropdown('type');self::conditionValueDropdown('status');
    echo "</div><div class='col-lg-1'><button class='btn btn-primary w-100' name='add_condition' aria-label='".__('Adicionar condição','escalation')."'><i class='ti ti-plus'></i></button></div></form></section>";
    echo Html::scriptBlock("function escalationConditionFieldChanged(field){var form=document.getElementById('escalation-condition-form');if(!form)return;form.querySelectorAll('[data-condition-value]').forEach(function(panel){var active=panel.dataset.conditionValue===field;panel.classList.toggle('d-none',!active);panel.querySelectorAll('select,input').forEach(function(control){control.disabled=!active;});});var op=form.querySelector('[name=operator]');Array.from(op.options).forEach(function(o){var allowed=o.dataset.fields.split(',').includes(field);o.disabled=!allowed;o.hidden=!allowed;});if(op.selectedOptions[0].disabled)op.value=field.endsWith('_group')?'member_of':'equals';} escalationConditionFieldChanged('category');");
  }

  private static function conditionValueDropdown(string $field): void {
    echo "<div data-condition-value='".$field."'>";
    match($field){
      'event'=>Dropdown::showFromArray('value_event',['created'=>__('Criação do chamado','escalation'),'updated'=>__('Atualização do chamado','escalation')],['width'=>'100%']),
      'category'=>ITILCategory::dropdown(['name'=>'value_category','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']),
      'creator'=>User::dropdown(['name'=>'value_creator','right'=>'all','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']),
      'requester'=>User::dropdown(['name'=>'value_requester','right'=>'all','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']),
      'creator_group'=>Group::dropdown(['name'=>'value_creator_group','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']),
      'requester_group'=>Group::dropdown(['name'=>'value_requester_group','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']),
      'type'=>Dropdown::showFromArray('value_type',Ticket::getTypes(),['width'=>'100%']),
      'status'=>Ticket::dropdownStatus(['name'=>'value_status','width'=>'100%']), default=>null,
    }; echo '</div>';
  }

  private static function showActions(int $version_id,int $flow_id,string $url): void {
    global $DB;
    echo "<section class='mt-5' aria-labelledby='actions-title'><h3 id='actions-title' class='h3'>".__('O que fazer automaticamente?','escalation')."</h3><p class='text-muted'>".__('Estas ações usam os mesmos usuários, grupos, categorias e status cadastrados no GLPI.','escalation')."</p>";
    $has=false;foreach($DB->request(['FROM'=>'glpi_plugin_escalation_actions','WHERE'=>['plugin_escalation_flowversions_id'=>$version_id],'ORDERBY'=>['event ASC','action_order ASC']])as $action){$has=true;$p=json_decode($action['parameters']?:'{}',true)?:[];echo "<div class='d-flex gap-3 align-items-start border-bottom py-3'><i class='ti ti-bolt text-muted mt-1' aria-hidden='true'></i><div><strong>".htmlescape(self::actionLabels()[$action['type']]??$action['name'])."</strong><div class='text-muted'>".htmlescape(self::eventLabels()[$action['event']]??$action['event'])." · ".htmlescape(self::actionDetails($action['type'],$p))."</div></div><form class='ms-auto' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('flow_id',['value'=>$flow_id]).Html::hidden('action_id',['value'=>$action['id']])."<button class='btn btn-sm btn-ghost-danger' name='delete_action' aria-label='".__('Excluir ação','escalation')."'><i class='ti ti-trash'></i></button></form></div>";}if(!$has)echo "<div class='text-muted py-2'>".__('Nenhuma ação automática configurada.','escalation')."</div>";
    echo "<form id='escalation-action-form' class='mt-3 p-3 bg-light rounded' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('flow_id',['value'=>$flow_id])."<div class='row g-3'><div class='col-lg-5'><label class='form-label'>".__('Quando?','escalation')."</label>";Dropdown::showFromArray('event',self::eventLabels(),['value'=>'flow_approved','width'=>'100%']);echo "</div><div class='col-lg-5'><label class='form-label'>".__('O que o GLPI deve fazer?','escalation')."</label>";Dropdown::showFromArray('type',self::actionLabels(),['value'=>'assign_user','width'=>'100%','on_change'=>'escalationActionTypeChanged(this.value)']);echo "</div></div>";
    self::actionPanel('assign_user');self::actionPanel('assign_group');self::actionPanel('change_status');self::actionPanel('change_category');self::actionPanel('add_followup');self::actionPanel('create_task');self::actionPanel('notify');self::actionPanel('create_related_ticket');
    echo "<details class='mt-3'><summary class='text-muted'>".__('Se esta ação falhar','escalation')."</summary><div class='row g-3 mt-1'><div class='col-lg-4'><label class='form-label'>".__('Comportamento','escalation')."</label>";Dropdown::showFromArray('error_policy',['retry'=>__('Tentar novamente','escalation'),'continue'=>__('Continuar com as próximas ações','escalation'),'stop'=>__('Parar as próximas ações','escalation')],['width'=>'100%']);echo "</div><div class='col-lg-3'><label class='form-label'>".__('Número máximo de tentativas','escalation')."</label><input class='form-control' type='number' min='1' max='10' name='max_attempts' value='3'></div></div></details><button class='btn btn-primary mt-3' name='add_action'><i class='ti ti-plus me-1'></i>".__('Adicionar ação','escalation')."</button></form></section>";
    echo Html::scriptBlock("function escalationActionTypeChanged(type){var form=document.getElementById('escalation-action-form');if(!form)return;form.querySelectorAll('[data-action-panel]').forEach(function(panel){var active=panel.dataset.actionPanel===type;panel.classList.toggle('d-none',!active);panel.querySelectorAll('select,input,textarea').forEach(function(control){control.disabled=!active;});});} escalationActionTypeChanged('assign_user');");
  }
  private static function actionPanel(string $type): void {
    echo "<div class='row g-3 mt-1' data-action-panel='".$type."'>";
    switch($type){
      case 'assign_user':echo "<div class='col-lg-6'><label class='form-label'>".__('Técnico','escalation')."</label>";User::dropdown(['name'=>'action_users_id','right'=>'all','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']);echo '</div>';break;
      case 'assign_group':echo "<div class='col-lg-6'><label class='form-label'>".__('Grupo','escalation')."</label>";Group::dropdown(['name'=>'action_groups_id','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']);echo '</div>';break;
      case 'change_status':echo "<div class='col-lg-6'><label class='form-label'>".__('Novo status','escalation')."</label>";Ticket::dropdownStatus(['name'=>'action_status','width'=>'100%']);echo '</div>';break;
      case 'change_category':echo "<div class='col-lg-6'><label class='form-label'>".__('Nova categoria','escalation')."</label>";ITILCategory::dropdown(['name'=>'action_itilcategories_id','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']);echo '</div>';break;
      case 'add_followup':echo "<div class='col-lg-8'><label class='form-label'>".__('Texto do acompanhamento','escalation')."</label><textarea class='form-control' name='action_followup_content' rows='3'></textarea></div><div class='col-lg-4'><label class='form-label'>".__('Visibilidade','escalation')."</label>";Dropdown::showFromArray('action_followup_private',[0=>__('Público','escalation'),1=>__('Privado','escalation')],['width'=>'100%']);echo '</div>';break;
      case 'create_task':echo "<div class='col-lg-8'><label class='form-label'>".__('Descrição da tarefa','escalation')."</label><textarea class='form-control' name='action_task_content' rows='3'></textarea></div><div class='col-lg-4'><label class='form-label'>".__('Técnico responsável (opcional)','escalation')."</label>";User::dropdown(['name'=>'action_task_users_id','right'=>'all','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']);echo '</div>';break;
      case 'notify':echo "<div class='col-lg-6'><label class='form-label'>".__('Notificação','escalation')."</label>";Dropdown::showFromArray('action_notification_event',['escalation_workflow'=>__('Atualização do fluxo','escalation'),'escalation_approved'=>__('Fluxo aprovado','escalation'),'escalation_rejected'=>__('Fluxo recusado','escalation'),'escalation_failed'=>__('Falha na automação','escalation')],['width'=>'100%']);echo '</div>';break;
      case 'create_related_ticket':echo "<div class='col-lg-6'><label class='form-label'>".__('Título do novo chamado','escalation')."</label><input class='form-control' name='action_related_name'></div><div class='col-lg-6'><label class='form-label'>".__('Categoria','escalation')."</label>";ITILCategory::dropdown(['name'=>'action_related_category','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']);echo "</div><div class='col-12'><label class='form-label'>".__('Descrição','escalation')."</label><textarea class='form-control' name='action_related_content' rows='3'></textarea></div>";break;
    } echo '</div>';
  }

  public static function showStepConfiguration(PluginEscalationStep $step): void {
    global $DB;
    $step_id=(int)$step->getID();$flow_id=(int)$step->fields['plugin_escalation_flows_id'];$url=PluginEscalationConfig::getWebPath().'/front/stepconfig.form.php';$source_labels=self::sourceLabels();
    echo "<div class='card mt-3'><div class='card-header'><h2 class='card-title'>".__('Quem aprova e o que acontece depois','escalation')."</h2></div><div class='card-body'><h3 class='h3'>".__('Aprovadores','escalation')."</h3><p class='text-muted'>".__('Escolha pessoas, grupos ou papéis do próprio chamado. A lista é registrada quando a etapa começa.','escalation')."</p>";
    $has=false;foreach($DB->request(['FROM'=>'glpi_plugin_escalation_stepapprovers','WHERE'=>['plugin_escalation_steps_id'=>$step_id]])as $source){$has=true;$label=$source_labels[$source['source_type']]??$source['source_type'];if($source['source_type']==='user')$label.=': '.self::itemName(User::class,(int)$source['items_id']);if($source['source_type']==='group')$label.=': '.self::itemName(Group::class,(int)$source['items_id']);echo "<div class='d-flex align-items-center border-bottom py-2'><i class='ti ti-user-check text-muted me-2'></i><span>".htmlescape($label)."</span><form class='ms-auto' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('step_id',['value'=>$step_id]).Html::hidden('flow_id',['value'=>$flow_id]).Html::hidden('source_id',['value'=>$source['id']])."<button class='btn btn-sm btn-ghost-danger' name='delete_source' aria-label='".__('Remover aprovador','escalation')."'><i class='ti ti-trash'></i></button></form></div>";}if(!$has)echo "<div class='text-muted py-2'>".__('Nenhum aprovador adicional configurado.','escalation')."</div>";
    echo "<form id='escalation-source-form' class='row g-3 align-items-end mt-1' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('step_id',['value'=>$step_id]).Html::hidden('flow_id',['value'=>$flow_id])."<div class='col-lg-4'><label class='form-label'>".__('Quem deve aprovar?','escalation')."</label>";Dropdown::showFromArray('source_type',$source_labels,['value'=>'user','width'=>'100%','on_change'=>'escalationToggleApproverSource(this.value)']);echo "</div><div class='col-lg-5' data-approver-source='user'><label class='form-label'>".__('Pessoa','escalation')."</label>";User::dropdown(['name'=>'source_users_id','right'=>'all','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']);echo "</div><div class='col-lg-5' data-approver-source='group'><label class='form-label'>".__('Grupo','escalation')."</label>";Group::dropdown(['name'=>'source_groups_id','entity'=>$_SESSION['glpiactiveentities']??0,'width'=>'100%']);echo "</div><div class='col-lg-3'><button class='btn btn-outline-primary w-100' name='add_source'><i class='ti ti-plus me-1'></i>".__('Adicionar aprovador','escalation')."</button></div></form>";
    $later=[];foreach($DB->request(['FROM'=>PluginEscalationStep::getTable(),'WHERE'=>['plugin_escalation_flowversions_id'=>$step->fields['plugin_escalation_flowversions_id'],['step_order'=>['>',$step->fields['step_order']]]],'ORDERBY'=>['step_order ASC']])as $row)$later[(int)$row['id']]=$row['name'];$dest=['default'=>__('Usar o comportamento padrão','escalation')];foreach($later as $id=>$name)$dest['step:'.$id]=sprintf(__('Seguir para: %s','escalation'),$name);$dest+=['terminal:approved'=>__('Encerrar o fluxo como aprovado','escalation'),'terminal:rejected'=>__('Encerrar o fluxo como recusado','escalation'),'terminal:expired'=>__('Encerrar porque o prazo terminou','escalation'),'terminal:failed'=>__('Encerrar com falha de automação','escalation'),'terminal:pending_configuration'=>__('Pausar para o administrador corrigir','escalation')];$outcomes=($step->fields['step_kind']??'approval')==='action'?['success','failure']:['approved','rejected','expired','approver_missing'];
    echo "<h3 class='h3 mt-4'>".__('Próximo passo','escalation')."</h3><p class='text-muted'>".__('Defina para onde o fluxo segue em cada resultado. Só é possível escolher etapas posteriores.','escalation')."</p>";foreach($outcomes as $outcome){$tr=$DB->request(['FROM'=>'glpi_plugin_escalation_transitions','WHERE'=>['plugin_escalation_steps_id'=>$step_id,'outcome'=>$outcome],'LIMIT'=>1])->current();$current='default';if($tr)$current=(int)$tr['target_steps_id']>0?'step:'.$tr['target_steps_id']:'terminal:'.$tr['terminal_state'];echo "<form class='row g-3 align-items-end border-bottom py-3' method='post' action='".htmlescape($url)."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('step_id',['value'=>$step_id]).Html::hidden('flow_id',['value'=>$flow_id]).Html::hidden('outcome',['value'=>$outcome])."<div class='col-lg-4'><label class='form-label'>".htmlescape(self::outcomeLabels()[$outcome])."</label></div><div class='col-lg-5'>";Dropdown::showFromArray('destination',$dest,['value'=>$current,'width'=>'100%']);echo "</div><div class='col-lg-3'><button class='btn btn-outline-primary w-100' name='save_transition'>".__('Salvar caminho','escalation')."</button></div></form>";}
    echo "</div></div>";echo Html::scriptBlock("function escalationToggleApproverSource(type){document.querySelectorAll('[data-approver-source]').forEach(function(el){var active=el.dataset.approverSource===type;el.classList.toggle('d-none',!active);el.querySelectorAll('select,input').forEach(function(c){c.disabled=!active;});});} escalationToggleApproverSource('user');");
  }
}
