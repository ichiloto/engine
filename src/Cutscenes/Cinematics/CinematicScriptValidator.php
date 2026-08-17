<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use InvalidArgumentException;

/**
 * Validates cinematic command trees without booting a game runtime.
 *
 * This is the validation-facing counterpart to EventInterpreter. Keeping the
 * structural rules here lets runtime loaders and authoring tools consume the
 * same command vocabulary and receive stable command-path diagnostics.
 */
final class CinematicScriptValidator
{
  /** @param array<int, mixed> $commands */
  public static function validate(array $commands, string $cinematicId = 'cinematic'): void
  {
    self::validateCommands($commands, $cinematicId, 'script');
  }

  /** @param array<int, mixed> $commands */
  protected static function validateCommands(array $commands, string $cinematicId, string $path): void
  {
    if (! array_is_list($commands)) {
      throw self::failure($cinematicId, $path, 'command blocks must be lists.');
    }

    foreach ($commands as $index => $command) {
      $commandPath = sprintf('%s[%d]', $path, $index + 1);

      if (! is_array($command) || array_is_list($command)) {
        throw self::failure($cinematicId, $commandPath, 'each command must be a keyed array.');
      }

      $type = trim(strval($command['type'] ?? ''));

      if ($type === '') {
        throw self::failure($cinematicId, $commandPath, 'command type is required.');
      }

      if (! in_array($type, CinematicCommandSchema::COMMAND_TYPES, true)) {
        throw self::failure($cinematicId, $commandPath, sprintf('unknown command type "%s".', $type));
      }

      match ($type) {
        'sequence' => self::validateRequiredBlock($command, 'commands', $cinematicId, "$commandPath/sequence"),
        'parallel' => self::validateParallel($command, $cinematicId, $commandPath),
        'branch' => self::validateBranch($command, $cinematicId, $commandPath),
        'choice' => self::validateChoice($command, $cinematicId, $commandPath),
        'camera' => self::validateCamera($command, $cinematicId, $commandPath),
        'stage_actor' => self::validateStagedActor(
          is_array($command['actor'] ?? null) ? $command['actor'] : $command,
          $cinematicId,
          $commandPath,
        ),
        'show_actor', 'hide_actor', 'remove_actor' => self::validateActorReference($command, $cinematicId, $commandPath),
        'field_animation' => self::validateFieldAnimation($command, $cinematicId, $commandPath),
        'title_card', 'narration' => self::validateOptionalDuration($command, ['seconds'], $cinematicId, $commandPath),
        'transition' => self::validateTransition($command, $cinematicId, $commandPath),
        'cinematic_music' => self::validateCinematicMusic($command, $cinematicId, $commandPath),
        'common_event' => self::validateStableReference($command, 'id', $cinematicId, $commandPath),
        'checkpoint' => self::validateStableReference($command, 'name', $cinematicId, $commandPath),
        default => null,
      };
    }
  }

  /** @param array<string, mixed> $command */
  protected static function validateRequiredBlock(
    array $command,
    string $field,
    string $cinematicId,
    string $path,
  ): void
  {
    $commands = $command[$field] ?? null;

    if (! is_array($commands)) {
      throw self::failure($cinematicId, $path, sprintf('field "%s" must contain a command list.', $field));
    }

    self::validateCommands($commands, $cinematicId, $path);
  }

  /** @param array<string, mixed> $command */
  protected static function validateParallel(array $command, string $cinematicId, string $path): void
  {
    $lanes = $command['lanes'] ?? null;

    if (! is_array($lanes) || ! array_is_list($lanes)) {
      throw self::failure($cinematicId, "$path/parallel", 'lanes must be a list.');
    }

    if ($lanes === []) {
      throw self::failure($cinematicId, "$path/parallel", 'lanes must not be empty.');
    }

    $ids = [];

    foreach ($lanes as $index => $lane) {
      $lanePath = sprintf('%s/parallel[%d]', $path, $index + 1);

      if (! is_array($lane)) {
        throw self::failure($cinematicId, $lanePath, 'lane must contain a command list.');
      }

      if (array_is_list($lane)) {
        if ($lane === []) {
          throw self::failure($cinematicId, $lanePath, 'lane command list must not be empty.');
        }

        self::validateCommands($lane, $cinematicId, $lanePath);
        continue;
      }

      $id = trim(strval($lane['id'] ?? $index));

      if ($id === '') {
        throw self::failure($cinematicId, $lanePath, 'lane id cannot be empty.');
      }

      if (isset($ids[$id])) {
        throw self::failure($cinematicId, $lanePath, sprintf('duplicate lane id "%s".', $id));
      }

      $ids[$id] = true;
      if (($lane['commands'] ?? []) === []) {
        throw self::failure($cinematicId, "$path/parallel[$id]", 'lane command list must not be empty.');
      }
      self::validateRequiredBlock($lane, 'commands', $cinematicId, "$path/parallel[$id]");
    }
  }

