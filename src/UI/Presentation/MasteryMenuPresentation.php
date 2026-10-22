<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

/**
 * Draws one character's Mastery board: a corner block (title, portrait,
 * name, role, level and points), the disciplines as tabs with their counts,
 * a strip of the shown discipline's families, the board itself (or, zoomed
 * out, a summary of each family), the focused place's name, price and Learn
 * button, and a footer with what the focus does and the bindings.
 *
 * Nodes, lines, badges and tabs are live shapes in the theme's colours;
 * pictures come from the theme's icon roles and portraits, so another game
 * re-themes the screen without drawing code. Choosing and learning stay with
 * the menu state; the Terminal shows the same projection.
 */
final class MasteryMenuPresentation
{
  /** How far the board may scroll past its outer lanes, as a share of a lane. */
  private const float LANE_MARGIN = 0.4;
  private const int ICON_SIZE = 32;
  private const int BADGE_HEIGHT = 17;
  private const int BUTTON_WIDTH = 180;
  private const int BUTTON_HEIGHT = 40;
  private const int CARD_HEIGHT = 84;
  private const int CARD_GAP = 12;
  private const int CARD_PADDING = 20;

  public static function compose(MasteryMenuContent $content, MenuPresentationCatalog $theme, float $time = 0,
    int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): PresentationCanvas
  {
    $view = new MenuCanvas($theme, $width, $height, $time);
    $host = MenuLayout::getBounds($width, $height);
    $m = $theme->mastery;
    $left = $host->x + $theme->metrics->panelPadding;
    $right = $host->x + $host->width - $theme->metrics->panelPadding - 5;
    $column = $left + $m->portraitWidth + 35;
    $areas = [
      'suits' => new CanvasRectangle($column, $host->y + 25, $right - $column, 60),
      'families' => new CanvasRectangle($column, $host->y + 95, $right - $column, 32),
      'board' => new CanvasRectangle($column, $host->y + 140, $right - $column, 390),
      'selection' => new CanvasRectangle($column, $host->y + 550, $right - $column, 67),
      'footer' => new CanvasRectangle($left, $host->y + $host->height - 59, $right - $left, 50),
    ];
    $chrome = new MenuShapes($theme, new CanvasRectangle(0, 0, $width, $height));
    self::renderCorner($view, $chrome, $content, $theme, $time, $left, $host);
    self::renderDisciplines($view, $chrome, $content, $theme, $areas['suits']);
    self::renderFamilies($view, $chrome, $content, $theme, $areas['families']);
    $board = new MenuShapes($theme, $areas['board']);
    if ($content->overview) {
      self::renderOverview($view, $chrome, $content, $theme, $areas['board']);
    } elseif ($content->nodes === []) {
      $view->renderText('mastery-empty', $content->emptyText, $areas['board']->x + $areas['board']->width / 2,
        $areas['board']->y + $areas['board']->height / 2 - 9, $m->bodyCell, 'disabled', HorizontalAlignment::CENTER);
    } else {
      self::renderBoard($view, $board, $content, $theme, $time, $areas['board']);
    }
    self::renderSelection($view, $chrome, $content, $theme, $areas['selection']);
    self::renderFooter($view, $chrome, $content, $theme, $areas['footer']);
    if ($content->info !== null) {
      self::renderInfo($view, $content, $theme, new CanvasRectangle($column, $areas['families']->y + 15, $right - $column, 115));
    }
    $view->picture(new CanvasRectangle(0, 0, $width, $height), [], array_values(array_filter([
      $chrome->compose('mastery-chrome', 5), $board->compose('mastery-board', 12)])));

    return $view->finish();
  }

