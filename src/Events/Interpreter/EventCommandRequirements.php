<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicFramingDefinition;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use InvalidArgumentException;

/** Shared runtime and authoring availability, including reusable-script call sites. */
final class EventCommandRequirements
{
  public const array CINEMATIC_COMMAND_TYPES = ['framing'];

  public static function isAvailable(string $type, EventScriptContext $context): bool
  {
    return !in_array($type, self::CINEMATIC_COMMAND_TYPES, true)
      || in_array($context, [EventScriptContext::CINEMATIC, EventScriptContext::REUSABLE], true);
  }

  public static function assertRequiredContext(string $type, EventScriptContext $context, string $path = 'script'): void
  {
    if (!self::isAvailable($type, $context)) {
      throw new InvalidArgumentException(sprintf('%s: "%s" requires an active field cinematic.', $path, $type));
    }
  }

  public static function assertAvailable(string $type, EventScriptContext $context, string $path = 'script'): void
  {
    self::assertRequiredContext($type, $context, $path);
    $scope = $context->getScope();
    if ($scope === null) { return; }
    if (in_array($type, CinematicCommandSchema::COMMAND_TYPES, true)) {
      $allowed = $scope->allowsBuiltInCommand($type, $context === EventScriptContext::CINEMATIC);
    } else {
      $allowed = ScriptCommandRegistry::getCatalog()->findDefinition($type)?->runsIn($scope) ?? true;
    }
    if (!$allowed) {
      throw new InvalidArgumentException(sprintf('%s: "%s" cannot run in %s.', $path, $type, $scope->getLabel()));
    }
  }

  /**
   * Validate a local tree; supply the common-event reader to validate its reachable call graph too.
   * Common events have direct references and never inherit their caller's private binding keys.
   * Cycles retain the caller's context and are inspected once per active call chain.
   *
   * @param array<int, mixed> $commands
   * @param null|callable(string): array $readCommonEvent Reads a selected common event without a runtime.
   */
  public static function validate(
    array $commands,
    EventScriptContext $context,
    ?callable $readCommonEvent = null,
    ?CinematicFramingDefinition $framing = null,
    string $path = 'script',
  ): void {
    self::validateCommands($commands, $context, $readCommonEvent, $framing ?? new CinematicFramingDefinition(), $path, [], true);
  }

  /**
   * Before session acquisition, validate context-owned requirements without changing the historical
   * command-failure lifecycle. Ordinary field/battle scope faults still fail their active session.
   */
  public static function validateRuntimeRequirements(array $commands, EventScriptContext $context,
    ?CinematicFramingDefinition $framing = null, string $path = 'script'): void
  {
    self::validateCommands($commands, $context, null, $framing ?? new CinematicFramingDefinition(), $path, [], false);
  }

  private static function validateCommands(array $commands, EventScriptContext $context, ?callable $reader,
    CinematicFramingDefinition $framing, string $path, array $activeCalls, bool $validateScope): void
  {
    foreach ($commands as $index => $command) {
      if (!is_array($command)) { continue; } // Historical event scripts retain their permissive shape boundary.
      $type = is_string($command['type'] ?? null) ? $command['type'] : '';
      $commandPath = sprintf('%s[%s]', $path, is_int($index) ? $index + 1 : $index);
      if ($validateScope) { self::assertAvailable($type, $context, $commandPath); }
      else { self::assertRequiredContext($type, $context, $commandPath); }
      if ($type === 'framing') {
        try {
          if (!is_array($command['subjects'] ?? null)) {
            throw new InvalidArgumentException('A framing phase requires a subject list.');
          }
          $framing->getSubjectReferences($command['subjects']);
        } catch (InvalidArgumentException $error) {
          throw new InvalidArgumentException($commandPath . ': ' . $error->getMessage(), previous: $error);
        }
      }
      foreach (CinematicCommandSchema::getCommandBlocks($command) as $suffix => $block) {
        self::validateCommands($block, $context, $reader, $framing, $commandPath . '/' . $suffix, $activeCalls, $validateScope);
      }
      if ($type === 'common_event' && $reader !== null) {
        $id = $command['id'] ?? null;
        if (!is_string($id) || preg_match('/\A[a-zA-Z0-9._-]+\z/', $id) !== 1) {
          throw new InvalidArgumentException($commandPath . ': common_event requires a safe stable id.');
        }
        if (isset($activeCalls[$id])) { continue; }
        $body = $reader($id);
        if (!is_array($body) || !array_is_list($body)) {
          throw new InvalidArgumentException($commandPath . ': a common event must return a command list.');
        }
        self::validateCommands($body, $context, $reader, new CinematicFramingDefinition(),
          $commandPath . '/common_event:' . $id, [...$activeCalls, $id => true], $validateScope);
      }
    }
  }
}
