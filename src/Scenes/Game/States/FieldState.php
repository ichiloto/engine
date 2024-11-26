<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneStateContext;
use function Termwind\parse;

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
    Console::clear();

    // Render the field.
    $this->getGameScene()->mapManager->render();
    $this->getGameScene()->player->render();
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

    if (InputManager::isAnyKeyPressed([KeyCode::Q, KeyCode::q])) {
      $scene->getGame()->quit();
    }

    if (InputManager::isAnyKeyPressed([KeyCode::ESCAPE])) {
      $this->setState($scene->mainMenuState);
    }

    $h = Input::getAxis(AxisName::HORIZONTAL);
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($h) || abs($v)) {
      $scene->player->move(new Vector2(intval($h), intval($v)));
    }
  }
}