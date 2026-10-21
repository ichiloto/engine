<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;
use RuntimeException;

/**
 * Battle arenas as plain data: the form an editor reads and writes without
 * rewriting program code. Each arena names its display name, its background
 * (an asset-relative image filling the battle canvas, optionally a crop of
 * it) and the ground its background shows ({@see BattleGround}); the file
 * may name the default arena. It builds the same BattleArenaDefinition values
 * code would. Image dimensions are read from the current file, never stored.
 *
 *     return [
 *       'default' => 'arena.road',
 *       'arenas' => [
 *         'arena.road' => [
 *           'name' => 'Road',
 *           'background' => ['image' => 'Graphics/Arenas/Road.png', 'source' => ['x' => 0, 'y' => 0, 'width' => 675, 'height' => 360]],
 *           'ground' => ['top' => 330, 'bottom' => 590, 'perspective' => 0.9],
 *           'footing' => ['land' => [['x' => 340, 'y' => 330], ['x' => 1350, 'y' => 330], ['x' => 1350, 'y' => 590], ['x' => 520, 'y' => 590]]],
 *         ],
 *       ],
 *     ];
 *
 * `source`, `ground` (and each ground edge) and `footing` are optional:
 * `footing` marks the picture's land and water as polygons of canvas points
 * where battlers may plant their feet ({@see BattleFooting}); an arena
 * without it is land throughout. `shadow` is the arena's light on battlers'
 * contact shadows: `['strength' => 1.3, 'color' => '#1a120c']` ({@see BattleShadowLight}).
 */
final readonly class BattleArenaBindings
{
  public const string FILE = 'Data/Presentation/arenas.php';

  private const array TOP_KEYS = ['default', 'arenas'];
  private const array ARENA_KEYS = ['name', 'background', 'ground', 'footing', 'shadow'];
  private const array BACKGROUND_KEYS = ['image', 'source'];
  private const array SOURCE_KEYS = ['x', 'y', 'width', 'height'];

  /** @param array<string, array{name: string, image: string, source: ?SpriteSourceRect, ground: ?BattleGround, footing: BattleFooting, shadow: ?BattleShadowLight}> $arenas */
  private function __construct(
    public array $arenas,
    public ?string $defaultArena,
  ) {}

  public static function exists(string $assetRoot): bool
  {
    $path = $assetRoot . '/' . self::FILE;
    return file_exists($path) || is_link($path);
  }

  /** The project's arena data, or null when it declares none. */
  public static function load(string $assetRoot): ?self
  {
    if (!self::exists($assetRoot)) { return null; }
    $root = realpath($assetRoot);
    $path = realpath($assetRoot . '/' . self::FILE);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Battle arenas must be a readable file inside assets.');
    }
    $data = (static fn(string $file): mixed => require $file)($path);
    if (!is_array($data)) {
      throw new RuntimeException(self::FILE . ' must return an array of battle arenas.');
    }
    return self::getFromArray($data);
  }

  /**
   * Validates arenas as authored.
   *
   * @throws InvalidArgumentException Naming the first entry that cannot be read, by its path in the file.
   */
  public static function getFromArray(array $data): self
  {
    self::assertKeys($data, self::TOP_KEYS, self::FILE);
    $arenas = [];
    foreach (self::requireMap($data['arenas'] ?? [], 'arenas') as $id => $entry) {
      $where = "arenas.{$id}";
      if (!is_string($id)) { throw new InvalidArgumentException("{$where}: arena identities are strings."); }
      CanvasValidation::id($id);
      $entry = self::requireMap($entry, $where);
      self::assertKeys($entry, self::ARENA_KEYS, $where);
      $name = $entry['name'] ?? null;
      if (!is_string($name) || trim($name) === '' || preg_match('/\p{Cc}/u', $name) !== 0) {
        throw new InvalidArgumentException("{$where}.name: expected display text.");
      }
      $background = self::requireMap($entry['background'] ?? null, "{$where}.background");
      self::assertKeys($background, self::BACKGROUND_KEYS, "{$where}.background");
      $image = $background['image'] ?? null;
      if (!is_string($image)) {
        throw new InvalidArgumentException("{$where}.background.image: expected an asset-relative image path.");
      }
      SpriteValidation::validateAssetPath($image);
      $source = null;
      if (array_key_exists('source', $background)) {
        $rect = self::requireMap($background['source'], "{$where}.background.source");
        self::assertKeys($rect, self::SOURCE_KEYS, "{$where}.background.source");
        foreach (self::SOURCE_KEYS as $key) {
          if (!is_int($rect[$key] ?? null)) {
            throw new InvalidArgumentException("{$where}.background.source.{$key}: expected a whole number of pixels.");
          }
        }
        $source = new SpriteSourceRect($rect['x'], $rect['y'], $rect['width'], $rect['height']);
      }
      $ground = array_key_exists('ground', $entry) ? BattleGround::fromArray($entry['ground'], "{$where}.ground") : null;
      $footing = array_key_exists('footing', $entry) ? BattleFooting::fromArray($entry['footing'], "{$where}.footing") : new BattleFooting();
      $light = array_key_exists('shadow', $entry) ? BattleShadowLight::fromArray($entry['shadow'], "{$where}.shadow") : null;
      $arenas[$id] = ['name' => $name, 'image' => $image, 'source' => $source, 'ground' => $ground, 'footing' => $footing,
        'shadow' => $light];
    }
    $default = $data['default'] ?? null;
    if ($default !== null && (!is_string($default) || !isset($arenas[$default]))) {
      throw new InvalidArgumentException('default: expected the identity of an arena in this file.');
    }
    return new self($arenas, $default);
  }

  /**
   * The arenas as a battle canvas of this size draws them, each background
   * filling it.
   *
   * @return array<string, BattleArenaDefinition>
   */
  public function getDefinitions(int $width, int $height): array
  {
    $definitions = [];
    foreach ($this->arenas as $id => $arena) {
      $definitions[$id] = new BattleArenaDefinition($arena['name'],
        new CanvasImage($id, $arena['image'], new CanvasRectangle(0, 0, $width, $height), sourceRect: $arena['source']),
        ground: $arena['ground'], footing: $arena['footing'], shadowLight: $arena['shadow']);
    }
    return $definitions;
  }

  private static function requireMap(mixed $value, string $where): array
  {
    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
      throw new InvalidArgumentException("{$where}: expected keyed entries.");
    }
    return $value;
  }

  /** @param list<string> $allowed */
  private static function assertKeys(array $data, array $allowed, string $where): void
  {
    $unknown = array_diff(array_map(strval(...), array_keys($data)), $allowed);
    if ($unknown !== []) {
      throw new InvalidArgumentException(sprintf('%s: unknown %s %s; expected %s.', $where,
        count($unknown) === 1 ? 'key' : 'keys', implode(', ', $unknown), implode(', ', $allowed)));
    }
  }
}
