<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\States\CutsceneState;
use Ichiloto\Engine\Scenes\Game\States\DialogueState;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\MapState;
use Ichiloto\Engine\Scenes\Game\States\OverworldState;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Scenes\SceneStateContext;

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
   */
  public function configure(GameConfig $config): void
  {
    $this->mapManager = MapManager::getInstance($this->getGame());
    $this->sceneStateContext = new SceneStateContext($this);
    $this->cutsceneState = new CutsceneState($this->sceneStateContext);
    $this->dialogueState = new DialogueState($this->sceneStateContext);
    $this->fieldState = new FieldState($this->sceneStateContext);
    $this->mainMenuState = new MainMenuState($this->sceneStateContext);
    $this->mapState = new MapState($this->sceneStateContext);
    $this->overworldState = new OverworldState($this->sceneStateContext);
    $this->shopState = new ShopState($this->sceneStateContext);

    $this->config = $config;
    $this->loadMap("{$this->config->mapId}.php");
    $this->player = new Player(
      $this,
      'Player',
      $this->config->playerPosition,
      $this->config->playerShape,
      $this->config->playerSprite
    );
    $this->setState($this->fieldState);
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    parent::update();
    $this->state->execute($this->sceneStateContext);
  }

  public function loadMap(string $mapFilename): void
  {
    $this->mapManager->loadMap($mapFilename);
  }
}