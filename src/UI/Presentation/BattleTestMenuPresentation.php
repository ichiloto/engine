<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Arena\BattleTestMenuList;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

/**
 * The battle test as a graphical renderer shows it, in the game's own menu
 * theme: the list being worked in (troops, a member's setup, the test troop
 * composer) beside the battle's stage, which is the battle's own resting
 * picture of the chosen troop on the chosen arena with the party standing
 * on it, scaled into its frame; the party beneath. The member chosen in the
 * composer is outlined on the stage, and while it is moved the outline
 * follows it. Draws only: lists, focus and every choice belong to the
 * arena's setup and composer.
 */
final class BattleTestMenuPresentation
{
  private const int MARGIN = 10;
  private const int GAP = 10;
  private const int LIST_WIDTH = 420;
  private const int PARTY_ROWS = 4;

  /**
   * @param list<array{0: string, 1: array<string, string>}> $party Each member's name and its figures by label.
   * @param ?int $partySelected The member chosen while the party is being set up.
   * @param ?string $arena The arena fights take place in, where arenas are offered.
   * @param ?int $outlined The test troop member to outline on the stage.
   */
  public static function compose(MenuPresentationCatalog $theme, BattleTestMenuList $list, bool $listFocused,
    array $party, ?int $partySelected, ?string $arena, ?BattleFormationLayout $stage, ?int $outlined, bool $placing,
    ?string $stageIssue = null, float $time = 0, int $width = PresentationCanvas::DEFAULT_WIDTH,
    int $height = PresentationCanvas::DEFAULT_HEIGHT): PresentationCanvas
  {
    $view = new MenuCanvas($theme, $width, $height, $time);
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $listBox = new CanvasRectangle(self::MARGIN, self::MARGIN, self::LIST_WIDTH, $height - 2 * self::MARGIN);
    $right = self::MARGIN + self::LIST_WIDTH + self::GAP;
    $stageWidth = $width - $right - self::MARGIN;
    $stageHeight = $stage === null ? (int) floor($stageWidth * $height / $width) : $stageWidth * $stage->layout->height / $stage->layout->width;
    $stageBox = new CanvasRectangle($right, self::MARGIN, $stageWidth, $stageHeight);
    $partyBox = new CanvasRectangle($right, $stageBox->y + $stageBox->height + self::GAP, $stageWidth,
      $height - self::MARGIN - ($stageBox->y + $stageBox->height + self::GAP));

    self::renderList($view, $theme, $list, $listFocused, $arena, $listBox);
    self::renderStage($view, $theme, $stage, $outlined, $placing, $stageIssue, $stageBox);
    self::renderParty($view, $theme, $party, $partySelected, $partyBox);

    return $view->finish();
  }