  /** Title, portrait, name, role, and level and points together in one corner block. */
  private static function renderCorner(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content, MenuPresentationCatalog $theme,
    float $time, float $left, CanvasRectangle $host): void
  {
    $m = $theme->mastery;
    $view->renderText('mastery-title', $content->title, $left, $host->y + 22, $m->titleCell, 'focus');
    $portrait = new CanvasRectangle($left, $host->y + 95, $m->portraitWidth, $m->portraitHeight);
    $view->portrait($content->character->actorId, $portrait, 'mastery-portrait');
    $rule = $portrait->y + $portrait->height;
    $shapes->fillRectangle(new CanvasRectangle($left, $rule, $m->portraitWidth, 1), 'edge', 0.8);
    $view->renderText('mastery-name', $content->character->name, $left, $rule + 23, $m->nameCell);
    $view->renderText('mastery-role', $content->role, $left, $rule + 66, $m->smallCell, 'muted');
    $valueRight = $left + $m->portraitWidth - 80;
    $view->renderTexts('mastery-level', [
      ['text' => $content->levelLabel, 'x' => $left, 'y' => $rule + 97, 'color' => 'muted'],
      ['text' => (string)$content->character->level, 'x' => $valueRight, 'y' => $rule + 97, 'color' => 'text', 'alignment' => HorizontalAlignment::RIGHT],
      ['text' => $content->pointsLabel, 'x' => $left, 'y' => $rule + 137, 'color' => 'muted'],
    ], new CanvasRectangle($left, $rule + 97, $m->portraitWidth, 3 * $m->bodyCell[1]), $m->bodyCell);
    $points = $content->getPointsAt($time);
    $moving = $points !== $content->points;
    $view->renderText('mastery-points', (string)$points, $valueRight, $rule + 124, $m->numberCell, $moving ? 'focus' : 'text',
      HorizontalAlignment::RIGHT);
  }

  /** The disciplines as tabs: picture, name and Learned/Total count, the shown one underlined in its accent. */
  private static function renderDisciplines(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content,
    MenuPresentationCatalog $theme, CanvasRectangle $area): void
  {
    $m = $theme->mastery;
    $count = count($content->regions);
    if ($count === 0) {
      return;
    }
    $gap = 6;
    $tabWidth = ($area->width - $gap * ($count - 1)) / $count;
    $names = $counts = [];
    foreach ($content->regions as $index => $tab) {
      $x = $area->x + $index * ($tabWidth + $gap);
      $active = $index === $content->regionIndex;
      $accent = self::getRegionColor($theme, $tab->id);
      $shapes->fillRectangle(new CanvasRectangle($x, $area->y, $tabWidth, $area->height), 'panel', $active ? 0.95 : 0.6);
      $shapes->fillRectangle(new CanvasRectangle($x, $area->y + $area->height - ($active ? 2 : 1), $tabWidth, $active ? 2 : 1),
        $active ? 'focus' : 'edge', $active ? 1.0 : 0.7);
      $countText = $tab->describeCount();
      $nameWidth = TerminalText::displayWidth($tab->name) * $m->tabCell[0];
      $countWidth = TerminalText::displayWidth($countText) * $m->smallCell[0];
      $icon = self::findIcon($theme, $tab->iconRoles) !== null ? 16 + 8 : 0;
      $start = $x + ($tabWidth - $icon - $nameWidth - 8 - $countWidth) / 2;
      $middle = $area->y + $area->height / 2;
      if ($icon > 0) {
        self::renderIcon($view, "mastery-suit-{$index}-icon", $theme, $tab->iconRoles, $start + 8, $middle, 16, $area, $active ? 1.0 : 0.75);
      }
      $names[] = ['text' => $tab->name, 'x' => $start + $icon, 'y' => $middle - $m->tabCell[1] / 2, 'color' => $active ? $accent : 'text'];
      $counts[] = ['text' => $countText, 'x' => $start + $icon + $nameWidth + 8, 'y' => $middle - $m->smallCell[1] / 2 + 1,
        'color' => $active ? $accent : 'muted'];
    }
    $view->renderTexts('mastery-suits', $names, $area, $m->tabCell);
    $view->renderTexts('mastery-suit-counts', $counts, $area, $m->smallCell);
  }

