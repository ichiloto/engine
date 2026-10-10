<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;
use RuntimeException;

/**
 * Battle art bound to stable actor and enemy identities as plain data: the
 * form an editor reads and writes without rewriting program code. Each
 * identity may name its base artwork, its pose roles with their sheet intent,
 * and its body profile against the shared scale reference; the file may name
 * that reference once. It builds the same BattlerArtwork, BattlerPose,
 * BattlePoseSet and BattlerScale values code would, so a battle cannot tell
 * which an identity came from.
 *
 * Paths are asset-root relative. Image dimensions are read from the current
 * files, never stored, so replacing an image needs no edit here. Identities
 * and roles are named explicitly, never inferred from directory names.
 *
 * Shape, every key optional except where noted:
 *
 *     return [
 *       'reference' => ['actor' => 'Hero', 'height' => 150],
 *       'actors' => ['Hero' => [
 *         'artwork' => ['image' => 'Graphics/Hero/Idle.png', 'pivot' => ['x' => 0.5, 'y' => 0.9]],
 *         'poses' => ['attack' => ['image' => 'Graphics/Hero/Attack.png', 'columns' => 4, 'frames' => [0, 1, 2, 3],
 *           'fps' => 12, 'loop' => false, 'restFrame' => 0, 'pivot' => ['x' => 0.5, 'y' => 0.9], 'scaleSpan' => 0.7]],
 *         'scale' => ['relativeSize' => 1, 'sourceSpan' => 0.7, 'horizontal' => false],
 *       ]],
 *       'enemies' => [...the same per identity...],
 *     ];
 *
 * An identity needs artwork, poses or both. `image` is required wherever
 * artwork or a pose appears; a pivot defaults to bottom centre.
 */
