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

    $cast = array_values(array_filter((array) ($data['cast'] ?? []), is_array(...)));
    $castIds = [];

    foreach ($cast as $index => $entry) {
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

    $finalizer = array_values(array_filter((array) ($data['finalizer'] ?? []), is_array(...)));

    if ($skipPolicy === 'authored' && $finalizer === []) {
      throw new InvalidArgumentException(sprintf('Skippable cinematic "%s" requires an authored finalizer.', $id));
    }

    foreach ($finalizer as $index => $command) {
      $type = strval($command['type'] ?? '');

      if (! in_array($type, CinematicCommandSchema::FINALIZER_COMMAND_TYPES, true)) {
        throw new InvalidArgumentException(sprintf(
          'Cinematic "%s" finalizer command %d uses unsafe type "%s".',
          $id,
          $index + 1,
          $type !== '' ? $type : '(empty)',
        ));
      }
    }

    CinematicScriptValidator::validate($finalizer, "$id:finalizer");

    $commands = array_is_list($script)
      ? $script
      : (array) ($script['commands'] ?? []);
    CinematicScriptValidator::validate($commands, $id);
    $known = ['id', 'name', 'description', 'version', 'authoring', 'startMap', 'presentation', 'cast', 'skip', 'checkpoints', 'finalizer'];

    return new self(
      id: $id,
      name: $name,
      description: strval($data['description'] ?? ''),
      version: max(1, intval($data['version'] ?? 1)),
      metadata: is_array($data['authoring'] ?? null) ? $data['authoring'] : [],
      startMap: trim(strval($data['startMap'] ?? '')) ?: null,
      presentation: is_array($data['presentation'] ?? null) ? $data['presentation'] : [],
      cast: $cast,
      skipPolicy: $skipPolicy,
      checkpoints: array_values(array_filter((array) ($data['checkpoints'] ?? []), 'is_string')),
      finalizer: $finalizer,
      commands: array_values($commands),
      extra: array_diff_key($data, array_flip($known)),
    );
  }
}