  /** The shown discipline's families as a strip of three, the focused one underlined, with arrows for the rest. */
  private static function renderFamilies(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content,
    MenuPresentationCatalog $theme, CanvasRectangle $area): void
  {
    $m = $theme->mastery;
    $families = $content->families;
    if ($families === []) {
      return;
    }
    $arrow = 28;
    $gap = 8;
    $slots = min(3, count($families));
    $first = max(0, min($content->familyIndex - 1, count($families) - $slots));
    $slotWidth = ($area->width - 2 * ($arrow + $gap) - ($slots - 1) * $gap) / $slots;
    $texts = [];
    foreach (['previous' => $area->x, 'next' => $area->x + $area->width - $arrow] as $direction => $x) {
      $shapes->fillRectangle(new CanvasRectangle($x, $area->y + $area->height - 1, $arrow, 1), 'edge', 0.6);
      if (! self::renderIcon($view, "mastery-family-{$direction}", $theme, ["navigation.{$direction}"], $x + $arrow / 2,
        $area->y + $area->height / 2, 14, $area, 0.85)) {
        $texts[] = ['text' => $direction === 'previous' ? '<' : '>', 'x' => $x + $arrow / 2, 'y' => $area->y + ($area->height - $m->tabCell[1]) / 2,
          'color' => 'muted', 'alignment' => HorizontalAlignment::CENTER];
      }
    }
    for ($slot = 0; $slot < $slots; $slot++) {
      $index = $first + $slot;
      $active = $index === $content->familyIndex;
      $x = $area->x + $arrow + $gap + $slot * ($slotWidth + $gap);
      if ($active) {
        $shapes->fillRectangle(new CanvasRectangle($x, $area->y, $slotWidth, $area->height), 'panel', 0.9);
      }
      $shapes->fillRectangle(new CanvasRectangle($x, $area->y + $area->height - ($active ? 2 : 1), $slotWidth, $active ? 2 : 1),
        $active ? 'focus' : 'edge', $active ? 1.0 : 0.6);
      $texts[] = ['text' => $families[$index]->name, 'x' => $x + $slotWidth / 2, 'y' => $area->y + ($area->height - $m->tabCell[1]) / 2,
        'color' => $active ? 'focus' : 'muted', 'alignment' => HorizontalAlignment::CENTER];
    }
    $view->renderTexts('mastery-families', $texts, $area, $m->tabCell);
  }

