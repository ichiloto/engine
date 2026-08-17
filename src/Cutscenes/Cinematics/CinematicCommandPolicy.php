<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use InvalidArgumentException;

/** Semantic safety rules shared by cinematic hydration and authoring tools. */
final class CinematicCommandPolicy
{
  /** @param array<int, array<string, mixed>> $commands */
  public static function assertAuthoredSkipSafe(array $commands, string $cinematicId): void
  {
    self::inspectSkipCommands($commands, $cinematicId, 'script');
  }

  /** @param array<int, array<string, mixed>> $commands */
  public static function assertFinalizer(array $commands, string $cinematicId): void
  {
    foreach ($commands as $index => $command) {
      $path = sprintf('finalizer[%d]', $index + 1);

      if (! is_array($command) || array_is_list($command)) {
        throw self::failure($cinematicId, $path, 'finalizer entries must be keyed command arrays.');
      }

      $type = trim(strval($command['type'] ?? ''));

      if (! in_array($type, CinematicCommandSchema::FINALIZER_COMMAND_TYPES, true)) {
        throw self::failure($cinematicId, $path, sprintf(
          'finalizer command uses unsafe type "%s".',
          $type !== '' ? $type : '(empty)',
        ));
      }

      self::assertFinalizerShape($command, $cinematicId, $path, $type);
    }
  }

  /** @param array<int, array<string, mixed>> $commands */
  private static function inspectSkipCommands(array $commands, string $cinematicId, string $path): void
  {
    foreach ($commands as $index => $command) {
      $commandPath = sprintf('%s[%d]', $path, $index + 1);
      $type = trim(strval($command['type'] ?? ''));

      if (in_array($type, CinematicCommandSchema::UNSAFE_AUTHORED_SKIP_COMMAND_TYPES, true)) {
        throw self::failure($cinematicId, $commandPath, sprintf(
          'authored skipping cannot reach irreversible command "%s".',
          $type,
        ));
      }

      if ($type === 'common_event') {
        throw self::failure($cinematicId, $commandPath, sprintf(
          'authored skipping cannot prove Common Event "%s" safe; Common Events are rejected conservatively.',
          trim(strval($command['id'] ?? '')) ?: '(missing)',
        ));
      }

      if ($type === 'sequence') {
        self::inspectSkipCommands($command['commands'], $cinematicId, "$commandPath/sequence");
        continue;
      }

      if ($type === 'parallel') {
        foreach ($command['lanes'] as $laneIndex => $lane) {
          $laneId = is_array($lane) && ! array_is_list($lane)
            ? trim(strval($lane['id'] ?? $laneIndex))
            : strval($laneIndex);
          $laneCommands = is_array($lane) && ! array_is_list($lane) ? $lane['commands'] : $lane;
          self::inspectSkipCommands($laneCommands, $cinematicId, "$commandPath/parallel[$laneId]");
        }
        continue;
      }

      if ($type === 'branch') {
        foreach (['then', 'else'] as $arm) {
          if (array_key_exists($arm, $command)) {
            self::inspectSkipCommands($command[$arm], $cinematicId, "$commandPath/branch:$arm");
          }
        }
        continue;
      }

      if ($type === 'choice') {
        foreach ($command['options'] as $optionIndex => $option) {
          if (array_key_exists('then', $option)) {
            self::inspectSkipCommands($option['then'], $cinematicId, sprintf('%s/choice[%d]', $commandPath, $optionIndex + 1));
          }
        }

        if (array_key_exists('cancel', $command)) {
          self::inspectSkipCommands($command['cancel'], $cinematicId, "$commandPath/choice:cancel");
        }
      }
    }
  }

  /** @param array<string, mixed> $command */
  private static function assertFinalizerShape(array $command, string $cinematicId, string $path, string $type): void
  {
    match ($type) {
      'set_switch' => self::assertSetSwitch($command, $cinematicId, $path),
      'set_variable' => self::assertSetVariable($command, $cinematicId, $path),
      'record_event' => self::assertRecordEvent($command, $cinematicId, $path),
      'move_player' => self::assertCoordinates($command, $cinematicId, $path, ['type', 'x', 'y']),
      'transfer' => self::assertTransfer($command, $cinematicId, $path),
      'camera' => self::assertCamera($command, $cinematicId, $path),
      'remove_actor' => self::assertRemoveActor($command, $cinematicId, $path),
      'clear_presentation' => self::assertAllowedKeys($command, ['type'], $cinematicId, $path),
      'cinematic_music' => self::assertMusic($command, $cinematicId, $path),
      default => throw self::failure($cinematicId, $path, 'unsupported finalizer command.'),
    };
  }

  /** @param array<string, mixed> $command */
  private static function assertSetSwitch(array $command, string $cinematicId, string $path): void
  {
    self::assertAllowedKeys($command, ['type', 'name', 'value'], $cinematicId, $path);
    self::assertNonEmptyString($command, 'name', $cinematicId, $path);

    if (! array_key_exists('value', $command) || ! is_bool($command['value'])) {
      throw self::failure($cinematicId, $path, 'set_switch requires an explicit boolean value.');
    }
  }

  /** @param array<string, mixed> $command */
  private static function assertSetVariable(array $command, string $cinematicId, string $path): void
  {
    self::assertAllowedKeys($command, ['type', 'name', 'value', 'op'], $cinematicId, $path);
    self::assertNonEmptyString($command, 'name', $cinematicId, $path);

    if (! array_key_exists('value', $command)
      || (! is_int($command['value']) && ! is_float($command['value']) && ! is_string($command['value']))
    ) {
      throw self::failure($cinematicId, $path, 'set_variable requires an explicit numeric or string value.');
    }

    if (array_key_exists('op', $command) && $command['op'] !== 'set') {
      throw self::failure($cinematicId, $path, 'set_variable finalizers only allow the "set" operation.');
    }
  }

