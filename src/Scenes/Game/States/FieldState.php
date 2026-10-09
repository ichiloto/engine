<?php

namespace Ichiloto\Engine\Scenes\Game\States;

use Ichiloto\Engine\Localization\Vocabulary;

use Exception;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\OutOfBounds;
use Ichiloto\Engine\Field\PlayerWalk;
use Ichiloto\Engine\Field\SkitPrompt;
use Ichiloto\Engine\Field\SkitPromptPresentation;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasOverlayProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneStateContext;
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
class FieldState extends GameSceneState implements CanvasOverlayProviderInterface
{
    private ?PlayerWalk $walk = null;
    private ?SkitPromptPresentation $skitPromptPresentation = null;

    private function getSkitPrompt(): ?SkitPrompt
    {
        if (!isset($this->context)) { return null; }
        $scene = $this->getGameScene();
        $prompt = $scene->skitManager?->getAvailablePrompt();
        if ($prompt === null) { return null; }
        return $scene->isStopping || $scene->state !== $this
            || $scene->sceneManager->currentScene !== $scene || $scene->sceneManager->hasSceneTransition()
            || $scene->hasUnstableEventSession() || $scene->getUI()->getActivePresentations() !== []
            ? null : $prompt;
    }

    public function renderPresentationOverlay(): void
    {
        ($this->skitPromptPresentation ??= new SkitPromptPresentation())->renderTerminal($this->getSkitPrompt(),
            ActionHints::resolve('skit', 'Watch skit'));
    }

    public function getPresentationOverlay(int $width, int $height): ?PresentationCanvas
    {
        $prompt = $this->getSkitPrompt();
        if ($prompt === null) { return null; }
        $runtime = $this->getGameScene()->getGame()->getRendererRuntime();
        return $runtime === null ? null
            : ($this->skitPromptPresentation ??= new SkitPromptPresentation())->getCanvas($prompt,
                ActionHints::resolve('skit', 'Watch skit'), $runtime, $width, $height);
    }

    public function getExcludedOverlayLayers(): array { return [SkitPromptPresentation::LAYER]; }

    public function exit(): void { Console::removeOverlay(SkitPromptPresentation::LAYER); }
    public function suspend(): void { Console::removeOverlay(SkitPromptPresentation::LAYER); }

    /** Held walking, for input that reports held keys. */
    protected PlayerWalk $playerWalk {
        get => $this->walk ??= new PlayerWalk();
    }

    /** Stop held walking; keys pressed so far must be pressed again. */
    public function cancelWalking(): void
    {
        $this->walk?->cancel();
    }

    /**
     * @inheritDoc
     */
    public function enter(): void
    {
        parent::enter();
        $this->getGameScene()->locationHUDWindow->activate();
        $this->renderTheField(forceFullRepaint: true);
    }

    /**
     * Renders the field.
     *
     * @return void
     */
    public function renderTheField(bool $forceFullRepaint = false): void
    {
        $this->getGameScene()->synchronizeFieldViewport();
        Console::recomposeFrame(function (): void {
            $this->getGameScene()->mapManager->render();
            $this->getGameScene()->player->renderEventCues();
            $this->getGameScene()->npcManager?->render();
            $this->getGameScene()->cinematicStage?->render();
            $this->getGameScene()->player->render();
            $this->getGameScene()->getUI()->render();
            $this->getGameScene()->cinematicPresentation?->render();
            $this->getGameScene()->eventInterpreter?->renderPresentation();
            $this->renderPresentationOverlay();
        }, $forceFullRepaint);
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
        try { $this->executeField($context); }
        finally { $this->renderPresentationOverlay(); }
    }

    private function executeField(?SceneStateContext $context): void
    {
        $scene = $this->context->getScene();
        assert($scene instanceof GameScene);

        if ($scene->isStopping) {
            return;
        }
        $scene->reconcileFieldPresentation();
        // The player's last step arrives once its slide has shown, here in
        // the field and never under a menu opened while it slid.
        $scene->player?->completeArrival();
        if ($scene->isStopping || $scene->state !== $this || $scene->sceneManager->hasSceneTransition()) {
            return;
        }

        // A story event owns field input while it is running. Its pending
        // dialogue, timer, route, transfer, or battle continuation advances
        // once, then the frame returns without reopening actions or moving
        // the player underneath it.
        if ($scene->hasUnstableEventSession()) {
            if ($scene->cinematicController?->active() !== null
                && (Input::isButtonDown('cancel') || Input::isButtonDown('back'))
            ) {
                $scene->skipCinematic();
            }

            $scene->updateEventSession(Time::getDeltaTime());

            if (! $scene->isStopping && $scene->cinematicController?->active() !== null
                && $scene->sceneManager->currentScene === $scene) {
                $this->renderTheField();
            }
            return;
        }

        $this->handleActions($scene);

        if ($scene->isStopping || $scene->hasUnstableEventSession() || $scene->sceneManager->hasSceneTransition()) {
            return;
        }

        // An action may have handed the screen to another state (the menu, the
        // map). Moving the player or wandering an NPC now would draw the field
        // over whatever that state just rendered.
        if ($scene->state !== $this) {
            return;
        }

        $this->handleNavigation($scene);

        if ($scene->isStopping || $scene->hasUnstableEventSession() || $scene->sceneManager->hasSceneTransition()) {
            return;
        }

        $scene->npcManager?->update();

        // After the player and NPCs have moved: who can be spoken to from here.
        if (! $scene->isStopping && $scene->state === $this) {
            $scene->player?->refreshTalkTarget();
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
                Vocabulary::getTerm('game.shutdown', 'Exit Game'))) {
            $scene->getGame()->quit();
            return;
        }

        if (Input::isButtonDown("menu")) {
            play_sound(SystemSound::CONFIRM);
            $this->setState($scene->mainMenuState);
        }

        if (Input::isButtonDown("action")) {
            $scene->player->interact();
        }

        if ($scene->isStopping || $scene->sceneManager->hasSceneTransition()) {
            return;
        }

        if (Input::isButtonDown("map")) {
            $this->showInGameMap();
        }

        if (Input::isButtonDown("skit")) {
            $scene->skitManager?->playNextAvailableSkit();
        }

        if ($scene->isStopping) {
            return;
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
                    NotificationDuration::SHORT,
                    presentationRole: 'save'
                );
            } catch (\Throwable $exception) {
                Debug::warn(sprintf('Quick save failed: %s', $exception->getMessage()));
                alert('Saving is unavailable. Check storage permissions and try again.', 'Quick Save Unavailable');
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
        if (Input::isHeldInputAvailable()) {
            // The walk's clock and the step's presentation share one duration.
            $this->playerWalk->update(Time::getDeltaTime(), static fn(Vector2 $direction, float $seconds): bool
                => $scene->moveAtPace($seconds, static fn(): bool => $scene->player->tryMove($direction, $scene->camera)));
            return;
        }

        // Event-only input (the terminal) steps once per key event, as it always has.
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
        $this->renderTheField(forceFullRepaint: true);
    }
}