  /**
   * The board: families side by side, each a tree of straight lines down and across, every node a
   * disc with its picture, its rim badge and its name; learned lit in the discipline's accent,
   * reachable plain, locked dark with a padlock. The view follows the focus.
   */
  private static function renderBoard(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content, MenuPresentationCatalog $theme,
    float $time, CanvasRectangle $area): void
  {
    $m = $theme->mastery;
    $region = $content->findRegion()?->id ?? '';
    $accent = self::getRegionColor($theme, $region);
    $radius = $m->nodeDiameter / 2;
    $centreX = $area->x + $area->width / 2;
    $centreY = $area->y + $area->height / 2;
    [$lane, $stage] = $content->reducedMotion ? [$content->camera->toLane, $content->camera->toStage] : $content->camera->getCentreAt($time);
    $worldWidth = $content->lanes * $m->laneWidth;
    $margin = $m->laneWidth * self::LANE_MARGIN;
    $camera = $worldWidth + 2 * $margin <= $area->width ? $worldWidth / 2
      : min(max($lane * $m->laneWidth, $area->width / 2 - $margin), $worldWidth + $margin - $area->width / 2);
    $toX = static fn(float $lane): float => $centreX + $lane * $m->laneWidth - $camera;
    $toY = static fn(float $at): float => $centreY + ($at - $stage) * $m->stageSpacing;
    $shapes->fillGlow($area, $centreX, $centreY, $area->width * 0.6, $area->height * 0.7, 'panel', 0.9);
    $shapes->fillRectangle(new CanvasRectangle($area->x, $area->y, $area->width, 1), 'edge', 0.6);
    $shapes->fillRectangle(new CanvasRectangle($area->x, $area->y + $area->height - 1, $area->width, 1), 'edge', 0.6);
    $byId = [];
    foreach ($content->nodes as $node) {
      $byId[$node->id] = $node;
    }
    $dim = self::mix($theme->colors['edge'], $theme->colors['background'], 0.75);

    // The discipline's bar joins its families' tops; each top hangs from it.
    $tops = array_values(array_filter($content->nodes, static fn(MasteryMenuNode $node): bool => $node->parent === null));
    if ($tops !== []) {
      $bar = $toY(-0.5);
      $xs = array_map(static fn(MasteryMenuNode $node): float => $toX($node->lane), $tops);
      $shapes->strokeLine([[min($xs), $bar], [max($xs), $bar]], 1.5, $dim);
      foreach ($tops as $top) {
        $shapes->strokeLine([[$toX($top->lane), $bar], [$toX($top->lane), $toY($top->stage) - $radius]], 1.5, $top->isLit() ? $accent : $dim);
      }
    }
    // Lines down and across from each node to what hangs from it, lit once that is learned.
    foreach ($content->nodes as $node) {
      $parent = $node->parent === null ? null : $byId[$node->parent] ?? null;
      if ($parent === null) {
        continue;
      }
      $points = [[$toX($parent->lane), $toY($parent->stage) + $radius], [$toX($parent->lane), $toY($parent->stage + 0.5)],
        [$toX($node->lane), $toY($parent->stage + 0.5)], [$toX($node->lane), $toY($node->stage) - $radius]];
      $points = array_values(array_unique($points, SORT_REGULAR));
      $shapes->strokeLine($points, $node->isLit() ? 2.0 : 1.5, $node->isLit() ? $accent : $dim);
      if ($content->curriculum && $node->curriculum) {
        for ($segment = 0; $segment < count($points) - 1; $segment++) {
          $shapes->strokeDashedLine($points[$segment][0], $points[$segment][1], $points[$segment + 1][0], $points[$segment + 1][1], 1.5, 4, 4,
            'focus', 0.85);
        }
      }
    }
    $labels = $tops = $badges = [];
    foreach ($content->nodes as $node) {
      $x = $toX($node->lane);
      $y = $toY($node->stage);
      if ($x + $m->laneWidth / 2 < $area->x || $x - $m->laneWidth / 2 > $area->x + $area->width
        || $y + $radius + 40 < $area->y || $y - $radius - 20 > $area->y + $area->height) {
        continue;
      }
      self::renderNode($view, $shapes, $content, $theme, $time, $node, $x, $y, $radius, $accent, $dim, $area, $badges);
      // A name sits under the badge, or clear of the focus rings at a family's top.
      $labelY = $y + $radius + ($node->badge !== null ? 12 : ($node->parent === null ? 15 : 6));
      if ($node->parent === null) {
        $tops[] = ['text' => $node->label, 'x' => $x, 'y' => $labelY, 'color' => self::getLabelColor($node), 'alignment' => HorizontalAlignment::CENTER];
      } else {
        $labels[] = ['text' => $node->label, 'x' => $x, 'y' => $labelY, 'color' => self::getLabelColor($node), 'alignment' => HorizontalAlignment::CENTER];
      }
    }
    $view->renderTexts('mastery-node-names', $labels, $area, $m->bodyCell);
    $view->renderTexts('mastery-family-names', $tops, $area, $m->tabCell);
    $view->renderTexts('mastery-badges', $badges, $area, $m->smallCell, 31);
  }

