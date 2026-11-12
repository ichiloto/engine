<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;

/**
 * One of a map's battle arena rules: the arena random battles are fought on
 * in a named area of the map, on an encounter terrain, or on that terrain
 * within the area. Authored in the map's encounter block:
 *
 *     'battleArenas' => [
 *       ['area' => 'garden-north-fringe', 'battleArena' => 'arena.garden-fields'],
 *       ['terrain' => 'woodland', 'battleArena' => 'arena.apthia-woodland'],
 *       ['area' => 'apthia-fields', 'terrain' => 'wetland', 'battleArena' => 'arena.apthia-marsh'],
 *     ],
 *
 * A rule naming a terrain picks the arena drawn for it outright; a rule
 * naming only an area picks the area's region arena, whose terrain variants
 * still refine it ({@see BattlePresentationCatalog::resolveArenaVariant()}).
 * Arenas are graphical presentation only; a rule changes no troop and no fight.
 */
final class EncounterArenaRule
{
  /** The encounter block key holding the rules. */
  public const string KEY = 'battleArenas';
  /** The keys one rule may carry. */
  public const array FIELDS = ['area', 'terrain', BattlePresentationCatalog::ARENA_SETTING];

  /**
   * @param string|null $area The map area the rule holds in, or null for the whole map.
   * @param string|null $terrain The encounter terrain the rule holds on, or null for any.
   * @param string $arena The arena it picks, by catalog key.
   */
  public function __construct(public readonly ?string $area, public readonly ?string $terrain, public readonly string $arena)
  {
  }

  /**
   * Reads one authored rule, or says why it cannot be read.
   *
   * @return self|string The rule, or why the engine leaves it out.
   */
  public static function getFromValue(mixed $value): self|string
  {
    if (!is_array($value) || array_is_list($value) && $value !== []) {
      return 'a rule is a map of area, terrain and battleArena';
    }
    $unknown = array_diff(array_map(strval(...), array_keys($value)), self::FIELDS);
    if ($unknown !== []) {
      return sprintf('it carries %s, which a rule does not read', implode(', ', $unknown));
    }
    $area = $value['area'] ?? null;
    if ($area !== null && (!is_string($area) || preg_match(MapAreaCatalog::ID_PATTERN, $area) !== 1)) {
      return 'its area is not an area id';
    }
    $terrain = $value['terrain'] ?? null;
    if ($terrain !== null && (!is_string($terrain) || preg_match(TerrainKind::ENCOUNTER_PATTERN, $terrain) !== 1)) {
      return 'its terrain is not an encounter terrain id';
    }
    $arena = $value[BattlePresentationCatalog::ARENA_SETTING] ?? null;
    if (!is_string($arena) || trim($arena) === '') {
      return 'it names no battleArena';
    }
    if ($area === null && $terrain === null) {
      return 'it names neither an area nor a terrain; the map\'s own battleArena covers the whole map';
    }
    return new self($area, $terrain, $arena);
  }

  /** How the rule reads in a report: its area, its terrain, or both. */
  public string $scope {
    get => match (true) {
      $this->area !== null && $this->terrain !== null => "area {$this->area}, {$this->terrain}",
      $this->area !== null => "area {$this->area}",
      default => "terrain {$this->terrain}",
    };
  }
}
