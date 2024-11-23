<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
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
  public function enter(): void
  {
    parent::enter();
    Console::clear();

    // Render the field.
  }
  /**
   * @inheritDoc
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
  }
}