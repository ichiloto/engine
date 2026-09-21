<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Stats;

/** Ephemeral display projections of the existing character, never a second gameplay model. */
final class CharacterMenuRows
{
  private const array STATS = ['attack' => 'Attack', 'defence' => 'Defence', 'magicAttack' => 'M.Attack',
    'magicDefence' => 'M.Defence', 'evasion' => 'Evasion', 'speed' => 'Speed', 'grace' => 'Grace'];

  /** @return list<MenuRow> */
  public static function stats(Character $character, MenuPresentationCatalog $theme, bool $comparison = false, ?Stats $preview = null): array
  {
    $rows = [];
    if ($comparison) {
      $rows[] = new MenuRow('heading', 'Stats', [new MenuRowValue('Current'), new MenuRowValue(''),
        new MenuRowValue('Preview'), new MenuRowValue('')], MenuRowKind::HEADING);
    }
    $fields = $comparison ? ['totalHp' => 'HP', 'totalMp' => 'MP', ...self::STATS] : self::STATS;
    $current = $character->effectiveStats;
    foreach ($fields as $field => $label) {
      $values = [new MenuRowValue(number_format($current->$field))];
      if ($comparison) {
        $next = $preview === null ? $current->$field : $preview->$field;
        $direction = $next <=> $current->$field;
        $color = $theme->colors[match ($direction) { 1 => 'increase', -1 => 'decrease', default => 'text' }];
        array_push($values, new MenuRowValue('>'), new MenuRowValue(number_format($next), $color),
          new MenuRowValue(match ($direction) { 1 => '+', -1 => '-', default => '' }, $color));
      }
      $rows[] = new MenuRow($field, $label, $values);
    }
    return $rows;
  }

  /** @return list<MenuRow> */
  public static function slots(Character $character, int $activeIndex = -1, bool $focused = false): array
  {
    $rows = [];
    foreach ($character->equipment as $index => $slot) {
      $equipment = $slot->equipment;
      $rows[] = new MenuRow('slot-' . $index, $slot->name,
        [new MenuRowValue($equipment === null ? '' : $equipment->name)], icon: self::icon($equipment instanceof Equipment ? $equipment : null, $slot->semanticSlot),
        selected: $index === $activeIndex, focused: $focused && $index === $activeIndex);
    }
    return $rows;
  }

  public static function icon(?Equipment $equipment, ?EquipmentSlotType $slot = null): WeaponType|EquipmentSlotType|null
  {
    if ($equipment === null) { return $slot; }
    return $equipment->equipmentType instanceof WeaponType ? $equipment->equipmentType : ($equipment->semanticSlot ?? $slot);
  }
}
