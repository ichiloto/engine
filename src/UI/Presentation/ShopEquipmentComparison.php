<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats\StatKey;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\UI\Text\TextViewport;

/** Read-only comparison content for the existing shop detail panel in both presentations. */
final readonly class ShopEquipmentComparison
{
  /** @param list<array{offset: int, text: string, color: string, stat: bool, neutralLength: int}> $cells */
  private function __construct(public string $source, private array $cells) {}

  public static function create(Party $party, Equipment $item): self
  {
    $source = '';
    $cells = [];
    $add = static function (string $text, string $color = 'text', bool $stat = false, int $neutralLength = 0) use (&$source, &$cells): void {
      $text = TextViewport::normalize($text);
      $cells[] = ['offset' => strlen($source), 'text' => $text, 'color' => $color, 'stat' => $stat, 'neutralLength' => $neutralLength];
      $source .= $text . "\n";
    };
    foreach ($party->members->toArray() as $index => $actor) {
      $name = $actor->name . ($index >= Party::BATTLE_SIZE ? ' (' . Vocabulary::getTerm('party.reserve', 'Reserve') . ')' : '');
      if (!$actor instanceof Character) {
        $add($name, 'accent');
        $add(Vocabulary::getTerm('shop.previewUnavailable', 'Equipment preview unavailable'), 'muted');
        $add('');
        continue;
      }
      $slots = array_values(array_filter($actor->equipment, static fn($slot): bool => $actor->canEquip($item)
        && $slot->acceptsType === $item::class && $slot->semanticSlot === $item->semanticSlot));
      if ($slots === []) {
        $add($name . ': ' . Vocabulary::getTerm('shop.cannotEquip', 'Cannot equip'), 'muted');
        $add('');
        continue;
      }
      $current = $actor->effectiveStats;
      foreach ($slots as $slot) {
        $after = $actor->previewEquipmentStats($slot->name, $item);
        if ($after === null) { throw new \LogicException('Eligible shop equipment has no stat preview.'); }
        $same = $slot->equipment instanceof Equipment && $slot->equipment::class === $item::class && $slot->equipment->id === $item->id;
        $add($name . (count($slots) > 1 ? ' / ' . $slot->name : '') . ': '
          . Vocabulary::getTerm($same ? 'shop.equipped' : 'shop.canEquip', $same ? 'Already equipped' : 'Can equip'), 'accent');
        $add(Vocabulary::getTerm('shop.wearing', 'Wearing') . ': ' . ($slot->equipment?->name ?? Vocabulary::getTerm('equipment.empty', 'Empty')), 'muted');
        $labels = ['maxHp' => 'HP', 'maxMp' => 'MP', 'attack' => 'ATK', 'defence' => 'DEF',
          'magicAttack' => 'MAT', 'magicDefence' => 'MDF', 'speed' => 'SPD', 'grace' => 'GRC', 'evasion' => 'EVA'];
        foreach (StatKey::cases() as $stat) {
          $field = $stat->statsProperty();
          $beforeValue = $current->$field;
          $afterValue = $after->$field;
          $delta = $afterValue - $beforeValue;
          $label = Vocabulary::getTerm('stats.short.' . $stat->value, $labels[$stat->value]);
          $prefix = $label . ' ' . number_format($beforeValue) . ' → ';
          $add($prefix . number_format($afterValue),
            $delta > 0 ? 'increase' : ($delta < 0 ? 'decrease' : 'text'), true, strlen($prefix));
        }
      }
    }
    return new self($source, $cells);
  }

  /** Compact stat cells flow within the current panel width; long names remain complete wrapped lines.
   * @return list<array{offset: int, cells: list<array{x: int, text: string, color: string}>}>
   */
  public function getLines(int $columns): array
  {
    $lines = [];
    $row = null;
    $used = 0;
    $groups = 0;
    foreach ($this->cells as $cell) {
      $width = TextViewport::measure($cell['text']);
      if ($cell['stat'] && $width <= $columns) {
        if ($row !== null && ($groups >= 3 || $used + 2 + $width > $columns)) {
          $lines[] = $row;
          $row = null;
        }
        if ($row === null) { $row = ['offset' => $cell['offset'], 'cells' => []]; $used = 0; $groups = 0; }
        $x = $used === 0 ? 0 : $used + 2;
        array_push($row['cells'], ...self::createLineCells($cell, $cell['text'], 0, $x));
        $used = $x + $width;
        $groups++;
      } else {
        if ($row !== null) { $lines[] = $row; $row = null; }
        foreach (TextViewport::wrap($cell['text'], $columns) as [$offset, $text]) {
          $lines[] = ['offset' => $cell['offset'] + $offset, 'cells' => self::createLineCells($cell, $text, $offset)];
        }
      }
    }
    if ($row !== null) { $lines[] = $row; }
    return $lines;
  }

  /** Keep the stat name, current value and arrow neutral; only the resulting value carries comparison colour.
   * @param array{neutralLength: int, color: string} $cell
   * @return list<array{x: int, text: string, color: string}>
   */
  private static function createLineCells(array $cell, string $text, int $offset, int $x = 0): array
  {
    $neutral = substr($text, 0, max(0, $cell['neutralLength'] - $offset));
    $result = substr($text, strlen($neutral));
    $cells = [];
    if ($neutral !== '') { $cells[] = ['x' => $x, 'text' => $neutral, 'color' => 'text']; }
    if ($result !== '' || $cells === []) {
      $cells[] = ['x' => $x + TextViewport::measure($neutral), 'text' => $result, 'color' => $cell['color']];
    }
    return $cells;
  }

  /** @param array{cells: list<array{x: int, text: string, color: string}>} $line */
  public static function getPlainLine(array $line): string
  {
    $text = '';
    foreach ($line['cells'] as $cell) {
      $text .= str_repeat(' ', max(0, $cell['x'] - TextViewport::measure($text))) . $cell['text'];
    }
    return $text;
  }
}