  /**
   * One node: its disc and ring by state, Learn's fill while held, the focus rings, the curriculum's
   * dashed ring, its rim badge, its learned or locked mark beside the badge, and its picture.
   *
   * @param list<array{text: string, x: float, y: float, color: string, alignment?: HorizontalAlignment}> $badges
   */
  private static function renderNode(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content, MenuPresentationCatalog $theme,
    float $time, MasteryMenuNode $node, float $x, float $y, float $radius, PresentationColor $accent, PresentationColor $dim,
    CanvasRectangle $area, array &$badges): void
  {
    $m = $theme->mastery;
    $state = $node->state;
    $focused = $node->id === $content->focus;
    $flare = $content->flare !== null && $content->flare['node'] === $node->id && ! $content->reducedMotion
      ? ($time - $content->flare['at']) / 0.36 : null;
    if ($flare !== null && $flare >= 0 && $flare < 1) {
      $shapes->strokeCircle($x, $y, $radius + 6 + 18 * $flare, 3, $accent, 1 - $flare);
    }
    $learned = $state === MasteryNodeState::LEARNED;
    $shapes->fillCircle($x, $y, $radius, $learned ? self::mix($accent, $theme->colors['background'], 0.24) : 'background');
    $hold = $content->hold !== null && $content->hold->node === $node->id ? $content->hold->getProgressAt($time) : null;
    if ($hold !== null && ! $content->reducedMotion) {
      $shapes->fillCircleFrom($x, $y, $radius, $hold, $accent, 0.45);
    }
    match (true) {
      $learned => $shapes->strokeCircle($x, $y, $radius - 1, 2, $accent),
      $state === MasteryNodeState::OPEN || $node->isHeading() => $shapes->strokeCircle($x, $y, $radius - 0.75, 1.5,
        self::mix($theme->colors['text'], $theme->colors['background'], 0.55)),
      $state === MasteryNodeState::UNAVAILABLE => $shapes->strokeCircle($x, $y, $radius - 0.75, 1.5, $dim, 1.0, 24),
      default => $shapes->strokeCircle($x, $y, $radius - 0.5, 1, $dim),
    };
    if ($content->curriculum && $node->curriculum) {
      $shapes->strokeCircle($x, $y, $radius - 7, 1.2, 'focus', 0.85, 20);
    }
    if ($focused) {
      $shapes->strokeCircle($x, $y, $radius + 6, 2, 'focus', $hold === null ? 1.0 : 0.35);
      $shapes->strokeCircle($x, $y, $radius + 13, 2, 'focus', 0.22);
      if ($hold !== null && ! $content->reducedMotion) {
        $shapes->strokeArc($x, $y, $radius + 6, $hold, 4, 'focus');
      }
    }
    $badgeRight = $x + $radius * 0.45;
    if ($node->badge !== null) {
      $cells = TerminalText::displayWidth($node->badge);
      $badgeWidth = $cells * $m->smallCell[0] + 12;
      $badgeY = $y + $radius - 1;
      $shapes->fillPill($x, $badgeY, $badgeWidth, self::BADGE_HEIGHT, 'background');
      $shapes->strokePill($x, $badgeY, $badgeWidth, self::BADGE_HEIGHT, 1, $learned ? $accent : self::mix($theme->colors['text'],
        $theme->colors['background'], 0.5));
      $badges[] = ['text' => $node->badge, 'x' => $x, 'y' => $badgeY - $m->smallCell[1] / 2, 'color' => $learned ? 'text' : 'muted',
        'alignment' => HorizontalAlignment::CENTER];
      $badgeRight = $x + $badgeWidth / 2;
    }
    // The learned or locked mark sits just past the badge, so a wide badge never covers it.
    $markX = max($x + $radius * 0.62, $badgeRight + 10);
    $markY = $y + $radius - 1;
    if ($learned) {
      $shapes->fillCircle($markX, $markY, 8, 'background');
      $shapes->strokeLine([[$markX - 4, $markY], [$markX - 1, $markY + 3], [$markX + 4.5, $markY - 4]], 2, 'text');
    } elseif ($state === MasteryNodeState::LOCKED) {
      $shapes->fillCircle($markX, $markY, 8, 'background');
      $shapes->strokeArc($markX, $markY - 2, 3, 0.5, 1.4, 'muted');
      $shapes->strokeLine([[$markX - 3, $markY - 2], [$markX - 3, $markY - 1]], 1.4, 'muted');
      $shapes->strokeLine([[$markX + 3, $markY - 2], [$markX + 3, $markY - 1]], 1.4, 'muted');
      $shapes->fillRectangle(new CanvasRectangle(max(0, $markX - 4.5), max(0, $markY - 1), 9, 7), 'muted');
    }
    $opacity = match (true) {
      $learned, $node->isHeading() => 1.0,
      $state === MasteryNodeState::OPEN => 0.95,
      $state === MasteryNodeState::LOCKED => 0.45,
      default => 0.3,
    };
    self::renderIcon($view, "mastery-node-{$node->id}-icon", $theme, $node->iconRoles, $x, $y, self::ICON_SIZE, $area, $opacity);
  }

