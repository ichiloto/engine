<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Time;
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
        $this->getGameScene()->locationHUDWindow->activate();
        $this->renderTheField();
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
        $this->getGameScene()->player->renderEventCues();
        $this->getGameScene()->npcManager?->render();
        $this->getGameScene()->player->render();
        $this->getGameScene()->locationHUDWindow->render();
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

        $scene->reconcileFieldPresentation();

        // A story event owns field input while it is running. Its pending
        // dialogue, timer, route, transfer, or battle continuation advances
        // once, then the frame returns without reopening actions or moving
        // the player underneath it.
        if ($scene->hasUnstableEventSession()) {
            $scene->updateEventSession(Time::getDeltaTime());
            return;
        }

        $this->handleActions($scene);

        if ($scene->hasUnstableEventSession()) {
            return;
        }

        // An action may have handed the screen to another state (the menu, the
        // map). Moving the player or wandering an NPC now would draw the field
        // over whatever that state just rendered.
        if ($scene->state !== $this) {
            return;
        }

        $this->handleNavigation($scene);

        if ($scene->hasUnstableEventSession()) {
            return;
        }

        $scene->npcManager?->update();
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
            play_sound(SystemSound::CONFIRM);
            $this->setState($scene->mainMenuState);
        }

        if (Input::isButtonDown("action")) {
            $scene->player->interact();
        }

        if (Input::isButtonDown("map")) {
            $this->showInGameMap();
        }

        if (Input::isButtonDown("skit")) {
            $scene->skitManager?->playNextAvailableSkit();
        }

        // F5 quick-saves from the field, the way a PC RPG player expects.
        if (Input::isKeyDown(KeyCode::F5)) {
            try {
                $scene->sceneManager->saveManager->quickSave($scene);
                play_sound(SystemSound::SAVE);
                notify(
                    $scene->getGame(),
                    NotificationChannel::SYSTEM,
                    'Quick saved',
                    $scene->party?->location?->name ?? '',
                    NotificationDuration::SHORT
                );
            } catch (\Throwable $exception) {
                Debug::warn(sprintf('Quick save failed: %s', $exception->getMessage()));
                alert($exception->getMessage(), 'Quick Save Unavailable');
            }
        }

    }

    /**
     * Displays the in-game map.
     *
     * @return void
     */
    public function showInGameMap(): void
    {
        $scene = $this->context->getScene();
        assert($scene instanceof GameScene);

        play_sound(SystemSound::CONFIRM);
        $this->setState($scene->mapState);
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
     * @inheritDoc
     */
    public function resume(): void
    {
        $this->getGameScene()->locationHUDWindow->activate();
        $this->renderTheField();
    }
}
