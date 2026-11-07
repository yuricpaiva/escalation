<?php
define('GLPI_ROOT', __DIR__);
if (!function_exists('__')) { function __(string $message, ?string $domain = null): string { return $message; } }
class CommonDBTM {}
class Ticket { public array $fields=[]; public function getID(): int { return 1; } }
class PluginEscalationStep {
  public const QUORUM_MIN1='min1';
  public const QUORUM_MAJORITY='majority';
  public const QUORUM_ALL='all';
  public const TYPE_HIERARCHY='hierarchy';
}
require_once dirname(__DIR__) . '/inc/approval.class.php';
require_once dirname(__DIR__) . '/inc/actionexecutor.class.php';
require_once dirname(__DIR__) . '/inc/condition.class.php';
