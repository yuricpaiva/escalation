<?php

if (!defined('GLPI_ROOT')) {
  die("Sorry. You can't access this file directly");
}

class PluginEscalationFlow extends CommonDBTM {
  static $rightname = 'config';

  static function getTypeName($nb = 0) {
    return _n('Fluxo', 'Fluxos', $nb, 'escalation');
  }

  static function getIcon() {
    return 'ti ti-sitemap';
  }

  static function installBaseData(Migration $migration, $version) {
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists(self::getTable())) {
      $DB->doQuery("CREATE TABLE `" . self::getTable() . "` (
        `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
        `name` varchar(255) COLLATE {$default_collation} NOT NULL,
        `is_active` tinyint(1) NOT NULL DEFAULT '0',
        `timeout_days` int NOT NULL DEFAULT '0',
        `date_mod` timestamp NULL DEFAULT NULL,
        `date_creation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `is_active` (`is_active`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation};");
    } else {
      if ($DB->fieldExists(self::getTable(), 'timeout_hours') && !$DB->fieldExists(self::getTable(), 'timeout_days')) {
        $DB->doQuery("ALTER TABLE `" . self::getTable() . "` CHANGE `timeout_hours` `timeout_days` int NOT NULL DEFAULT '0'");
      } elseif (!$DB->fieldExists(self::getTable(), 'timeout_days')) {
        $migration->addField(self::getTable(), 'timeout_days', "int NOT NULL DEFAULT '0'");
      }
      // itilcategories_id foi movido para tabela de junção — veja hook.php
    }

    // Colunas padrão da listagem: ativo, categorias (lista), timeout
    if (countElementsInTable('glpi_displaypreferences', ['itemtype' => self::class, 'users_id' => 0]) === 0) {
      $dp = new DisplayPreference();
      foreach ([2 => 1, 5 => 2, 4 => 3] as $num => $rank) {
        $dp->add(['itemtype' => self::class, 'num' => $num, 'rank' => $rank, 'users_id' => 0]);
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
    // Cascata: apagar categorias associadas
    $DB->delete(PluginEscalationFlowCategory::getTable(), ['plugin_escalation_flows_id' => $this->fields['id']]);
    // Cascata: apagar etapas (que apagam usuários específicos)
    $steps = $DB->request(['FROM' => PluginEscalationStep::getTable(), 'WHERE' => ['plugin_escalation_flows_id' => $this->fields['id']]]);
    foreach ($steps as $step) {
      $obj = new PluginEscalationStep();
      $obj->getFromDB($step['id']);
      $obj->cleanDBonPurge();
      $DB->delete(PluginEscalationStep::getTable(), ['id' => $step['id']]);
    }
  }

  public function prepareInputForAdd($input) {
    if (isset($input['itilcategories_ids'])) {
      $this->_categories_ids = (array)$input['itilcategories_ids'];
      unset($input['itilcategories_ids']);
    }
    return $input;
  }

  public function prepareInputForUpdate($input) {
    if (isset($input['itilcategories_ids'])) {
      $this->_categories_ids = (array)$input['itilcategories_ids'];
      unset($input['itilcategories_ids']);
    }
    return $input;
  }

  public function post_addItem() {
    if (isset($this->_categories_ids)) {
      PluginEscalationFlowCategory::saveForFlow($this->getID(), $this->_categories_ids);
      unset($this->_categories_ids);
    }
    if ($this->getID() > 0) PluginEscalationFlowVersion::syncDraftMetadata($this->getID());
  }

  public function post_updateItem($history = true) {
    if (isset($this->_categories_ids)) {
      PluginEscalationFlowCategory::saveForFlow($this->getID(), $this->_categories_ids);
      unset($this->_categories_ids);
    }
    PluginEscalationFlowVersion::syncDraftMetadata($this->getID());
  }

  static function canCreate(): bool { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }
  static function canView(): bool   { return PluginEscalationProfile::have(PluginEscalationProfile::VIEW) || PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }
  static function canUpdate(): bool { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }
  static function canPurge(): bool  { return PluginEscalationProfile::have(PluginEscalationProfile::MANAGE); }

  static function getMenuContent() {
    if (!static::canView()) return false;
    $p = PluginEscalationConfig::getWebPath(false);
    $lbl_flows = self::getTypeName(2);
    $lbl_tmpls = _n('Modelo', 'Modelos', 2, 'escalation');
    $menu = [
      'title' => __('Escalation', 'escalation'),
      'page'  => "$p/front/flow.php",
      'icon'  => static::getIcon(),
      'links' => [
        '<i class="ti ti-sitemap me-1"></i>' . $lbl_flows => "$p/front/flow.php",
        '<i class="ti ti-file-description me-1"></i>' . $lbl_tmpls => "$p/front/template.php",
      ],
      'options' => [
        'flow' => [
          'title' => $lbl_flows,
          'page'  => "$p/front/flow.php",
          'icon'  => 'ti ti-sitemap',
          'links' => [
            'add'    => "$p/front/flow.form.php",
            'search' => "$p/front/flow.php",
            '<i class="ti ti-file-import me-1"></i>' . __('Importar workflow', 'escalation') => "$p/front/import.form.php",
            '<i class="ti ti-file-description me-1"></i>' . $lbl_tmpls => "$p/front/template.php",
          ],
        ],
        'template' => [
          'title' => $lbl_tmpls,
          'page'  => "$p/front/template.php",
          'icon'  => 'ti ti-file-description',
          'links' => [
            'add'    => "$p/front/template.form.php",
            'search' => "$p/front/template.php",
            '<i class="ti ti-sitemap me-1"></i>' . $lbl_flows => "$p/front/flow.php",
          ],
        ],
      ],
    ];
    return $menu;
  }

  public function rawSearchOptions() {
    $tab = [];
    $tab[] = ['id' => 'common', 'name' => __('Characteristics')];
    $tab[] = [
      'id' => '1', 'table' => $this->getTable(), 'field' => 'name',
      'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false,
    ];
    $tab[] = [
      'id' => '2', 'table' => $this->getTable(), 'field' => 'is_active',
      'name' => __('Active'), 'datatype' => 'bool', 'massiveaction' => true,
    ];
    $tab[] = [
      'id'            => '3',
      'table'         => 'glpi_itilcategories',
      'field'         => 'completename',
      'name'          => __('Categorias ITIL', 'escalation'),
      'datatype'      => 'dropdown',
      'massiveaction' => false,
      'forcegroupby'  => true,
      'joinparams'    => [
        'beforejoin' => [
          'table'      => PluginEscalationFlowCategory::getTable(),
          'joinparams' => ['jointype' => 'child'],
        ],
      ],
    ];
    $tab[] = [
      'id' => '4', 'table' => $this->getTable(), 'field' => 'timeout_days',
      'name' => __('Reprovação automática global (dias)', 'escalation'), 'datatype' => 'number', 'massiveaction' => false,
    ];
    $tab[] = [
      'id'          => '5',
      'table'       => $this->getTable(),
      'field'       => 'id',
      'name'        => __('Categorias (lista)', 'escalation'),
      'datatype'    => 'text',
      'massiveaction' => false,
      'nosearch'    => true,
      'computation' => "(SELECT GROUP_CONCAT(DISTINCT `_ic`.`completename` ORDER BY `_ic`.`completename` SEPARATOR ', ')
                         FROM `glpi_plugin_escalation_flowcategories` `_fc`
                         JOIN `glpi_itilcategories` `_ic` ON `_ic`.`id` = `_fc`.`itilcategories_id`
                         WHERE `_fc`.`plugin_escalation_flows_id` = `glpi_plugin_escalation_flows`.`id`)",
    ];
    return $tab;
  }

  function showForm($ID, array $options = []) {
    global $DB;

    $this->initForm($ID, $options);
    $this->showFormHeader($options);

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Name') . "</td>";
    echo "<td>" . Html::input('name', ['value' => $this->fields['name']]) . "</td>";
    echo "<td>" . __('Active') . "</td>";
    echo "<td>"; Dropdown::showYesNo('is_active', $this->fields['is_active']); echo "</td>";
    echo "</tr>";

    if ($this->isNewItem()) {
      echo "<tr class='tab_bg_2'><td>" . __('Como deseja começar?', 'escalation') . "</td><td colspan='3'>";
      Dropdown::showFromArray('visual_template', [
        'empty' => __('Fluxo vazio', 'escalation'),
        'simple' => __('Aprovação simples', 'escalation'),
        'double' => __('Aprovação em duas etapas', 'escalation'),
        'assignment' => __('Duas aprovações e atribuição final', 'escalation'),
      ], ['value' => 'simple', 'width' => '100%']);
      echo "<small class='text-muted d-block mt-1'>" . __('Depois de criar, você será levado ao Editor visual para escolher as pessoas e as regras.', 'escalation') . '</small></td></tr>';
    }

    echo "<tr class='tab_bg_2'>";
    echo "<td>" . __('Descrição', 'escalation') . "</td><td>" . Html::textarea(['name' => 'description', 'value' => $this->fields['description'] ?? '', 'rows' => 3, 'cols' => 50, 'display' => false]) . "</td>";
    echo "<td>" . __('Prioridade', 'escalation') . "</td><td>" . Html::input('priority', ['value' => (int)($this->fields['priority'] ?? 0), 'type' => 'number']) . "</td>";
    echo "</tr>";
    echo "<tr class='tab_bg_1'><td>" . __('Entidade', 'escalation') . "</td><td>";
    Entity::dropdown(['name' => 'entities_id', 'value' => (int)($this->fields['entities_id'] ?? 0), 'entity' => $_SESSION['glpiactiveentities'] ?? 0]);
    echo "</td><td>" . __('Recursivo', 'escalation') . "</td><td>"; Dropdown::showYesNo('is_recursive', (int)($this->fields['is_recursive'] ?? 0)); echo "</td></tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Categorias ITIL', 'escalation') . "</td>";
    echo "<td colspan='3'>";
    $current_cats = $this->isNewItem() ? [] : PluginEscalationFlowCategory::getForFlow($this->getID());
    $cat_iter = $DB->request([
      'FROM'    => 'glpi_itilcategories',
      'ORDERBY' => ['completename ASC'],
    ]);
    $cat_options = [];
    foreach ($cat_iter as $cat) {
      $cat_options[$cat['id']] = $cat['completename'];
    }

    $cat_rand  = mt_rand();
    $cat_fid   = Html::cleanId("dropdown_itilcategories_ids{$cat_rand}");
    $cat_modal = "modal_add_itilcat_{$cat_rand}";
    $can_add   = ITILCategory::canCreate();

    if ($can_add) {
      echo "<div class='btn-group btn-group-sm' role='group' style='width:100%'>";
    }
    Dropdown::showFromArray('itilcategories_ids', $cat_options, [
      'values'              => $current_cats,
      'multiple'            => true,
      'display_emptychoice' => false,
      'width'               => $can_add ? 'calc(100% - 0.9em - 18px)' : '100%',
      'rand'                => $cat_rand,
    ]);
    if ($can_add) {
      echo Ajax::createIframeModalWindow($cat_modal, ITILCategory::getFormURL(), ['display' => false]);
      echo "<div class='btn btn-outline-secondary' data-bs-toggle='modal'"
         . " data-bs-target='#" . htmlspecialchars($cat_modal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "'"
         . " title='" . __s('Add') . "'>"
         . "<span data-bs-toggle='tooltip'><i class='ti ti-plus'></i></span>"
         . "</div>";
      echo "</div>";

      $fid_js = json_encode($cat_fid);
      $mid_js = json_encode($cat_modal);
      echo Html::scriptBlock("
        \$(function() {
          document.getElementById({$mid_js})?.addEventListener('hidden.bs.modal', function() {
            var sel = document.getElementById({$fid_js});
            if (!sel) return;
            var existing = new Set(Array.from(sel.options).map(function(o) { return o.value; }));
            fetch(CFG_GLPI.root_doc + '/plugins/escalation/ajax/itilcategories.php')
              .then(function(r) { return r.json(); })
              .then(function(cats) {
                cats.forEach(function(cat) {
                  if (!existing.has(String(cat.id))) {
                    \$('#' + {$fid_js}).append(new Option(cat.text, cat.id, false, false));
                  }
                });
                \$('#' + {$fid_js}).trigger('change');
              });
          });
        });
      ");
    }
    echo "</td>";
    echo "</tr>";

    echo "<tr class='tab_bg_1'>";
    echo "<td>" . __('Reprovação automática global (dias)', 'escalation') . "</td>";
    echo "<td colspan='3'>";
    echo Html::input('timeout_days', ['value' => (int)($this->fields['timeout_days'] ?? 0), 'type' => 'number', 'min' => '0', 'style' => 'width:80px']);
    echo "&nbsp;<small class='text-muted'>" . __('0 = sem reprovação automática', 'escalation') . "</small>";
    echo "</td>";
    echo "</tr>";

    $this->showFormButtons($options);
    return true;
  }

  function defineTabs($options = []) {
    $ong = [];
    $this->addDefaultFormTab($ong);
    $this->addStandardTab(__CLASS__, $ong, $options);
    $this->addStandardTab('PluginEscalationAdmin', $ong, $options);
    return $ong;
  }

  public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
    if ($item->getType() === static::class && !$item->isNewItem()) {
      return [
        1 => CommonGLPI::createTabEntry(__('Editor visual', 'escalation'), 0, static::class, 'ti ti-route'),
        2 => CommonGLPI::createTabEntry(__('Modo avançado', 'escalation'), 0, static::class, 'ti ti-adjustments'),
        3 => CommonGLPI::createTabEntry(__('Etapas (legado)', 'escalation'), 0, static::class, 'ti ti-list'),
      ];
    }
    return '';
  }

  public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
    if ($item->getType() === static::class && $tabnum == 1) {
      PluginEscalationVisualEditor::showForFlow($item);
    } elseif ($item->getType() === static::class && $tabnum == 2) {
      PluginEscalationAdmin::showForFlow($item);
    } elseif ($item->getType() === static::class && $tabnum == 3) {
      PluginEscalationStep::showForFlow($item);
    }
    return true;
  }

  // -------------------------------------------------------------------------
  // Hooks
  // -------------------------------------------------------------------------

  static function ticketAdd($ticket) {
    if ($ticket instanceof Ticket) PluginEscalationWorkflow::handleTicket($ticket, 'created');
  }

  static function preTicketUpdate($ticket) {
    // TODO: bloquear alterações de status enquanto o fluxo estiver pendente (opcional)
    // Exemplo: impedir fechar o chamado enquanto aprovação não foi concluída
  }

  static function ticketUpdate(Ticket $ticket): void {
    PluginEscalationWorkflow::handleTicket($ticket, 'updated');
    // `oldvalues` só traz `status` quando o status mudou nesta atualização; sem essa
    // checagem, qualquer update de um chamado já solucionado (ex.: o próprio GLPI
    // atualizando a validação global) era tratado como "acabou de ser solucionado".
    if (!array_key_exists('status', $ticket->oldvalues ?? [])) return;
    $new_status = (int) ($ticket->fields['status'] ?? 0);
    $old_status = (int) $ticket->oldvalues['status'];
    // Quando chamado vai para "Solucionado", ativa etapas com generate_immediately = 0
    if ($new_status === Ticket::SOLVED && $old_status !== Ticket::SOLVED) {
      PluginEscalationTicketFlow::ticketSolved($ticket);
    }
  }

  /** GLPI 11 passa o NotificationTarget; versões antigas passavam o array de eventos. */
  static function notificationEvents($target) {
    $events = [
      'escalation_workflow' => __('Evento de workflow Escalation', 'escalation'),
      'escalation_approved' => __('Workflow Escalation aprovado', 'escalation'),
      'escalation_rejected' => __('Workflow Escalation rejeitado', 'escalation'),
      'escalation_failed' => __('Falha em workflow Escalation', 'escalation'),
    ];
    if (is_object($target) && isset($target->events) && is_array($target->events)) {
      $target->events = array_merge($target->events, $events);
      return;
    }
    if (is_array($target)) {
      return array_merge($target, $events);
    }
  }
}