  /** Zoomed out: the discipline's name, then a framed summary of each family, nine to a page, the focused one lit. */
  private static function renderOverview(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content, MenuPresentationCatalog $theme,
    CanvasRectangle $area): void
  {
    $m = $theme->mastery;
    $region = $content->findRegion();
    $shapes->fillGlow($area, $area->x + $area->width / 2, $area->y + $area->height / 2, $area->width * 0.6, $area->height * 0.7, 'panel', 0.9);
    $shapes->fillRectangle(new CanvasRectangle($area->x, $area->y, $area->width, 1), 'edge', 0.6);
    $shapes->fillRectangle(new CanvasRectangle($area->x, $area->y + $area->height - 1, $area->width, 1), 'edge', 0.6);
    if ($region !== null) {
      $nameWidth = TerminalText::displayWidth($region->name) * $m->nameCell[0];
      $icon = self::findIcon($theme, $region->iconRoles) !== null ? 28 : 0;
      $start = $area->x + ($area->width - $nameWidth - $icon) / 2;
      if ($icon > 0) {
        self::renderIcon($view, 'mastery-overview-icon', $theme, $region->iconRoles, $start + 10, $area->y + 41, 20, $area, 1.0);
      }
      $view->renderText('mastery-overview-title', $region->name, $start + $icon, $area->y + 41 - $m->nameCell[1] / 2, $m->nameCell);
    }
    $columns = 3;
    $cardWidth = ($area->width - 2 * self::CARD_PADDING - ($columns - 1) * self::CARD_GAP) / $columns;
    $first = intdiv($content->familyIndex, 9) * 9;
    $names = $counts = [];
    foreach (array_slice($content->families, $first, 9) as $offset => $family) {
      $index = $first + $offset;
      $x = $area->x + self::CARD_PADDING + ($offset % $columns) * ($cardWidth + self::CARD_GAP);
      $y = $area->y + 78 + intdiv($offset, $columns) * (self::CARD_HEIGHT + self::CARD_GAP);
      $card = new CanvasRectangle($x, $y, $cardWidth, self::CARD_HEIGHT);
      $focused = $index === $content->familyIndex;
      $shapes->fillRectangle($card, 'panel', $focused ? 1.0 : 0.65);
      $shapes->strokeRectangle($card, 1, $focused ? 'focus' : 'edge', $focused ? 1.0 : 0.8);
      self::renderIcon($view, "mastery-card-{$offset}-icon", $theme, $family->iconRoles, $x + 70, $y + self::CARD_HEIGHT / 2, self::ICON_SIZE,
        $area, 1.0);
      $textX = $x + 70 + ($cardWidth - 70) / 2;
      $names[] = ['text' => $family->name, 'x' => $textX, 'y' => $y + 18, 'color' => 'text', 'alignment' => HorizontalAlignment::CENTER];
      $counts[] = ['text' => \Ichiloto\Engine\Localization\MessageCatalog::getTerm('mastery.learned_count', $family->learned, $family->total),
        'x' => $textX, 'y' => $y + 48, 'color' => 'muted', 'alignment' => HorizontalAlignment::CENTER];
    }
    $view->renderTexts('mastery-card-names', $names, $area, $m->tabCell);
    $view->renderTexts('mastery-card-counts', $counts, $area, $m->smallCell);
  }

