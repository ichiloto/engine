<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use RuntimeException;

/** Shared Abilities/Magic layout. Learning, sorting, casting and targeting stay with the owner. */
final class SkillMenuPresentation
{
  public static function compose(SkillMenuContent $content, MenuPresentationCatalog $theme, float $time = 0,
    int $width = 1350, int $height = 720): PresentationCanvas
  {
    $m = $theme->metrics;
    $p = $m->panelPadding;
    $gap = $m->sectionGap;
    $host = new CanvasRectangle(($width - min(1100, $width - 20)) / 2, ($height - min(700, $height - 20)) / 2,
      min(1100, $width - 20), min(700, $height - 20));
    $innerWidth = $host->width - 2 * $p;
    $actor = $content->character;
    $portrait = isset($theme->portraits[$actor->actorId]) ? $m->portraitSize : 0;
    $identityWidth = floor($innerWidth * 0.30);
    $resourcesWidth = floor($innerWidth * 0.27);
    $countsWidth = $innerWidth - $identityWidth - $resourcesWidth - 2 * $gap;
    $nameWidth = $identityWidth - ($portrait > 0 ? $portrait + $gap : 0);
    $identity = $content->title . "\n" . $actor->name . "\nRole: " . $actor->role->name;
    $nameHeight = self::textHeight($identity, $nameWidth, $theme);
    $stats = $actor->effectiveStats;
    $resources = ['Lv' => (string)$actor->level,
      'HP' => number_format($stats->currentHp) . ' / ' . number_format($stats->totalHp),
      'MP' => number_format($stats->currentMp) . ' / ' . number_format($stats->totalMp)];
    $resourceHeight = self::fieldHeight($resources, $resourcesWidth, $theme);
    $countHeight = self::fieldHeight($content->summary, $countsWidth, $theme);
    $summaryHeight = max(140, 2 * $p + max($portrait, $nameHeight, $resourceHeight, $countHeight));
    $tabPadding = min($p, $gap);
    $tabHeight = $m->rowHeight + 2 * $tabPadding;
    $hints = [ActionHints::resolve('confirm', $content->confirmLabel), ActionHints::resolve('cancel', $content->targeting ? 'Back' : 'Cancel')];
    if (!$content->targeting) {
      $hints[] = ActionHints::resolve('character_next', 'Next', KeyCode::TAB);
      $hints[] = ActionHints::resolve('character_previous', 'Prev', KeyCode::SHIFT_TAB);
    }
    $hintHeight = MenuActionHints::height($hints, $theme, $innerWidth);
    $descriptionHeight = self::textHeight($content->description, $innerWidth, $theme);
    $statusHeight = self::textHeight($content->status ?? '', $innerWidth, $theme);
    $infoHeight = max(80, 2 * $p + $descriptionHeight + $statusHeight + $hintHeight
      + ($statusHeight > 0 ? $gap : 0) + ($hintHeight > 0 ? $gap : 0));
    $bodyHeight = $host->height - $summaryHeight - $tabHeight - $infoHeight;
    if ($bodyHeight < 2 * $p + $m->cellHeight + $gap + $m->rowHeight + $m->cellHeight) {
      throw new RuntimeException('Skill menu summary/description leaves no complete row in its finite viewport; terminal presentation retained.');
    }
    $view = new MenuCanvas($theme, $width, $height, $time);
    $summary = new CanvasRectangle($host->x, $host->y, $host->width, $summaryHeight);
    self::panel($view, 'skill-summary', $summary);
    $x = $host->x + $p;
    $y = $host->y + $p;
    if ($portrait > 0) { $view->portrait($actor->actorId, new CanvasRectangle($x, $y, $portrait, $portrait), 'skill-portrait'); }
    $view->prose('skill-identity', $identity, new CanvasRectangle($x + ($portrait > 0 ? $portrait + $gap : 0), $y, $nameWidth, $nameHeight));
    self::fields($view, 'skill-resource', $resources, new CanvasRectangle($x + $identityWidth + $gap, $y, $resourcesWidth, $resourceHeight));
    self::fields($view, 'skill-summary-field', $content->summary,
      new CanvasRectangle($x + $identityWidth + $resourcesWidth + 2 * $gap, $y, $countsWidth, $countHeight));

    $tabs = new CanvasRectangle($host->x, $host->y + $summaryHeight, $host->width, $tabHeight);
    self::panel($view, 'skill-tabs', $tabs, 'quiet');
    $tabWidth = ($innerWidth - (count($content->tabs) - 1) * $gap) / max(1, count($content->tabs));
    foreach ($content->tabs as $index => $label) {
      $bounds = new CanvasRectangle($x + $index * ($tabWidth + $gap), $tabs->y + $tabPadding, $tabWidth, $m->rowHeight);
      $view->rows('skill-tab', [new MenuRow((string)$index, $label, kind: MenuRowKind::BUTTON, selected: $index === $content->tabIndex)],
        new MenuRowLayout($bounds, rowHeight: $m->rowHeight, cellWidth: $m->cellWidth, cellHeight: $m->cellHeight, wrapText: true));
    }
    $detailWidth = $host->width * 380 / 1100;
    $details = new CanvasRectangle($host->x, $tabs->y + $tabHeight, $detailWidth, $bodyHeight);
    $list = new CanvasRectangle($details->x + $details->width, $details->y, $host->width - $detailWidth, $bodyHeight);
    self::panel($view, 'skill-details', $details);
    self::panel($view, 'skill-list', $list);
    $detailY = $details->y + $p;
    $detailInner = $details->width - 2 * $p;
    $headingHeight = self::textHeight($content->detailTitle, $detailInner, $theme);
    $fieldsHeight = self::fieldHeight($content->fields, $detailInner, $theme);
    $detailTextHeight = self::textHeight($content->detailText, $detailInner, $theme);
    $detailHeight = $headingHeight + $fieldsHeight + $detailTextHeight
      + ($fieldsHeight > 0 ? $gap : 0) + ($detailTextHeight > 0 ? $gap : 0);
    if ($detailHeight > $details->height - 2 * $p) {
      throw new RuntimeException('Skill menu complete requirements/details exceed their finite viewport; terminal presentation retained.');
    }
    $view->prose('skill-detail-title', $content->detailTitle, new CanvasRectangle($details->x + $p, $detailY, $detailInner, $headingHeight), 'accent');
    $detailY += $headingHeight;
    if ($fieldsHeight > 0) {
      $detailY += $gap;
      self::fields($view, 'skill-detail-field', $content->fields, new CanvasRectangle($details->x + $p, $detailY, $detailInner, $fieldsHeight));
      $detailY += $fieldsHeight;
    }
    if ($detailTextHeight > 0) {
      $view->prose('skill-requirements', $content->detailText,
        new CanvasRectangle($details->x + $p, $detailY + $gap, $detailInner, $detailTextHeight));
    }
    $listTitleHeight = self::textHeight($content->listTitle, $list->width - 2 * $p, $theme);
    $view->prose('skill-list-title', $content->listTitle,
      new CanvasRectangle($list->x + $p, $list->y + $p, $list->width - 2 * $p, $listTitleHeight), 'accent');
    $listBounds = new CanvasRectangle($list->x + $p, $list->y + $p + $listTitleHeight + $gap,
      $list->width - 2 * $p, $list->height - 2 * $p - $listTitleHeight - $gap);
    if ($content->rows === []) { $view->prose('skill-empty', $content->emptyText, $listBounds, 'disabled'); }
    else {
      $sizes = [];
      foreach ($content->rows as $row) {
        foreach ($row->values as $index => $value) { $sizes[$index] = max($sizes[$index] ?? 1, mb_strlen($value->text)); }
      }
      $available = (int)floor(($listBounds->width - 2 * $theme->rows->metrics->padding) / $m->cellWidth);
      $cap = max(1, (int)floor($available * 0.60 / max(1, count($sizes))));
      $columns = array_map(fn($size) => new MenuRowColumn(min($size, $cap)), $sizes);
      $view->rows('skill-entry', $content->rows, new MenuRowLayout($listBounds, $columns,
        $m->rowHeight, $m->cellWidth, $m->cellHeight, true), $content->index);
    }
    $info = new CanvasRectangle($host->x, $host->y + $host->height - $infoHeight, $host->width, $infoHeight);
    self::panel($view, 'skill-info', $info, 'quiet');
    $infoY = $info->y + $p;
    if ($descriptionHeight > 0) {
      $view->prose('skill-description', $content->description, new CanvasRectangle($x, $infoY, $innerWidth, $descriptionHeight));
      $infoY += $descriptionHeight;
    }
    if ($statusHeight > 0) {
      $view->prose('skill-status', $content->status, new CanvasRectangle($x, $infoY + $gap, $innerWidth, $statusHeight), 'accent');
      $infoY += $gap + $statusHeight;
    }
    if ($hintHeight > 0) { $view->hints('skill-hints', $hints, new CanvasRectangle($x, $infoY + $gap, $innerWidth, $hintHeight)); }
    return $view->finish();
  }

