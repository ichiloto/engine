<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use InvalidArgumentException;
use RuntimeException;

/** Current project configuration, deliberately outside all save payloads. */
final readonly class BattlePresentationCatalog
{
  public const string FILE = 'Data/Presentation/battle.php';
  /** @var array<string, BattleArenaDefinition> Authored scene identities. */
  public array $arenas;
  /** @var array<string, BattlerArtwork> Actor IDs. */
  public array $actors;
  /** @var array<string, BattlerArtwork> Existing EnemyStore catalog keys. */
  public array $enemies;
  /** @var array<string, BattlePoseSet> Stable actor IDs, separate from field art. */
  public array $actorPoses;
  /** @var array<string, BattlePoseSet> EnemyStore catalog keys. */
  public array $enemyPoses;

  public function __construct(array $arenas, array $actors, array $enemies, public ?BattleCanvasLayout $ui = null,
    public ?BattleResultsSkin $results = null, public ?BattlePauseSkin $pause = null,
    array $actorPoses = [], array $enemyPoses = [], public ?string $defaultArena = null,
    public ?BattleScale $scale = null)
  {
    $this->arenas = self::catalog($arenas, BattleArenaDefinition::class);
    if ($defaultArena !== null && !isset($this->arenas[$defaultArena])) {
      throw new InvalidArgumentException("Unknown default battle arena: {$defaultArena}");
    }
    $this->actors = self::catalog($actors, BattlerArtwork::class);
    $this->enemies = self::catalog($enemies, BattlerArtwork::class);
    $this->actorPoses = self::catalog($actorPoses, BattlePoseSet::class);
    $this->enemyPoses = self::catalog($enemyPoses, BattlePoseSet::class);
    if ($scale !== null) {
      if (!isset($this->actors[$scale->referenceActorId])) {
        throw new InvalidArgumentException('The battle scale reference must be a registered actor identity.');
      }
      foreach ([[$this->actors, $this->actorPoses, true], [$this->enemies, $this->enemyPoses, false]] as [$artwork, $poses, $party]) {
        // A pose can supply usable artwork even when no base image is registered.
        foreach (array_unique([...array_keys($artwork), ...array_keys($poses)]) as $id) {
          if ($scale->getProfile($id, $party) === null) {
            throw new InvalidArgumentException("Reference battle scale requires a body profile for {$id}.");
          }
          if (($poses[$id]->displayWidth ?? null) !== null) {
            throw new InvalidArgumentException("{$id}: reference battle scale cannot also use legacy displayWidth.");
          }
        }
      }
    }
  }

  public function getArenaFor(BattleConfig $battle): ?BattleArenaDefinition
  {
    $key = array_key_exists('battleArena', $battle->settings) ? $battle->settings['battleArena'] : $this->defaultArena;
    if ($key === null && !array_key_exists('battleArena', $battle->settings)) { return null; }
    if (!is_string($key)) {
      throw new InvalidArgumentException('battleArena must be a string arena key.');
    }
    return $this->getArena($key);
  }

  public function getArena(string $key): BattleArenaDefinition
  {
    CanvasValidation::id($key);
    return $this->arenas[$key] ?? throw new RuntimeException("Unknown graphical battle arena: {$key}");
  }

  /** @return array<string, string> Scene key => display name, in authored order. */
  public function getArenaChoices(): array
  {
    return array_map(static fn(BattleArenaDefinition $arena): string => $arena->name, $this->arenas);
  }

  public static function exists(string $assetRoot): bool
  {
    $path = $assetRoot . '/' . self::FILE;
    return file_exists($path) || is_link($path);
  }

  /** Command effects may need crops and alpha even when no pose or skin is bound. */
  public function requiredCapabilities(): array
  {
    return [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::SPRITE_SOURCE_RECT,
      RendererSessionConfig::CANVAS_CLIP_OPACITY,
      ...($this->pause !== null || $this->results !== null || $this->ui?->skin !== null || array_any($this->arenas, static fn($arena) => $arena->skin !== null)
        ? [RendererSessionConfig::CANVAS_GLYPH_EFFECTS] : [])];
  }

  public static function load(string $assetRoot): ?self
  {
    $catalog = self::loadCode($assetRoot);
    if ($catalog === null) { return null; }
    $bindings = BattlerBindings::load($assetRoot);
    return $bindings === null ? $catalog : $catalog->bindBattlers($bindings);
  }

  /** Load the validated code-owned catalog so editors can bind proposed, unsaved data. */
  public static function loadCode(string $assetRoot): ?self
  {
    if (!self::exists($assetRoot)) { return null; }
    $root = realpath($assetRoot);
    $path = realpath($assetRoot . '/' . self::FILE);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Battle presentation catalog must be a readable file inside assets.');
    }
    $catalog = (static fn(string $file): mixed => require $file)($path);
    if (!$catalog instanceof self) {
      throw new RuntimeException(self::FILE . ' must return a BattlePresentationCatalog.');
    }
    return $catalog;
  }

  /**
   * This catalog with battlers bound as data ({@see BattlerBindings}) beside
   * those its code registers. Each identity has one owner: one bound in both
   * is refused, never silently overridden, and so is a scale reference named
   * in both. Data profiles join the code's scale; data may name the
   * reference when code does not.
   *
   * @throws InvalidArgumentException When code and data both own an identity or the reference.
   */
  public function bindBattlers(BattlerBindings $bindings): self
  {
    foreach ([true, false] as $party) {
      $owned = [...array_keys($party ? $this->actors : $this->enemies),
        ...array_keys($party ? $this->actorPoses : $this->enemyPoses),
        ...array_keys(($party ? $this->scale?->actors : $this->scale?->enemies) ?? [])];
      $shared = array_values(array_intersect($bindings->getIdentities($party), $owned));
      if ($shared !== []) {
        throw new InvalidArgumentException(sprintf('%s %s bound both in %s and in %s; one of them must own it.',
          implode(', ', $shared), count($shared) === 1 ? 'is' : 'are', self::FILE, BattlerBindings::FILE));
      }
    }
    if ($bindings->referenceActorId !== null && $this->scale !== null) {
      throw new InvalidArgumentException(sprintf('The battle scale reference is named both in %s and in %s; one of them must own it.',
        self::FILE, BattlerBindings::FILE));
    }
    $profiles = $bindings->actorProfiles !== [] || $bindings->enemyProfiles !== [];
    if ($profiles && $this->scale === null && $bindings->referenceActorId === null) {
      throw new InvalidArgumentException(BattlerBindings::FILE . ' gives body profiles but no scale reference to measure them against.');
    }
    $scale = $this->scale === null && $bindings->referenceActorId === null ? null : new BattleScale(
      $bindings->referenceActorId ?? $this->scale->referenceActorId,
      $bindings->referenceHeight ?? $this->scale->referenceHeight,
      [...($this->scale?->actors ?? []), ...$bindings->actorProfiles],
      [...($this->scale?->enemies ?? []), ...$bindings->enemyProfiles],
    );
    return new self($this->arenas, [...$this->actors, ...$bindings->actors], [...$this->enemies, ...$bindings->enemies],
      $this->ui, $this->results, $this->pause, [...$this->actorPoses, ...$bindings->actorPoses],
      [...$this->enemyPoses, ...$bindings->enemyPoses], $this->defaultArena, $scale);
  }

  /**
   * @template T of object
   * @param array<string, T> $entries
   * @param class-string<T> $type
   * @return array<string, T>
   */
  private static function catalog(array $entries, string $type): array
  {
    $copy = [];
    foreach ($entries as $key => $entry) {
      if (!is_string($key) || !$entry instanceof $type) {
        throw new InvalidArgumentException('Battle presentation catalogs require string identities and typed entries.');
      }
      CanvasValidation::id($key);
      $copy[$key] = $entry;
    }
    return $copy;
  }
}
