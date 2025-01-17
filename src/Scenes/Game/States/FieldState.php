<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Exception;
use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

/**
 * This state serves as the backbone of the game, managing the player's exploration experience.
 *
 * Key Features:
 * - Player Character Movement: Supports walking, running, and interacting with the environment. Movement can be grid-based (classic JRPG style) or free.
 * - NPC Interactions: Handles initiating conversations with NPCs or triggering quest-related dialogue.
 * - Collision Detection: Prevents the player from walking through walls, objects, or unpassable terrain.
 * - Event Triggers: Detects and activates events, such as transitioning to battles, entering buildings, or starting cutscenes.
 *
 * Interactions with Other States:
 * - Transitions to BattleState when a random or scripted encounter occurs.
 * - Transitions to DialogueState when interacting with NPCs or objects with dialogue.
 * - Transitions to MenuState when the player opens the in-game menu.
 *
 * @package Ichiloto\Engine\Scenes\Game\States
 */
class FieldState extends GameSceneState
{
  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    parent::enter();
    $this->renderTheField();
    $this->getGameScene()->locationHUDWindow->activate();
  }

  /**
   * @inheritDoc
   * @param SceneStateContext|null $context
   * @throws NotFoundException If the scene is not set.
   * @throws OutOfBounds If the player is out of bounds.
   * @throws Exception If a failure occurs when trying to quit the game.
   */
  public function execute(?SceneStateContext $context = null): void
  {
    $scene = $this->context->getScene();
    assert($scene instanceof GameScene);

    $this->handleActions($scene);
    $this->handleNavigation($scene);
  }

  /**
   * Renders the field.
   *
   * @return void
   */
  public function renderTheField(): void
  {
    Console::clear();
    $this->getGameScene()->mapManager->render();
    $this->getGameScene()->player->render();
    $this->getGameScene()->locationHUDWindow->render();
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->renderTheField();
  }

  /**
   * Handles the player's navigation.
   *
   * @param GameScene $scene
   * @return void
   * @throws NotFoundException
   * @throws OutOfBounds
   */
  protected function handleNavigation(GameScene $scene): void
  {
    $h = Input::getAxis(AxisName::HORIZONTAL);
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($h) || abs($v)) {
      $scene->player->move(new Vector2(intval($h), intval($v)), $this->getGameScene()->camera);
    }
  }

  /**
   * Handles the actions of the player.
   *
   * @param GameScene $scene The game scene.
   * @return void
   * @throws NotFoundException
   * @throws Exception
   */
  protected function handleActions(GameScene $scene): void
  {
    if (
      Input::isButtonDown("quit") &&
      confirm(
        get_message("confirm.quit", "Are you sure you want to quit?"),
        config(ProjectConfig::class, 'vocab.game.shutdown', 'Exit Game'))) {
      $scene->getGame()->quit();
    }

    if (Input::isButtonDown("menu")) {
      $this->setState($scene->mainMenuState);
    }

    if (Input::isButtonDown("action")) {
      $scene->player->interact();
    }

    if (Input::isAnyKeyPressed([KeyCode::C, KeyCode::c])) {
      Debug::log("Selected choice: " . select("Choose an option", ["Option 1", "Option 2", "Option 3"]));
    }

    if (Input::isButtonDown("notify")) {
      notify(
        $this->getGameScene()->getGame(),
        NotificationChannel::ACHIEVEMENT,
        'Achievement unlocked',
        '100G - New Character Created'
      );
    }

    if (Input::isAnyKeyPressed([KeyCode::G, KeyCode::g])) {
      $scene->sceneManager->loadGameOverScene();
    }

    if (Input::isAnyKeyPressed([KeyCode::B, KeyCode::b])) {
      $battleEvents = [];
      $troopNames = [
        'Rat + Bat',
        'Bat x 2'
      ];
      $troopNameKey = array_rand($troopNames);
      $troop = get_troop($troopNames[$troopNameKey]);
      $this->getGameScene()->sceneManager->loadBattleScene($this->getGameScene()->party, $troop, $battleEvents);
    }

    if (Input::isAnyKeyPressed([KeyCode::M, KeyCode::m])) {
      show_text(
        'Hello, player and welcome to the world of Ichiloto!',
        'Squall',
        '',
        WindowPosition::BOTTOM,
        charactersPerSecond: 20
      );
    }
  }
}