<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * Writes an income command's amount to the variable the script named.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class IncomeCommandResult
{
  private function __construct() {}

  /**
   * @param ScriptCommandContext $context The running script.
   * @param array<string, mixed> $command The command; its optional `resultVariable` names the variable.
   * @param int $amount The amount paid or collected.
   */
  public static function write(ScriptCommandContext $context, array $command, int $amount): void
  {
    $variable = trim(strval($command['resultVariable'] ?? ''));
    if ($variable !== '') {
      $context->scene->gameState->setVariable($variable, $amount);
    }
  }
}
