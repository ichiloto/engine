<?php

namespace Ichiloto\Engine\Core\Menu\ShopMenu\Windows;

use Ichiloto\Engine\UI\Text\TerminalMenuRow;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Presentation\ShopEquipmentComparison;
use Ichiloto\Engine\UI\Presentation\ShopComparisonMark;
use Ichiloto\Engine\UI\Presentation\CharacterMenuRows;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;
use Ichiloto\Engine\UI\Text\TextPage;
use Ichiloto\Engine\UI\Text\TextViewport;

use Ichiloto\Engine\Core\Menu\ShopMenu\ShopMenu;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * Class ShopItemDetailPanel
 *
 * @package Ichiloto\Engine\Core\Menu\ShopMenu\Windows
 */
class ShopItemDetailPanel extends Window implements ObserverInterface
{
  /**
   * @var int $possession Displays the number of items of the selected item type the player has.
   */
  public int $possession = 0;
  private ?ShopEquipmentComparison $comparison = null;
  private TextViewport $comparisonText;
  private ?TextPage $lastComparisonPage = null;
  private int $comparisonGroup = 0;
  private bool $lastComparisonIdentity = true;
  public private(set) bool $comparisonFocused = false;
  public bool $comparisonNavigationEnabled = false;

  public function setItem(?InventoryItem $item, Party $party): void
  {
    $this->comparison = $item instanceof Equipment ? ShopEquipmentComparison::create($party, $item) : null;
    $this->comparisonText->setText($this->comparison?->source ?? '');
    $this->lastComparisonPage = null;
    $this->comparisonGroup = 0;
    $this->comparisonFocused = false;
  }

  /** @return list<array{id: string, name: string, affected: bool, mark: ?ShopComparisonMark}> */
  public function getComparisonProfiles(): array { return $this->comparison?->profiles ?? []; }

  public function getComparisonStatLabel(): ?string
  {
    $stat = $this->comparison?->primaryStat;
    return $stat === null ? null : CharacterMenuRows::getStatLabel($stat->statsProperty());
  }

  public function getCurrentComparisonActorId(): ?string
  {
    return $this->comparison?->getGroupActorId($this->comparisonGroup);
  }

  public function canSelectComparisonActor(): bool
  {
    return $this->comparisonNavigationEnabled && count($this->comparison?->getActorGroups() ?? []) > 1;
  }

  public function focusComparison(bool $focused): void
  {
    $this->comparisonFocused = $focused && $this->canSelectComparisonActor();
  }

  /** Moves between party members, leaving slot and page inspection to Info. */
  public function moveComparisonActor(int $direction): void
  {
    $groups = $this->comparison?->getActorGroups() ?? [];
    if (count($groups) < 2 || $direction === 0) { return; }
    $next = $this->getComparisonActorIndex($groups) + ($direction <=> 0);
    if ($next < 0) {
      $this->focusComparison(false);
      return;
    }
    if (!isset($groups[$next])) { return; }

    $this->comparisonGroup = $groups[$next];
    $this->comparisonText->reset();
    $this->lastComparisonPage = null;
  }

  /** @param list<int> $groups */
  private function getComparisonActorIndex(array $groups): int
  {
    $actors = array_map($this->comparison->getGroupActorId(...), $groups);
    $index = array_search($this->getCurrentComparisonActorId(), $actors, true);
    return $index === false ? 0 : $index;
  }

  /** @return list<\Ichiloto\Engine\IO\ActionHint> */
  public function getComparisonNavigationHints(): array
  {
    if (!$this->comparisonFocused) {
      return $this->canSelectComparisonActor() ? [ActionHints::resolve('right', 'Compare')] : [];
    }

    $groups = $this->comparison->getActorGroups();
    $index = $this->getComparisonActorIndex($groups);
    return match ($index) {
      0 => [ActionHints::resolve('left', 'Items'), ActionHints::resolve('right', 'Character')],
      count($groups) - 1 => [ActionHints::resolve('left', 'Character')],
      default => [ActionHints::resolvePair('left', 'right', 'Character')],
    };
  }

  /** @return array{page: TextPage, lines: array}|null */
  public function getComparisonPage(int $columns, int $rows, bool $showIdentity = true): ?array
  {
    if ($this->comparison === null || $this->comparison->source === '') { return null; }
    $this->lastComparisonIdentity = $showIdentity;
    $lines = $this->comparison->getLines(max(1, $columns), $this->comparisonGroup, $showIdentity);
    $wrapped = array_map(static fn(array $line): array => [$line['offset'], ShopEquipmentComparison::getPlainLine($line)], $lines);
    $page = $this->comparisonText->createLayoutPage($wrapped, max(1, $columns), max(1, $rows));
    $this->lastComparisonPage = $page;
    return ['page' => $page, 'lines' => array_slice($lines, $page->first, count($page->lines))];
  }

