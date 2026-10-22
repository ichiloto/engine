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
 * a strip of the shown discipline's families, the family's tree (or, while the
 * focus is above the tree, a summary of each family), the focused place's name,
 * price and Learn button, and a footer with what the focus does and the few
 * bindings it needs. The focus colour marks only the level the focus is on.
 *
 * Nodes, lines, badges and tabs are live shapes in the theme's colours;
 * pictures come from the theme's icon roles and portraits, so another game
 * re-themes the screen without drawing code. Choosing and learning stay with
 * the menu state; the Terminal shows the same projection.
 */
final class MasteryMenuPresentation
{
  private const int ICON_SIZE = 32;
  private const int BUTTON_WIDTH = 180;
  private const int BUTTON_HEIGHT = 40;

  /**
   * @param bool $dashedStrokes Whether the renderer negotiated canvas_stroke_dashes (MenuShapes).
   */
  public static function compose(MasteryMenuContent $content, MenuPresentationCatalog $theme, float $time = 0,
    int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT, bool $dashedStrokes = false): PresentationCanvas
  {
    $view = new MenuCanvas($theme, $width, $height, $time);
    $host = MenuLayout::getBounds($width, $height);
    $m = $theme->mastery;
    $areas = self::getAreas($theme, $width, $height);
    $chrome = new MenuShapes($theme, new CanvasRectangle(0, 0, $width, $height));
    self::renderCorner($view, $chrome, $content, $theme, $time, $areas['footer']->x, $host);
    self::renderDisciplines($view, $chrome, $content, $theme, $areas['suits']);
    self::renderFamilies($view, $chrome, $content, $theme, $areas['families']);
    $frame = self::layoutBoard($content, $theme, $time, $width, $height);
    $area = $frame->area;
    $chrome->fillGlow($area, $area->x + $area->width / 2, $area->y + $area->height / 2, $area->width * 0.6, $area->height * 0.7, 'panel', 0.9);
    $chrome->fillRectangle(new CanvasRectangle($area->x, $area->y, $area->width, 1), 'edge', 0.6);
    $chrome->fillRectangle(new CanvasRectangle($area->x, $area->y + $area->height - 1, $area->width, 1), 'edge', 0.6);
    // The tree is drawn in three retained pictures: what stays put, what moves while Learn is held or a
    // node is focused or learned, and the rings and badges over both. A hold then redraws only the
    // small middle picture each frame, never the whole tree.
    $board = new MenuShapes($theme, $frame->viewport, $dashedStrokes);
    $motion = new MenuShapes($theme, $frame->viewport, $dashedStrokes);
    $marks = new MenuShapes($theme, $frame->viewport, $dashedStrokes);
    if ($content->level !== MasteryMenuLevel::TREE) {
      self::renderOverview($view, $chrome, $content, $theme, $frame);
    } elseif ($content->nodes === []) {
      $view->renderText('mastery-empty', $content->emptyText, $frame->viewport->x + $frame->viewport->width / 2,
        $frame->viewport->y + $frame->viewport->height / 2 - 9, $m->bodyCell, 'disabled', HorizontalAlignment::CENTER);
    } else {
      self::renderBoard($view, $board, $motion, $marks, $content, $theme, $time, $frame);
    }
    self::renderSelection($view, $chrome, $content, $theme, $areas['selection']);
    self::renderFooter($view, $chrome, $content, $theme, $areas['footer']);
    if ($frame->sheet !== null) {
      self::renderInfo($view, $theme, $frame);
    }
    $view->picture(new CanvasRectangle(0, 0, $width, $height), [], array_values(array_filter([
      $chrome->compose('mastery-chrome', 5), $board->compose('mastery-board', 12), $motion->compose('mastery-board-motion', 13),
      $marks->compose('mastery-board-marks', 14)])));

    return $view->finish();
  }

