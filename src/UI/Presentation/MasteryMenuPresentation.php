<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progression\Mastery\MasteryLayout;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Text\TextViewport;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use RuntimeException;

/**
 * Draws one character's Mastery Map: the project's regions laid out around
 * the character, each node as a card in its region, the links from the
 * nodes it builds on, and the focused node's details. Choosing what to
 * learn stays with the menu state; the Terminal list shows the same
 * projection and focus.
 */
final class MasteryMenuPresentation
{
  /** The map takes this share of the body; the details take the rest. */
  private const float MAP_WIDTH_SHARE = 0.64;
  /** A node card is this many cells wide at most. */
  private const int CARD_CELLS = 16;
  /** Link lines are this many pixels thick. */
  private const int LINK_THICKNESS = 2;

  public static function compose(MasteryMenuContent $content, MenuPresentationCatalog $theme, float $time = 0,
    int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): PresentationCanvas
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $host = MenuLayout::getBounds($width, $height);
    $innerWidth = $host->width - 2 * $p;
    $actor = $content->character;
    $view = new MenuCanvas($theme, $width, $height, $time);

    // Summary: portrait, name, level and SP together in one corner block, then how much is learned.
    $portrait = isset($theme->portraits[$actor->actorId]) ? $m->portraitSize : 0;
    $identity = $content->title . "\n" . $actor->name . "\n" . Vocabulary::getTerm('stats.level_short') . ' ' . $actor->level
      . "\n" . $content->getPointsText($time);
    $summaryText = implode("\n", array_map(static fn(string $label, string $value): string => "{$label}: {$value}",
      array_keys($content->summary), $content->summary));
    $identityWidth = (int)floor($innerWidth * 0.35);
    $textHeight = max(self::textHeight($identity, $identityWidth - ($portrait > 0 ? $portrait + $gap : 0), $theme),
      self::textHeight($summaryText, $innerWidth - $identityWidth - $gap, $theme));
    $summaryHeight = 2 * $p + max($portrait, $textHeight);
    $summary = new CanvasRectangle($host->x, $host->y, $host->width, $summaryHeight);
    $view->frame('mastery-summary', $summary);
    $x = $host->x + $p;
    if ($portrait > 0) { $view->portrait($actor->actorId, new CanvasRectangle($x, $host->y + $p, $portrait, $portrait), 'mastery-portrait'); }
    $view->prose('mastery-identity', $identity, new CanvasRectangle($x + ($portrait > 0 ? $portrait + $gap : 0), $host->y + $p,
      $identityWidth - ($portrait > 0 ? $portrait + $gap : 0), $textHeight));
    $view->prose('mastery-learned', $summaryText, new CanvasRectangle($x + $identityWidth + $gap, $host->y + $p,
      $innerWidth - $identityWidth - $gap, $textHeight));

    // Footer: description, status and hints.
    $hints = [ActionHints::resolve('confirm', 'Learn'), ActionHints::resolve('cancel', 'Back'),
      ActionHints::resolve('character_next', 'Next', KeyCode::TAB), ActionHints::resolve('character_previous', 'Prev', KeyCode::SHIFT_TAB)];
    $hintHeight = MenuActionHints::height($hints, $theme, $innerWidth);
    $infoHeight = MenuInfoPanel::getHeight($theme, $hints, $host->width);
    $bodyHeight = $host->height - $summaryHeight - $infoHeight;
    $mapWidth = (int)floor($host->width * self::MAP_WIDTH_SHARE);
    $mapFrame = new CanvasRectangle($host->x, $host->y + $summaryHeight, $mapWidth, $bodyHeight);
    $detailFrame = new CanvasRectangle($host->x + $mapWidth, $mapFrame->y, $host->width - $mapWidth, $bodyHeight);
    $view->frame('mastery-map', $mapFrame);
    $view->frame('mastery-details', $detailFrame);

    $map = new CanvasRectangle($mapFrame->x + $p, $mapFrame->y + $p, $mapFrame->width - 2 * $p, $mapFrame->height - 2 * $p);
    if ($content->nodes === []) {
      $view->prose('mastery-empty', $content->emptyText, $map, 'disabled');
    } else {
      self::renderMap($view, $content, $map);
    }

    // Details of the focused node: the same sheet, columns and words the Terminal panel shows.
    $detail = new CanvasRectangle($detailFrame->x + $p, $detailFrame->y + $p, $detailFrame->width - 2 * $p, $detailFrame->height - 2 * $p);
    if ($content->details === null) {
      $view->prose('mastery-detail-empty', $content->emptyText, $detail, 'disabled');
    } else {
      $style = $theme->createDetailStyle();
      $area = new MenuDetailViewport(max(1, (int)floor($detail->width / $m->cellWidth)), max(1, (int)floor($detail->height)), $m->cellHeight);
      $page = $content->details->createPage(new TextViewport(), $area, $style);
      if ($page->total > count($page->lines)) {
        throw new RuntimeException('Mastery details exceed their finite viewport; terminal presentation retained.');
      }
      $view->details('mastery-detail', $content->details->layout($area->columns, $style, $area), $page, $detail);
    }

    $info = new CanvasRectangle($host->x, $host->y + $host->height - $infoHeight, $host->width, $infoHeight);
    $view->frame('mastery-info', $info, 'quiet');
    $infoY = $info->y + $p;
    MenuInfoPanel::renderContent($view, 'mastery', new CanvasRectangle($x, $infoY, $innerWidth, 2 * $m->cellHeight),
      $content->description, $content->status, infoModel: $content->infoModel,
      rangeBounds: new CanvasRectangle($x, $info->y + $info->height - $p, $innerWidth, $p));
    if ($hintHeight > 0) {
      $view->hints('mastery-hints', $hints, new CanvasRectangle($x, $infoY + 2 * $m->cellHeight + $gap, $innerWidth, $hintHeight));
    }

