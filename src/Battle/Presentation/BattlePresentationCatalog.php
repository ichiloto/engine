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
    array $actorPoses = [], array $enemyPoses = [], public ?string $defaultArena = null)
  {
    $this->arenas = self::catalog($arenas, BattleArenaDefinition::class);
    if ($defaultArena !== null && !isset($this->arenas[$defaultArena])) {
      throw new InvalidArgumentException("Unknown default battle arena: {$defaultArena}");
    }
    $this->actors = self::catalog($actors, BattlerArtwork::class);
    $this->enemies = self::catalog($enemies, BattlerArtwork::class);
    $this->actorPoses = self::catalog($actorPoses, BattlePoseSet::class);
    $this->enemyPoses = self::catalog($enemyPoses, BattlePoseSet::class);
  }

  public function getArenaFor(BattleConfig $battle): ?BattleArenaDefinition
  {
    $key = array_key_exists('battleArena', $battle->settings) ? $battle->settings['battleArena'] : $this->defaultArena;
    if ($key === null && !array_key_exists('battleArena', $battle->settings)) { return null; }
    if (!is_string($key)) {
      throw new InvalidArgumentException('battleArena must be a string arena key.');
    }
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
      RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::CANVAS_COMPOSITING,
      ...($this->pause !== null || $this->results !== null || $this->ui?->skin !== null || array_any($this->arenas, static fn($arena) => $arena->skin !== null)
        ? [RendererSessionConfig::CANVAS_GLYPH_EFFECTS] : [])];
  }

  public static function load(string $assetRoot): ?self
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
