<?php
namespace GlpiPlugin\Escalation\Tests;

use PHPUnit\Framework\TestCase;

final class ActionParametersTest extends TestCase {
  public function testRequiredTypedIdentifiers(): void {
    self::assertNotEmpty(\PluginEscalationActionExecutor::validateParameters('assign_user', ['users_id' => '7']));
    self::assertSame([], \PluginEscalationActionExecutor::validateParameters('assign_user', ['users_id' => 7]));
    self::assertNotEmpty(\PluginEscalationActionExecutor::validateParameters('change_category', ['itilcategories_id' => 0]));
  }

  public function testTextAndPrivacyParameters(): void {
    self::assertNotEmpty(\PluginEscalationActionExecutor::validateParameters('add_followup', ['content' => '']));
    self::assertNotEmpty(\PluginEscalationActionExecutor::validateParameters('add_followup', ['content' => 'ok', 'is_private' => 'yes']));
    self::assertSame([], \PluginEscalationActionExecutor::validateParameters('add_followup', ['content' => 'ok', 'is_private' => true]));
  }
}
