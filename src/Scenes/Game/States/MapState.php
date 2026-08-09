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
   * Draws the region as connected places.
   *
   * @param int $width The panel's inner width.
   * @param int $height The panel's inner height.
   * @return string[] The rows to draw.
   */
  protected function buildMapContent(int $width, int $height): array
  {
    $currentId = $this->currentMapId();
    $columns = $this->visibleColumns($currentId);

    if ($columns === []) {
      return array_pad([' This place is not on any map.'], $height, '');
    }

    $areas = RegionMap::areas();
    $cellWidth = $this->cellWidth($columns, $areas);
    $canvas = array_fill(0, $height, array_fill(0, $width, ' '));
    $positions = [];
    $here = null;

    foreach ($columns as $columnIndex => $column) {
      $x = $columnIndex * ($cellWidth + self::GUTTER) + 1;

      foreach (array_values($column) as $rowIndex => $id) {
        $y = $rowIndex * self::ROW_SPACING + 1;

        if ($x + $cellWidth >= $width || $y >= $height) {
          continue;
        }

        $positions[$id] = ['x' => $x, 'y' => $y];
        $this->paint($canvas, $x, $y, $this->cellFor($id, $areas, $cellWidth));

        if ($id === $currentId) {
          $here = ['x' => $x, 'y' => $y];
        }
      }
    }

    $this->paintLinks($canvas, $positions, $areas, $cellWidth);

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
   * Returns the columns worth drawing.
   *
   * A place the party has never been and has no route to from anywhere they
   * have been is not on their map yet.
   *
   * @param string $currentId The map the player is on.
   * @return array<int, string[]> The map ids, by column.
   */
  protected function visibleColumns(string $currentId): array
  {
    $areas = RegionMap::areas();
    $columns = [];

    foreach (RegionMap::layout($currentId) as $column) {
      $visible = array_values(array_filter(
        $column,
        fn(string $id): bool => $this->hasVisited($id) || $this->isNextToVisited($id, $areas)
      ));

      if ($visible !== []) {
        $columns[] = $visible;
      }
    }

    return $columns;
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
      if ($this->hasVisited($area->id) && in_array($id, $area->links, true)) {
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
   * @param array<int, string[]> $columns The laid-out map ids.
   * @param array<string, RegionArea> $areas Every area.
   * @return int The cell width.
   */
  protected function cellWidth(array $columns, array $areas): int
  {
    $longest = self::MIN_CELL_WIDTH;

    foreach ($columns as $column) {
      foreach ($column as $id) {
        if (! $this->hasVisited($id)) {
          continue;
        }

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
   * @param array<int, array<int, string>> $canvas The canvas, by row.
   * @param array<string, array{x: int, y: int}> $positions Where each place was drawn.
   * @param array<string, RegionArea> $areas Every area.
   * @param int $cellWidth The cell width.
   * @return void
   */
  protected function paintLinks(array &$canvas, array $positions, array $areas, int $cellWidth): void
  {
    foreach ($positions as $id => $from) {
      foreach (($areas[$id]->links ?? []) as $link) {
        $to = $positions[$link] ?? null;

        if ($to === null || $to['x'] <= $from['x']) {
          continue;
        }

        $startX = $from['x'] + $cellWidth;
        $endX = $to['x'] - 1;
        $turnX = min($endX, $startX + intdiv(max(1, $endX - $startX), 2));

        $this->paintHorizontal($canvas, $startX, $turnX, $from['y']);
        $this->paintVertical($canvas, $turnX, $from['y'], $to['y']);
        $this->paintHorizontal($canvas, $turnX, $endX, $to['y']);
      }
    }
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

      if ($existing === null || $existing === '│') {
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
    if ($fromY === $toY) {
      if (isset($canvas[$fromY][$x]) && $canvas[$fromY][$x] === ' ') {
        $canvas[$fromY][$x] = '─';
      }

      return;
    }

    $down = $toY > $fromY;

    for ($y = min($fromY, $toY) + 1; $y < max($fromY, $toY); $y++) {
      if (! isset($canvas[$y][$x])) {
        continue;
      }

      $canvas[$y][$x] = match ($canvas[$y][$x]) {
        ' ' => '│',
        '─' => '┼',
        '└', '┌', '├' => '├',
        default => $canvas[$y][$x],
      };
    }

    if (isset($canvas[$fromY][$x])) {
      $canvas[$fromY][$x] = match ($canvas[$fromY][$x]) {
        '─', '┬', '┴', '┼' => $down ? '┬' : '┴',
        default => $down ? '┐' : '┘',
      };
    }

    if (isset($canvas[$toY][$x])) {
      $canvas[$toY][$x] = match ($canvas[$toY][$x]) {
        '│', '└', '┌', '├' => '├',
        default => $down ? '└' : '┌',
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