  /** @param array<string, mixed> $command */
  protected static function validateBranch(array $command, string $cinematicId, string $path): void
  {
    foreach (['then', 'else'] as $arm) {
      if (! array_key_exists($arm, $command)) {
        continue;
      }

      self::validateRequiredBlock($command, $arm, $cinematicId, "$path/branch:$arm");
    }
  }

  /** @param array<string, mixed> $command */
  protected static function validateChoice(array $command, string $cinematicId, string $path): void
  {
    $options = $command['options'] ?? null;

    if (! is_array($options) || ! array_is_list($options)) {
      throw self::failure($cinematicId, "$path/choice", 'options must be a list.');
    }

    foreach ($options as $index => $option) {
      if (! is_array($option) || array_is_list($option)) {
        throw self::failure($cinematicId, sprintf('%s/choice[%d]', $path, $index + 1), 'option must be a keyed array.');
      }

      if (array_key_exists('then', $option)) {
        self::validateRequiredBlock($option, 'then', $cinematicId, sprintf('%s/choice[%d]', $path, $index + 1));
      }
    }

    if (array_key_exists('cancel', $command)) {
      self::validateRequiredBlock($command, 'cancel', $cinematicId, "$path/choice:cancel");
    }
  }

  /** @param array<string, mixed> $command */
  protected static function validateCamera(array $command, string $cinematicId, string $path): void
  {
    $operation = strtolower(trim(strval($command['operation'] ?? '')));

    if (! in_array($operation, CinematicCommandSchema::CAMERA_OPERATIONS, true)) {
      throw self::failure($cinematicId, $path, sprintf(
        'unsupported camera operation "%s".',
        $operation !== '' ? $operation : '(empty)',
      ));
    }

    if (in_array($operation, ['focus', 'pan', 'track'], true)) {
      $target = is_array($command['target'] ?? null) ? $command['target'] : $command;
      self::validateSubject($target, $cinematicId, "$path/target", allowScreenPosition: false);
    }

    if (in_array($operation, ['pan', 'track', 'shake'], true)) {
      self::validateRequiredPositiveDuration($command, ['seconds', 'duration'], $cinematicId, $path);
    }

    if ($operation !== 'route') {
      return;
    }

    $points = $command['points'] ?? null;

    if (! is_array($points) || ! array_is_list($points) || $points === []) {
      throw self::failure($cinematicId, "$path/route", 'points must be a non-empty list.');
    }

    foreach ($points as $index => $point) {
      $pointPath = sprintf('%s/route[%d]', $path, $index + 1);

      if (! is_array($point) || array_is_list($point)) {
        throw self::failure($cinematicId, $pointPath, 'point must be a keyed array.');
      }

      self::validateSubject($point, $cinematicId, "$pointPath/target", allowScreenPosition: false);
      self::validateRequiredPositiveDuration($point, ['seconds', 'duration'], $cinematicId, $pointPath);
    }
  }

  /** @param array<string, mixed> $entry */
  public static function validateStagedActor(array $entry, string $cinematicId, string $path): void
  {
    $id = trim(strval($entry['id'] ?? ''));

    if ($id === '') {
      throw self::failure($cinematicId, $path, 'staged actor id is required.');
    }

    if (! array_key_exists('sprite', $entry) && trim(strval($entry['asset'] ?? '')) === '') {
      throw self::failure($cinematicId, $path, 'staged actor requires a sprite or asset reference.');
    }

    foreach (['x', 'y'] as $coordinate) {
      if (array_key_exists($coordinate, $entry) && ! is_numeric($entry[$coordinate])) {
        throw self::failure($cinematicId, $path, sprintf('staged actor %s coordinate must be numeric.', $coordinate));
      }
    }

    foreach (['visible', 'collision'] as $booleanField) {
      if (array_key_exists($booleanField, $entry) && ! is_bool($entry[$booleanField])) {
        throw self::failure($cinematicId, $path, sprintf('staged actor %s must be boolean.', $booleanField));
      }
    }

    if (array_key_exists('facing', $entry)
      && ! in_array(strtolower(trim(strval($entry['facing']))), ['north', 'south', 'east', 'west'], true)
    ) {
      throw self::failure($cinematicId, $path, 'staged actor facing must be north, south, east, or west.');
    }
  }

  /** @param array<string, mixed> $command */
  protected static function validateActorReference(array $command, string $cinematicId, string $path): void
  {
    $id = trim(strval($command['actorId'] ?? $command['id'] ?? ''));

    if ($id === '') {
      throw self::failure($cinematicId, $path, 'staged actor reference is required.');
    }
  }

