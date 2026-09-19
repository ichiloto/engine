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
  /** @var array<string, BattleArenaDefinition> Authored arena keys; legacy entries use troop IDs/names. */
  public array $arenas;
  /** @var array<string, BattlerArtwork> Actor IDs. */
  public array $actors;
  /** @var array<string, BattlerArtwork> Existing EnemyStore catalog keys. */
  public array $enemies;

  public function __construct(array $arenas, array $actors, array $enemies, public ?BattleCanvasLayout $ui = null,
    public ?BattleResultsSkin $results = null, public ?BattlePauseSkin $pause = null)
  {
    if ($ui !== null && $ui->skin === null) {
      throw new InvalidArgumentException('Project-wide battle UI requires a skin.');
    }
    $this->arenas = self::catalog($arenas, BattleArenaDefinition::class);
    $this->actors = self::catalog($actors, BattlerArtwork::class);
    $this->enemies = self::catalog($enemies, BattlerArtwork::class);
  }

  public function arenaFor(BattleConfig $battle): ?BattleArenaDefinition
  {
    $key = $battle->settings['battleArena'] ?? null;
    if ($key === null) {
      return $this->arenas[$battle->troop->definitionId ?? $battle->troop->name] ?? null;
    }
    if (!is_string($key)) {
      throw new InvalidArgumentException('battleArena must be a string arena key.');
    }
    CanvasValidation::id($key);
    return $this->arenas[$key] ?? throw new RuntimeException("Unknown graphical battle arena: {$key}");
  }

  public static function exists(string $assetRoot): bool
  {
    $path = $assetRoot . '/' . self::FILE;
    return file_exists($path) || is_link($path);
  }

  /** Requested before renderer startup; absent skins keep the G1 capability set. */
  public function requiredCapabilities(): array
  {
    return [RendererSessionConfig::GRAPHICAL_CANVAS,
      ...($this->pause !== null || $this->results !== null || $this->ui !== null || array_any($this->arenas, static fn($arena) => $arena->skin !== null)
        ? [RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::CANVAS_GLYPH_EFFECTS] : [])];
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
