<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use InvalidArgumentException;

/** A hydrated first-class cinematic asset. */
final class CinematicDefinition
{
  /** @param array<int, array<string, mixed>> $cast */
  public function __construct(
    public readonly string $id,
    public readonly string $name,
    public readonly string $description,
    public readonly int $version,
    public readonly array $metadata,
    public readonly ?string $startMap,
    public readonly array $presentation,
    public readonly array $cast,
    public readonly string $skipPolicy,
    public readonly array $checkpoints,
    public readonly array $finalizer,
    public readonly array $commands,
    public readonly array $extra = [],
  )
  {
  }

  /**
   * @param array<string, mixed> $data
   * @param array<int, array<string, mixed>>|array<string, mixed> $script
   */
  public static function fromArrays(array $data, array $script): self
  {
    $id = trim(strval($data['id'] ?? ''));

    if ($id === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) !== 1) {
      throw new InvalidArgumentException('Cinematic id must be a stable lowercase identifier.');
    }

    $name = trim(strval($data['name'] ?? ''));

    if ($name === '') {
      throw new InvalidArgumentException(sprintf('Cinematic "%s" requires a display name.', $id));
    }

    $cast = self::listField($data, 'cast', $id);
    $castIds = [];

    foreach ($cast as $index => $entry) {
      if (! is_array($entry) || array_is_list($entry)) {
        throw new InvalidArgumentException(sprintf('Cinematic "%s" cast entry %d must be a keyed array.', $id, $index + 1));
      }

      $castId = trim(strval($entry['id'] ?? ''));

      if ($castId === '') {
        throw new InvalidArgumentException(sprintf('Cinematic "%s" cast entry %d requires an id.', $id, $index + 1));
      }

      if (isset($castIds[$castId])) {
        throw new InvalidArgumentException(sprintf('Cinematic "%s" has duplicate cast id "%s".', $id, $castId));
      }

      $castIds[$castId] = true;
    }

    foreach ($cast as $index => $entry) {
      $kind = strtolower(trim(strval($entry['kind'] ?? 'staged_actor')));
      $path = sprintf('cast[%d]', $index + 1);

      if (! in_array($kind, ['player', 'party_actor', 'npc', 'staged_actor'], true)) {
        throw new InvalidArgumentException(sprintf(
          'Cinematic "%s" %s has unsupported kind "%s".',
          $id,
          $path,
          $kind !== '' ? $kind : '(empty)',
        ));
      }

      if ($kind === 'staged_actor') {
        CinematicScriptValidator::validateStagedActor($entry, $id, $path);
      }
    }

    $skip = $data['skip'] ?? ['policy' => 'forbidden'];
    $skipPolicy = is_bool($skip)
      ? ($skip ? 'authored' : 'forbidden')
      : strtolower(trim(strval(is_array($skip) ? ($skip['policy'] ?? 'forbidden') : $skip)));

    if (! in_array($skipPolicy, CinematicCommandSchema::SKIP_POLICIES, true)) {
      throw new InvalidArgumentException(sprintf('Cinematic "%s" has unsupported skip policy "%s".', $id, $skipPolicy));
    }

    $finalizer = self::listField($data, 'finalizer', $id);

    if ($skipPolicy === 'authored' && $finalizer === []) {
      throw new InvalidArgumentException(sprintf('Skippable cinematic "%s" requires an authored finalizer.', $id));
    }

    CinematicCommandPolicy::assertFinalizer($finalizer, $id);
    CinematicScriptValidator::validate($finalizer, "$id:finalizer");

    if (array_is_list($script)) {
      $commands = $script;
    } else {
      if (! array_key_exists('commands', $script) || ! is_array($script['commands']) || ! array_is_list($script['commands'])) {
        throw new InvalidArgumentException(sprintf('Cinematic "%s" script.commands must be a command list.', $id));
      }

      $commands = $script['commands'];
    }

    CinematicScriptValidator::validate($commands, $id);

    if ($skipPolicy === 'authored') {
      CinematicCommandPolicy::assertAuthoredSkipSafe($commands, $id);
    }

    $checkpoints = self::listField($data, 'checkpoints', $id);
    $checkpointIds = [];

    foreach ($checkpoints as $index => $checkpoint) {
      if (! is_string($checkpoint)
        || trim($checkpoint) === ''
        || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $checkpoint) !== 1
      ) {
        throw new InvalidArgumentException(sprintf(
          'Cinematic "%s" checkpoint %d must be a non-empty stable string.',
          $id,
          $index + 1,
        ));
      }

      if (isset($checkpointIds[$checkpoint])) {
        throw new InvalidArgumentException(sprintf('Cinematic "%s" has duplicate checkpoint "%s".', $id, $checkpoint));
      }

      $checkpointIds[$checkpoint] = true;
    }

    $version = $data['version'] ?? 1;

    if (! is_int($version) || $version < 1) {
      throw new InvalidArgumentException(sprintf('Cinematic "%s" version must be a positive integer.', $id));
    }

    $known = ['id', 'name', 'description', 'version', 'authoring', 'startMap', 'presentation', 'cast', 'skip', 'checkpoints', 'finalizer'];

    return new self(
      id: $id,
      name: $name,
      description: strval($data['description'] ?? ''),
      version: $version,
      metadata: is_array($data['authoring'] ?? null) ? $data['authoring'] : [],
      startMap: trim(strval($data['startMap'] ?? '')) ?: null,
      presentation: is_array($data['presentation'] ?? null) ? $data['presentation'] : [],
      cast: $cast,
      skipPolicy: $skipPolicy,
      checkpoints: $checkpoints,
      finalizer: $finalizer,
      commands: $commands,
      extra: array_diff_key($data, array_flip($known)),
    );
  }

  /** @param array<string, mixed> $data @return array<int, mixed> */
  private static function listField(array $data, string $field, string $cinematicId): array
  {
    if (! array_key_exists($field, $data)) {
      return [];
    }

    if (! is_array($data[$field]) || ! array_is_list($data[$field])) {
      throw new InvalidArgumentException(sprintf('Cinematic "%s" field "%s" must be a list.', $cinematicId, $field));
    }

    return $data[$field];
  }
}
