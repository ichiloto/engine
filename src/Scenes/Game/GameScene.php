<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Exceptions\IchilotoException;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Game\States\CutsceneState;
use Ichiloto\Engine\Scenes\Game\States\DialogueState;
use Ichiloto\Engine\Scenes\Game\States\AbilityMenuState;
use Ichiloto\Engine\Field\EncounterManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\SkitManager;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Progress\Bestiary;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\States\QuestMenuState;
use Ichiloto\Engine\Scenes\Game\States\RecordsMenuState;
use Ichiloto\Engine\Scenes\Game\States\SummonsMenuState;
use Ichiloto\Engine\Scenes\Game\States\ControlsMenuState;
use Ichiloto\Engine\Scenes\Game\States\EquipmentMenuState;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\Scenes\Game\States\MagicMenuState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Scenes\Game\States\MapState;
use Ichiloto\Engine\Scenes\Game\States\OverworldState;
use Ichiloto\Engine\Scenes\Game\States\SaveMenuState;
use Ichiloto\Engine\Scenes\Game\States\ShopState;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\Util\Config\ProjectConfig;
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
     * @inheritDoc
     */
    public function __construct(SceneManager $sceneManager, string $name)
    {
        parent::__construct($sceneManager, $name);
        $this->gameState = new GameState();
    }

    /**
     * @var CutsceneState|null The cutscene state.
     */
    protected(set) ?CutsceneState $cutsceneState = null;
    /**
     * @var DialogueState|null The dialogue state.
     */
    protected(set) ?DialogueState $dialogueState = null;
    /**
     * @var FieldState|null The field state.
     */
    protected(set) ?FieldState $fieldState = null;
    /**
     * @var MainMenuState|null The main menu state.
     */
    protected(set) ?MainMenuState $mainMenuState = null;
    /**
     * @var ItemMenuState|null The item menu state.
     */
    protected(set) ?ItemMenuState $itemMenuState = null;
    /**
     * @var EquipmentMenuState|null The equipment menu state.
     */
    protected(set) ?EquipmentMenuState $equipmentMenuState = null;
    /**
     * @var AbilityMenuState|null The ability menu state.
     */
    protected(set) ?AbilityMenuState $abilityMenuState = null;
    /**
     * @var SummonsMenuState|null The summon-assignment menu state.
     */
    protected(set) ?SummonsMenuState $summonsMenuState = null;
    /**
     * @var QuestMenuState|null The quest-journal menu state.
     */
    protected(set) ?QuestMenuState $questMenuState = null;
    /**
     * @var RecordsMenuState|null The achievements/bestiary records state.
     */
    protected(set) ?RecordsMenuState $recordsMenuState = null;
    /**
     * @var ControlsMenuState|null The key-rebinding state.
     */
    protected(set) ?ControlsMenuState $controlsMenuState = null;
    /**
     * @var MagicMenuState|null The magic menu state.
     */
    protected(set) ?MagicMenuState $magicMenuState = null;
    /**
     * @var MapState|null The map state.
     */
    protected(set) ?MapState $mapState = null;
    /**
     * @var OverworldState|null The overworld state.
     */
    protected(set) ?OverworldState $overworldState = null;
    /**
     * @var ShopState|null The shop state.
     */
    protected(set) ?ShopState $shopState = null;
    /**
     * @var SaveMenuState|null The save-menu state.
     */
    protected(set) ?SaveMenuState $saveMenuState = null;
    /**
     * @var MapManager|null The map manager.
     */
    protected(set) ?MapManager $mapManager = null;
    /**
     * @var Player|null The player.
     */
    protected(set) ?Player $player = null;
    /**
     * @var LocationHUDWindow|null The location HUD window.
     */
    public ?LocationHUDWindow $locationHUDWindow {
        get {
            return $this->uiManager->locationHUDWindow;
        }
    }
    /**
     * @var Party|null The party.
     */
    protected(set) ?Party $party = null;
    /**
     * @var GameState The persistent world state (switches, variables, story
     * events, one-shot event completion).
     */
    protected(set) GameState $gameState;
    /**
     * @var QuestManager|null The quest manager.
     */
    protected(set) ?QuestManager $questManager = null;
    /**
     * @var EncounterManager|null The random-encounter manager.
     */
    protected(set) ?EncounterManager $encounterManager = null;
    /**
     * @var NpcManager|null The field NPC manager.
     */
    protected(set) ?NpcManager $npcManager = null;
    /**
     * @var EventInterpreter|null The one active story-event runtime.
     */
    protected(set) ?EventInterpreter $eventInterpreter = null;
    /**
     * @var bool Whether a transfer requested an autosave during an event.
     */
    protected(set) bool $hasDeferredAutoSave = false;
    /**
     * @var SkitManager|null The skit manager.
     */
    protected(set) ?SkitManager $skitManager = null;
    /**
     * @var AchievementManager|null The achievement manager.
     */
    protected(set) ?AchievementManager $achievementManager = null;
    /**
     * @var Bestiary The party's enemy codex.
     */
    protected(set) Bestiary $bestiary;
    /**
     * @var string[] The currently recorded story-event flags.
     */
    public array $storyEvents {
        get {
            return $this->gameState->storyEvents;
        }
    }
    /**
     * @var string The currently loaded map identifier.
     */
    protected(set) string $currentMapId = '';
    /**
     * @var GameSceneState|null The state of the scene.
     */
    protected(set) ?GameSceneState $state = null;
    /**
     * @var SceneStateContext|null The scene state context.
     */
    protected ?SceneStateContext $sceneStateContext = null;
    /**
     * @var GameConfig|null The configuration of the game.
     */
    protected ?GameConfig $config = null;

    /**
     * Configures the game scene.
     *
     * @param GameConfig $config The game configuration.
     * @return void
     * @throws IchilotoException
     * @throws NotFoundException If the map is not found.
     */
    public function configure(SceneConfigurationInterface $config): void
    {
        if (!$config instanceof GameConfig) {
            throw new IchilotoException('Invalid configuration.');
        }

        $this->mapManager = MapManager::getInstance($this->getGame(), $this);

        $this->initializeGameSceneStates();

        if (isset($this->uiManager->locationHUDWindow)) {
            $this->uiManager->locationHUDWindow->deactivate();
            $this->uiManager->uiElements->remove($this->uiManager->locationHUDWindow);
        }

        $this->uiManager->locationHUDWindow = new LocationHUDWindow(new Vector2(0, 0), MovementHeading::NONE);
        $this->uiManager->uiElements->add($this->locationHUDWindow);

        $this->config = $config;
        $this->gameState = GameState::fromArray($this->config->gameState);

        // Flag writes feed quest objectives that watch switches and story
        // events, and can make new skits available.
        $this->gameState->onChange = function (string $kind, string $name): void {
            if ($kind !== 'variable') {
                $this->questManager?->recordFlag($name);
                $this->skitManager?->announceAvailableSkits();
                $this->achievementManager?->evaluateConditionalAchievements();
            }
        };

        Time::setElapsedTime($this->config->playTimeSeconds);

        $this->player = new Player(
            $this,
            'Player',
            $this->config->playerPosition,
            $this->config->playerShape,
            $this->config->playerSprite,
            $this->config->playerHeading,
            $this->config->playerSprites
        );
        $this->party = $this->config->party;
        $this->party->assertSummonAssignments((new SummonCutsceneLibrary())->load());

        // The quest manager must exist before the first map load so the
        // starting map counts toward reach-map objectives.
        $this->questManager = new QuestManager($this->getGame(), $this);
        $this->questManager->hydrate($this->config->questLog);
        $this->encounterManager = new EncounterManager($this);
        $this->npcManager = new NpcManager($this);
        $this->eventInterpreter = new EventInterpreter($this);
        $this->hasDeferredAutoSave = false;
        $this->skitManager = new SkitManager($this);
        $this->achievementManager = new AchievementManager($this->getGame(), $this);
        $this->achievementManager->hydrate($this->config->achievements);
        $this->bestiary = Bestiary::fromArray($this->config->bestiary);

        $this->loadMap($this->config->mapId, $this->player);
        $this->player->activate();
        $this->locationHUDWindow->updateDetails($this->player->position, $this->player->heading);
        $this->setState($this->fieldState);
        $this->player->evaluateAutomaticTriggersAtCurrentPosition();
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
        $this->equipmentMenuState = new EquipmentMenuState($this->sceneStateContext);
        $this->itemMenuState = new ItemMenuState($this->sceneStateContext);
        $this->abilityMenuState = new AbilityMenuState($this->sceneStateContext);
        $this->summonsMenuState = new SummonsMenuState($this->sceneStateContext);
        $this->questMenuState = new QuestMenuState($this->sceneStateContext);
        $this->recordsMenuState = new RecordsMenuState($this->sceneStateContext);
        $this->controlsMenuState = new ControlsMenuState($this->sceneStateContext);
        $this->magicMenuState = new MagicMenuState($this->sceneStateContext);
        $this->mapState = new MapState($this->sceneStateContext);
        $this->overworldState = new OverworldState($this->sceneStateContext);
        $this->shopState = new ShopState($this->sceneStateContext);
        $this->saveMenuState = new SaveMenuState($this->sceneStateContext);
    }

    /**
     * @inheritDoc
     *
     * The field's music belongs to the current map, so returning to the game
     * scene (e.g. after a battle) resumes whatever the map declares.
     */
    #[Override]
    public function getBackgroundMusic(): ?string
    {
        return $this->mapManager?->backgroundMusic;
    }

    /**
     * Loads the map.
     *
     * @param string $mapFilename The map filename.
     * @param Player $player
     * @return void
     * @throws IchilotoException If the map cannot be loaded.
     * @throws NotFoundException If the map is not found.
     */
    public function loadMap(string $mapFilename, Player $player): void
    {
        $this->currentMapId = preg_replace('/(\.(data|map|event))?\.php$/', '', $mapFilename) ?: $mapFilename;
        $this->mapManager->loadMap($mapFilename, $player);
    }

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
     * Creates a snapshot of the live field state for persistence.
     *
     * @param int $playTimeSeconds The elapsed play time in seconds.
     * @return GameConfig The captured game snapshot.
     * @throws NotFoundException
     */
    public function createSnapshot(int $playTimeSeconds = 0): GameConfig
    {
        if (!$this->party instanceof Party || !$this->player instanceof Player) {
            throw new NotFoundException('The game scene is not ready to be saved.');
        }

        return new GameConfig(
            mapId: $this->currentMapId ?: $this->config?->mapId ?? '',
            party: $this->party,
            playerPosition: clone $this->player->position,
            playerShape: clone $this->player->getShape(),
            playerHeading: $this->player->heading,
            playerStats: [],
            events: $this->gameState->storyEvents,
            playerSprite: $this->player->sprite,
            playerSprites: $this->player->getDirectionalSprites(),
            playTimeSeconds: $playTimeSeconds,
            gameState: $this->gameState->toArray(),
            questLog: $this->questManager?->log->toArray() ?? [],
            achievements: $this->achievementManager?->toArray() ?? [],
            bestiary: $this->bestiary->toArray(),
        );
    }

    /**
     * Returns whether a story-event flag has been recorded.
     *
     * @param string $eventName The event name to check.
     * @return bool True when the event has been recorded.
     */
    public function hasStoryEvent(string $eventName): bool
    {
        return $this->gameState->hasStoryEvent($eventName);
    }

    /**
     * Records a story-event flag if it has not already been stored.
     *
     * @param string $eventName The event name to record.
     * @return void
     */
    public function recordStoryEvent(string $eventName): void
    {
        $this->gameState->recordStoryEvent($eventName);
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
        Debug::info("Transferring player to $location->mapFilename... at $location->playerPosition");

        $transition = ScreenTransition::fromConfig();
        $transition->out();

        $this->player->position->x = $location->playerPosition->x;
        $this->player->position->y = $location->playerPosition->y;
        if ($location->playerSprite) {
            $this->player->setFacingSprite($location->playerSprite);
        }
        $this->loadMap($location->mapFilename, $this->player);

        // The field is drawn behind the cover, then revealed.
        $transition->in(function (): void {
            $this->fieldState?->renderTheField();
        });

        $this->player->render();

        $this->locationHUDWindow->updateDetails($this->player->position, $this->player->heading);
        $this->locationHUDWindow->render();
        Debug::info("Player transferred to $location->mapFilename... at {$this->player->position}");

        // The originating interpreter stays in memory while MapManager and
        // NpcManager load the destination. It advances past transfer only
        // after that existing path has fully completed.
        $this->eventInterpreter?->resumeAfterTransfer();

        $this->autoSave();
    }

    /**
     * Writes an autosave when the project enables them.
     *
     * Map transfers are the natural checkpoint: the player has just
     * committed to a new area. Opt in with `save.autosave` in the project
     * config; failures warn and never interrupt play.
     *
     * @return void
     */
    public function autoSave(): void
    {
        if (! config(ProjectConfig::class, 'save.autosave', false)) {
            return;
        }

        if ($this->hasUnstableEventSession()) {
            $this->hasDeferredAutoSave = true;
            return;
        }

        try {
            $this->sceneManager->saveManager->autoSave($this);
        } catch (\Throwable $exception) {
            Debug::warn(sprintf('Autosave failed: %s', $exception->getMessage()));
        }
    }

    /**
     * Starts a script on the GameScene-owned interpreter.
     *
     * @param array<int, array<string, mixed>> $commands The commands.
     * @param string|null $scriptId Stable script identity when available.
     * @param array<string, scalar|null> $origin Plain authoring origin metadata.
     * @return EventExecutionSession|null The session, or null when one is active.
     */
    public function startEventScript(
        array $commands,
        ?string $scriptId = null,
        ?EventSessionCompletionTargetInterface $completionTarget = null,
        array $origin = [],
    ): ?EventExecutionSession
    {
        return $this->eventInterpreter?->run($commands, $scriptId, $completionTarget, $origin);
    }

    /**
     * Ticks the active story-event continuation.
     */
    public function updateEventSession(?float $deltaSeconds = null): void
    {
        $this->eventInterpreter?->update($deltaSeconds);
    }

    /**
     * Returns whether saving would capture a half-completed story event.
     */
    public function hasUnstableEventSession(): bool
    {
        return $this->eventInterpreter?->hasActiveSession() ?? false;
    }

    /**
     * Resumes a suspended scripted battle after the field has been restored.
     */
    public function resumeEventAfterBattle(BattleResult $result): void
    {
        $this->eventInterpreter?->resumeAfterBattle($result);
    }

    /**
     * Cancels a scripted battle continuation when normal defeat goes to the
     * game-over scene.
     */
    public function failEventAfterBattle(string $message): void
    {
        $this->eventInterpreter?->failActiveSession($message);
    }

    /**
     * Lifecycle hook called when a new session begins.
     */
    public function onEventSessionStarted(EventExecutionSession $session): void
    {
        Debug::info(sprintf(
            'Event session %d started (%s).',
            $session->id,
            $session->scriptId ?? 'inline script',
        ));
    }

    /**
     * Lifecycle hook called after completion or controlled failure.
     */
    public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
    {
        Debug::info(sprintf(
            'Event session %d %s.',
            $session->id,
            $completed ? 'completed' : 'failed',
        ));

        if (! $completed) {
            // Never turn a failed, potentially partial script into an
            // automatic checkpoint.
            $this->hasDeferredAutoSave = false;
            return;
        }

        if ($this->hasDeferredAutoSave) {
            $this->hasDeferredAutoSave = false;
            $this->autoSave();
        }
    }

    /**
     * Re-renders the complete field composition after a transient overlay.
     *
     * Story dialogue is driven without leaving FieldState, so it does not get
     * the normal state-resume redraw that blocking modals receive.
     */
    public function restoreFieldAfterOverlay(): void
    {
        if ($this->state === $this->fieldState) {
            $this->fieldState?->renderTheField();
        }
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function renderBackgroundTile(int $x, int $y): void
    {
        $this->mapManager->erase($x, $y);
    }

    /**
     * Updates the active game-scene layout after the terminal size changes.
     *
     * @param int $width The new terminal width.
     * @param int $height The new terminal height.
     * @return void
     */
    #[Override]
    public function onScreenResize(int $width, int $height): void
    {
        parent::onScreenResize($width, $height);

        if ($this->player) {
            $this->camera->resetPosition($this->player);
        }

        $this->locationHUDWindow?->refreshLayout();

        Console::clear();

        if ($this->state instanceof FieldState) {
            $this->state->renderTheField();
            return;
        }

        $this->state?->enter();
    }
}