  /**
   * Where the board's area puts the tree or the family cards and the Info sheet at a moment. The tree and the cards
   * stand the same with Info open or closed; the sheet lies over them (MasteryBoardView).
   */
  public static function layoutBoard(MasteryMenuContent $content, MenuPresentationCatalog $theme, float $time = 0,
    int $width = PresentationCanvas::DEFAULT_WIDTH, int $height = PresentationCanvas::DEFAULT_HEIGHT): MasteryBoardView
  {
    return MasteryBoardView::create($content, $theme, self::getAreas($theme, $width, $height)['board'], $time);
  }

  /** @return array{suits: CanvasRectangle, families: CanvasRectangle, board: CanvasRectangle, selection: CanvasRectangle, footer: CanvasRectangle} */
  private static function getAreas(MenuPresentationCatalog $theme, int $width, int $height): array
  {
    $host = MenuLayout::getBounds($width, $height);
    $left = $host->x + $theme->metrics->panelPadding;
    $right = $host->x + $host->width - $theme->metrics->panelPadding - 5;
    $column = $left + $theme->mastery->portraitWidth + 35;

    return [
      'suits' => new CanvasRectangle($column, $host->y + 25, $right - $column, 60),
      'families' => new CanvasRectangle($column, $host->y + 95, $right - $column, 32),
      'board' => new CanvasRectangle($column, $host->y + 140, $right - $column, 390),
      'selection' => new CanvasRectangle($column, $host->y + 550, $right - $column, 67),
      'footer' => new CanvasRectangle($left, $host->y + $host->height - 59, $right - $left, 50),
    ];
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

  /**
   * The disciplines as tabs: picture, name and Learned/Total count, the shown one underlined in its accent;
   * while the focus is on the disciplines, the shown one is framed and underlined in the focus colour.
   */
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
      $focused = $active && $content->level === MasteryMenuLevel::DISCIPLINE;
      $accent = self::getRegionColor($theme, $tab->id);
      $tabArea = new CanvasRectangle($x, $area->y, $tabWidth, $area->height);
      $shapes->fillRectangle($tabArea, 'panel', $active ? 0.95 : 0.6);
      $shapes->fillRectangle(new CanvasRectangle($x, $area->y + $area->height - ($active ? 2 : 1), $tabWidth, $active ? 2 : 1),
        $focused ? 'focus' : ($active ? $accent : 'edge'), $active ? 1.0 : 0.7);
      if ($focused) {
        $shapes->strokeRectangle($tabArea, 1, 'focus');
      }
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

  /** The shown discipline's families as a strip of three, the chosen one underlined in the discipline's accent, with arrows for the rest. */
  private static function renderFamilies(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content,
    MenuPresentationCatalog $theme, CanvasRectangle $area): void
  {
    $m = $theme->mastery;
    $families = $content->families;
    if ($families === []) {
      return;
    }
    // The focus colour marks only where the focus is; the strip shows which family is chosen.
    $accent = self::getRegionColor($theme, $content->findRegion()?->id ?? '');
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
        $active ? $accent : 'edge', $active ? 1.0 : 0.6);
      $texts[] = ['text' => $families[$index]->name, 'x' => $x + $slotWidth / 2, 'y' => $area->y + ($area->height - $m->tabCell[1]) / 2,
        'color' => $active ? $accent : 'muted', 'alignment' => HorizontalAlignment::CENTER];
    }
    $view->renderTexts('mastery-families', $texts, $area, $m->tabCell);
  }

  /**
   * The board: families side by side, each a tree of straight lines down and across, every node a
   * disc with its picture, its rim badge and its name; a line leaves a node under its name and runs
   * across just above the row it leads to, wrapped rows included; learned lit in the discipline's accent,
   * reachable plain, locked dark with a padlock. The view follows the focus and frames whole places
   * (MasteryBoardView); lines to places out of view run off its edge. The role's curriculum is
   * marked quietly with dashes, never in the focus colour, so the focus stays the one bright mark.
   *
   * @param MenuShapes $shapes What stays put: the lines and the discs.
   * @param MenuShapes $motion What moves: Learn's fill, the focus rings and the accent on a node just learned.
   * @param MenuShapes $marks What sits over both: state rings, curriculum rings, badges, learned and locked marks.
   */
  private static function renderBoard(MenuCanvas $view, MenuShapes $shapes, MenuShapes $motion, MenuShapes $marks, MasteryMenuContent $content,
    MenuPresentationCatalog $theme, float $time, MasteryBoardView $frame): void
  {
    $m = $theme->mastery;
    $region = $content->findRegion()?->id ?? '';
    $accent = self::getRegionColor($theme, $region);
    $radius = $m->nodeDiameter / 2;
    $toX = $frame->getLaneX(...);
    $toY = $frame->getStageY(...);
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
    // Lines down and across from each node to what hangs from it, lit once that is learned. A line leaves under the
    // node's name and runs across just above the row it leads to: the next stage, or a wrapped row further down,
    // which the line reaches straight down past the rows above it. Siblings share the line down from what they hang
    // from, so lit lines are drawn after the rest and stay lit where they share it.
    $lines = [...array_filter($content->nodes, static fn(MasteryMenuNode $node): bool => ! $node->isLit()),
      ...array_filter($content->nodes, static fn(MasteryMenuNode $node): bool => $node->isLit())];
    foreach ($lines as $node) {
      $parent = $node->parent === null ? null : $byId[$node->parent] ?? null;
      if ($parent === null) {
        continue;
      }
      $from = $frame->places[$parent->id] ?? MasteryBoardPlace::measure($parent, $toX($parent->lane), $toY($parent->stage),
        $parent->id === $content->focus, $m);
      $across = $frame->getJunctionY($node->stage - 1);
      $points = [[$from->x, $from->getStemY()], [$from->x, $across], [$toX($node->lane), $across], [$toX($node->lane), $toY($node->stage) - $radius]];
      $points = array_values(array_unique($points, SORT_REGULAR));
      $shapes->strokeLine($points, $node->isLit() ? 2.0 : 1.5, $node->isLit() ? $accent : $dim);
      if ($content->curriculum && $node->curriculum) {
        $shapes->strokeDashedLine($points, 1.5, 4, 4, 'muted', 0.7);
      }
    }
    $labels = $tops = $badges = [];
    foreach ($frame->places as $place) {
      $node = $place->node;
      self::renderNode($view, $shapes, $motion, $marks, $content, $theme, $time, $place, $accent, $dim, $frame->viewport, $badges);
      $label = ['text' => $node->label, 'x' => $place->x, 'y' => $place->labelY, 'color' => self::getLabelColor($node),
        'alignment' => HorizontalAlignment::CENTER];
      if ($node->isFamilyTop()) {
        $tops[] = $label;
      } else {
        $labels[] = $label;
      }
    }
    $view->renderTexts('mastery-node-names', $labels, $frame->viewport, $m->bodyCell);
    $view->renderTexts('mastery-family-names', $tops, $frame->viewport, $m->tabCell);
    $view->renderTexts('mastery-badges', $badges, $frame->viewport, $m->smallCell, 31);
  }

  /**
   * One node: its disc and ring by state, Learn's fill while held, the focus rings, the curriculum's
   * dashed ring, its rim badge, its learned or locked mark beside the badge, and its picture.
   *
   * @param list<array{text: string, x: float, y: float, color: string, alignment?: HorizontalAlignment}> $badges
   */
  private static function renderNode(MenuCanvas $view, MenuShapes $shapes, MenuShapes $motion, MenuShapes $marks, MasteryMenuContent $content,
    MenuPresentationCatalog $theme, float $time, MasteryBoardPlace $place, PresentationColor $accent, PresentationColor $dim,
    CanvasRectangle $area, array &$badges): void
  {
    $m = $theme->mastery;
    [$node, $x, $y, $radius, $focused] = [$place->node, $place->x, $place->y, $place->radius, $place->focused];
    $state = $node->state;
    $flare = $content->flare !== null && $content->flare['node'] === $node->id && ! $content->reducedMotion
      ? ($time - $content->flare['at']) / 0.36 : null;
    if ($flare !== null && $flare >= 0 && $flare < 1) {
      $motion->strokeCircle($x, $y, $radius + 6 + 18 * $flare, 3, $accent, 1 - $flare);
    }
    $learned = $state === MasteryNodeState::LEARNED;
    $shapes->fillCircle($x, $y, $radius, $learned ? self::mix($accent, $theme->colors['background'], 0.24) : 'background');
    $hold = $content->hold !== null && $content->hold->node === $node->id ? $content->hold->getProgressAt($time) : null;
    if ($hold !== null && ! $content->reducedMotion) {
      $motion->fillCircleFrom($x, $y, $radius, $hold, $accent, 0.45);
    }
    match (true) {
      $learned => $marks->strokeCircle($x, $y, $radius - 1, 2, $accent),
      $state === MasteryNodeState::OPEN || $node->isHeading() => $marks->strokeCircle($x, $y, $radius - 0.75, 1.5,
        self::mix($theme->colors['text'], $theme->colors['background'], 0.55)),
      $state === MasteryNodeState::UNAVAILABLE => $marks->strokeCircle($x, $y, $radius - 0.75, 1.5, $dim, 1.0, 24),
      default => $marks->strokeCircle($x, $y, $radius - 0.5, 1, $dim),
    };
    if ($content->curriculum && $node->curriculum) {
      $marks->strokeCircle($x, $y, $radius - 7, 1.2, 'muted', 0.7, 20);
    }
    if ($focused) {
      // The rings part around the rim badge and mark, which sit over them.
      $rim = $place->getRimMarks();
      $motion->strokeCircle($x, $y, $radius + 6, 2, 'focus', $hold === null ? 1.0 : 0.35, clear: $rim);
      $motion->strokeCircle($x, $y, $radius + 13, 2, 'focus', 0.22, clear: $rim);
      if ($hold !== null && ! $content->reducedMotion) {
        $motion->strokeArc($x, $y, $radius + 6, $hold, 4, 'focus', clear: $rim);
      }
    }
    if ($place->badgeWidth !== null) {
      $badgeY = $y + $radius - 1;
      $marks->fillPill($x, $badgeY, $place->badgeWidth, MasteryBoardPlace::BADGE_HEIGHT, 'background');
      $marks->strokePill($x, $badgeY, $place->badgeWidth, MasteryBoardPlace::BADGE_HEIGHT, 1, $learned ? $accent : self::mix($theme->colors['text'],
        $theme->colors['background'], 0.5));
      $badges[] = ['text' => $node->badge, 'x' => $x, 'y' => $badgeY - $m->smallCell[1] / 2, 'color' => $learned ? 'text' : 'muted',
        'alignment' => HorizontalAlignment::CENTER];
    }
    // The learned or locked mark sits just past the badge (MasteryBoardPlace), so a wide badge never covers it.
    $markX = $place->markX ?? $x;
    $markY = $y + $radius - 1;
    if ($learned) {
      $marks->fillCircle($markX, $markY, MasteryBoardPlace::MARK_RADIUS, 'background');
      $marks->strokeLine([[$markX - 4, $markY], [$markX - 1, $markY + 3], [$markX + 4.5, $markY - 4]], 2, 'text');
    } elseif ($state === MasteryNodeState::LOCKED) {
      $marks->fillCircle($markX, $markY, MasteryBoardPlace::MARK_RADIUS, 'background');
      $marks->strokeArc($markX, $markY - 2, 3, 0.5, 1.4, 'muted');
      $marks->strokeLine([[$markX - 3, $markY - 2], [$markX - 3, $markY - 1]], 1.4, 'muted');
      $marks->strokeLine([[$markX + 3, $markY - 2], [$markX + 3, $markY - 1]], 1.4, 'muted');
      $marks->fillRectangle(new CanvasRectangle(max(0, $markX - 4.5), max(0, $markY - 1), 9, 7), 'muted');
    }
    $opacity = match (true) {
      $learned, $node->isHeading() => 1.0,
      $state === MasteryNodeState::OPEN => 0.95,
      $state === MasteryNodeState::LOCKED => 0.45,
      default => 0.3,
    };
    self::renderIcon($view, "mastery-node-{$node->id}-icon", $theme, $node->iconRoles, $x, $y, self::ICON_SIZE, $area, $opacity);
  }

  /**
   * Above the tree: the discipline's name, then a framed summary of each family, nine to a page, the focused
   * one lit. An open Info sheet lies over them without moving them.
   */
  private static function renderOverview(MenuCanvas $view, MenuShapes $shapes, MasteryMenuContent $content, MenuPresentationCatalog $theme,
    MasteryBoardView $frame): void
  {
    $m = $theme->mastery;
    $area = $frame->area;
    $region = $content->findRegion();
    if ($region !== null && $frame->showsTitle) {
      $nameWidth = TerminalText::displayWidth($region->name) * $m->nameCell[0];
      $icon = self::findIcon($theme, $region->iconRoles) !== null ? 28 : 0;
      $start = $area->x + ($area->width - $nameWidth - $icon) / 2;
      if ($icon > 0) {
        self::renderIcon($view, 'mastery-overview-icon', $theme, $region->iconRoles, $start + 10, $area->y + 41, 20, $area, 1.0);
      }
      $view->renderText('mastery-overview-title', $region->name, $start + $icon, $area->y + 41 - $m->nameCell[1] / 2, $m->nameCell);
    }
    $names = $counts = [];
    foreach ($frame->cards as $index => $card) {
      $family = $content->families[$index];
      [$x, $y] = [$card->x, $card->y];
      // The cards are the focus on the family level; above it, on the disciplines, none is.
      $focused = $index === $content->familyIndex && $content->level === MasteryMenuLevel::FAMILY;
      $shapes->fillRectangle($card, 'panel', $focused ? 1.0 : 0.65);
      $shapes->strokeRectangle($card, 1, $focused ? 'focus' : 'edge', $focused ? 1.0 : 0.8);
      self::renderIcon($view, "mastery-card-{$index}-icon", $theme, $family->iconRoles, $x + 70, $y + $card->height / 2, self::ICON_SIZE,
        $frame->viewport, 1.0);
      $textX = $x + 70 + ($card->width - 70) / 2;
      $names[] = ['text' => $family->name, 'x' => $textX, 'y' => $y + 18, 'color' => 'text', 'alignment' => HorizontalAlignment::CENTER];
      $counts[] = ['text' => \Ichiloto\Engine\Localization\MessageCatalog::getTerm('mastery.learned_count', $family->learned, $family->total),
        'x' => $textX, 'y' => $y + 48, 'color' => 'muted', 'alignment' => HorizontalAlignment::CENTER];
    }
    $view->renderTexts('mastery-card-names', $names, $frame->viewport, $m->tabCell);
    $view->renderTexts('mastery-card-counts', $counts, $frame->viewport, $m->smallCell);
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

  /** The compact Info sheet where MasteryBoardView puts it over the board, above the tree's pictures and names. */
  private static function renderInfo(MenuCanvas $view, MenuPresentationCatalog $theme, MasteryBoardView $frame): void
  {
    $m = $theme->mastery;
    $padding = $theme->metrics->panelPadding;
    $area = $frame->sheet;
    $view->surface('mastery-info', $area, 'panel', 40);
    $view->renderOutline('mastery-info-edge', $area, 'edge', 1, 41);
    $view->surface('mastery-info-accent', new CanvasRectangle($area->x, $area->y, $area->width, 2), 'focus', 42);
    $text = new CanvasRectangle($area->x + $padding, $area->y + $padding, $area->width - 2 * $padding, $area->height - 2 * $padding);
    foreach ([$m->tabCell, $m->smallCell] as $size => $cell) {
      $items = array_values(array_map(static fn(array $line): array => ['text' => $line['text'], 'x' => $text->x, 'y' => $text->y + $line['y'],
        'color' => $line['color']], array_filter($frame->sheetLines, static fn(array $line): bool => $line['cell'] === $cell)));
      $view->renderTexts("mastery-info-text-{$size}", $items, $text, $cell, 43);
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