final readonly class BattlerBindings
{
  public const string FILE = 'Data/Presentation/battlers.php';

  private const array TOP_KEYS = ['reference', 'actors', 'enemies'];
  private const array REFERENCE_KEYS = ['actor', 'height'];
  private const array BATTLER_KEYS = ['artwork', 'poses', 'scale'];
  private const array ARTWORK_KEYS = ['image', 'pivot'];
  private const array POSE_KEYS = ['image', 'pivot', 'columns', 'rows', 'frames', 'fps', 'loop', 'restFrame', 'scaleSpan'];
  private const array PIVOT_KEYS = ['x', 'y'];
  private const array PROFILE_KEYS = ['relativeSize', 'sourceSpan', 'horizontal'];

  /**
   * @param array<string, BattlerArtwork> $actors
   * @param array<string, BattlerArtwork> $enemies
   * @param array<string, BattlePoseSet> $actorPoses
   * @param array<string, BattlePoseSet> $enemyPoses
   * @param array<string, BattlerScale> $actorProfiles
   * @param array<string, BattlerScale> $enemyProfiles
   */
  private function __construct(
    public array $actors,
    public array $enemies,
    public array $actorPoses,
    public array $enemyPoses,
    public array $actorProfiles,
    public array $enemyProfiles,
    public ?string $referenceActorId,
    public ?float $referenceHeight,
  ) {}

  public static function exists(string $assetRoot): bool
  {
    $path = $assetRoot . '/' . self::FILE;
    return file_exists($path) || is_link($path);
  }

  /** The project's bindings, or null when it declares none. */
  public static function load(string $assetRoot): ?self
  {
    if (!self::exists($assetRoot)) { return null; }
    $root = realpath($assetRoot);
    $path = realpath($assetRoot . '/' . self::FILE);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Battler bindings must be a readable file inside assets.');
    }
    $data = (static fn(string $file): mixed => require $file)($path);
    if (!is_array($data)) {
      throw new RuntimeException(self::FILE . ' must return an array of battler bindings.');
    }
    return self::getFromArray($data, $assetRoot);
  }

  /**
   * Validates bindings as authored and builds their presentation values.
   *
   * @throws InvalidArgumentException Naming the first entry that cannot be read, by its path in the file.
   */
  public static function getFromArray(array $data, string $assetRoot): self
  {
    self::assertKeys($data, self::TOP_KEYS, self::FILE);
    $referenceActorId = $referenceHeight = null;
    if (array_key_exists('reference', $data)) {
      $reference = self::requireMap($data['reference'], 'reference');
      self::assertKeys($reference, self::REFERENCE_KEYS, 'reference');
      $referenceActorId = self::requireIdentity($reference['actor'] ?? null, 'reference.actor');
      $referenceHeight = self::requireNumber($reference['height'] ?? null, 'reference.height');
    }
    $built = [];
    foreach (['actors', 'enemies'] as $side) {
      $built[$side] = ['artwork' => [], 'poses' => [], 'scale' => []];
      foreach (self::requireMap($data[$side] ?? [], $side) as $id => $entry) {
        $where = "{$side}.{$id}";
        $id = self::requireIdentity($id, $where);
        $entry = self::requireMap($entry, $where);
        self::assertKeys($entry, self::BATTLER_KEYS, $where);
        if (!isset($entry['artwork']) && !isset($entry['poses'])) {
          throw new InvalidArgumentException("{$where}: a battler needs artwork, poses or both.");
        }
        try {
          if (isset($entry['artwork'])) {
            $built[$side]['artwork'][$id] = self::buildArtwork($entry['artwork'], $assetRoot, "{$where}.artwork");
          }
          if (isset($entry['poses'])) {
            $built[$side]['poses'][$id] = self::buildPoseSet($entry['poses'], "{$where}.poses");
          }
          if (isset($entry['scale'])) {
            $built[$side]['scale'][$id] = self::buildProfile($entry['scale'], "{$where}.scale");
          }
        } catch (InvalidArgumentException $error) {
          throw str_starts_with($error->getMessage(), $where) ? $error
            : new InvalidArgumentException("{$where}: {$error->getMessage()}", previous: $error);
        }
      }
    }
    return new self($built['actors']['artwork'], $built['enemies']['artwork'],
      $built['actors']['poses'], $built['enemies']['poses'],
      $built['actors']['scale'], $built['enemies']['scale'], $referenceActorId, $referenceHeight);
  }

  /** @return list<string> Every identity this file binds on one side. */
  public function getIdentities(bool $party): array
  {
    return array_values(array_unique([...array_keys($party ? $this->actors : $this->enemies),
      ...array_keys($party ? $this->actorPoses : $this->enemyPoses),
      ...array_keys($party ? $this->actorProfiles : $this->enemyProfiles)]));
  }

  private static function buildArtwork(mixed $data, string $assetRoot, string $where): BattlerArtwork
  {
    $data = self::requireMap($data, $where);
    self::assertKeys($data, self::ARTWORK_KEYS, $where);
    [$pivotX, $pivotY] = self::readPivot($data, $where);
    return BattlerArtwork::getFromPng($assetRoot, self::requireImage($data, $where), $pivotX, $pivotY);
  }

  private static function buildPoseSet(mixed $data, string $where): BattlePoseSet
  {
    $poses = [];
    foreach (self::requireMap($data, $where) as $role => $pose) {
      $at = "{$where}.{$role}";
      if (!is_string($role) || BattlePoseRole::tryFrom($role) === null) {
        $roles = implode(', ', array_map(static fn(BattlePoseRole $known): string => $known->value, BattlePoseRole::cases()));
        throw new InvalidArgumentException("{$at}: a pose role is one of {$roles}.");
      }
      $pose = self::requireMap($pose, $at);
      self::assertKeys($pose, self::POSE_KEYS, $at);
      [$pivotX, $pivotY] = self::readPivot($pose, $at);
      $frames = $pose['frames'] ?? [0];
      if (!is_array($frames) || !array_is_list($frames)) {
        throw new InvalidArgumentException("{$at}.frames: expected a list of cell numbers.");
      }
      $poses[$role] = new BattlerPose(
        self::requireImage($pose, $at),
        self::readInteger($pose, 'columns', 1, $at),
        self::readInteger($pose, 'rows', 1, $at),
        $frames,
        self::readInteger($pose, 'fps', 8, $at),
        self::readBoolean($pose, 'loop', true, $at),
        self::readInteger($pose, 'restFrame', 0, $at),
        $pivotX,
        $pivotY,
        array_key_exists('scaleSpan', $pose) ? self::requireNumber($pose['scaleSpan'], "{$at}.scaleSpan") : null,
      );
    }
    return new BattlePoseSet($poses);
  }

  private static function buildProfile(mixed $data, string $where): BattlerScale
  {
    $data = self::requireMap($data, $where);
    self::assertKeys($data, self::PROFILE_KEYS, $where);
    return new BattlerScale(
      self::requireNumber($data['relativeSize'] ?? null, "{$where}.relativeSize"),
      self::requireNumber($data['sourceSpan'] ?? null, "{$where}.sourceSpan"),
      self::readBoolean($data, 'horizontal', false, $where),
    );
  }

  /** @return array{float, float} A normalized pivot, bottom centre when unnamed. */
  private static function readPivot(array $data, string $where): array
  {
    if (!array_key_exists('pivot', $data)) { return [0.5, 1.0]; }
    $pivot = self::requireMap($data['pivot'], "{$where}.pivot");
    self::assertKeys($pivot, self::PIVOT_KEYS, "{$where}.pivot");
    $point = [];
    foreach (self::PIVOT_KEYS as $axis) {
      $value = self::requireNumber($pivot[$axis] ?? null, "{$where}.pivot.{$axis}");
      if ($value < 0 || $value > 1) {
        throw new InvalidArgumentException("{$where}.pivot.{$axis}: expected a number from 0 to 1, a share of the image.");
      }
      $point[] = $value;
    }
    return [$point[0], $point[1]];
  }

  private static function requireImage(array $data, string $where): string
  {
    $image = $data['image'] ?? null;
    if (!is_string($image)) {
      throw new InvalidArgumentException("{$where}.image: expected an asset-relative image path.");
    }
    SpriteValidation::validateAssetPath($image);
    return $image;
  }

  private static function requireIdentity(mixed $id, string $where): string
  {
    if (!is_string($id)) {
      throw new InvalidArgumentException("{$where}: battler identities are strings.");
    }
    CanvasValidation::id($id);
    return $id;
  }

  private static function requireNumber(mixed $value, string $where): float
  {
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
      throw new InvalidArgumentException("{$where}: expected a finite number.");
    }
    return (float) $value;
  }

  private static function readInteger(array $data, string $key, int $default, string $where): int
  {
    $value = $data[$key] ?? $default;
    if (!is_int($value)) {
      throw new InvalidArgumentException("{$where}.{$key}: expected a whole number.");
    }
    return $value;
  }

  private static function readBoolean(array $data, string $key, bool $default, string $where): bool
  {
    $value = $data[$key] ?? $default;
    if (!is_bool($value)) {
      throw new InvalidArgumentException("{$where}.{$key}: expected true or false.");
    }
    return $value;
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
