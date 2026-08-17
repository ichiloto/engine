<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Scenes\Game\GameScene;
use RuntimeException;

/** Owns temporary actors for the active field cinematic. */
final class CinematicStageManager
{
  /** @var array<string, StagedActor> */
  protected array $actors = [];

  public function __construct(protected GameScene $gameScene)
  {
  }

  /** @param array<int, array<string, mixed>> $entries */
  public function configure(array $entries): void
  {
    $this->clear();

    foreach ($entries as $entry) {
      $this->add($entry);
    }
  }

  /** @param array<string, mixed> $entry */
  public function add(array $entry): StagedActor
  {
    $id = trim(strval($entry['id'] ?? ''));

    if ($id === '') {
      throw new RuntimeException('A staged actor requires a stable cutscene-local id.');
    }

    if (isset($this->actors[$id])) {
      throw new RuntimeException(sprintf('Duplicate staged actor id "%s".', $id));
    }

    $assetReference = trim(strval($entry['asset'] ?? '')) ?: null;
    $sprite = $entry['sprite'] ?? '@';

    if ($assetReference !== null) {
      $loaded = asset($assetReference, true);

      if (is_array($loaded)) {
        $sprite = $loaded['sprite'] ?? $loaded;
      }
    }

    $sprite = is_array($sprite) ? array_values(array_map('strval', $sprite)) : [strval($sprite)];
    $facing = MovementHeading::tryFrom(ucfirst(strtolower(strval($entry['facing'] ?? 'South')))) ?? MovementHeading::SOUTH;
    $actor = new StagedActor(
      id: $id,
      sprite: $sprite !== [] ? $sprite : ['@'],
      position: new Vector2(intval($entry['x'] ?? 0), intval($entry['y'] ?? 0)),
      isVisible: ($entry['visible'] ?? true) !== false,
      hasCollision: boolval($entry['collision'] ?? false),
      directionalSprites: is_array($entry['sprites'] ?? null) ? $entry['sprites'] : [],
      facing: $facing,
      assetReference: $assetReference,
    );
    $this->actors[$id] = $actor;
    return $actor;
  }

  public function find(string $id): ?StagedActor
  {
    return $this->actors[trim($id)] ?? null;
  }

  public function require(string $id): StagedActor
  {
    return $this->find($id)
      ?? throw new RuntimeException(sprintf('Staged actor "%s" was not found.', $id));
  }

  public function show(string $id): void
  {
    $this->require($id)->show();
  }

  public function hide(string $id): void
  {
    $this->require($id)->hide();
  }

  public function remove(string $id): void
  {
    unset($this->actors[trim($id)]);
  }

  public function clear(): void
  {
    $this->actors = [];
  }

  /** @return StagedActor[] */
  public function all(): array
  {
    return array_values($this->actors);
  }

  public function actorAt(int $x, int $y, ?string $exceptId = null): ?StagedActor
  {
    foreach ($this->actors as $actor) {
      if ($actor->id === $exceptId || ! $actor->isVisible || ! $actor->hasCollision) {
        continue;
      }

      if (intval($actor->position->x) === $x && intval($actor->position->y) === $y) {
        return $actor;
      }
    }

    return null;
  }

  public function move(string $id, Vector2 $direction, bool $faceOnly = false): bool
  {
    $actor = $this->require($id);
    $actor->face($direction);

    if ($faceOnly) {
      return true;
    }

    $x = intval($actor->position->x + $direction->x);
    $y = intval($actor->position->y + $direction->y);
    $player = $this->gameScene->player;

    if (! $this->gameScene->mapManager->canMoveTo($x, $y)
      || $this->actorAt($x, $y, $id) !== null
      || ($player !== null && intval($player->position->x) === $x && intval($player->position->y) === $y)
    ) {
      return false;
    }

    $actor->position->x = $x;
    $actor->position->y = $y;
    return true;
  }

  public function render(): void
  {
    foreach ($this->actors as $actor) {
      if ($actor->isVisible) {
        $this->gameScene->camera->renderOnScreen($actor->sprite, $actor->position);
      }
    }
  }

  /** Returns the widest visible footprint for validation and cleanup. */
  public function displayWidth(string $id): int
  {
    $width = 1;

    foreach ($this->require($id)->sprite as $row) {
      $width = max($width, TerminalText::displayWidth($row));
    }

    return $width;
  }
}
