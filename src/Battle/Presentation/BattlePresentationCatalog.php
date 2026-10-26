<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/** Current project configuration, deliberately outside all save payloads. */
final readonly class BattlePresentationCatalog
{
  public const string FILE = 'Data/Presentation/battle.php';
  /** The battle setting naming the arena a battle is fought on. */
  public const string ARENA_SETTING = 'battleArena';
  /**
   * The battle setting naming the encounter terrain a battle started on, which
   * the arena's terrain variants refine its picture by. A battle with none
   * (an event or boss battle) is drawn on its arena as authored.
   */
  public const string TERRAIN_SETTING = 'battleTerrain';
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
  /** @var array<string, BattlerGrounding> How actors meet the ground: hover and contact shadow. */
  public array $actorGrounding;
  /** @var array<string, BattlerGrounding> How enemies meet the ground: hover and contact shadow. */
  public array $enemyGrounding;

  public function __construct(array $arenas, array $actors, array $enemies, public ?BattleCanvasLayout $ui = null,
    public ?BattleResultsSkin $results = null, public ?BattlePauseSkin $pause = null,
    array $actorPoses = [], array $enemyPoses = [], public ?string $defaultArena = null,
    public ?BattleScale $scale = null, array $actorGrounding = [], array $enemyGrounding = [])
  {
    $this->actorGrounding = self::catalog($actorGrounding, BattlerGrounding::class);
    $this->enemyGrounding = self::catalog($enemyGrounding, BattlerGrounding::class);
    $this->arenas = self::catalog($arenas, BattleArenaDefinition::class);
    if ($defaultArena !== null && !isset($this->arenas[$defaultArena])) {
      throw new InvalidArgumentException("Unknown default battle arena: {$defaultArena}");
    }
    foreach ($this->arenas as $id => $arena) {
      foreach ($arena->variants as $terrain => $variant) {
        if (!isset($this->arenas[$variant])) {
          throw new InvalidArgumentException("Battle arena {$id} draws {$terrain} on {$variant}, which is no battle arena.");
        }
      }
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

  /**
   * The arena a battle is drawn on: the one it names, or the default; on a
   * terrain the arena has a variant for, that variant. A battle that started
   * on a terrain its arena has no variant for is drawn on the arena itself,
   * and the missing variant is logged, never passed over in silence.
   */
  public function getArenaFor(BattleConfig $battle): ?BattleArenaDefinition
  {
    $key = array_key_exists(self::ARENA_SETTING, $battle->settings) ? $battle->settings[self::ARENA_SETTING] : $this->defaultArena;
    if ($key === null && !array_key_exists(self::ARENA_SETTING, $battle->settings)) { return null; }
    if (!is_string($key)) {
      throw new InvalidArgumentException('battleArena must be a string arena key.');
    }
    $terrain = $battle->settings[self::TERRAIN_SETTING] ?? null;
    if ($terrain !== null && (!is_string($terrain) || preg_match(TerrainKind::ENCOUNTER_PATTERN, $terrain) !== 1)) {
      throw new InvalidArgumentException('battleTerrain must be an encounter terrain id.');
    }
    $choice = $this->resolveArenaVariant($key, $terrain);
    if ($choice->isMissingVariant) {
      Debug::warn(sprintf('Battle arena %s has no %s variant; the battle is drawn on its own background. Add the variant in %s.',
        $key, $terrain, BattleArenaBindings::FILE));
    }
    return $this->getArena($choice->key);
  }

  /**
   * Which arena draws a battle named on this arena that started on this
   * encounter terrain: the arena's variant for it, or the arena itself, with
   * whether that is because the variant is missing.
   *
   * @param string|null $terrain The encounter terrain, or null for a battle that started on none.
   */
  public function resolveArenaVariant(string $key, ?string $terrain): BattleArenaChoice
  {
    $arena = $this->getArena($key);
    if ($terrain === null) {
      return new BattleArenaChoice($key, null, false);
    }
    $variant = $arena->variants[$terrain] ?? null;
    return $variant === null ? new BattleArenaChoice($key, $terrain, true) : new BattleArenaChoice($variant, $terrain, false);
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
    $catalog = $bindings === null ? $catalog : $catalog->bindBattlers($bindings);
    $arenas = BattleArenaBindings::load($assetRoot);
    return $arenas === null ? $catalog : $catalog->bindArenas($arenas);
  }

  /**
   * This catalog with arenas bound as data ({@see BattleArenaBindings})
   * beside those its code registers. Each arena identity, and the default
   * arena, has one owner: one named in both is refused, never overridden.
   *
   * @throws InvalidArgumentException When code and data both own an arena or the default, or no layout can draw them.
   */
  public function bindArenas(BattleArenaBindings $bindings): self
  {
    if ($bindings->arenas === []) {
      return $bindings->defaultArena === null ? $this
        : throw new InvalidArgumentException(BattleArenaBindings::FILE . ' names a default arena it does not declare.');
    }
    $ui = $this->ui ?? throw new InvalidArgumentException(BattleArenaBindings::FILE
      . ' declares arenas, but ' . self::FILE . ' gives no battle canvas layout to draw them on.');
    $shared = array_values(array_intersect(array_keys($bindings->arenas), array_keys($this->arenas)));
    if ($shared !== []) {
      throw new InvalidArgumentException(sprintf('%s %s declared both in %s and in %s; one of them must own it.',
        implode(', ', $shared), count($shared) === 1 ? 'is' : 'are', self::FILE, BattleArenaBindings::FILE));
    }
    if ($bindings->defaultArena !== null && $this->defaultArena !== null) {
      throw new InvalidArgumentException(sprintf('The default battle arena is named both in %s and in %s; one of them must own it.',
        self::FILE, BattleArenaBindings::FILE));
    }
    return new self([...$this->arenas, ...$bindings->getDefinitions($ui->width, $ui->height)], $this->actors, $this->enemies,
      $this->ui, $this->results, $this->pause, $this->actorPoses, $this->enemyPoses,
      $this->defaultArena ?? $bindings->defaultArena, $this->scale, $this->actorGrounding, $this->enemyGrounding);
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
      [...$this->enemyPoses, ...$bindings->enemyPoses], $this->defaultArena, $scale,
      [...$this->actorGrounding, ...$bindings->actorGrounding], [...$this->enemyGrounding, ...$bindings->enemyGrounding]);
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
