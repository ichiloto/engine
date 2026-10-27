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

  public function setItem(?InventoryItem $item, Party $party): void
  {
    $this->comparison = $item instanceof Equipment ? ShopEquipmentComparison::create($party, $item) : null;
    $this->comparisonText->setText($this->comparison?->source ?? '');
    $this->lastComparisonPage = null;
  }

  /** @return array{page: TextPage, lines: array}|null */
  public function getComparisonPage(int $columns, int $rows): ?array
  {
    if ($this->comparison === null || $this->comparison->source === '') { return null; }
    $lines = $this->comparison->getLines(max(1, $columns));
    $wrapped = array_map(static fn(array $line): array => [$line['offset'], ShopEquipmentComparison::getPlainLine($line)], $lines);
    $page = $this->comparisonText->createLayoutPage($wrapped, max(1, $columns), max(1, $rows));
    $this->lastComparisonPage = $page;
    return ['page' => $page, 'lines' => array_slice($lines, $page->first, count($page->lines))];
  }

  /** The existing semantic Info action reads the next comparison page, cycling at the end. */
  public function advanceComparison(): bool
  {
    $page = $this->lastComparisonPage;
    if ($page === null || $page->total <= count($page->lines)) { return false; }
    if ($page->first + count($page->lines) >= $page->total) { $this->comparisonText->reset(); return true; }
    $target = $page->first + count($page->lines);
    do {
      $this->comparisonText->scroll(1, $page);
      $next = $this->getComparisonPage($page->columns, $page->rows)['page'];
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

    $comparison = $this->getComparisonPage(max(1, $this->getContentWidth() - 1), max(1, $this->height - 5));
    if ($comparison !== null) {
      $content[] = '';
      foreach ($comparison['lines'] as $line) {
        $text = '';
        foreach ($line['cells'] as $cell) {
          $padding = str_repeat(' ', max(0, $cell['x'] - TextViewport::measure(TerminalText::stripAnsi($text))));
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
    $this->setContent(array_fill(0, $this->height - 2, ''));
    $this->render();
  }
}