    return $view->finish();
  }

  /** Lays out the regions, links and node cards inside the map area. */
  private static function renderMap(MenuCanvas $view, MasteryMenuContent $content, CanvasRectangle $map): void
  {
    $m = $view->theme->metrics;
    $gap = $m->sectionGap;
    ['columns' => $columns, 'rows' => $rows] = MasteryLayout::getGrid(count($content->regions));
    $cellWidth = $map->width / $columns;
    $cellHeight = $map->height / $rows;
    // Cards fit the columns a region's nodes may share.
    $cardWidth = (int)min($cellWidth / MasteryLayout::REGION_COLUMNS - $gap, self::CARD_CELLS * $m->cellWidth);
    $cardHeight = 2 * $m->cellHeight + 2 * max(2, (int)floor($gap / 2));
    $labelHeight = $m->cellHeight;
    if ($cardWidth < 6 * $m->cellWidth) {
      throw new RuntimeException('Mastery map regions are too narrow for their nodes; terminal presentation retained.');
    }

    foreach ($content->regions as $index => $name) {
      $column = $index % $columns;
      $row = intdiv($index, $columns);
      $cell = new CanvasRectangle($map->x + $column * $cellWidth, $map->y + $row * $cellHeight, $cellWidth, $cellHeight);
      $active = $index === $content->regionIndex;
      // A region names itself at the top of its share of the map, toward the outer edge.
      $alignment = $column < $columns / 2 ? HorizontalAlignment::LEFT : HorizontalAlignment::RIGHT;
      $labelY = $cell->y;
      $view->prose("mastery-region-{$index}", $name, new CanvasRectangle($cell->x + $gap, $labelY, $cell->width - 2 * $gap, $labelHeight),
        $active ? 'accent' : 'edge', $alignment);
    }

    // Each node's card, centred on its place on the map (MasteryLayout): authored, or in its region's column.
    $cards = [];
    foreach ($content->nodes as $node) {
      $place = $node->position ?? ['x' => 0.5, 'y' => 0.5];
      $card = new CanvasRectangle(
        max($map->x, min($map->x + $map->width - $cardWidth, $map->x + $place['x'] * $map->width - $cardWidth / 2)),
        max($map->y + $labelHeight, min($map->y + $map->height - $cardHeight, $map->y + $place['y'] * $map->height - $cardHeight / 2)),
        $cardWidth, $cardHeight);
      foreach ($cards as $other) {
        if ($card->x < $other->x + $other->width && $other->x < $card->x + $card->width
          && $card->y < $other->y + $other->height && $other->y < $card->y + $card->height) {
          throw new RuntimeException('Mastery map nodes overlap at this size; terminal presentation retained.');
        }
      }
      $cards[$node->id] = $card;
    }

    // Links under the cards: across from the node built on, then down or up to the node that needs it.
    $states = array_column(array_map(static fn(MasteryMenuNode $node): array => [$node->id, $node->state], $content->nodes), 1, 0);
    foreach ($content->nodes as $node) {
      foreach ($node->requires as $required) {
        if (! isset($cards[$required])) { continue; }
        $from = $cards[$required];
        $to = $cards[$node->id];
        $color = ($states[$required] ?? null) === MasteryNodeState::LEARNED ? 'accent' : 'edge';
        $ax = $from->x + $from->width / 2;
        $ay = $from->y + $from->height / 2;
        $bx = $to->x + $to->width / 2;
        $by = $to->y + $to->height / 2;
        $t = self::LINK_THICKNESS;
        $view->surface("mastery-link-{$required}-{$node->id}-across",
          new CanvasRectangle(min($ax, $bx), $ay - $t / 2, max($t, abs($bx - $ax)), $t), $color, 14);
        $view->surface("mastery-link-{$required}-{$node->id}-down",
          new CanvasRectangle($bx - $t / 2, min($ay, $by), $t, max($t, abs($by - $ay))), $color, 14);
      }
    }

    foreach ($content->nodes as $node) {
      $card = $cards[$node->id];
      $focused = $node->id === $content->focusedNode;
      $view->surface("mastery-node-{$node->id}", $card, 'panel', 20);
      if ($focused) {
        $view->renderOutline("mastery-node-{$node->id}-focus", $card, 'focus', 2, 24);
      }
      $cells = max(1, (int)floor(($card->width - 2 * $gap) / $m->cellWidth));
      $text = new CanvasRectangle($card->x + $gap, $card->y + max(2, (int)floor($gap / 2)), $card->width - 2 * $gap, 2 * $m->cellHeight);
      $view->prose("mastery-node-{$node->id}-label",
        mb_strimwidth($node->label, 0, $cells, '…') . "\n" . mb_strimwidth($node->value, 0, $cells, '…'), $text,
        self::getNodeColor($node));
    }
  }

  /** Learned reads bright, what can be learned now plain, what costs more than the character holds dim, and locked grey. */
  private static function getNodeColor(MasteryMenuNode $node): string
  {
    return match ($node->state) {
      MasteryNodeState::LEARNED => 'increase',
      MasteryNodeState::OPEN => $node->affordable ? 'text' : 'muted',
      MasteryNodeState::LOCKED => 'disabled',
    };
  }

  private static function textHeight(string $text, float $width, MenuPresentationCatalog $theme): int
  {
    return $text === '' ? 0 : count(MenuCanvas::wrap($text, (int)floor($width / $theme->metrics->cellWidth))) * $theme->metrics->cellHeight;
  }
}
