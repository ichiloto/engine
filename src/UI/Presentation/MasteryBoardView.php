<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/**
 * Where the drawn Mastery board puts things inside its area. The tree and the
 * family cards are laid out the same whether Info is open or not: Info is
 * meta text, a sheet over the board that never moves, reframes or rescales
 * what is under it. It stands at the top of the area, or at the bottom while
 * the view is scrolled all the way up, so it does not lie over the tree's
 * tops and headings or the discipline's name; on the side it takes it stops
 * short of the focus. It stays inside the board's area, clear of the tabs,
 * the family strip and the selection below.
 *
 * On the tree the camera frames whole rows, measured with each place's focus
 * rings, badge and name: the focus with what it hangs from above (with the
 * wrapped rows of siblings between them) and what hangs from it below when
 * all fit, else the focus and what it hangs from, else the focus alone. Only
 * places that lie wholly inside the viewport are drawn; the lines to the rest
 * run off its edge. While the view pans, a
 * place whole where the pan starts or ends slides through the viewport's edge.
 * Above the tree the family cards follow the focused card's row the same way.
 */
final readonly class MasteryBoardView
{
  /** How far the board may scroll past its outer lanes, as a share of a lane. */
  public const float LANE_MARGIN = 0.4;
  public const int CARD_COLUMNS = 3;
  public const int CARDS_PER_PAGE = 9;
  public const int CARD_HEIGHT = 84;
  public const int CARD_GAP = 12;
  public const int CARD_PADDING = 20;
  /** The band the discipline's name takes above the family cards. */
  public const int OVERVIEW_TITLE_HEIGHT = 78;
  /** How far under a row's names the lines from it may run across. */
  public const int JUNCTION_GAP = 4;

  /**
   * @param CanvasRectangle $area The board's whole area.
   * @param CanvasRectangle|null $sheet The open Info sheet over the board, or null while it is closed.
   * @param list<array{text: string, cell: list<int>, y: float, color: string}> $sheetLines The sheet's lines, each from the top of its text.
   * @param CanvasRectangle $viewport Where the tree or the cards are drawn: the area between its hairlines.
   * @param float $rowBelow How far any place may reach below its disc's centre (MasteryBoardPlace::getRowReach).
   * @param float $originX Where lane 0 stands across the canvas at this moment.
   * @param float $originY Where stage 0 stands down the canvas at this moment.
   * @param int $rows How many stages the camera frames whole around the focus (1 to 3).
   * @param array<string, MasteryBoardPlace> $places The places drawn, by id, in board order.
   * @param array<int, CanvasRectangle> $cards Each family card drawn, by family index.
   * @param bool $showsTitle Whether the discipline's name stands above the cards.
   */
  public function __construct(
    public CanvasRectangle $area,
    public ?CanvasRectangle $sheet,
    public array $sheetLines,
    public CanvasRectangle $viewport,
    public float $laneWidth,
    public float $stageSpacing,
    public float $rowBelow,
    public float $originX,
    public float $originY,
    public int $rows,
    public array $places,
    public array $cards,
    public bool $showsTitle,
  ) {}

  public static function create(MasteryMenuContent $content, MenuPresentationCatalog $theme, CanvasRectangle $area, float $time = 0): self
  {
    $m = $theme->mastery;
    // The area's edges are hairlines; the viewport lies between them, whether Info is open or not.
    $viewport = new CanvasRectangle($area->x, $area->y + 1, $area->width, max(1, $area->height - 2));
    [$above, $below] = MasteryBoardPlace::getRowReach($m);
    if ($content->level !== MasteryMenuLevel::TREE) {
      [$cards, $scrolledUp] = self::layoutCards($content, $area, $viewport);
      $focus = $content->level === MasteryMenuLevel::FAMILY ? $cards[$content->familyIndex] ?? null : null;
      [$sheet, $lines] = self::layoutSheet($content, $theme, $area, $scrolledUp,
        $focus === null ? null : [$focus->y, $focus->y + $focus->height]);

      return new self($area, $sheet, $lines, $viewport, $m->laneWidth, $m->stageSpacing, $below, $viewport->x, $viewport->y, 0, [], $cards,
        true);
    }

    $spacing = $m->stageSpacing;
    $rows = match (true) {
      2 * $spacing + $above + $below <= $viewport->height => 3,
      $spacing + $above + $below <= $viewport->height => 2,
      default => 1,
    };
    $worldWidth = $content->lanes * $m->laneWidth;
    $margin = $m->laneWidth * self::LANE_MARGIN;
    // The framed rows run from the stages kept above the focus (up to what it hangs from, as many as fit with it)
    // down to the rows below; the viewport's middle goes to their middle.
    $getOrigin = static function (float $lane, float $stage, float $kept) use ($viewport, $worldWidth, $margin, $m, $spacing, $rows, $above,
      $below): array {
      $camera = $worldWidth + 2 * $margin <= $viewport->width ? $worldWidth / 2
        : min(max($lane * $m->laneWidth, $viewport->width / 2 - $margin), $worldWidth + $margin - $viewport->width / 2);
      $first = $stage - min($kept, $rows - 1);
      $middle = ($first * $spacing - $above + ($first + $rows - 1) * $spacing + $below) / 2;

      return [$viewport->x + $viewport->width / 2 - $camera, $viewport->y + $viewport->height / 2 - $middle];
    };
    $camera = $content->camera;
    $rest = $getOrigin($camera->toLane, $camera->toStage, $camera->toAbove);
    $panning = ! $content->reducedMotion && $time < $camera->startedAt + MasteryCamera::PAN_DURATION
      && ($camera->fromLane !== $camera->toLane || $camera->fromStage !== $camera->toStage || $camera->fromAbove !== $camera->toAbove);
    $now = $panning ? $getOrigin(...$camera->getCentreAt($time)) : $rest;
    $start = $panning ? $getOrigin($camera->fromLane, $camera->fromStage, $camera->fromAbove) : $rest;
    $places = [];
    $focus = null;
    foreach ($content->nodes as $node) {
      $place = MasteryBoardPlace::measure($node, $now[0] + $node->lane * $m->laneWidth, $now[1] + $node->stage * $spacing,
        $node->id === $content->focus, $m);
      if ($node->id === $content->focus) {
        $focus = $place->moveBy($rest[0] - $now[0], $rest[1] - $now[1]);
      }
      if (self::isWholeAt($place, $rest[0] - $now[0], $rest[1] - $now[1], $viewport)
        || ($panning && self::isWholeAt($place, $start[0] - $now[0], $start[1] - $now[1], $viewport))) {
        $places[$node->id] = $place;
      }
    }
    // Scrolled all the way up, the tops of the families (stage 0) rest whole in view.
    [$sheet, $lines] = self::layoutSheet($content, $theme, $area, $rest[1] - $above >= $viewport->y,
      $focus === null ? null : [$focus->top, $focus->bottom]);

    return new self($area, $sheet, $lines, $viewport, $m->laneWidth, $spacing, $below, $now[0], $now[1], $rows, $places, [], false);
  }

  /** Where a lane's middle stands across the canvas. */
  public function getLaneX(float $lane): float
  {
    return $this->originX + $lane * $this->laneWidth;
  }

  /** Where a stage stands down the canvas. */
  public function getStageY(float $stage): float
  {
    return $this->originY + $stage * $this->stageSpacing;
  }

  /**
   * Where the lines from a place run across to what hangs from it: half way down to the next stage, or lower,
   * just clear of the names of the place's row, so a line never runs through a name.
   */
  public function getJunctionY(float $stage): float
  {
    return $this->getStageY($stage) + max($this->stageSpacing / 2, $this->rowBelow + self::JUNCTION_GAP);
  }

  private static function isWholeAt(MasteryBoardPlace $place, float $dx, float $dy, CanvasRectangle $viewport): bool
  {
    return $place->moveBy($dx, $dy)->isWithin($viewport->x, $viewport->y, $viewport->x + $viewport->width, $viewport->y + $viewport->height);
  }

  /**
   * The open Info sheet over the board, inset by the theme's gap and as tall as its text: the summary or description in
   * the tab size, then the rest smaller. It stands at the top of the area, or at its bottom while the view is scrolled all
   * the way up; when the focus leaves that side less room than one line, it takes the other. On its side it stops short of
   * the focus (a node or a family card), else it leaves a row of the board in view; text past that ends its last shown
   * line with an ellipsis.
   *
   * @param array{float, float}|null $focus The focus's top and bottom where the view rests, when there is one to keep clear.
   * @return array{?CanvasRectangle, list<array{text: string, cell: list<int>, y: float, color: string}>}
   */
  private static function layoutSheet(MasteryMenuContent $content, MenuPresentationCatalog $theme, CanvasRectangle $area, bool $scrolledUp,
    ?array $focus): array
  {
    if ($content->info === null) {
      return [null, []];
    }
    $m = $theme->mastery;
    $padding = $theme->metrics->panelPadding;
    $gap = $theme->metrics->sectionGap;
    $inner = $area->width - 2 * $padding;
    [$above, $below] = MasteryBoardPlace::getRowReach($m);
    // The room inside the area's hairlines each side leaves the sheet: short of the focus, or of a row the board keeps.
    $kept = $content->level === MasteryMenuLevel::TREE ? $m->stageSpacing + $above + $below : self::CARD_HEIGHT + self::CARD_GAP;
    [$top, $bottom] = [$area->y + 1 + $gap, $area->y + $area->height - 1 - $gap];
    $rooms = ['top' => ($focus[0] ?? $bottom - $kept) - $gap - $top, 'bottom' => $bottom - ($focus[1] ?? $top + $kept) - $gap];
    $side = $scrolledUp ? 'bottom' : 'top';
    $line = $m->tabCell[1] + 2 * $padding;
    if ($rooms[$side] < $line && $rooms[$side === 'top' ? 'bottom' : 'top'] > $rooms[$side]) {
      $side = $side === 'top' ? 'bottom' : 'top';
    }
    $room = $rooms[$side] - 2 * $padding;
    $lines = [];
    $height = 0.0;
    foreach ($content->info as $index => $text) {
      $cell = $index === 0 ? $m->tabCell : $m->smallCell;
      $columns = max(1, (int)floor($inner / $cell[0]));
      foreach (MenuCanvas::wrap($text, $columns) as $wrapped) {
        if ($lines !== [] && $height + $cell[1] > $room) {
          $last = array_key_last($lines);
          $lastColumns = max(1, (int)floor($inner / $lines[$last]['cell'][0]));
          $lines[$last]['text'] = TerminalText::truncateToWidth($lines[$last]['text'], $lastColumns - 1) . '…';
          break 2;
        }
        $lines[] = ['text' => $wrapped, 'cell' => $cell, 'y' => $height, 'color' => $index === 0 ? 'text' : 'muted'];
        $height += $cell[1] + 2;
      }
      $height += $gap;
    }
    $sheetHeight = max(array_map(static fn(array $line): float => $line['y'] + $line['cell'][1], $lines)) + 2 * $padding;

    return [new CanvasRectangle($area->x, $side === 'top' ? $top : $bottom - $sheetHeight, $area->width, $sheetHeight), $lines];
  }

  /**
   * The family cards of the focused card's page, three to a row, under the discipline's name. When the viewport holds
   * fewer rows than the page, the rows shown follow the focused card.
   *
   * @return array{array<int, CanvasRectangle>, bool} The cards, and whether the view is scrolled all the way up (its first row shown).
   */
  private static function layoutCards(MasteryMenuContent $content, CanvasRectangle $area, CanvasRectangle $viewport): array
  {
    $first = intdiv($content->familyIndex, self::CARDS_PER_PAGE) * self::CARDS_PER_PAGE;
    $count = min(self::CARDS_PER_PAGE, max(0, count($content->families) - $first));
    $pageRows = intdiv($count + self::CARD_COLUMNS - 1, self::CARD_COLUMNS);
    $top = $area->y + self::OVERVIEW_TITLE_HEIGHT;
    $step = self::CARD_HEIGHT + self::CARD_GAP;
    $capacity = max(1, (int)floor(($viewport->y + $viewport->height - $top + self::CARD_GAP) / $step));
    $focusRow = intdiv($content->familyIndex - $first, self::CARD_COLUMNS);
    $firstRow = min(max(0, $focusRow - $capacity + 1), max(0, $pageRows - $capacity));
    $width = ($area->width - 2 * self::CARD_PADDING - (self::CARD_COLUMNS - 1) * self::CARD_GAP) / self::CARD_COLUMNS;
    $cards = [];
    for ($offset = $firstRow * self::CARD_COLUMNS; $offset < min($count, ($firstRow + $capacity) * self::CARD_COLUMNS); $offset++) {
      $cards[$first + $offset] = new CanvasRectangle($area->x + self::CARD_PADDING + ($offset % self::CARD_COLUMNS) * ($width + self::CARD_GAP),
        $top + (intdiv($offset, self::CARD_COLUMNS) - $firstRow) * $step, $width, self::CARD_HEIGHT);
    }

    return [$cards, $firstRow === 0];
  }
}
