<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use InvalidArgumentException;

/** Project-owned field and battle timelines; consumers own their anchors and gameplay. */
final class EffectTimelineLibrary
{
  public const string DIRECTORY = 'Animations';
  /** A timeline's stable identity: lowercase letters, digits, hyphens and underscores. */
  public const string ID_PATTERN = '/\A[a-z0-9][a-z0-9_-]*\z/';
  /** @var array<string, CompiledEffectTimeline> */
  private array $cache = [];

  public function __construct(public readonly string $assetRoot) {}

  public function load(string $id, bool $forBattle = false,
    EffectPresentation $presentation = EffectPresentation::GRAPHICAL, bool $forStage = false): CompiledEffectTimeline
  {
    if ($forStage && $forBattle) {
      throw new InvalidArgumentException('Standalone stage loading cannot also select battle admission.');
    }
    self::assertId($id);
    $key = ($forStage ? 'stage:' : ($forBattle ? 'battle:' : 'field:')) . $presentation->value . ':' . $id;
    if (isset($this->cache[$key])) { return $this->cache[$key]; }
    $path = $this->assetRoot . '/' . self::DIRECTORY . "/{$id}/{$id}.timeline.php";
    $root = realpath($this->assetRoot);
    $file = realpath($path);
    if ($root === false || $file === false || !is_file($file)
      || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
      throw new InvalidArgumentException("Effect timeline {$id} was not found inside the asset root.");
    }
    $data = (static fn(string $file): mixed => require $file)($file);
    return $this->cache[$key] = $this->compile($id, $data, $forBattle, $presentation, $forStage);
  }

  public function loadStage(string $id): CompiledEffectTimeline
  {
    return $this->load($id, forStage: true);
  }

  /**
   * Lists the timelines this asset root holds by stable identity, sorted:
   * every `Animations/<id>/<id>.timeline.php` with a valid id, inside the
   * asset root as load() requires. Listing reads no timeline; load() still
   * decides whether one can play.
   *
   * @return list<string>
   */
  public function findTimelineIds(): array
  {
    $root = realpath($this->assetRoot);
    $ids = [];

    foreach ($root === false ? [] : (glob($this->assetRoot . '/' . self::DIRECTORY . '/*/*.timeline.php') ?: []) as $path) {
      $id = basename(dirname($path));
      $file = realpath($path);

      if ($id === basename($path, '.timeline.php') && preg_match(self::ID_PATTERN, $id) === 1
        && $file !== false && is_file($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        $ids[] = $id;
      }
    }

    sort($ids);
    return $ids;
  }

  public static function assertId(string $id): void
  {
    if (preg_match(self::ID_PATTERN, $id) !== 1) {
      throw new InvalidArgumentException('Effect identities use lowercase letters, digits, hyphens and underscores.');
    }
  }

  public function compile(string $id, mixed $data, bool $forBattle = false,
    EffectPresentation $presentation = EffectPresentation::GRAPHICAL, bool $forStage = false): CompiledEffectTimeline
  {
    return (new EffectTimelineValidator($this->assetRoot))->compile($id, $data, $forBattle, $presentation, forStage: $forStage);
  }

  public function compileStage(string $id, mixed $data): CompiledEffectTimeline
  {
    return $this->compile($id, $data, forStage: true);
  }
}