  /** The existing semantic Info action reads the next comparison page, cycling at the end. */
  public function advanceComparison(): bool
  {
    $page = $this->lastComparisonPage;
    if ($page === null) { return false; }
    if ($page->first + count($page->lines) >= $page->total) {
      $count = $this->comparison?->getGroupCount() ?? 0;
      if ($count < 2 && $page->total <= count($page->lines)) { return false; }
      $this->comparisonGroup = ($this->comparisonGroup + 1) % max(1, $count);
      $this->comparisonText->reset();
      return true;
    }
    $target = $page->first + count($page->lines);
    do {
      $this->comparisonText->scroll(1, $page);
      $next = $this->getComparisonPage($page->columns, $page->rows, $this->lastComparisonIdentity)['page'];
      if ($next->first <= $page->first) { break; }
      $page = $next;
    } while ($page->first < $target);
    return true;
  }

  /**
   * ShopItemDetailPanel constructor.
   *
   * @param ShopMenu $shopMenu The shop menu.
   * @param Rect $area The area of the window.
   * @param BorderPackInterface $borderPack The border pack.
   */
  public function __construct(
    protected ShopMenu $shopMenu,
    Rect $area,
    BorderPackInterface $borderPack
  )
  {
    $this->comparisonText = new TextViewport();
    parent::__construct(
    '',
    '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack
    );
  }

  /**
   * @inheritDoc
   */
  public function onNotify(object $entity, EventInterface $event): void
  {
    // TODO: Implement onNotify() method.
  }

  /**
   * Updates the content of the window.
   *
   * @return void
   */
  public function updateContent(): void
  {
    $content = [
      TerminalMenuRow::composeField(get_engine_message('inventory.possession'), (string)$this->possession,
        $this->getContentWidth() - 1, ' ', 'right'),
    ];

    $navigation = $this->getComparisonNavigationHints();
    $columns = max(1, $this->getContentWidth() - 2);
    $summary = [];
    $summaryWidth = max(1, intdiv($columns - 1, 2));
    foreach ($this->getComparisonProfiles() as $profile) {
      if ($profile['mark'] === null) { continue; }
      $summary[] = TerminalText::padRight(
        TerminalText::truncateToWidth($profile['name'], max(1, $summaryWidth - 2)) . ' ' . $profile['mark']->value,
        $summaryWidth);
    }
    if ($summary !== []) {
      $content[] = '';
      if (($label = $this->getComparisonStatLabel()) !== null) { $content[] = ' ' . $label; }
      foreach (array_chunk($summary, 2) as $line) { $content[] = ' ' . rtrim(implode(' ', $line)); }
    }
    $rows = max(1, $this->height - 4 - count($content) - (int)($navigation !== []));
    $comparison = $this->getComparisonPage($columns, $rows);
    if ($comparison !== null) {
      $content[] = '';
      foreach ($comparison['lines'] as $line) {
        $text = ' ';
        foreach ($line['cells'] as $cell) {
          $padding = str_repeat(' ', max(0, $cell['x'] + 1 - TextViewport::measure(TerminalText::stripAnsi($text))));
          $color = match ($cell['color']) { 'increase' => Color::GREEN, 'decrease' => Color::RED, 'accent' => Color::YELLOW, 'muted' => Color::DARK_GRAY, default => Color::RESET };
          $text .= $padding . Color::apply($cell['text'], $color);
        }
        $content[] = $text;
      }
      $page = $comparison['page'];
      if ($page->total > count($page->lines)) {
        $hint = ActionHints::resolve('info', 'More stats');
        $content[] = $page->range() . ' · ' . ($hint->control?->label ?? 'Info') . ' ' . $hint->label;
      }
      if ($navigation !== []) { $content[] = TerminalControlText::formatHints($navigation); }
    }
    $content = array_pad($content, $this->height - 2, '');
    $this->setContent($content);
    $this->render();
  }

  /**
   * Clears the content of the window.
   *
   * @return void
   */
  public function clear(): void
  {
    $this->comparison = null;
    $this->comparisonText->setText('');
    $this->lastComparisonPage = null;
    $this->comparisonFocused = false;
    $this->setContent(array_fill(0, $this->height - 2, ''));
    $this->render();
  }
}
