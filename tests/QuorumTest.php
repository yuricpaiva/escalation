<?php
namespace GlpiPlugin\Escalation\Tests;
use PHPUnit\Framework\TestCase;

final class QuorumTest extends TestCase {
  public function testMinimumOneWaitsUntilAnApprovalOrAllReject(): void {
    self::assertSame('pending', \PluginEscalationApproval::evaluateQuorum('min1', 3, 0, 2));
    self::assertSame('approved', \PluginEscalationApproval::evaluateQuorum('min1', 3, 1, 0));
    self::assertSame('rejected', \PluginEscalationApproval::evaluateQuorum('min1', 3, 0, 3));
  }
  public function testMajorityIsStrictForEvenAndOddTotals(): void {
    self::assertSame('pending', \PluginEscalationApproval::evaluateQuorum('majority', 2, 1, 0));
    self::assertSame('approved', \PluginEscalationApproval::evaluateQuorum('majority', 2, 2, 0));
    self::assertSame('approved', \PluginEscalationApproval::evaluateQuorum('majority', 3, 2, 0));
    self::assertSame('rejected', \PluginEscalationApproval::evaluateQuorum('majority', 3, 1, 2));
  }
  public function testAllRejectsOnFirstRefusal(): void {
    self::assertSame('pending', \PluginEscalationApproval::evaluateQuorum('all', 3, 2, 0));
    self::assertSame('rejected', \PluginEscalationApproval::evaluateQuorum('all', 3, 2, 1));
    self::assertSame('approved', \PluginEscalationApproval::evaluateQuorum('all', 3, 3, 0));
  }
}
