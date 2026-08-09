<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * The in-game map: the current map drawn whole, with the player on it.
 *
 * A map is usually larger than the terminal, which is the reason to have this
 * screen at all: it samples the tile map down until it fits, so the player can
 * see the shape of the place they are standing in and where they are in it.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class MapState extends GameSceneState
{
  protected const int MAP_WIDTH = 110;
  protected const int MAP_PANEL_HEIGHT = 28;
  protected const int INFO_PANEL_HEIGHT = 4;
  protected const string PLAYER_MARKER = '@';

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
    $this->leftMargin = max(0, intdiv(get_screen_width() - self::MAP_WIDTH, 2));
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
    $location = $this->getGameScene()->party->location;

    $this->mapPanel = new Window(
      trim(sprintf('%s  |  %s', $location->name, $location->region), ' |'),
      'c:Cancel',
      new Vector2($this->leftMargin, $this->topMargin),
      self::MAP_WIDTH,
      self::MAP_PANEL_HEIGHT,
      $this->borderPack
    );

    $this->infoPanel = new Window(
      'Info',
      '',
      new Vector2($this->leftMargin, $this->topMargin + self::MAP_PANEL_HEIGHT),
      self::MAP_WIDTH,
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
    $innerWidth = self::MAP_WIDTH - 2;
    $innerHeight = self::MAP_PANEL_HEIGHT - 2;

    $this->mapPanel?->setContent($this->buildMapContent($innerWidth, $innerHeight));
    $this->mapPanel?->render();

    $position = $this->getGameScene()->player?->position;

    $this->infoPanel?->setContent([
      sprintf(' You are at %s, marked %s.', $position ? "({$position->x}, {$position->y})" : 'your last position', self::PLAYER_MARKER),
      '',
    ]);
    $this->infoPanel?->render();
  }

  /**
   * Samples the tile map down to the panel and marks the player on it.
   *
   * Sampling rather than averaging keeps the map's own art: a wall stays the
   * character the project drew, just with fewer of them.
   *
   * @param int $width The panel's inner width.
   * @param int $height The panel's inner height.
   * @return string[] The rows to draw.
   */
  protected function buildMapContent(int $width, int $height): array
  {
    $tileMap = $this->getGameScene()->mapManager?->tileMap ?? [];

    if ($tileMap === []) {
      return array_pad([' No map is loaded.'], $height, '');
    }

    $mapHeight = count($tileMap);
    $mapWidth = array_reduce($tileMap, static fn(int $carry, array $row): int => max($carry, count($row)), 0);
    $horizontalStep = max(1, (int)ceil($mapWidth / $width));
    $verticalStep = max(1, (int)ceil($mapHeight / $height));

    $position = $this->getGameScene()->player?->position;
    $playerColumn = $position ? intdiv($position->x, $horizontalStep) : null;
    $playerRow = $position ? intdiv($position->y, $verticalStep) : null;

    $rows = [];

    for ($row = 0; $row * $verticalStep < $mapHeight; $row++) {
      $line = '';

      for ($column = 0; $column * $horizontalStep < $mapWidth; $column++) {
        $line .= $row === $playerRow && $column === $playerColumn
          ? self::PLAYER_MARKER
          : $this->sampleTile($tileMap, $column * $horizontalStep, $row * $verticalStep, $horizontalStep, $verticalStep);
      }

      $line = TerminalText::truncateToWidth($line, $width);

      // Highlighted after truncation, so the marker is unmistakable even when
      // the map's own art uses the same character somewhere else.
      if ($row === $playerRow && $playerColumn !== null && $playerColumn < $width) {
        $line = mb_substr($line, 0, $playerColumn)
          . SelectionStyle::apply(self::PLAYER_MARKER)
          . mb_substr($line, $playerColumn + 1);
      }

      $rows[] = $line;
    }

    return array_pad(array_slice($rows, 0, $height), $height, '');
  }

  /**
   * Picks the tile that best represents a sampled block.
   *
   * The first non-blank tile in the block wins, so a thin wall running through
   * an otherwise empty block still shows up instead of being sampled away.
   *
   * @param array<int, string[]> $tileMap The tile map.
   * @param int $x The block's left edge.
   * @param int $y The block's top edge.
   * @param int $horizontalStep The block width.
   * @param int $verticalStep The block height.
   * @return string The representative tile.
   */
  protected function sampleTile(array $tileMap, int $x, int $y, int $horizontalStep, int $verticalStep): string
  {
    for ($row = $y; $row < $y + $verticalStep; $row++) {
      for ($column = $x; $column < $x + $horizontalStep; $column++) {
        $tile = $tileMap[$row][$column] ?? '';

        if ($tile !== '' && trim($tile) !== '') {
          return mb_substr($tile, 0, 1);
        }
      }
    }

    return ' ';
  }
}