  private static function textHeight(string $text, float $width, MenuPresentationCatalog $theme): int
  {
    return $text === '' ? 0 : count(MenuCanvas::wrap($text, (int)floor($width / $theme->metrics->cellWidth))) * $theme->metrics->cellHeight;
  }

  private static function panel(MenuCanvas $view, string $id, CanvasRectangle $box, string $role = 'panel'): void
  {
    $view->backing($id . '-backing', $box);
    $view->frame($id, $box, $role);
  }

  private static function fieldLayout(array $fields, CanvasRectangle $bounds, MenuPresentationCatalog $theme): MenuRowLayout
  {
    $m = $theme->metrics;
    $metrics = $theme->rows->metrics;
    $label = max(1, ...array_map('mb_strlen', array_keys($fields)));
    $values = (int)floor(($bounds->width - 2 * $metrics->padding) / $m->cellWidth) - $label - $metrics->gapCells;
    if ($values < 1) { throw new RuntimeException('Skill menu fields require a wider finite viewport.'); }
    return new MenuRowLayout($bounds, [new MenuRowColumn($values)], $m->cellHeight + (int)ceil(max(1, $metrics->separatorWidth)), $m->cellWidth, $m->cellHeight, true);
  }

  private static function fieldRows(array $fields): array
  {
    $rows = [];
    foreach ($fields as $label => $value) { $rows[] = new MenuRow((string)count($rows), $label, [new MenuRowValue($value)]); }
    return $rows;
  }

  private static function fieldHeight(array $fields, float $width, MenuPresentationCatalog $theme): int
  {
    if ($fields === []) { return 0; }
    $layout = self::fieldLayout($fields, new CanvasRectangle(0, 0, $width, 1), $theme);
    return array_sum(array_map(fn($row) => $layout->heightFor($row, $theme->rows->metrics, false), self::fieldRows($fields)));
  }

  private static function fields(MenuCanvas $view, string $id, array $fields, CanvasRectangle $bounds): void
  {
    $view->rows($id, self::fieldRows($fields), self::fieldLayout($fields, $bounds, $view->theme));
  }
}
