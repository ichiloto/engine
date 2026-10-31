<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;

/**
 * One declarative map edit that moves saves to another map: a retired map's
 * places now stand on the map `into`, so a save standing on the retired map
 * moves there. Its position becomes the `to` of the first landing rectangle
 * holding it, or else the entry's own `to`.
 *
 * A retired map's places may have been split across several maps. A landing
 * then names its own `map`, the successor that holds that part of the old
 * places, and a save it holds lands there; a landing without one lands on
 * `into`, which takes over the retired identity.
 *
 * A map may retire into itself: its old layout is retired and redrawn under
 * the same identity, so every save made on it before this step moves from
 * where it stood on the old layout to that layout's landing on the new one.
 *
 * A save that visited the retired map has visited `into`, since the places it
 * saw are there now. Only `into` takes over the retired identity, so only it
 * inherits the visit; a save landing on another successor stands on it, and
 * standing there visits it as any arrival does. The retired map's one-shot events stay its own: an event
 * that moved to another map follows it only through an explicit
 * `one_shot_events` alias, so no marker of `into` reads as completed by
 * accident.
 *
 * The rectangles and landings are frozen in the manifest; loading a save
 * never consults the mutable map to decide where a player goes.
 */
final readonly class MapRetirement
{
  /**
   * @param list<array{area: Rect, map: string, to: array{int, int}}> $landings In authored order, each naming
   *     the map it lands on (`into` when the entry named none).
   * @param array{int, int} $to
   */
  private function __construct(
    public string $map,
    public string $into,
    public array $landings,
    public array $to,
  )
  {
  }

  /** Validates one manifest `mapRetirements` entry. */
  public static function createFromArray(mixed $entry, string $where): self
  {
    if (! is_array($entry)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must be an array with map, into, to and optional landings.', $where
      ));
    }

    $map = is_string($entry['map'] ?? null) ? trim($entry['map']) : '';
    if ($map === '') {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s map must be a non-empty string.', $where));
    }

    $into = is_string($entry['into'] ?? null) ? trim($entry['into']) : '';
    if ($into === '') {
      throw new InvalidSaveCompatibilityManifestException(sprintf('%s into must be a non-empty string.', $where));
    }

    $landings = [];
    if (array_key_exists('landings', $entry)) {
      $rawLandings = $entry['landings'];
      if (! is_array($rawLandings) || $rawLandings === [] || ! array_is_list($rawLandings)) {
        throw new InvalidSaveCompatibilityManifestException(sprintf(
          '%s landings must be a non-empty list; leave it out when every save lands on to.', $where
        ));
      }

      foreach ($rawLandings as $index => $landing) {
        $landingWhere = sprintf('%s landings[%d]', $where, $index);
        $landing = self::readLanding($landing, $landingWhere, $into);
        // Only a map redrawn under its own identity keeps saves on it; a retired map is gone.
        if ($landing['map'] === $map && $into !== $map) {
          throw new InvalidSaveCompatibilityManifestException(sprintf(
            '%s map %s is the retired map; land its saves on a map that took over its places.', $landingWhere, $map
          ));
        }
        $landings[] = $landing;
      }
    }

    return new self($map, $into, $landings, self::readCell($entry['to'] ?? null, "{$where} to"));
  }

  /**
   * Returns where a save standing at this position of the retired map lands:
   * the map and cell of the first landing whose rectangle holds the
   * position's cell, else the entry's `to` on `into`.
   *
   * @return array{map: string, position: Vector2}
   */
  public function getLandingPlace(Vector2 $position): array
  {
    $cell = new Vector2((int) floor($position->x), (int) floor($position->y));

    foreach ($this->landings as $landing) {
      if ($landing['area']->contains($cell)) {
        return ['map' => $landing['map'], 'position' => new Vector2(...$landing['to'])];
      }
    }

    return ['map' => $this->into, 'position' => new Vector2(...$this->to)];
  }

  /**
   * Returns every place a save can land on, the fallback on `into` first,
   * each once, so tooling checks the same places the migration uses.
   *
   * @return list<array{map: string, cell: array{int, int}}>
   */
  public function getLandingPlaces(): array
  {
    $places = [['map' => $this->into, 'cell' => $this->to]];

    foreach ($this->landings as $landing) {
      $place = ['map' => $landing['map'], 'cell' => $landing['to']];
      if (! in_array($place, $places, true)) {
        $places[] = $place;
      }
    }

    return $places;
  }

  /** @return array{area: Rect, map: string, to: array{int, int}} */
  private static function readLanding(mixed $landing, string $where, string $into): array
  {
    if (! is_array($landing)) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must be an array with x, y, width, height, to and an optional map.', $where
      ));
    }

    $map = $into;
    if (array_key_exists('map', $landing)) {
      $map = is_string($landing['map']) ? trim($landing['map']) : '';
      if ($map === '') {
        throw new InvalidSaveCompatibilityManifestException(sprintf('%s map must be a non-empty string; leave it out to land on into.', $where));
      }
    }

    foreach (['x', 'y'] as $key) {
      if (! is_int($landing[$key] ?? null) || $landing[$key] < 0) {
        throw new InvalidSaveCompatibilityManifestException(sprintf('%s %s must be a non-negative integer.', $where, $key));
      }
    }

    foreach (['width', 'height'] as $key) {
      if (! is_int($landing[$key] ?? null) || $landing[$key] < 1) {
        throw new InvalidSaveCompatibilityManifestException(sprintf('%s %s must be a positive integer.', $where, $key));
      }
    }

    return [
      'area' => new Rect($landing['x'], $landing['y'], $landing['width'], $landing['height']),
      'map' => $map,
      'to' => self::readCell($landing['to'] ?? null, "{$where} to"),
    ];
  }

  /** @return array{int, int} */
  private static function readCell(mixed $cell, string $where): array
  {
    if (! is_array($cell) || ! array_is_list($cell) || count($cell) !== 2
      || ! is_int($cell[0]) || ! is_int($cell[1]) || $cell[0] < 0 || $cell[1] < 0) {
      throw new InvalidSaveCompatibilityManifestException(sprintf(
        '%s must be [x, y] with non-negative integers.', $where
      ));
    }

    return [$cell[0], $cell[1]];
  }
}
