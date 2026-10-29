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
  /** @param list<array{offset: int, text: string, color: string, label: ?string, current: ?string, result: ?string, group: int, context: bool}> $entries */
  private function __construct(public string $source, private array $entries,
    /** @var list<array{id: string, affected: bool}> */ public array $profiles,
    /** @var list<string> */ private array $actors) {}

  public static function create(Party $party, Equipment $item): self
  {
    $source = '';
    $entries = [];
    $profiles = [];
    $actors = [];
    $group = -1;
    $add = static function (string $text, string $color = 'text', ?string $label = null,
      ?string $current = null, ?string $result = null, bool $context = false) use (&$source, &$entries, &$group): void {
      $text = TextViewport::normalize($text);
      $entries[] = ['offset' => strlen($source), 'text' => $text, 'color' => $color,
        'label' => $label, 'current' => $current, 'result' => $result, 'group' => $group, 'context' => $context];
      $source .= $text . "\n";
    };
    foreach ($party->participants->toArray() as $index => $actor) {
      if (!$actor instanceof Character) { continue; }
      $profile = count($profiles);
      $profiles[] = ['id' => $actor->actorId, 'affected' => false];
      if (!$actor->canEquip($item)) { continue; }
      $slots = array_values(array_filter($actor->equipment, static fn($slot): bool =>
        $slot->acceptsType === $item::class && $slot->semanticSlot === $item->semanticSlot));
      $name = $actor->name . ($index >= $party->battleSize ? ' (' . Vocabulary::getTerm('party.reserve', 'Reserve') . ')' : '');
      $current = $actor->effectiveStats;
      foreach ($slots as $slot) {
        $after = $actor->previewEquipmentStats($slot->name, $item);
        if ($after === null) { throw new \LogicException('Eligible shop equipment has no stat preview.'); }
        $changed = false;
        foreach (StatKey::cases() as $stat) {
          $field = $stat->statsProperty();
          if ($current->$field === $after->$field) { continue; }
          if (!$changed) {
            $group++;
            $actors[] = $actor->actorId;
            $add($name, 'accent');
            if (count($slots) > 1) { $add($slot->name, 'muted', context: true); }
            $add($slot->equipment?->name ?? Vocabulary::getTerm('equipment.empty', 'Empty'), 'muted');
            $changed = true;
            $profiles[$profile]['affected'] = true;
          }
          $label = CharacterMenuRows::getStatLabel($field);
          $beforeValue = number_format($current->$field);
          $afterValue = number_format($after->$field);
          $add($label . ' ' . $beforeValue . ' → ' . $afterValue,
            $after->$field > $current->$field ? 'increase' : 'decrease', $label, $beforeValue, $afterValue);
        }
      }
    }
    return new self($source, $entries, $profiles, $actors);
  }

  public function getGroupCount(): int { return count($this->actors); }

  public function getGroupActorId(int $group): ?string { return $this->actors[$group] ?? null; }

  /** Full stat names share right-aligned value columns; names and gear retain complete wrapping.
   * @return list<array{offset: int, cells: list<array{x: int, text: string, color: string}>}>
   */
  public function getLines(int $columns, ?int $group = null, bool $showIdentity = true): array
  {
    $entries = array_values(array_filter($this->entries, static fn(array $entry): bool =>
      ($group === null || $entry['group'] === $group) && ($showIdentity || $entry['label'] !== null || $entry['context'])));
    $figures = [];
    foreach ($entries as $entry) {
      if ($entry['label'] !== null) { array_push($figures, $entry['current'], $entry['result']); }
    }
    $figure = MenuRowColumn::fit($figures);
    $valueWidth = 2 * $figure->cells + 3;
    $lines = [];
    foreach ($entries as $entry) {
      if ($entry['label'] === null) {
        foreach (TextViewport::wrap($entry['text'], $columns) as [$offset, $text]) {
          $lines[] = ['offset' => $entry['offset'] + $offset, 'cells' => [['x' => 0, 'text' => $text, 'color' => $entry['color']]]];
        }
        continue;
      }
      $labelColumns = $columns - $valueWidth - 2;
      if ($labelColumns < 1) {
        // Narrow Terminal/theme viewports retain every figure through the shared text wrapper.
        $prefix = $entry['label'] . ' ' . $entry['current'] . ' → ';
        foreach (TextViewport::wrap($entry['text'], $columns) as [$offset, $text]) {
          $neutral = substr($text, 0, max(0, strlen($prefix) - $offset));
          $result = substr($text, strlen($neutral));
          $cells = [];
          if ($neutral !== '') { $cells[] = ['x' => 0, 'text' => $neutral, 'color' => 'text']; }
          if ($result !== '') { $cells[] = ['x' => TextViewport::measure($neutral), 'text' => $result, 'color' => $entry['color']]; }
          $lines[] = ['offset' => $entry['offset'] + $offset, 'cells' => $cells];
        }
        continue;
      }
      foreach (TextViewport::wrap($entry['label'], $labelColumns) as $index => [$offset, $label]) {
        $cells = [['x' => 0, 'text' => $label, 'color' => 'text']];
        if ($index === 0) {
          $x = $columns - $valueWidth;
          $cells[] = ['x' => $x, 'text' => $figure->formatCell($entry['current']), 'color' => 'text'];
          $cells[] = ['x' => $x + $figure->cells + 1, 'text' => '→', 'color' => 'text'];
          $cells[] = ['x' => $x + $figure->cells + 3, 'text' => $figure->formatCell($entry['result']), 'color' => $entry['color']];
        }
        $lines[] = ['offset' => $entry['offset'] + $offset, 'cells' => $cells];
      }
    }
    return $lines;
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