  /** @param array<string, mixed> $command */
  protected static function validateFieldAnimation(array $command, string $cinematicId, string $path): void
  {
    $reference = $command['animation'] ?? $command['id'] ?? null;

    if (! is_scalar($reference) || trim(strval($reference)) === '') {
      throw self::failure($cinematicId, $path, 'field animation reference is required.');
    }

    $target = $command['target'] ?? null;

    if (! is_array($target) || array_is_list($target)) {
      throw self::failure($cinematicId, "$path/target", 'target must be a keyed subject reference.');
    }

    self::validateSubject($target, $cinematicId, "$path/target", allowScreenPosition: true);
    self::validateOptionalDuration($command, ['secondsPerFrame'], $cinematicId, $path, positive: true);
  }

  /** @param array<string, mixed> $command */
  protected static function validateTransition(array $command, string $cinematicId, string $path): void
  {
    $direction = strtolower(trim(strval($command['direction'] ?? 'out')));

    if (! in_array($direction, ['in', 'out'], true)) {
      throw self::failure($cinematicId, $path, 'transition direction must be in or out.');
    }

    self::validateOptionalDuration($command, ['seconds'], $cinematicId, $path);
  }

  /** @param array<string, mixed> $command */
  protected static function validateCinematicMusic(array $command, string $cinematicId, string $path): void
  {
    $track = trim(strval($command['track'] ?? $command['music'] ?? ''));

    if ($track === '') {
      throw self::failure($cinematicId, $path, 'cinematic music track is required.');
    }

    self::validateOptionalDuration($command, ['fadeIn', 'fadeOut'], $cinematicId, $path);
    $behavior = boolval($command['restorePreviousMusic'] ?? false)
      ? 'restore_previous'
      : strtolower(trim(strval($command['completionBehavior'] ?? 'continue')));

    if (! in_array($behavior, CinematicCommandSchema::MUSIC_COMPLETION_BEHAVIORS, true)) {
      throw self::failure($cinematicId, $path, sprintf('unsupported music completion behavior "%s".', $behavior));
    }
  }

  /** @param array<string, mixed> $reference */
  protected static function validateSubject(
    array $reference,
    string $cinematicId,
    string $path,
    bool $allowScreenPosition,
  ): void
  {
    $kind = strtolower(trim(strval($reference['kind'] ?? $reference['subject'] ?? '')));
    $allowed = $allowScreenPosition
      ? CinematicCommandSchema::SUBJECT_KINDS
      : array_values(array_diff(CinematicCommandSchema::SUBJECT_KINDS, ['screen_position']));

    if (! in_array($kind, $allowed, true)) {
      throw self::failure($cinematicId, $path, sprintf('unsupported subject kind "%s".', $kind !== '' ? $kind : '(empty)'));
    }

    if (in_array($kind, ['npc', 'staged_actor', 'marker'], true)
      && trim(strval($reference['id'] ?? '')) === ''
    ) {
      throw self::failure($cinematicId, $path, sprintf('%s subject requires an id.', $kind));
    }

    if (in_array($kind, ['position', 'screen_position'], true)
      && (! is_numeric($reference['x'] ?? null) || ! is_numeric($reference['y'] ?? null))
    ) {
      throw self::failure($cinematicId, $path, sprintf('%s subject requires numeric x and y coordinates.', $kind));
    }
  }

  /** @param array<string, mixed> $command */
  protected static function validateStableReference(
    array $command,
    string $field,
    string $cinematicId,
    string $path,
  ): void
  {
    $value = trim(strval($command[$field] ?? ''));

    if ($value === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $value) !== 1) {
      throw self::failure($cinematicId, $path, sprintf('%s must be a safe stable reference.', $field));
    }
  }

  /** @param array<string, mixed> $command @param string[] $fields */
  protected static function validateOptionalDuration(
    array $command,
    array $fields,
    string $cinematicId,
    string $path,
    bool $positive = false,
  ): void
  {
    foreach ($fields as $field) {
      if (! array_key_exists($field, $command)) {
        continue;
      }

      $value = $command[$field];

      if (! is_numeric($value) || ($positive ? floatval($value) <= 0.0 : floatval($value) < 0.0)) {
        $expectation = $positive ? 'greater than zero' : 'zero or greater';
        throw self::failure($cinematicId, $path, sprintf('%s must be numeric and %s.', $field, $expectation));
      }
    }
  }

  /** @param array<string, mixed> $command @param string[] $fields */
  protected static function validateRequiredPositiveDuration(
    array $command,
    array $fields,
    string $cinematicId,
    string $path,
  ): void
  {
    foreach ($fields as $field) {
      if (array_key_exists($field, $command)) {
        self::validateOptionalDuration($command, [$field], $cinematicId, $path, positive: true);
        return;
      }
    }

    throw self::failure($cinematicId, $path, sprintf('%s is required.', implode(' or ', $fields)));
  }

  protected static function failure(string $cinematicId, string $path, string $reason): InvalidArgumentException
  {
    return new InvalidArgumentException(sprintf(
      'Cinematic "%s" is invalid at command path "%s": %s',
      $cinematicId,
      $path,
      ucfirst($reason),
    ));
  }
}
