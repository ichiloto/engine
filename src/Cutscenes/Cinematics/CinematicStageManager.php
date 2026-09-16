<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Scenes\Game\GameScene;
use RuntimeException;

/** Owns temporary actors for the active field cinematic. */
final class CinematicStageManager
{
  /** @var array<string, StagedActor> */
  protected array $actors = [];
  /** @var array<int, CinematicSubjectLease> Transform ownership outlives a visual replacement/removal. */
  private array $subjects = [];
  protected(set) ?string $lastMoveFailure = null;

  public function __construct(protected GameScene $gameScene)
  {
  }

  /** @param array<int, array<string, mixed>> $entries */
  public function configure(array $entries): void
  {
    $this->clear();

    try {
      foreach ($entries as $entry) {
        $this->add($entry);
      }
    } catch (\Throwable $error) {
      $this->clear();
      throw $error;
    }
  }

  /** @param array<string, mixed> $entry */
  public function add(array $entry): StagedActor
  {
    $id = trim(strval($entry['id'] ?? ''));

    if ($id === '') {
      throw new RuntimeException('A staged actor requires a stable cutscene-local id.');
    }

    self::validateBinding($entry);
    $replace = ($entry['replace'] ?? false) === true;
    if (isset($this->actors[$id]) && !$replace) {
      throw new RuntimeException(sprintf('Duplicate staged actor id "%s".', $id));
    }
    if ($replace && !isset($this->actors[$id])) {
      throw new RuntimeException(sprintf('Cannot replace missing staged actor "%s".', $id));
    }

    if (array_key_exists('sprites2d', $entry) && !is_array($entry['sprites2d'])) {
      throw new \InvalidArgumentException('Staged actor sprites2d must be an array.');
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
    $graphicalSprites = isset($entry['sprites2d']) ? self::graphicalSprites($entry['sprites2d']) : null;
    $subject = isset($entry['subject']) ? $this->resolveSubject($entry['subject']) : null;
    $suppressed = $subject !== null ? [spl_object_id($subject) => $subject] : [];
    foreach ($entry['suppress'] ?? [] as $reference) {
      $other = $this->resolveSubject($reference);
      $suppressed[spl_object_id($other)] = $other;
    }
    foreach ($this->actors as $existing) {
      if ($existing->id === $id) {
        continue;
      }
      foreach ($existing->suppressedSubjects as $lease) {
        if (isset($suppressed[spl_object_id($lease->subject)])) {
          throw new RuntimeException(sprintf('Subject presentation is already owned by staged actor "%s".', $existing->id));
        }
      }
    }
    $leases = [];
    foreach ($suppressed as $key => $realSubject) {
      $leases[$key] = $this->subjects[$key] ?? new CinematicSubjectLease($this->gameScene, $realSubject);
    }
    $actor = new StagedActor(
      id: $id,
      sprite: $sprite !== [] ? $sprite : ['@'],
      position: new Vector2(intval($entry['x'] ?? 0), intval($entry['y'] ?? 0)),
      isVisible: ($entry['visible'] ?? true) !== false,
      hasCollision: boolval($entry['collision'] ?? false),
      directionalSprites: is_array($entry['sprites'] ?? null) ? $entry['sprites'] : [],
      facing: $facing,
      assetReference: $assetReference,
      graphicalSprites: $graphicalSprites,
      subject: $subject !== null ? $leases[spl_object_id($subject)] : null,
      suppressedSubjects: array_values($leases),
    );
    ($this->actors[$id] ?? null)?->releaseVisual();
    $this->subjects += $leases;
    $this->actors[$id] = $actor;
    $this->gameScene->requestFieldPresentationReconciliation();
    return $actor;
  }

  /** Shared runtime/authoring boundary for real-subject presentation ownership. */
  public static function validateBinding(array $entry): void
  {
    if (array_key_exists('replace', $entry) && !is_bool($entry['replace'])) {
      throw new \InvalidArgumentException('Staged actor replace must be boolean.');
    }
    if (!array_key_exists('subject', $entry)) {
      if (array_key_exists('suppress', $entry)) {
        throw new \InvalidArgumentException('Additional suppression requires a real subject anchor.');
      }
      return;
    }
    if (($entry['collision'] ?? false) !== false
      || array_intersect(['x', 'y', 'facing'], array_keys($entry)) !== []) {
      throw new \InvalidArgumentException('A bound visual inherits real position/facing and cannot add collision.');
    }
    $suppressed = array_key_exists('suppress', $entry) ? $entry['suppress'] : [];
    if (!is_array($suppressed) || !array_is_list($suppressed)) {
      throw new \InvalidArgumentException('Staged actor suppress must be a list of real subject references.');
    }
    foreach ([$entry['subject'], ...$suppressed] as $reference) {
      if (!is_array($reference) || !in_array($reference['kind'] ?? null, ['player', 'npc'], true)
        || (($reference['kind'] ?? null) === 'npc'
          && (!is_string($reference['id'] ?? null) || trim($reference['id']) === ''))) {
        throw new \InvalidArgumentException('Real subject requires kind player or npc, with a stable id for npc.');
      }
    }
  }

  private function resolveSubject(array $reference): Player|Npc
  {
    $subject = $reference['kind'] === 'player' ? $this->gameScene->player
      : $this->gameScene->npcManager?->findById($reference['id']);
    if ($subject === null || ($subject instanceof Npc
      && !in_array($subject, $this->gameScene->npcManager?->visibleNpcs() ?? [], true))) {
      throw new RuntimeException(sprintf('Real cinematic subject "%s" is unavailable on map "%s".',
        $reference['id'] ?? 'player', $this->gameScene->currentMapId));
    }
    return $subject;
  }

  public function suppresses(Player|Npc $subject): bool
  {
    foreach ($this->actors as $actor) {
      if (!$actor->ownsPresentation()) {
        continue;
      }
      foreach ($actor->suppressedSubjects as $lease) {
        if ($lease->subject === $subject) {
          return true;
        }
      }
    }
    return false;
  }

  public function subjectMoved(Player|Npc $subject): void
  {
    foreach ($this->actors as $actor) {
      if ($actor->subject?->subject === $subject) {
        $actor->beginGraphicalStep();
      }
    }
  }

  public function subjectStopped(Player|Npc $subject): void
  {
    foreach ($this->actors as $actor) {
      if ($actor->subject?->subject === $subject) {
        $actor->stopGraphicalAnimation();
      }
    }
  }

  public function restoreSubjectTransforms(): void
  {
    foreach ($this->subjects as $lease) {
      $lease->restore();
      $this->subjectStopped($lease->subject);
    }
    $this->gameScene->requestFieldPresentationReconciliation();
  }

  public function commitSubjectTransforms(Player|Npc|null $subject = null): void
  {
    foreach ($this->subjects as $lease) {
      if ($subject === null || $lease->subject === $subject) {
        $lease->commit();
      }
    }
  }

  /** Shared by authoring validation and runtime staging. */
  public static function graphicalSprites(array $data): GraphicalSpriteDefinition|DirectionalGraphicalSpriteSet
  {
    $sprites = array_key_exists('asset', $data)
      ? GraphicalSpriteDefinition::fromArray($data) : DirectionalGraphicalSpriteSet::fromArray($data);
    $definitions = $sprites instanceof DirectionalGraphicalSpriteSet
      ? [$sprites->north, $sprites->east, $sprites->south, $sprites->west] : [$sprites];
    foreach ($definitions as $definition) {
      if ($definition->layer < PresentationLayerPolicy::WORLD || $definition->layer >= PresentationLayerPolicy::UI) {
        throw new \InvalidArgumentException('Cinematic world sprites require layers 0..999; UI layers are reserved.');
      }
    }
    return $sprites;
  }

  public function advanceGraphicalAnimation(float $seconds): void
  {
    foreach ($this->actors as $actor) {
      $actor->advanceGraphicalAnimation($seconds);
    }
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
    ($this->actors[trim($id)] ?? null)?->releaseVisual();
    unset($this->actors[trim($id)]);
    $this->gameScene->requestFieldPresentationReconciliation();
  }

  public function clear(bool $restoreTransforms = true): void
  {
    foreach ($this->subjects as $lease) {
      $lease->release($restoreTransforms);
    }
    foreach ($this->actors as $actor) {
      $actor->releaseVisual();
    }
    $this->actors = [];
    $this->subjects = [];
    $this->gameScene->requestFieldPresentationReconciliation();
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
    $this->lastMoveFailure = null;
    if ($faceOnly) {
      $actor->face($direction);
      return true;
    }

    $x = intval($actor->position->x + $direction->x);
    $y = intval($actor->position->y + $direction->y);
    $player = $this->gameScene->player;

    // Non-colliding actors are presentation-only. Their authored routes may
    // cross terrain and occupied tiles (and may briefly leave map bounds)
    // without affecting field passability.
    if ($actor->hasCollision) {
      $blocker = null;

      if ($player !== null && intval($player->position->x) === $x && intval($player->position->y) === $y) {
        $blocker = 'the player';
      }

      $npc = $this->gameScene->npcManager?->npcAt($x, $y);

      if ($blocker === null && $npc !== null) {
        $identity = $npc->id ?? $npc->name;
        $blocker = sprintf('visible NPC "%s"', $identity);
      }

      $stagedActor = $this->actorAt($x, $y, $id);

      if ($blocker === null && $stagedActor !== null) {
        $blocker = sprintf('visible collidable staged actor "%s"', $stagedActor->id);
      }

      $collisionType = null;

      if ($blocker === null && ! $this->gameScene->mapManager->canMoveTo($x, $y, $collisionType)) {
        $blocker = $collisionType instanceof CollisionType
          ? sprintf('map collision "%s"', strtolower($collisionType->name))
          : 'map bounds or undefined terrain';
      }

      if ($blocker !== null) {
        $actor->face($direction);
        $this->lastMoveFailure = sprintf(
          'Staged actor "%s" cannot move to (%d, %d): blocked by %s.',
          $id,
          $x,
          $y,
          $blocker,
        );
        return false;
      }
    }

    $actor->move($direction);
    return true;
  }

  public function render(): void
  {
    foreach ($this->actors as $actor) {
      if ($actor->isVisible) {
        Console::withLayer($actor->getGraphicalSpriteId(), function () use ($actor): void {
          $this->gameScene->camera->renderOnScreen($actor->sprite, $actor->position);
        });
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