  private static function renderList(MenuCanvas $view, MenuPresentationCatalog $theme, BattleTestMenuList $list, bool $focused,
    ?string $arena, CanvasRectangle $box): void
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $view->frame('battle-test-list', $box);
    $inner = new CanvasRectangle($box->x + $p, $box->y + $p, $box->width - 2 * $p, $box->height - 2 * $p);
    $y = $inner->y + $view->prose('battle-test-title', 'Battle Test', new CanvasRectangle($inner->x, $inner->y, $inner->width, $m->cellHeight), 'disabled');
    $y += $view->prose('battle-test-list-title', $list->title, new CanvasRectangle($inner->x, $y, $inner->width, $m->cellHeight), 'accent');
    if ($arena !== null) {
      $y += $view->prose('battle-test-arena', sprintf('Arena  < %s >', $arena), new CanvasRectangle($inner->x, $y, $inner->width, $m->cellHeight));
    }
    $y += $m->sectionGap / 2;
    $hints = array_map(static fn(array $hint) => ActionHints::resolve($hint[0], $hint[1]), $list->hints);
    $hintHeight = $hints === [] ? 0 : MenuActionHints::height($hints, $theme, $inner->width);
    $noteHeight = $list->note === null ? 0 : count(MenuCanvas::wrap($list->note, max(1, (int) floor($inner->width / $m->cellWidth)))) * $m->cellHeight;
    $bottom = $inner->y + $inner->height - $hintHeight - ($hintHeight > 0 ? $m->sectionGap / 2 : 0);
    if ($list->note !== null) {
      $bottom -= $noteHeight + $m->sectionGap / 2;
      $view->prose('battle-test-note', $list->note, new CanvasRectangle($inner->x, $bottom + $m->sectionGap / 2, $inner->width, $noteHeight), 'accent');
    }
    $rows = [];
    foreach ($list->rows as $index => [$label, $value]) {
      $selected = $index === $list->selected;
      $rows[] = new MenuRow('battle-test-row-' . $index, $label === '' ? $value : $label,
        $label === '' || $value === '' ? [] : [new MenuRowValue($value)], selected: $selected, focused: $focused && $selected);
    }
    if ($rows !== []) {
      $rowHeight = (int) ceil(max($m->cellHeight + $theme->rows->metrics->separatorWidth, $theme->rows->metrics->cursorHeight,
        min($m->rowHeight, $m->cellHeight + $m->sectionGap / 3)));
      $view->rows('battle-test-rows', $rows, new MenuRowLayout(new CanvasRectangle($inner->x, $y, $inner->width, max($rowHeight, $bottom - $y)),
        self::getValueColumns($rows, $inner->width, $m->cellWidth), $rowHeight, $m->cellWidth, $m->cellHeight), $list->selected);
    }
    if ($hints !== []) {
      $view->hints('battle-test-hints', $hints, new CanvasRectangle($inner->x, $inner->y + $inner->height - $hintHeight, $inner->width, $hintHeight));
    }
  }

  private static function renderStage(MenuCanvas $view, MenuPresentationCatalog $theme, ?BattleFormationLayout $stage, ?int $outlined,
    bool $placing, ?string $issue, CanvasRectangle $box): void
  {
    if ($stage === null) {
      $view->frame('battle-test-stage', $box, 'quiet');
      $m = $theme->metrics;
      $view->prose('battle-test-stage-issue', $issue ?? 'This project draws no graphical battle stage.',
        new CanvasRectangle($box->x + $m->panelPadding, $box->y + $box->height / 2 - $m->cellHeight,
          $box->width - 2 * $m->panelPadding, 2 * $m->cellHeight), 'disabled', HorizontalAlignment::CENTER);
      return;
    }
    $view->picture($box, $stage->composeImagesInto($box, 1));
    $view->renderOutline('battle-test-stage-edge', $box, 'edge', 1, 6);
    $member = $outlined === null ? null : ($stage->enemies[$outlined] ?? null);
    if ($member !== null) {
      $scale = $box->width / $stage->layout->width;
      $bounds = BattleFormationLayout::getRectangleInto($member->image?->destination ?? $member->bounds, $box, $scale);
      $thickness = $placing ? 3 : 2;
      if (2 * $thickness < min($bounds->width, $bounds->height)) {
        $view->renderOutline('battle-test-stage-member', self::getInside($bounds, $box), $placing ? 'accent' : 'focus', $thickness, 7);
      }
    }
  }

  /** @param list<array{0: string, 1: array<string, string>}> $party */
  private static function renderParty(MenuCanvas $view, MenuPresentationCatalog $theme, array $party, ?int $selected, CanvasRectangle $box): void
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $view->frame('battle-test-party', $box);
    $inner = new CanvasRectangle($box->x + $p, $box->y + $p, $box->width - 2 * $p, $box->height - 2 * $p);
    $y = $inner->y + $view->prose('battle-test-party-title', 'Party', new CanvasRectangle($inner->x, $inner->y, $inner->width, $m->cellHeight), 'accent');
    if ($party === []) {
      $view->prose('battle-test-party-empty', 'No party could be built from this project.', new CanvasRectangle($inner->x, $y, $inner->width, $m->cellHeight), 'disabled');
      return;
    }
    $rows = [];
    foreach ($party as $index => [$name, $stats]) {
      $rows[] = new MenuRow('battle-test-member-' . $index, $name,
        [new MenuRowValue(implode('   ', array_map(static fn(string $label, string $figure): string => "{$label} {$figure}", array_keys($stats), $stats)))],
        selected: $index === $selected, focused: $index === $selected);
    }
    $rowHeight = (int) max($m->cellHeight, floor(($inner->y + $inner->height - $y) / max(self::PARTY_ROWS, count($rows))));
    $view->rows('battle-test-members', $rows, new MenuRowLayout(new CanvasRectangle($inner->x, $y, $inner->width, $inner->y + $inner->height - $y),
      self::getValueColumns($rows, $inner->width, $m->cellWidth), min($rowHeight, (int) max($m->cellHeight, $m->rowHeight)), $m->cellWidth,
      $m->cellHeight), $selected);
  }

  /**
   * One right-aligned value column as wide as the widest value, never more
   * than half the row, so labels keep the rest.
   *
   * @param list<MenuRow> $rows
   * @return list<MenuRowColumn>
   */
  private static function getValueColumns(array $rows, float $width, int $cellWidth): array
  {
    $widest = max([0, ...array_map(static fn(MenuRow $row): int => max([0, ...array_map(static fn(MenuRowValue $value): int =>
      mb_strlen($value->text), $row->values)]), $rows)]);
    return $widest === 0 ? [] : [new MenuRowColumn(max(1, min($widest, (int) floor($width / $cellWidth / 2))))];
  }

  /** A rectangle kept inside another, for an outline that never leaves its picture. */
  private static function getInside(CanvasRectangle $rectangle, CanvasRectangle $frame): CanvasRectangle
  {
    $x = max($frame->x, min($rectangle->x, $frame->x + $frame->width - 1));
    $y = max($frame->y, min($rectangle->y, $frame->y + $frame->height - 1));
    return new CanvasRectangle($x, $y, max(1, min($rectangle->x + $rectangle->width, $frame->x + $frame->width) - $x),
      max(1, min($rectangle->y + $rectangle->height, $frame->y + $frame->height) - $y));
  }
}
