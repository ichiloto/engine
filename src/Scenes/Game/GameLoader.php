<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Util\Debug;

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
    $systemData = asset('Data/system.php', true) ?? ['startingParty'];
    assert(is_array($systemData));
    $startingParty = [];

    foreach ($systemData['startingParty'] as $member) {
      $characterData = asset("Data/Actors/$member.php", true);
      assert(is_array($characterData));
      $startingParty[] = $characterData['data'];
    }
    $party = Party::fromArray($startingParty);
    return new GameConfig(
      mapId: 'happyville/home',
      party: $party,
      playerPosition: new Vector2(4, 5),
      playerShape: new Rect(0, 0, 1, 1),
      playerHeading: MovementHeading::NONE,
      playerStats: [],
      events: [],
      playerSprite: ['v']
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
    $party = new Party();
    $party->location = new PartyLocation();
    $savedData = [
      'mapId' => 'happyville/home',
      'party' => $party,
      'playerPosition' => new Vector2(4, 5),
      'playerShape' => new Rect(0, 0, 1, 1),
      'playerHeading' => MovementHeading::NONE,
      'playerStats' => [],
      'events' => [],
      'playerSprite' => ['v'],
    ];

    return new GameConfig(
      mapId: $savedData['mapId'],
      party: $savedData['party'],
      playerPosition: $savedData['playerPosition'],
      playerShape: $savedData['playerShape'],
      playerHeading: $savedData['playerHeading'],
      playerStats: $savedData['playerStats'],
      events: $savedData['events'],
      playerSprite: $savedData['playerSprite'],
    );
  }
}