<?php
namespace GlpiPlugin\Escalation\Tests;

use PHPUnit\Framework\TestCase;

final class ConditionTest extends TestCase {
  public function testScalarAndListOperators(): void {
    $ticket = new \Ticket();
    $ticket->fields = ['status' => 2, 'type' => 1, 'itilcategories_id' => 9];
    self::assertTrue(\PluginEscalationCondition::matches(['field'=>'status','operator'=>'equals','value'=>'2'], $ticket, 'updated'));
    self::assertTrue(\PluginEscalationCondition::matches(['field'=>'category','operator'=>'in','value'=>'[8,9]'], $ticket, 'updated'));
    self::assertTrue(\PluginEscalationCondition::matches(['field'=>'event','operator'=>'not_equals','value'=>'created'], $ticket, 'updated'));
  }

  public function testStatusTransitionOperatorsRequireARealChange(): void {
    $ticket = new \Ticket();
    $ticket->fields = ['status' => 5];
    self::assertTrue(\PluginEscalationCondition::matches(['field'=>'status','operator'=>'changed_from','value'=>'2'], $ticket, 'updated', ['status'=>2]));
    self::assertTrue(\PluginEscalationCondition::matches(['field'=>'status','operator'=>'changed_to','value'=>'5'], $ticket, 'updated', ['status'=>2]));
    self::assertFalse(\PluginEscalationCondition::matches(['field'=>'status','operator'=>'changed_to','value'=>'5'], $ticket, 'created'));
  }
}