  /** The focused place's name and status on the left; its price, shown once, and the centred Learn button on the right. */
  private static function renderSelection(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content, MenuPresentationCatalog $theme,
    CanvasRectangle $area): void
  {
    $m = $theme->mastery;
    $selection = $content->selection;
    $hasSubtitle = $selection->subtitle !== null && $selection->subtitle !== '';
    $view->renderText('mastery-selection-name', $selection->name, $area->x, $area->y + ($hasSubtitle ? 10 : 19), $m->nameCell);
    if ($hasSubtitle) {
      $view->renderText('mastery-selection-subtitle', $selection->subtitle, $area->x, $area->y + 45, $m->smallCell, 'muted');
    }
    $right = $area->x + $area->width;
    $middle = $area->y + 34;
    if ($selection->action !== null) {
      $button = new CanvasRectangle($right - self::BUTTON_WIDTH, $middle - self::BUTTON_HEIGHT / 2, self::BUTTON_WIDTH, self::BUTTON_HEIGHT);
      $shapes->fillRectangle($button, 'panel', $selection->enabled ? 0.9 : 0.5);
      $shapes->strokeRectangle($button, 1, $selection->enabled ? 'muted' : 'edge', $selection->enabled ? 0.9 : 0.7);
      // A button's label is centred in the button itself.
      $view->renderText('mastery-learn', $selection->action, $button->x + $button->width / 2, $middle - $m->tabCell[1] / 2, $m->tabCell,
        $selection->enabled ? 'text' : 'disabled', HorizontalAlignment::CENTER);
      $right = $button->x - 20;
    }
    if ($selection->price !== null) {
      $view->renderText('mastery-price', $selection->price, $right, $middle - $m->tabCell[1] / 2, $m->tabCell, 'focus',
        HorizontalAlignment::RIGHT);
    }
  }

  /** What the focus does (its MP and reach, or counts) on the left; the bindings right-aligned. */
  private static function renderFooter(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content, MenuPresentationCatalog $theme,
    CanvasRectangle $area): void
  {
    $m = $theme->mastery;
    $shapes->fillRectangle(new CanvasRectangle($area->x, $area->y, $area->width, 1), 'edge', 0.8);
    $facts = [];
    $x = $area->x;
    foreach ($content->footer as $fact) {
      $facts[] = ['text' => $fact['text'], 'x' => $x, 'y' => $area->y + 16, 'color' => $fact['color']];
      $x += (TerminalText::displayWidth($fact['text']) + 3) * $m->bodyCell[0];
    }
    $view->renderTexts('mastery-footer', $facts, new CanvasRectangle($area->x, $area->y + 16, $area->width, $m->bodyCell[1]), $m->bodyCell);
    if ($content->hints === []) {
      return;
    }
    $cell = $m->bodyCell;
    $available = $area->x + $area->width - $x - 2 * $cell[0];
    $cells = MenuActionHints::measureCells($content->hints, $theme, $cell);
    $width = min($cells * $cell[0], max($cell[0], $available));
    $rows = MenuActionHints::height($content->hints, $theme, $width, true, $cell);
    $bounds = new CanvasRectangle($area->x + $area->width - $width, $area->y + max(4, (50 - $rows) / 2), $width, $rows);
    $view->hints('mastery-hints', $content->hints, $bounds, true, $cell);
  }

