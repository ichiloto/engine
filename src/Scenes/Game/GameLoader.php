<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\SystemData;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use RuntimeException;

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
   * @var ItemStore The item store.
   */
  protected ItemStore $itemStore;

  /**
   * GameLoader constructor.
   *
   * @param Game $game The game.
   */
  private function __construct(protected Game $game)
  {
    $itemStore = ConfigStore::get(ItemStore::class);

    if (! $itemStore instanceof ItemStore) {
      throw new RuntimeException('Item store not found.');
    }
    $this->itemStore = $itemStore;
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
   * @throws RequiredFieldException Thrown when a required field is missing.
   */
  public function loadNewGame(): GameConfig
  {
    $systemData = asset('Data/system.php', true) ?? throw new RuntimeException('System data not found.');
    if (!is_array($systemData)) {
      throw new RuntimeException('System data is not an array.');
    }
    $systemData = SystemData::fromArray($systemData);
    $startingParty = [];

    foreach ($systemData->startingParty as $member) {
      $characterData = asset("Data/Actors/$member.php", true);
      if (! is_array($characterData) ) {
        throw new RuntimeException("Character data for $member is not an array.");
      }
      $startingParty[] = $characterData['data'];
    }
    $party = Party::fromArray($startingParty);
    if ($systemData->currency->amount) {
      $party->accountBalance = $systemData->currency->amount;
    }
    $party->inventory->addItems(...$this->itemStore->load($systemData->startingInventory));

    $playerPosition = new Vector2($systemData->startingPositions->player->spawnPoint->x, $systemData->startingPositions->player->spawnPoint->y);
    return new GameConfig(
      mapId: $systemData->startingPositions->player->destinationMap,
      party: $party,
      playerPosition: $playerPosition,
      playerShape: new Rect(0, 0, 1, 1),
      playerHeading: match($systemData->startingPositions->player->spawnSprite) {
        ['^'] => MovementHeading::NORTH,
        ['v'] => MovementHeading::SOUTH,
        ['<'] => MovementHeading::WEST,
        ['>'] => MovementHeading::EAST,
        default => MovementHeading::NONE,
      },
      playerStats: [],
      events: [],
      playerSprite: $systemData->startingPositions->player->spawnSprite,
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
    $systemData = new SystemData(
      'Last Legend',
      (object)['name' => 'Gold', 'symbol' => 'G', 'amount' => 1000],
      ['hero'],
      [
        ['item' => 'S-Potion', 'quantity' => 5],
        ['item' => 'M-Potion', 'quantity' => 3],
      ],
      (object)['player' => (object)['destinationMap' => 'happyville/home', 'spawnPoint' => (object)['x' => 4, 'y' => 5], 'spawnSprite' => ['v']]],
    );

    $party = new Party();
    if ($systemData->currency->amount) {
      $party->accountBalance = $systemData->currency->amount;
    }
    $party->location = new PartyLocation();
    $playerPosition = new Vector2(
      $systemData->startingPositions->player->spawnPoint->x,
      $systemData->startingPositions->player->spawnPoint->y
    );
    $savedData = [
      'mapId' => $systemData->startingPositions->player->destinationMap,
      'party' => $party,
      'playerPosition' => $playerPosition,
      'playerShape' => new Rect(0, 0, 1, 1),
      'playerHeading' => $systemData->startingPositions->player->heading,
      'playerStats' => [],
      'events' => [],
      'playerSprite' => $systemData->startingPositions->player->spawnSprite,
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