<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * Runs one registered script command.
 *
 * The interpreter checks the command against its definition's fields before
 * calling the handler, so a handler reads values the definition promises.
 * A handler throws to fail the script closed; the interpreter adds the
 * script, lane and command path to the message.
 *
 * Handlers are created with no constructor arguments when a command runs.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
interface ScriptCommandHandlerInterface
{
  /**
   * Runs the command.
   *
   * @param ScriptCommandContext $context The running script.
   * @param array<string, mixed> $command The authored command.
   * @return ScriptCommandOutcome Whether the script continues now or waits.
   */
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome;
}