  /** The compact Info sheet laid over the top of the board: the description, then what the focus builds on. */
  private static function renderInfo(MenuCanvas $view, MasteryMenuContent $content, MenuPresentationCatalog $theme, CanvasRectangle $area): void
  {
    $m = $theme->mastery;
    $view->surface('mastery-info', $area, 'panel', 40);
    $view->renderOutline('mastery-info-edge', $area, 'edge', 1, 41);
    $view->surface('mastery-info-accent', new CanvasRectangle($area->x, $area->y, $area->width, 2), 'focus', 42);
    $inner = $area->width - 60;
    $lines = [];
    $y = $area->y + 26;
    foreach ($content->info as $index => $text) {
      $cell = $index === 0 ? $m->tabCell : $m->smallCell;
      foreach (MenuCanvas::wrap($text, max(1, (int)floor($inner / $cell[0]))) as $line) {
        $lines[] = ['text' => $line, 'cell' => $cell, 'y' => $y, 'color' => $index === 0 ? 'text' : 'muted'];
        $y += $cell[1] + 2;
      }
      $y += 8;
    }
    foreach ([$m->tabCell, $m->smallCell] as $size => $cell) {
      $items = array_values(array_map(static fn(array $line): array => ['text' => $line['text'], 'x' => $area->x + 30, 'y' => $line['y'],
        'color' => $line['color']], array_filter($lines, static fn(array $line): bool => $line['cell'] === $cell)));
      $view->renderTexts("mastery-info-text-{$size}", $items, new CanvasRectangle($area->x + 30, $area->y + 20, $inner, $area->height - 24), $cell, 43);
    }
  }

  /**
   * Draws the first picture a theme binds among roles, contained in a square centred on a point and
   * cut to an area.
   *
   * @param list<string> $roles
   */
  private static function renderIcon(MenuCanvas $view, string $id, MenuPresentationCatalog $theme, array $roles, float $x, float $y, int $size,
    CanvasRectangle $clip, float $opacity): bool
  {
    $asset = self::findIcon($theme, $roles);
    if ($asset === null || $x - $size / 2 < 0 || $y - $size / 2 < 0 || $x + $size / 2 > $view->width || $y + $size / 2 > $view->height) {
      return false;
    }
    $images = MenuIconRegistry::containAsset($theme->assetRoot, $id, $asset, new CanvasRectangle($x - $size / 2, $y - $size / 2, $size, $size), 31,
      $clip);
    $view->picture($clip, array_map(static fn(CanvasImage $image): CanvasImage => new CanvasImage($image->id, $image->asset, $image->destination,
      $image->layer, $image->sourceRect, $opacity * $image->opacity, $image->clipRect, $image->brightness, $image->flipX, $image->flipY), $images));

    return $images !== [];
  }

  /** @param list<string> $roles */
  private static function findIcon(MenuPresentationCatalog $theme, array $roles): ?string
  {
    foreach ($roles as $role) {
      $asset = $theme->icons?->icons[$role] ?? null;
      if ($asset !== null && PngAssetPreflight::getAvailableSize($theme->assetRoot, $asset) !== null) {
        return $asset;
      }
    }

    return null;
  }

  private static function getRegionColor(MenuPresentationCatalog $theme, string $region): PresentationColor
  {
    return $theme->mastery->regionColors[$region] ?? $theme->colors['accent'];
  }

  private static function getLabelColor(MasteryMenuNode $node): string
  {
    return match ($node->state) {
      MasteryNodeState::LEARNED, MasteryNodeState::OPEN, null => 'text',
      MasteryNodeState::LOCKED => 'muted',
      MasteryNodeState::UNAVAILABLE => 'disabled',
    };
  }

  /** Blends one colour into another, keeping a share of the first. */
  private static function mix(PresentationColor $from, PresentationColor $to, float $share): PresentationColor
  {
    $a = $from->toArray();
    $b = $to->toArray();
    if (! isset($a['r'], $b['r'])) {
      return $from;
    }

    return PresentationColor::rgb((int)round($a['r'] * $share + $b['r'] * (1 - $share)), (int)round($a['g'] * $share + $b['g'] * (1 - $share)),
      (int)round($a['b'] * $share + $b['b'] * (1 - $share)));
  }
}
