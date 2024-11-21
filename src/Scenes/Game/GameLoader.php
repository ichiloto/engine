<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\SingletonInterface;
use Ichiloto\Engine\Core\Vector2;

/**
 * Class GameLoader. Loads a game.
 *
 * @package Ichiloto\Engine\Scenes\Game
 */
class GameLoader
{
  /**
   * The instance of this singleton.
   * @var GameLoader|null
   */
  protected static ?GameLoader $instance = null;

  /**
   * GameLoader constructor.
   */
  private function __construct(protected Game $game)
  {
  }

  /**
   * Returns the instance of this singleton.
   *
   * @param Game $game The game.
   */
  public static function getInstance(Game $game): self
  {
    if (self::$instance === null) {
      self::$instance = new GameLoader($game);
    }

    return self::$instance;
  }

  /**
   * Loads a new game.
   *
   * @return GameConfig The game configuration.
   */
  public function loadNewGame(): GameConfig
  {
    return new GameConfig(
      mapId: 'happyville/home',
      playerPosition: new Vector2(10, 5),
      playerHeading: MovementHeading::NONE,
      playerStats: [],
      events: [],
    );
  }

  /**
   * Loads a saved game.
   *
   * @param string $saveFilePath The path to the saved game file.
   * @return GameConfig The game configuration.
   */
  public function loadSavedGame(string $saveFilePath): GameConfig
  {
    // Load game data from a saved file
    $savedData = [
      'mapId' => 'happyville/home',
      'playerPosition' => new Vector2(10, 5),
      'playerHeading' => MovementHeading::NONE,
      'playerStats' => [],
      'events' => [],
    ];

    return new GameConfig(
      mapId: $savedData['mapId'],
      playerPosition: $savedData['playerPosition'],
      playerHeading: $savedData['playerHeading'],
      playerStats: $savedData['playerStats'],
      events: $savedData['events'],
    );
  }
}