  /** @param array<string, mixed> $command */
  private static function assertRecordEvent(array $command, string $cinematicId, string $path): void
  {
    self::assertAllowedKeys($command, ['type', 'name'], $cinematicId, $path);
    self::assertNonEmptyString($command, 'name', $cinematicId, $path);

    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/', $command['name']) !== 1) {
      throw self::failure($cinematicId, $path, 'record_event requires a stable event identity.');
    }
  }

  /** @param array<string, mixed> $command @param string[] $allowed */
  private static function assertCoordinates(array $command, string $cinematicId, string $path, array $allowed): void
  {
    self::assertAllowedKeys($command, $allowed, $cinematicId, $path);

    foreach (['x', 'y'] as $field) {
      if (! array_key_exists($field, $command) || ! is_int($command[$field])) {
        throw self::failure($cinematicId, $path, sprintf('%s requires an explicit integral %s coordinate.', $command['type'], $field));
      }
    }
  }

  /** @param array<string, mixed> $command */
  private static function assertTransfer(array $command, string $cinematicId, string $path): void
  {
    self::assertCoordinates($command, $cinematicId, $path, ['type', 'map', 'x', 'y', 'sprite']);
    self::assertNonEmptyString($command, 'map', $cinematicId, $path);

    if (array_key_exists('sprite', $command)
      && ! is_string($command['sprite'])
      && ! (is_array($command['sprite']) && array_is_list($command['sprite']) && array_all(
        $command['sprite'],
        static fn(mixed $row): bool => is_string($row),
      ))
    ) {
      throw self::failure($cinematicId, $path, 'transfer sprite must be a string or a list of strings.');
    }
  }

  /** @param array<string, mixed> $command */
  private static function assertCamera(array $command, string $cinematicId, string $path): void
  {
    self::assertAllowedKeys($command, ['type', 'operation'], $cinematicId, $path);

    if (! in_array($command['operation'] ?? null, ['attach', 'reset'], true)) {
      throw self::failure($cinematicId, $path, 'camera finalizers only allow attach or reset.');
    }
  }

  /** @param array<string, mixed> $command */
  private static function assertRemoveActor(array $command, string $cinematicId, string $path): void
  {
    self::assertAllowedKeys($command, ['type', 'actorId', 'id'], $cinematicId, $path);
    $actorId = trim(strval($command['actorId'] ?? $command['id'] ?? ''));

    if ($actorId === '') {
      throw self::failure($cinematicId, $path, 'remove_actor requires a non-empty actor ID.');
    }
  }

  /** @param array<string, mixed> $command */
  private static function assertMusic(array $command, string $cinematicId, string $path): void
  {
    self::assertAllowedKeys($command, [
      'type', 'track', 'loop', 'fadeIn', 'fadeOut', 'completionBehavior', 'restorePreviousMusic',
    ], $cinematicId, $path);
    self::assertNonEmptyString($command, 'track', $cinematicId, $path);

    if (! array_key_exists('loop', $command) || ! is_bool($command['loop'])) {
      throw self::failure($cinematicId, $path, 'cinematic_music finalizers require an explicit boolean loop policy.');
    }

    $hasBehavior = array_key_exists('completionBehavior', $command);
    $hasRestore = array_key_exists('restorePreviousMusic', $command);

    if (! $hasBehavior && ! $hasRestore) {
      throw self::failure($cinematicId, $path, 'cinematic_music finalizers require an explicit completion policy.');
    }

    if ($hasRestore && ! is_bool($command['restorePreviousMusic'])) {
      throw self::failure($cinematicId, $path, 'restorePreviousMusic must be boolean.');
    }

    if ($hasBehavior && ! in_array($command['completionBehavior'], CinematicCommandSchema::MUSIC_COMPLETION_BEHAVIORS, true)) {
      throw self::failure($cinematicId, $path, 'cinematic_music completionBehavior is invalid.');
    }

    if ($hasBehavior && ($command['restorePreviousMusic'] ?? false) === true && $command['completionBehavior'] !== 'restore_previous') {
      throw self::failure($cinematicId, $path, 'cinematic_music completion policies conflict.');
    }

    foreach (['fadeIn', 'fadeOut'] as $field) {
      if (array_key_exists($field, $command) && (! is_numeric($command[$field]) || floatval($command[$field]) < 0.0)) {
        throw self::failure($cinematicId, $path, sprintf('%s must be a non-negative number.', $field));
      }
    }
  }

  /** @param array<string, mixed> $command @param string[] $allowed */
  private static function assertAllowedKeys(array $command, array $allowed, string $cinematicId, string $path): void
  {
    $unknown = array_values(array_diff(array_keys($command), $allowed));

    if ($unknown !== []) {
      throw self::failure($cinematicId, $path, sprintf('unsafe payload field(s): %s.', implode(', ', $unknown)));
    }
  }

  /** @param array<string, mixed> $command */
  private static function assertNonEmptyString(array $command, string $field, string $cinematicId, string $path): void
  {
    if (! isset($command[$field]) || ! is_string($command[$field]) || trim($command[$field]) === '') {
      throw self::failure($cinematicId, $path, sprintf('%s must be a non-empty string.', $field));
    }
  }

  private static function failure(string $cinematicId, string $path, string $reason): InvalidArgumentException
  {
    return new InvalidArgumentException(sprintf(
      'Cinematic "%s" is unsafe at command path "%s": %s',
      $cinematicId,
      $path,
      ucfirst($reason),
    ));
  }
}
