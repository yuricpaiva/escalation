<?php
if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

final class PluginEscalationProfile extends CommonDBTM {
  public static $rightname='profile';
  public const VIEW='plugin_escalation_view';
  public const MANAGE='plugin_escalation_manage';
  public const PUBLISH='plugin_escalation_publish';
  public const REPROCESS='plugin_escalation_reprocess';
  public static function getTypeName($nb=0){return __('Escalation','escalation');}
  public static function getAllRights($all=false): array {return [
    ['rights'=>[READ=>__('Visualizar detalhes','escalation')],'label'=>__('Monitoramento','escalation'),'field'=>self::VIEW],
    ['rights'=>[READ=>__('Administrar definições','escalation')],'label'=>__('Definições','escalation'),'field'=>self::MANAGE],
    ['rights'=>[READ=>__('Publicar versões','escalation')],'label'=>__('Publicação','escalation'),'field'=>self::PUBLISH],
    ['rights'=>[READ=>__('Reprocessar falhas','escalation')],'label'=>__('Reprocessamento','escalation'),'field'=>self::REPROCESS],
  ];}
  public function getTabNameForItem(CommonGLPI $item,$withtemplate=0){return $item instanceof Profile && $item->getID()?self::createTabEntry(self::getTypeName()):'';}
  public static function displayTabContentForItem(CommonGLPI $item,$tabnum=1,$withtemplate=0){if($item instanceof Profile){echo "<form method='post' action='".htmlescape(Profile::getFormURL())."'>".Html::hidden('_glpi_csrf_token',['value'=>Session::getNewCSRFToken()]).Html::hidden('id',['value'=>$item->getID()]);$item->displayRightsChoiceMatrix(self::getAllRights(),['title'=>__('Direitos do Escalation','escalation'),'canedit'=>Session::haveRight('profile',UPDATE)]);echo "<button class='btn btn-primary mt-3' name='update'><i class='ti ti-device-floppy me-1'></i>"._sx('button','Save')."</button></form>";}return true;}
  public static function have(string $right): bool {return Session::haveRight($right,READ)||Session::haveRight('config',UPDATE);}
}
