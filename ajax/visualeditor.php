<?php

if (!defined('GLPI_ROOT')) {
  include('../../../inc/includes.php');
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $input = $method === 'POST' ? $_POST : $_GET;
  if (!is_array($input)) $input = [];
  if ($method === 'POST' && isset($input['payload'])) {
    $payload = json_decode((string) $input['payload'], true, 64, JSON_THROW_ON_ERROR);
    if (is_array($payload)) $input = array_merge($input, $payload);
  }
  $action = (string) ($input['action'] ?? 'load');

  $result = match ($action) {
    'token' => ['csrfToken' => Session::getNewCSRFToken()],
    'load' => PluginEscalationVisualEditor::load((int) ($input['flowId'] ?? 0)),
    'validate' => ['ok' => !($errors = PluginEscalationVisualEditor::validateDefinition((int) ($input['flowId'] ?? 0), (array) ($input['definition'] ?? []))), 'errors' => $errors],
    'save' => PluginEscalationVisualEditor::save((int) ($input['flowId'] ?? 0), (array) ($input['definition'] ?? []), (int) ($input['revision'] ?? 0)),
    'publish' => PluginEscalationVisualEditor::publish((int) ($input['flowId'] ?? 0), (array) ($input['definition'] ?? []), (int) ($input['revision'] ?? 0)),
    'options' => ['items' => PluginEscalationVisualEditor::options(
      (string) ($input['kind'] ?? ''),
      (string) ($input['search'] ?? ''),
      array_filter(array_map('intval', preg_split('/[,\s]+/', (string) ($input['ids'] ?? '')) ?: []))
    )],
    default => throw new InvalidArgumentException(__('Operação desconhecida.', 'escalation')),
  };
  echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
  http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
  echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
