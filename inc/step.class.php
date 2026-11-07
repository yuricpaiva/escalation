<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginEscalationStep extends CommonDBTM {
  static $rightname = 'plugin_escalation_manage';

  const TYPE_PEOPLE    = 'people';
  const TYPE_HIERARCHY = 'hierarchy';
  // Mantido apenas para migração de dados legados
  const TYPE_AUTO = 'auto';

  const QUORUM_MIN1     = 'min1';
  const QUORUM_MAJORITY = 'majority';
  const QUORUM_ALL      = 'all';

  static function getTypeName($nb = 0) {
    return _n('Etapa', 'Etapas', $nb, 'escalation');
  }

  static function getIcon() {
    return 'ti ti-list-check';
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `plugin_escalation_flows_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `name` varchar(255) COLLATE {$default_collation} NOT NULL,
        `type` varchar(32) COLLATE {$default_collation} NOT NULL DEFAULT '" . self::TYPE_PEOPLE . "',
        `step_order` int NOT NULL DEFAULT '0',
        `timeout_days` int NOT NULL DEFAULT '0',
        `quorum_type` varchar(32) COLLATE {$default_collation} NOT NULL DEFAULT '" . self::QUORUM_MIN1 . "',
        `hierarchy_level` int NOT NULL DEFAULT '1',
        `auto_hierarchy_dynamic` tinyint(1) NOT NULL DEFAULT '0',
        `generate_immediately` tinyint(1) NOT NULL DEFAULT '1',
        `include_requesters` tinyint(1) NOT NULL DEFAULT '0',
        `include_assigned` tinyint(1) NOT NULL DEFAULT '0',
        `include_observers` tinyint(1) NOT NULL DEFAULT '0',
        `plugin_escalation_templates_id` int {$default_key_sign} NOT NULL DEFAULT '0',
        `content` longtext COLLATE {$default_collation},
        PRIMARY KEY (`id`),
        KEY `plugin_escalation_flows_id` (`plugin_escalation_flows_id`),
        KEY `step_order` (`step_order`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    } else {
      // Migrações incrementais
      if ($DB->fieldExists(self::getTable(), 'timeout_hours') && !$DB->fieldExists(self::getTable(), 'timeout_days')) {
        $DB->doQuery("ALTER TABLE `" . self::getTable() . "` CHANGE `timeout_hours` `timeout_days` int NOT NULL DEFAULT '0'");
      } elseif (!$DB->fieldExists(self::getTable(), 'timeout_days')) {
        $migration->addField(self::getTable(), 'timeout_days', "int NOT NULL DEFAULT '0'");
      }
      foreach (['hierarchy_level' => "int NOT NULL DEFAULT '1'",
                'auto_hierarchy_dynamic' => "tinyint(1) NOT NULL DEFAULT '0'",
                'plugin_escalation_templates_id' => "int NOT NULL DEFAULT '0'"] as $col => $def) {
        if (!$DB->fieldExists(self::getTable(), $col)) {
          $migration->addField(self::getTable(), $col, $def);
        }
      }
      if (!$DB->fieldExists(self::getTable(), 'generate_immediately')) {
        // Adiciona coluna — executa imediatamente para poder migrar os dados a seguir
        $DB->doQuery("ALTER TABLE `" . self::getTable() . "` ADD COLUMN `generate_immediately` tinyint(1) NOT NULL DEFAULT '1'");
        // Converte etapas legadas TYPE_AUTO para HIERARCHY + generate_immediately=1
        if ($DB->fieldExists(self::getTable(), 'auto_mode')) {
          $DB->update(self::getTable(), ['type' => self::TYPE_HIERARCHY, 'generate_immediately' => 1, 'auto_hierarchy_dynamic' => 1],
            ['type' => 'auto', 'auto_mode' => 'hierarchy']);
          $DB->update(self::getTable(), ['type' => self::TYPE_PEOPLE, 'generate_immediately' => 1],
            ['type' => 'auto']);
        }
      }
      if (!$DB->fieldExists(self::getTable(), 'content')) {
        $DB->doQuery("ALTER TABLE `" . self::getTable() . "` ADD COLUMN `content` longtext COLLATE {$default_collation}");
      }
    }
  }

  static function uninstall() {
    global $DB;
    $DB->dropTable(self::getTable());
    return true;
  }

  public function cleanDBonPurge() {
    global $DB;
    $DB->delete(PluginEscalationStepUser::getTable(), ['plugin_escalation_steps_id' => $this->fields['id']]);
  }

  public function prepareInputForAdd($input) {
    if (empty($input['step_order']) && !empty($input['plugin_escalation_flows_id'])) {
      $input['step_order'] = self::getNextOrder((int) $input['plugin_escalation_flows_id']);
    }
    return $input;
  }

  static function canCreate(): bool { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }
  static function canView(): bool   { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }
  static function canUpdate(): bool { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }
  static function canPurge(): bool  { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }

  static function getTypeOptions(): array {
    return [
      self::TYPE_PEOPLE    => __('Pessoas', 'escalation'),
      self::TYPE_HIERARCHY => __('Hierarquia', 'escalation'),
    ];
  }

  static function getQuorumOptions(): array {
    return [
      self::QUORUM_MIN1     => __('Mínimo 1', 'escalation'),
      self::QUORUM_MAJORITY => __('Maioria', 'escalation'),
      self::QUORUM_ALL      => __('Todos', 'escalation'),
    ];
  }

  static function getTypeName_($type): string {
    return self::getTypeOptions()[$type] ?? $type;
  }

  static function getQuorumName($quorum): string {
    return self::getQuorumOptions()[$quorum] ?? $quorum;
  }

  // -------------------------------------------------------------------------
  // Builder UI (exibida na aba "Etapas" do fluxo)
  // -------------------------------------------------------------------------

  static function showForFlow(PluginEscalationFlow $flow) {
    global $DB;

    $flow_id = $flow->getID();
    if (!$flow->can($flow_id, READ)) return false;
    $canedit = $flow->can($flow_id, UPDATE);

    if ($canedit) {
      echo "<form action='" . static::getFormURL() . "' method='post'>";
      echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
      echo Html::hidden('plugin_escalation_flows_id', ['value' => $flow_id]);
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='tab_bg_1'><th colspan='12'>" . __('Adicionar Etapa', 'escalation') . "</th></tr>";
      echo "<tr class='tab_bg_2'>";

      // Nome
      echo "<td>" . __('Nome', 'escalation') . "</td>";
      echo "<td>" . Html::input('name', ['style' => 'width:140px']) . "</td>";

      // Tipo
      echo "<td>" . __('Tipo', 'escalation') . "</td>";
      echo "<td>";
      Dropdown::showFromArray('type', self::getTypeOptions(), [
        'value'     => self::TYPE_PEOPLE,
        'on_change' => "escalationInlineToggle(this.value)",
      ]);
      echo "</td>";

      // Reprovação
      echo "<td>" . __('Prazo (dias)', 'escalation') . "</td>";
      echo "<td>" . Html::input('timeout_days', ['value' => '0', 'type' => 'number', 'min' => '0', 'style' => 'width:80px']) . "</td>";

      // Hierarquia: nível + dinâmico (ocultos por padrão — tipo padrão é Pessoas)
      echo "<td id='il_hier_lbl' style='display:none'>" . __('Nível', 'escalation') . "</td>";
      $rand_dyn_il = 'il_dyn_' . mt_rand();
      echo "<td id='il_hier_fld' style='display:none'>";
      echo "<div class='d-flex align-items-center gap-2'>";
      echo Html::input('hierarchy_level', ['value' => '1', 'type' => 'number', 'min' => '1', 'style' => 'width:60px;flex-shrink:0']);
      echo "<div class='form-check mb-0'>";
      echo Html::getCheckbox([
        'name'          => 'auto_hierarchy_dynamic',
        'id'            => $rand_dyn_il,
        'value'         => '1',
        'zero_on_empty' => false,
        'specific_tags' => ['onchange' => "var n=document.querySelector('#il_hier_fld input[type=number]');if(n)n.style.opacity=this.checked?'0.3':'1'"],
      ]);
      echo "<label for='$rand_dyn_il' class='form-check-label'>" . __('Dinâmico', 'escalation') . "</label>";
      echo "</div>";
      echo "</div>";
      echo "</td>";

      // generate_immediately oculto com default 1
      echo Html::hidden('generate_immediately', ['value' => '1']);

      // Botão
      echo "<td>";
      echo "<button type='submit' name='add' class='btn btn-primary btn-sm me-1 pe-2'>";
      echo "<i class='ti ti-plus'></i><span class='d-none d-xxl-block'>" . _sx('button', 'Add') . "</span></button>";
      echo "</td>";
      echo "</tr></table>";

      echo "<script>
        function escalationInlineToggle(type) {
          var isHier = (type === 'hierarchy');
          ['il_hier_lbl','il_hier_fld'].forEach(function(id){
            var el = document.getElementById(id);
            if (el) el.style.display = isHier ? '' : 'none';
          });
        }
      </script>";
      Html::closeForm();
    }

    // Lista de etapas
    $draft_version_id = PluginEscalationFlowVersion::ensureDraft($flow_id);
    $steps = $DB->request([
      'FROM'    => self::getTable(),
      'WHERE'   => ['plugin_escalation_flows_id' => $flow_id, 'plugin_escalation_flowversions_id' => $draft_version_id],
      'ORDERBY' => ['step_order ASC'],
    ]);

    $steps_array = array_values(iterator_to_array($steps));
    if (empty($steps_array)) {
      echo "<p class='center'>" . __('Nenhuma etapa configurada.', 'escalation') . "</p>";
      return true;
    }

    echo "<div class='spaced'>";
    echo "<table class='tab_cadre_fixehov'>";
    echo "<tr class='headerRow'>";
    echo "<th>#</th>";
    echo "<th>" . __('Nome', 'escalation') . "</th>";
    echo "<th>" . __('Tipo', 'escalation') . "</th>";
    echo "<th>" . __('Gerar', 'escalation') . "</th>";
    echo "<th>" . __('Quórum', 'escalation') . "</th>";
    echo "<th>" . __('Prazo', 'escalation') . "</th>";
    echo "<th>" . __('Nível hierárquico', 'escalation') . "</th>";
    echo "<th>" . __('Fontes', 'escalation') . "</th>";
    if ($canedit) echo "<th>" . __('Ações', 'escalation') . "</th>";
    echo "</tr>";

    $total = count($steps_array);
    foreach ($steps_array as $i => $step) {
      $sources = self::buildSourcesLabel($step);
      echo "<tr class='tab_bg_" . (($i % 2) + 1) . "'>";
      echo "<td>" . ($i + 1) . "</td>";
      echo "<td><a href='" . static::getFormURL() . "?id=" . $step['id'] . "'>" . htmlspecialchars($step['name']) . "</a></td>";
      echo "<td>" . self::getTypeName_($step['type']) . "</td>";
      echo "<td>" . (!empty($step['generate_immediately'])
        ? "<span class='badge bg-success-lt'>" . __('Imediato', 'escalation') . "</span>"
        : "<span class='badge bg-secondary-lt'>" . __('Após solução', 'escalation') . "</span>")
        . "</td>";
      echo "<td>" . ($step['type'] === self::TYPE_PEOPLE ? self::getQuorumName($step['quorum_type']) : '—') . "</td>";
      echo "<td>" . ($step['timeout_days'] > 0 ? $step['timeout_days'] . 'd' : '—') . "</td>";
      echo "<td>";
      if ($step['type'] === self::TYPE_HIERARCHY) {
        if (!empty($step['auto_hierarchy_dynamic'])) {
          echo __('Dinâmico (nível do solicitante - 1)', 'escalation');
        } elseif ($step['hierarchy_level'] > 0) {
          echo sprintf(__('Nível %d', 'escalation'), $step['hierarchy_level']);
        } else {
          echo '—';
        }
      } else {
        echo '—';
      }
      echo "</td>";
      echo "<td>" . htmlspecialchars($sources) . "</td>";

      if ($canedit) {
        echo "<td class='nowrap'>";

        if ($i > 0) {
          echo "<form style='display:inline' action='" . static::getFormURL() . "' method='post'>";
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('id', ['value' => $step['id']]);
          echo Html::hidden('plugin_escalation_flows_id', ['value' => $flow_id]);
          echo "<button name='move_up' type='submit' class='btn btn-sm btn-ghost-secondary me-1 pe-2'><i class='ti ti-arrow-up'></i></button>";
          Html::closeForm();
        } else {
          echo "<button class='btn btn-sm me-1 pe-2' style='visibility:hidden' disabled><i class='ti ti-arrow-up'></i></button>";
        }

        if ($i < $total - 1) {
          echo "<form style='display:inline' action='" . static::getFormURL() . "' method='post'>";
          echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
          echo Html::hidden('id', ['value' => $step['id']]);
          echo Html::hidden('plugin_escalation_flows_id', ['value' => $flow_id]);
          echo "<button name='move_down' type='submit' class='btn btn-sm btn-ghost-secondary me-1 pe-2'><i class='ti ti-arrow-down'></i></button>";
          Html::closeForm();
        } else {
          echo "<button class='btn btn-sm me-1 pe-2' style='visibility:hidden' disabled><i class='ti ti-arrow-down'></i></button>";
        }

        echo "<a href='" . static::getFormURL() . "?id=" . $step['id'] . "' class='btn btn-sm btn-ghost-info me-1 pe-2'><i class='ti ti-pencil'></i></a>";

        echo "<form style='display:inline' action='" . static::getFormURL() . "' method='post'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('id', ['value' => $step['id']]);
        echo "<button name='purge' type='submit' class='btn btn-sm btn-ghost-danger pe-2' onclick=\"return confirm('" . __('Excluir esta etapa?', 'escalation') . "')\">";
        echo "<i class='ti ti-trash'></i></button>";
        Html::closeForm();

        echo "</td>";
      }
      echo "</tr>";
    }
    echo "</table></div>";
    return true;
  }

  function showForm($ID, array $options = []) {
    if ($ID > 0 && empty($this->fields['id'])) {
      $this->getFromDB($ID);
    } elseif ($ID <= 0 && empty($this->fields)) {
      $this->getEmpty();
    }

    $flow_id      = $this->fields['plugin_escalation_flows_id'] ?? ($_GET['plugin_escalation_flows_id'] ?? 0);
    $current_type = $this->fields['type'] ?? self::TYPE_PEOPLE;
    // Legado: se ainda vier como 'auto', trata como people até ser salvo
    if ($current_type === self::TYPE_AUTO) $current_type = self::TYPE_PEOPLE;
    $is_hier      = ($current_type === self::TYPE_HIERARCHY);
    $is_dyn       = !empty($this->fields['auto_hierarchy_dynamic']);
    $gen_immed    = !isset($this->fields['generate_immediately']) || !empty($this->fields['generate_immediately']);

    $this->initForm($ID, $options);
    $this->showFormHeader($options);

    echo Html::hidden('plugin_escalation_flows_id', ['value' => $flow_id]);

    // Linha 1: Nome + Tipo
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Nome', 'escalation') . "</td>";
    echo "<td>" . Html::input('name', ['value' => $this->fields['name'] ?? '']) . "</td>";
    echo "<td>" . __('Tipo', 'escalation') . "</td>";
    echo "<td>";
    Dropdown::showFromArray('type', self::getTypeOptions(), [
      'value'     => $current_type,
      'on_change' => "escalationToggleStepFields(this.value)",
    ]);
    echo "</td>";
    echo "</tr>";

    // Linha 2: Gerar imediatamente + Reprovação automática
    $rand_gi = 'gen_immed_' . mt_rand();
    echo "<tr class='tab_bg_2'>";
    echo "<td>" . __('Gerar imediatamente?', 'escalation');
    echo "<span class='form-help' style='margin-left:3px'
              data-bs-toggle='tooltip'
              data-bs-placement='top'
              data-bs-html='true'
              data-bs-title='" . __('Gera a aprovação assim que o chamado é criado (ou a etapa anterior for concluída). Desmarcado: aguarda o chamado ir para Solucionado.', 'escalation') . "'>
            ?
        </span>" . "</td>";
    echo "<td>";
    echo "<div class='d-flex align-items-center gap-1'>";
    echo "<div class='form-check mb-0'>";
    echo Html::getCheckbox([
      'name'          => 'generate_immediately',
      'id'            => $rand_gi,
      'value'         => '1',
      'checked'       => $gen_immed,
      'zero_on_empty' => true,
    ]);
    echo "</div>";
    echo "</div>";
    echo "</td>";
    echo "<td>" . __('Reprovação automática (dias)', 'escalation') . "</td>";
    echo "<td>";
    echo Html::input('timeout_days', [
      'value' => (int)($this->fields['timeout_days'] ?? 0),
      'type'  => 'number',
      'min'   => '0',
      'style' => 'width:80px',
    ]);
    echo "<div class='text-muted mt-1' style='font-size:85%'>" . __('0 = desativado', 'escalation') . "</div>";
    echo "</td>";
    echo "</tr>";

    // Linha 3: Template (para todos os tipos) + botão criar
    $tmpl_rand  = mt_rand();
    $tmpl_fid   = Html::cleanId("dropdown_plugin_escalation_templates_id{$tmpl_rand}");
    $tmpl_modal = "modal_add_tmpl_{$tmpl_rand}";
    $cur_tmpl   = (int) ($this->fields['plugin_escalation_templates_id'] ?? 0);
    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Modelo de validação', 'escalation') . "</td>";
    echo "<td colspan='3'>";
    $fid_esc = htmlspecialchars($tmpl_fid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo "<style>
      #{$fid_esc} ~ .select2-container { flex:1; min-width:0; }
      #{$fid_esc} ~ .select2-container .select2-selection.select2-selection--single { width:100% !important; max-width:none !important; }
    </style>";
    echo "<div class='btn-group btn-group-sm w-100' role='group'>";
    Dropdown::show('PluginEscalationTemplate', [
      'name'                => 'plugin_escalation_templates_id',
      'value'               => $cur_tmpl,
      'display_emptychoice' => true,
      'width'               => '100%',
      'rand'                => $tmpl_rand,
      'on_change'           => "escalationToggleStepContent(this.value)",
    ]);
    echo Ajax::createIframeModalWindow($tmpl_modal, PluginEscalationTemplate::getFormURL(), ['display' => false]);
    echo "<div class='btn btn-outline-secondary' data-bs-toggle='modal'"
       . " data-bs-target='#" . htmlspecialchars($tmpl_modal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "'"
       . " title='" . __s('Criar modelo', 'escalation') . "'>"
       . "<span data-bs-toggle='tooltip'><i class='ti ti-plus'></i></span>"
       . "</div>";
    echo "</div>";
    $fid_js   = json_encode($tmpl_fid);
    $mid_js   = json_encode($tmpl_modal);
    $ajax_url = json_encode(PluginEscalationConfig::getWebPath() . '/ajax/templates.php');
    echo Html::scriptBlock("
      \$(function() {
        document.getElementById({$mid_js})?.addEventListener('hidden.bs.modal', function() {
          var sel = document.getElementById({$fid_js});
          if (!sel) return;
          var existing = new Set(Array.from(sel.options).map(function(o) { return o.value; }));
          fetch({$ajax_url})
            .then(function(r) { return r.json(); })
            .then(function(items) {
              items.forEach(function(item) {
                if (!existing.has(String(item.id))) {
                  \$('#' + {$fid_js}).append(new Option(item.text, item.id, false, true));
                }
              });
              \$('#' + {$fid_js}).trigger('change');
            });
        });
      });
    ");
    echo "</td>";
    echo "</tr>";

    // Linha 3b: Conteúdo da aprovação (visível apenas quando nenhum modelo selecionado)
    $has_tmpl    = $cur_tmpl > 0;
    $cur_content = $this->fields['content'] ?? '';
    echo "<tr class='tab_bg_2' id='escalation_content_row'" . ($has_tmpl ? " style='display:none'" : '') . ">";
    echo "<td>" . __('Texto da aprovação', 'escalation') . "</td>";
    echo "<td colspan='3'>";
    Html::textarea(['name' => 'content', 'value' => $cur_content ?? '',
                'enable_richtext' => true, 'cols' => 100, 'rows' => 10]);
    echo "<div class='text-muted mt-1' style='font-size:85%'>"
       . __('Mensagem enviada aos aprovadores. Deixe vazio para não enviar mensagem.', 'escalation')
       . "</div>";
    echo "</td>";
    echo "</tr>";

    // Linha 4: Configurações de Hierarquia (visível apenas para tipo HIERARQUIA)
    $rand_dyn = 'hier_dyn_' . mt_rand();
    echo "<tr class='tab_bg_2' id='escalation_hier_row'" . ($is_hier ? '' : " style='display:none'") . ">";
    echo "<td>" . __('Hierarquia dinâmica?', 'escalation');
    echo "<span class='form-help' style='margin-left:3px'
              data-bs-toggle='tooltip'
              data-bs-placement='top'
              data-bs-html='true'
              data-bs-title='" . __('Usa o nível hierárquico do solicitante - 1 como alvo. O campo "Nível necessário" é ignorado quando ativo.', 'escalation') . "'>
            ?
        </span>" . "</td>";
    echo "<td>";
    echo "<div class='d-flex align-items-center gap-1'>";
    echo "<div class='form-check mb-0'>";
    echo Html::getCheckbox([
      'name'          => 'auto_hierarchy_dynamic',
      'id'            => $rand_dyn,
      'value'         => '1',
      'checked'       => $is_dyn,
      'zero_on_empty' => true,
      'onclick'       => 'escalationToggleHierDynamic(this.checked)',
    ]);
    echo "</div>";
    echo "</div>";
    echo "</td>";
    echo "<td id='hier_level_lbl'" . ($is_dyn ? " style='display:none'" : '') . ">" . __('Nível necessário', 'escalation') . "</td>";
    echo "<td id='hier_level_fld'" . ($is_dyn ? " style='display:none'" : '') . ">";
    echo Html::input('hierarchy_level', [
      'value' => (int)($this->fields['hierarchy_level'] ?? 1),
      'type'  => 'number',
      'min'   => '1',
      'style' => 'width:80px',
    ]);
    echo "<div class='text-muted mt-1' style='font-size:85%'>" . __('1 = nível mais alto da hierarquia', 'escalation') . "</div>";
    echo "</td>";
    echo "</tr>";

    // Linha 5: Quórum + Fontes (visível apenas para tipo PESSOAS)
    echo "<tr class='tab_bg_1' id='escalation_people_row'" . ($is_hier ? " style='display:none'" : '') . ">";
    echo "<td>" . __('Quórum', 'escalation') . "</td>";
    echo "<td>";
    Dropdown::showFromArray('quorum_type', self::getQuorumOptions(), [
      'value' => $this->fields['quorum_type'] ?? self::QUORUM_MIN1,
    ]);
    echo "</td>";
    echo "<td>" . __('Fontes de aprovadores', 'escalation') . "</td>";
    echo "<td>";
    $current_sources = [];
    if (!empty($this->fields['include_requesters'])) $current_sources[] = 'requesters';
    if (!empty($this->fields['include_assigned']))   $current_sources[] = 'assigned';
    if (!empty($this->fields['include_observers']))  $current_sources[] = 'observers';
    Dropdown::showFromArray('sources', [
      'requesters' => __('Requerentes', 'escalation'),
      'observers'  => __('Observadores', 'escalation'),
      'assigned'   => __('Atribuídos', 'escalation'),
    ], [
      'values'              => $current_sources,
      'multiple'            => true,
      'display_emptychoice' => false,
      'width'               => '80%',
    ]);
    echo "</td>";
    echo "</tr>";

    echo "<script>
      function escalationToggleStepFields(type) {
        var isHier  = (type === 'hierarchy');
        var hierRow    = document.getElementById('escalation_hier_row');
        var peopleRow  = document.getElementById('escalation_people_row');
        var usersBlock = document.getElementById('escalation_users_section');
        if (hierRow)    hierRow.style.display    = isHier ? '' : 'none';
        if (peopleRow)  peopleRow.style.display  = isHier ? 'none' : '';
        if (usersBlock) usersBlock.style.display = isHier ? 'none' : '';
      }

      function escalationToggleStepContent(tmplId) {
        var row = document.getElementById('escalation_content_row');
        if (row) row.style.display = (parseInt(tmplId) > 0) ? 'none' : '';
      }

      function escalationToggleHierDynamic(checked) {
        ['hier_level_lbl','hier_level_fld'].forEach(function(id){
          var el = document.getElementById(id);
          if (el) el.style.display = checked ? 'none' : '';
        });
      }
    </script>";

    echo "<tr class='tab_bg_2'><td>" . __('Impedir autoaprovação', 'escalation') . "</td><td>";
    Dropdown::showYesNo('prevent_self_approval', (int)($this->fields['prevent_self_approval'] ?? 1));
    echo "</td><td>" . __('Permitir solucionar', 'escalation') . "</td><td>";
    Dropdown::showYesNo('allow_solve', (int)($this->fields['allow_solve'] ?? 0));
    echo "</td></tr>";
    echo "<tr class='tab_bg_2'><td>" . __('Aprovador substituto', 'escalation') . "</td><td colspan='3'>";
    User::dropdown(['name' => 'fallback_users_id', 'value' => (int)($this->fields['fallback_users_id'] ?? 0), 'right' => 'all']);
    echo "</td></tr>";

    $this->showFormButtons($options);

    echo Html::scriptBlock("escalationToggleStepFields('" . addslashes($current_type) . "');");

    // Seção de usuários específicos (fora do form principal para evitar nesting)
    if ($ID > 0) {
      $users_style = $is_hier ? 'display:none' : '';
      echo "<hr class='mt-0 mb-4'>";
      echo "<div id='escalation_users_section' class='card' style='$users_style'>";
      echo "  <div class='card-header d-flex align-items-center py-2 ps-3'>";
      echo "    <i class='ti ti-users me-2 text-muted'></i>";
      echo "    <span class='fw-bold'>" . __('Usuários específicos', 'escalation') . "</span>";
      echo "  </div>";
      echo "  <div class='card-body p-3'>";
      PluginEscalationStepUser::showForStep($this, $flow_id);
      echo "  </div>";
      echo "</div>";
      PluginEscalationAdmin::showStepConfiguration($this);
    }

    return true;
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  static function buildSourcesLabel(array $step): string {
    $parts = [];
    if ($step['include_requesters']) $parts[] = __('Solicitantes', 'escalation');
    if ($step['include_assigned'])   $parts[] = __('Responsáveis', 'escalation');
    if ($step['include_observers'])  $parts[] = __('Observadores', 'escalation');

    $users = PluginEscalationStepUser::getUsersForStep($step['id']);
    foreach ($users as $u) {
      $user = new User();
      if ($user->getFromDB($u['users_id'])) {
        $parts[] = $user->getFriendlyName();
      }
    }
    return empty($parts) ? '—' : implode(', ', $parts);
  }

  static function getNextOrder(int $flow_id): int {
    global $DB;
    $row = $DB->request([
      'SELECT' => ['MAX' => 'step_order AS max_order'],
      'FROM'   => self::getTable(),
      'WHERE'  => ['plugin_escalation_flows_id' => $flow_id, 'plugin_escalation_flowversions_id' => PluginEscalationFlowVersion::ensureDraft($flow_id)],
    ])->current();
    return ($row['max_order'] ?? 0) + 1;
  }

  static function swapOrder(int $id_a, int $id_b): void {
    global $DB;
    $a = (new self());
    $a->getFromDB($id_a);
    $b = (new self());
    $b->getFromDB($id_b);
    $DB->update(self::getTable(), ['step_order' => $b->fields['step_order']], ['id' => $id_a]);
    $DB->update(self::getTable(), ['step_order' => $a->fields['step_order']], ['id' => $id_b]);
  }

  static function getAdjacentStep(int $id, string $direction): ?array {
    global $DB;
    $current = (new self());
    $current->getFromDB($id);
    $flow_id = $current->fields['plugin_escalation_flows_id'];
    $version_id = $current->fields['plugin_escalation_flowversions_id'] ?? 0;
    $order   = $current->fields['step_order'];

    if ($direction === 'up') {
      $row = $DB->request([
        'FROM'    => self::getTable(),
        'WHERE'   => ['plugin_escalation_flows_id' => $flow_id, 'plugin_escalation_flowversions_id' => $version_id, ['step_order' => ['<', $order]]],
        'ORDERBY' => ['step_order DESC'],
        'LIMIT'   => 1,
      ])->current();
    } else {
      $row = $DB->request([
        'FROM'    => self::getTable(),
        'WHERE'   => ['plugin_escalation_flows_id' => $flow_id, 'plugin_escalation_flowversions_id' => $version_id, ['step_order' => ['>', $order]]],
        'ORDERBY' => ['step_order ASC'],
        'LIMIT'   => 1,
      ])->current();
    }
    return $row ?: null;
  }
}
