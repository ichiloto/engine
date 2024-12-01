<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneStateContext;

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
  }

  /**
   * @inheritDoc
   * @param SceneStateContext|null $context
   * @throws NotFoundException If the scene is not set.
   * @throws OutOfBounds If the player is out of bounds.
   */
  public function execute(?SceneStateContext $context = null): void
  {
    $scene = $this->context->getScene();
    assert($scene instanceof GameScene);

    if (Input::isButtonDown("quit")) {
      $scene->getGame()->quit();
    }

    if (Input::isButtonDown("menu")) {
      $this->setState($scene->mainMenuState);
    }

    $h = Input::getAxis(AxisName::HORIZONTAL);
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($h) || abs($v)) {
      $scene->player->move(new Vector2(intval($h), intval($v)));
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
}