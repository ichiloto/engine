<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\EquipmentIcon;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Localization\Vocabulary;

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
      $label = Vocabulary::getTerm('stats.' . match ($field) { 'totalHp' => 'hp', 'totalMp' => 'mp', default => $field }, $label);
      $values = [new MenuRowValue(number_format($current->$field))];
      if ($comparison) {
        $next = $preview === null ? $current->$field : $preview->$field;
        $direction = $next <=> $current->$field;
        $color = $theme->colors[match ($direction) { 1 => 'increase', -1 => 'decrease', default => 'text' }];
        array_push($values, MenuRowValue::getArrow(MenuDirection::RIGHT), new MenuRowValue(number_format($next), $color),
          match ($direction) {
            1 => MenuRowValue::getArrow(MenuDirection::UP, $color),
            -1 => MenuRowValue::getArrow(MenuDirection::DOWN, $color),
            default => new MenuRowValue(''),
          });
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
        [new MenuRowValue($equipment === null ? '' : $equipment->name)], icon: self::getSlotIcon($character, $slot),
        selected: $index === $activeIndex, focused: $focused && $index === $activeIndex);
    }
    return $rows;
  }

  public static function getSlotIcon(Character $character, EquipmentSlot $slot): WeaponType|EquipmentSlotType
  {
    if ($slot->semanticSlot !== EquipmentSlotType::WEAPON) { return $slot->semanticSlot; }
    $types = self::getAcceptedTypes($character, $slot);
    if (count($types) === 1 && $types[0] instanceof WeaponType) {
      return $types[0];
    }
    // A representative icon is stable; the compatibility text lists every accepted type.
    if ($slot->semanticSlot === EquipmentSlotType::WEAPON && $character->attackStyle !== null
      && $character->role->allowsEquipmentType($character->attackStyle)) {
      return $character->attackStyle;
    }
    return $slot->semanticSlot;
  }

  public static function getSlotCompatibilityText(Character $character, EquipmentSlot $slot): string
  {
    if ($slot->semanticSlot === EquipmentSlotType::ACCESSORY) { return 'Accepts: Accessories'; }
    $types = self::getAcceptedTypes($character, $slot);
    $weapon = $slot->semanticSlot === EquipmentSlotType::WEAPON;
    $label = $weapon ? 'Weapon types: ' : 'Armor types: ';
    $all = $weapon ? WeaponType::cases() : ArmorType::cases();
    return $label . (count($types) === count($all) ? 'Any'
      : implode(', ', array_map(fn(WeaponType|ArmorType $type) => $type->value, $types)));
  }

  public static function getSlotDescription(Character $character, EquipmentSlot $slot): string
  {
    return implode("\n", array_filter([self::getSlotCompatibilityText($character, $slot), $slot->description],
      fn(string $text) => $text !== ''));
  }

  /** @return list<WeaponType|ArmorType> */
  private static function getAcceptedTypes(Character $character, EquipmentSlot $slot): array
  {
    $types = match ($slot->semanticSlot) {
      EquipmentSlotType::WEAPON => WeaponType::cases(),
      EquipmentSlotType::SHIELD, EquipmentSlotType::HEAD, EquipmentSlotType::BODY => ArmorType::cases(),
      EquipmentSlotType::ACCESSORY => [],
    };
    return array_values(array_filter($types, $character->role->allowsEquipmentType(...)));
  }

  public static function getEquipmentIcon(?Equipment $equipment, ?EquipmentSlotType $slot = null): WeaponType|EquipmentSlotType|null
  {
    if ($equipment === null) { return $slot; }
    return EquipmentIcon::getType($equipment);
  }
}
