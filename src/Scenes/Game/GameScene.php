<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Exceptions\IchilotoException;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\States\CutsceneState;
use Ichiloto\Engine\Scenes\Game\States\DialogueState;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\MapState;
use Ichiloto\Engine\Scenes\Game\States\OverworldState;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\LocationHUDWindow;
use Ichiloto\Engine\Util\Debug;
use Override;

/**
 * Class GameScene. Represents the game scene.
 *
 * @package Ichiloto\Engine\Scenes\Game
 */
class GameScene extends AbstractScene
{
  /**
   * The state of the scene.
   *
   * @var GameSceneState|null
   */
  protected ?GameSceneState $state = null;
  /**
   * The scene state context.
   *
   * @var SceneStateContext|null
   */
  protected ?SceneStateContext $sceneStateContext = null;
  /**
   * The configuration of the game.
   *
   * @var GameConfig|null
   */
  protected ?GameConfig $config = null;
  /**
   * The cutscene state.
   *
   * @var CutsceneState|null
   */
  protected(set) ?CutsceneState $cutsceneState = null;
  /**
   * The dialogue state.
   *
   * @var DialogueState|null
   */
  protected(set) ?DialogueState $dialogueState = null;
  /**
   * The field state.
   *
   * @var FieldState|null
   */
  protected(set) ?FieldState $fieldState = null;
  /**
   * The main menu state.
   *
   * @var MainMenuState|null
   */
  protected(set) ?MainMenuState $mainMenuState = null;
  /**
   * The item menu state.
   *
   * @var ItemMenuState|null
   */
  protected(set) ?ItemMenuState $itemMenuState = null;
  /**
   * The map state.
   *
   * @var MapState|null
   */
  protected(set) ?MapState $mapState = null;
  /**
   * The overworld state.
   *
   * @var OverworldState|null
   */
  protected(set) ?OverworldState $overworldState = null;
  /**
   * The shop state.
   *
   * @var ShopState|null
   */
  protected(set) ?ShopState $shopState = null;
  /**
   * The map manager.
   *
   * @var MapManager|null
   */
  protected(set) ?MapManager $mapManager = null;
  /**
   * The player.
   *
   * @var Player|null
   */
  protected(set) ?Player $player = null;
  /**
   * The location HUD window.
   *
   * @var LocationHUDWindow|null
   */
  protected(set) ?LocationHUDWindow $locationHUDWindow;
  /**
   * @var Party|null The party.
   */
  protected(set) ?Party $party = null;

  /**
   * Sets the state of the scene.
   *
   * @param GameSceneState $state The state.
   * @return void
   */
  public function setState(GameSceneState $state): void
  {
    $this->sceneStateContext = new SceneStateContext($this, $this->sceneStateContext);
    $this->state?->exit();
    $this->state = $state;
    $this->state->enter();
  }

  /**
   * Configures the game scene.
   *
   * @param GameConfig $config The game configuration.
   * @return void
   * @throws IchilotoException
   * @throws NotFoundException If the map is not found.
   */
  public function configure(GameConfig $config): void
  {
    $this->mapManager = MapManager::getInstance($this->getGame(), $this);

    $this->initializeGameSceneStates();

    $this->locationHUDWindow = new LocationHUDWindow(new Vector2(0, 0), MovementHeading::NONE);
    $this->uiManager->uiElements->add($this->locationHUDWindow);

    $this->config = $config;

    $this->player = new Player(
      $this,
      'Player',
      $this->config->playerPosition,
      $this->config->playerShape,
      $this->config->playerSprite,
      $this->config->playerHeading
    );
    $this->player->activate();
    $this->party = $this->config->party;

    $this->loadMap("{$this->config->mapId}.php");
    $this->setState($this->fieldState);
    usleep(400);
    $this->locationHUDWindow->updateDetails($this->player->position, $this->player->heading);
    $this->locationHUDWindow->render();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    parent::update();
    $this->state->execute($this->sceneStateContext);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function resume(): void
  {
    parent::resume();
    $this->state->resume();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function suspend(): void
  {
    parent::suspend();
    $this->state->suspend();
  }

  /**
   * Loads the map.
   *
   * @param string $mapFilename The map filename.
   * @return void
   * @throws NotFoundException If the map is not found.
   * @throws IchilotoException If the map cannot be loaded.
   */
  public function loadMap(string $mapFilename): void
  {
    $this->mapManager->loadMap($mapFilename);
  }

  /**
   * Transfers the player to the destination map.
   *
   * @param Location $location The destination location.
   * @return void
   * @throws IchilotoException If the map cannot be loaded.
   * @throws NotFoundException If the map is not found.
   */
  public function transferPlayer(Location $location): void
  {
    Debug::info("Transferring player to $location->mapFilename... at {$location->playerPosition}");
    $this->loadMap($location->mapFilename);

    $this->player->position->x = $location->playerPosition->x;
    $this->player->position->y = $location->playerPosition->y;
    if ($location->playerSprite) {
      $this->player->sprite = $location->playerSprite;
    }
    $this->player->render();

    $this->locationHUDWindow->updateDetails($this->player->position, $this->player->heading);
    $this->locationHUDWindow->render();
    Debug::info("Player transferred to $location->mapFilename... at {$this->player->position}");
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function renderBackgroundTile(int $x, int $y): void
  {
    $this->mapManager->renderBackgroundTile($x, $y);
  }

  /**
   * Initializes the game scene states.
   *
   * @return void
   */
  public function initializeGameSceneStates(): void
  {
    $this->sceneStateContext = new SceneStateContext($this);
    $this->cutsceneState = new CutsceneState($this->sceneStateContext);
    $this->dialogueState = new DialogueState($this->sceneStateContext);
    $this->fieldState = new FieldState($this->sceneStateContext);
    $this->mainMenuState = new MainMenuState($this->sceneStateContext);
    $this->itemMenuState = new ItemMenuState($this->sceneStateContext);
    $this->mapState = new MapState($this->sceneStateContext);
    $this->overworldState = new OverworldState($this->sceneStateContext);
    $this->shopState = new ShopState($this->sceneStateContext);
  }
}