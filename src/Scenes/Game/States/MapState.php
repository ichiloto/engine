<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\RegionArea;
use Ichiloto\Engine\Field\RegionMap;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * The region map: how the places around the player connect.
 *
 * Redrawing the tile map the player is already looking at tells them nothing.
 * What a map is for is the shape of the region, so this lays the region out
 * as a graph, the player's place first and everything else by how many doors
 * away it is, built from the maps' own transfer events.
 *
 * Places the party has been are named. Places they have only heard of, one
 * door from somewhere they have been, show as unknown. Everything further
 * stays off the map until they get closer.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class MapState extends GameSceneState
{
  protected const int MENU_WIDTH = 110;
  protected const int MAP_PANEL_HEIGHT = 28;
  protected const int INFO_PANEL_HEIGHT = 4;
  protected const int MIN_CELL_WIDTH = 11;
  protected const int MAX_CELL_WIDTH = 24;
  protected const int GUTTER = 5;
  protected const int ROW_SPACING = 2;
  protected const string UNKNOWN_NAME = '?????';

  protected int $leftMargin = 0;
  protected int $topMargin = 0;
  protected ?BorderPackInterface $borderPack = null;
  protected ?Window $mapPanel = null;
  protected ?Window $infoPanel = null;
  /**
   * @var array<string, true> The cells the places occupy, which no door may be
   * drawn through: a line crossing a name makes it unreadable.
   */
  protected array $blocked = [];

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->deactivate();
    $this->calculateMargins();
    $this->initializeUI();
    $this->refreshUI();
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if (
      Input::isButtonDown('back')
      || Input::isButtonDown('cancel')
      || Input::isButtonDown('map')
    ) {
      $this->setState($this->getGameScene()->fieldState);
    }
  }

  /**
   * @inheritDoc
   */
  public function exit(): void
  {
    Console::clear();
    $this->getGameScene()->locationHUDWindow->activate();
  }

  /**
   * Centers the screen inside the terminal.
   *
   * @return void
   */
  protected function calculateMargins(): void
  {
    $totalHeight = self::MAP_PANEL_HEIGHT + self::INFO_PANEL_HEIGHT;
    $this->leftMargin = max(0, intdiv(get_screen_width() - self::MENU_WIDTH, 2));
    $this->topMargin = max(0, intdiv(get_screen_height() - $totalHeight, 2));
  }

  /**
   * Builds the screen's windows.
   *
   * @return void
   */
  protected function initializeUI(): void
  {
    $this->borderPack = new DefaultBorderPack();
    $region = $this->getGameScene()->party->location->region;

    $this->mapPanel = new Window(
      $region !== '' ? $region : 'Map',
      'c:Cancel',
      new Vector2($this->leftMargin, $this->topMargin),
      self::MENU_WIDTH,
      self::MAP_PANEL_HEIGHT,
      $this->borderPack
    );

    $this->infoPanel = new Window(
      'Info',
      '',
      new Vector2($this->leftMargin, $this->topMargin + self::MAP_PANEL_HEIGHT),
      self::MENU_WIDTH,
      self::INFO_PANEL_HEIGHT,
      $this->borderPack
    );
  }

  /**
   * Redraws the screen.
   *
   * @return void
   */
  protected function refreshUI(): void
  {
    $this->mapPanel?->setContent($this->buildMapContent(self::MENU_WIDTH - 2, self::MAP_PANEL_HEIGHT - 2));
    $this->mapPanel?->render();
    $this->infoPanel?->setContent($this->buildInfoContent());
    $this->infoPanel?->render();
  }

  /**
   * Draws the region the way the world is laid out.
   *
   * @param int $width The panel's inner width.
   * @param int $height The panel's inner height.
   * @return string[] The rows to draw.
   */
  protected function buildMapContent(int $width, int $height): array
  {
    $currentId = $this->currentMapId();
    $placed = $this->visiblePlaces($currentId);

    if ($placed === []) {
      return array_pad([' This place is not on any map.'], $height, '');
    }

    $areas = RegionMap::areas();
    $cellWidth = $this->cellWidth(array_keys($placed), $areas);
    $step = $cellWidth + self::GUTTER;
    $canvas = array_fill(0, $height, array_fill(0, $width, ' '));
    $cells = [];
    $here = null;
    $this->blocked = [];

    foreach ($placed as $id => [$gridX, $gridY]) {
      $x = $gridX * $step + 1;
      $y = $gridY * self::ROW_SPACING + 1;

      if ($x + $cellWidth >= $width || $y >= $height) {
        continue;
      }

      $cells[$id] = ['x' => $x, 'y' => $y];
      $this->paint($canvas, $x, $y, $this->cellFor($id, $areas, $cellWidth));

      for ($column = $x; $column < $x + $cellWidth; $column++) {
        $this->blocked["{$y}:{$column}"] = true;
      }

      if ($id === $currentId) {
        $here = ['x' => $x, 'y' => $y];
      }
    }

    $this->paintLinks($canvas, $cells, $areas, $cellWidth);
    $this->paintCompass($canvas, $width);

    $rows = [];

    foreach ($canvas as $y => $row) {
      $line = rtrim(implode('', $row));

      // Highlighting is applied to the finished row: colour codes written onto
      // the canvas would take up cells of their own and shift everything drawn
      // after them.
      if ($here !== null && $here['y'] === $y) {
        $line = mb_substr($line, 0, $here['x'])
          . SelectionStyle::apply(mb_substr($line, $here['x'], $cellWidth))
          . mb_substr($line, $here['x'] + $cellWidth);
      }

      $rows[] = $line;
    }

    return array_slice($rows, 0, $height);
  }

  /**
   * Draws a compass in the corner, so the layout reads as a map.
   *
   * @param array<int, array<int, string>> $canvas The canvas.
   * @param int $width The panel's inner width.
   * @return void
   */
  protected function paintCompass(array &$canvas, int $width): void
  {
    $x = $width - 8;

    foreach (['  N  ', 'W ─ E', '  S  '] as $offset => $row) {
      $this->paint($canvas, $x, $offset, $row);
    }
  }

  /**
   * Builds the line under the map.
   *
   * @return string[] The info rows.
   */
  protected function buildInfoContent(): array
  {
    $location = $this->getGameScene()->party->location;
    $region = $location->region;
    $areas = RegionMap::inRegion($region);
    $visited = count(array_filter(
      $areas,
      fn(RegionArea $area): bool => $this->hasVisited($area->id)
    ));

    $exits = array_map(
      static fn(RegionArea $area): string => $area->region,
      RegionMap::exits($region)
    );

    return [
      sprintf(
        ' You are in %s. %d of %d places here found.',
        $location->name !== '' ? $location->name : 'an unknown place',
        $visited,
        max(count($areas), $visited)
      ),
      $exits === [] ? '' : sprintf(' Leads out to: %s.', implode(', ', $exits)),
    ];
  }

  /**
   * Returns the places worth drawing, and where each sits.
   *
   * Somewhere the party has never been and has no door to from anywhere they
   * have been is not on their map yet.
   *
   * @param string $currentId The map the player is on.
   * @return array<string, array{0: int, 1: int}> The visible places.
   */
  protected function visiblePlaces(string $currentId): array
  {
    $areas = RegionMap::areas();

    return array_filter(
      RegionMap::place($currentId),
      fn(array $position, string $id): bool => $this->hasVisited($id) || $this->isNextToVisited($id, $areas),
      ARRAY_FILTER_USE_BOTH
    );
  }

  /**
   * Determines whether a place sits one door from somewhere the party has been.
   *
   * @param string $id The map id.
   * @param array<string, RegionArea> $areas Every area.
   * @return bool True when the party has seen the door to it.
   */
  protected function isNextToVisited(string $id, array $areas): bool
  {
    foreach ($areas as $area) {
      if ($this->hasVisited($area->id) && array_key_exists($id, $area->links)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Determines whether the party has been somewhere.
   *
   * @param string $id The map id.
   * @return bool True when they have.
   */
  protected function hasVisited(string $id): bool
  {
    return $this->getGameScene()->gameState->hasVisitedMap($id);
  }

  /**
   * Returns the map the player is on.
   *
   * @return string The current map id.
   */
  protected function currentMapId(): string
  {
    return $this->getGameScene()->currentMapId;
  }

  /**
   * Sizes every cell to the longest name it has to hold.
   *
   * @param string[] $ids The map ids being drawn.
   * @param array<string, RegionArea> $areas Every area.
   * @return int The cell width.
   */
  protected function cellWidth(array $ids, array $areas): int
  {
    $longest = self::MIN_CELL_WIDTH;

    foreach ($ids as $id) {
      if ($this->hasVisited($id)) {
        $longest = max($longest, TerminalText::displayWidth($areas[$id]->name ?? $id) + 4);
      }
    }

    return min($longest, self::MAX_CELL_WIDTH);
  }

  /**
   * Renders one place.
   *
   * @param string $id The map id.
   * @param array<string, RegionArea> $areas Every area.
   * @param int $width The cell width.
   * @return string The cell.
   */
  protected function cellFor(string $id, array $areas, int $width): string
  {
    $name = $this->hasVisited($id)
      ? ($areas[$id]->name ?? $id)
      : self::UNKNOWN_NAME;

    $label = TerminalText::truncateToWidth($name, $width - 4);

    return sprintf('[%s]', TerminalText::padCenter($label, $width - 2));
  }

  /**
   * Draws the doors between places.
   *
   * @param array<int, array<int, string>> $canvas The canvas.
   * @param array<string, array{x: int, y: int}> $cells Where each place was drawn.
   * @param array<string, RegionArea> $areas Every area.
   * @param int $cellWidth The cell width.
   * @return void
   */
  protected function paintLinks(array &$canvas, array $cells, array $areas, int $cellWidth): void
  {
    $drawn = [];

    foreach ($cells as $id => $from) {
      foreach (array_keys($areas[$id]->links ?? []) as $link) {
        $to = $cells[$link] ?? null;
        $pair = $id < $link ? "{$id}|{$link}" : "{$link}|{$id}";

        // A door is drawn once, however many maps declare it.
        if ($to === null || isset($drawn[$pair])) {
          continue;
        }

        $drawn[$pair] = true;
        $this->connect($canvas, $from, $to, $cellWidth);
      }
    }
  }

  /**
   * Draws one door, from the edge of a place to the edge of its neighbour.
   *
   * Lines run through the gaps between places, never across them: a door drawn
   * over a name makes the name unreadable.
   *
   * @param array<int, array<int, string>> $canvas The canvas.
   * @param array{x: int, y: int} $from The place the door leaves.
   * @param array{x: int, y: int} $to The place it leads to.
   * @param int $cellWidth The cell width.
   * @return void
   */
  protected function connect(array &$canvas, array $from, array $to, int $cellWidth): void
  {
    $rightwards = $to['x'] > $from['x'];
    $downwards = $to['y'] > $from['y'];

    if ($from['y'] === $to['y']) {
      $left = $rightwards ? $from : $to;
      $right = $rightwards ? $to : $from;

      $this->paintHorizontal($canvas, $left['x'] + $cellWidth, $right['x'] - 1, $left['y']);

      return;
    }

    if ($from['x'] === $to['x']) {
      // Straight above or below: one run down the middle, through the gap.
      $this->paintVertical($canvas, $from['x'] + intdiv($cellWidth, 2), $from['y'], $to['y']);

      return;
    }

    // Diagonally placed: out of the side, along the gap, and back in.
    $startX = $rightwards ? $from['x'] + $cellWidth : $from['x'] - 1;
    $endX = $rightwards ? $to['x'] - 1 : $to['x'] + $cellWidth;
    $turnX = $rightwards ? min($startX + 2, $endX) : max($startX - 2, $endX);

    $step = $rightwards ? 1 : -1;
    $inner = $downwards ? [$from['y'] + 1, $to['y'] - 1] : [$to['y'] + 1, $from['y'] - 1];

    // The legs stop short of the turns, and the run between them covers only
    // the rows in between, so each turn lands on a cell of its own.
    $this->paintHorizontal($canvas, $startX, $turnX - $step, $from['y']);
    $this->paintVertical($canvas, $turnX, $inner[0], $inner[1]);
    $this->paintHorizontal($canvas, $turnX + $step, $endX, $to['y']);

    $this->stamp($canvas, $turnX, $from['y'], $this->corner($rightwards, $downwards, true));
    $this->stamp($canvas, $turnX, $to['y'], $this->corner($rightwards, $downwards, false));
  }

  /**
   * Returns the corner a turn draws.
   *
   * @param bool $rightwards Whether the door leads right.
   * @param bool $downwards Whether it leads down.
   * @param bool $leaving Whether this is the turn out of the first place.
   * @return string The corner.
   */
  protected function corner(bool $rightwards, bool $downwards, bool $leaving): string
  {
    return match (true) {
      $leaving && $rightwards => $downwards ? '┐' : '┘',
      $leaving => $downwards ? '┌' : '└',
      $rightwards => $downwards ? '└' : '┌',
      default => $downwards ? '┘' : '┐',
    };
  }

  /**
   * Writes one piece of track, merging it with whatever is already there.
   *
   * Several doors share the gaps between places, so a corner landing on a run
   * becomes a junction rather than replacing it.
   *
   * @param array<int, array<int, string>> $canvas The canvas.
   * @param int $x The column.
   * @param int $y The row.
   * @param string $piece The piece to write.
   * @return void
   */
  protected function stamp(array &$canvas, int $x, int $y, string $piece): void
  {
    if (! isset($canvas[$y][$x]) || isset($this->blocked["{$y}:{$x}"])) {
      return;
    }

    $existing = $canvas[$y][$x];
    $pointsDown = in_array($piece, ['┌', '┐'], true);

    $canvas[$y][$x] = match (true) {
      $existing === ' ', $existing === $piece => $piece,
      $existing === '─' => $pointsDown ? '┬' : '┴',
      $existing === '│' => in_array($piece, ['┐', '┘'], true) ? '┤' : '├',
      default => '┼',
    };
  }

  /**
   * Draws a run of track.
   *
   * @param array<int, array<int, string>> $canvas The canvas.
   * @param int $fromX The start column.
   * @param int $toX The end column.
   * @param int $y The row.
   * @return void
   */
  protected function paintHorizontal(array &$canvas, int $fromX, int $toX, int $y): void
  {
    for ($x = min($fromX, $toX); $x <= max($fromX, $toX); $x++) {
      $existing = $canvas[$y][$x] ?? null;

      if ($existing === null || $existing === '│' || isset($this->blocked["{$y}:{$x}"])) {
        continue;
      }

      $canvas[$y][$x] = $existing === ' ' ? '─' : $existing;
    }
  }

  /**
   * Draws a turn between two rows.
   *
   * Several doors leave the same place, so their turns share a column and have
   * to merge into one another rather than overwrite: a run passing through
   * becomes a branch, and a corner another line passes through becomes a tee.
   *
   * @param array<int, array<int, string>> $canvas The canvas.
   * @param int $x The column.
   * @param int $fromY The start row.
   * @param int $toY The end row.
   * @return void
   */
  protected function paintVertical(array &$canvas, int $x, int $fromY, int $toY): void
  {
    for ($y = min($fromY, $toY); $y <= max($fromY, $toY); $y++) {
      if (! isset($canvas[$y][$x]) || isset($this->blocked["{$y}:{$x}"])) {
        continue;
      }

      $canvas[$y][$x] = match ($canvas[$y][$x]) {
        ' ' => '│',
        '─' => '┼',
        default => $canvas[$y][$x],
      };
    }
  }

  /**
   * Writes text onto the canvas.
   *
   * @param array<int, array<int, string>> $canvas The canvas.
   * @param int $x The column to start at.
   * @param int $y The row.
   * @param string $text The text to write.
   * @return void
   */
  protected function paint(array &$canvas, int $x, int $y, string $text): void
  {
    if (! isset($canvas[$y])) {
      return;
    }

    // A highlighted cell carries its own escape codes, so it is written as one
    // unit rather than one cell per column.
    foreach (mb_str_split($text) as $offset => $character) {
      if (isset($canvas[$y][$x + $offset])) {
        $canvas[$y][$x + $offset] = $character;
      }
    }
  }
}
