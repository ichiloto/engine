<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\ProjectState\ProjectStateParticipantInterface;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\GameSceneState;
use Ichiloto\Engine\Scenes\Interfaces\SceneExcursionResultInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneExcursionOutcome;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

final class SceneExcursionCheckpointParticipant implements ProjectStateParticipantInterface
{
    public const string NAMESPACE = 'qualification/excursion';
    public string $namespace { get => self::NAMESPACE; }
    public int $version { get => 1; }

    public function migrateState(array $data, int $fromVersion): array
    {
        throw new RuntimeException('This synthetic schema has no older version.');
    }

    public function validateState(array $data): void
    {
        if ($data !== [] && (($data['runId'] ?? null) !== 'synthetic-run'
            || ($data['phase'] ?? null) !== 'in-progress'
            || !is_int($data['step'] ?? null) || $data['step'] < 0
            || !is_array($data['pending'] ?? null)
            || !is_string($data['pending']['actionId'] ?? null)
            || !is_array($data['pending']['choices'] ?? null))) {
            throw new RuntimeException('Invalid synthetic in-progress state.');
        }
    }
}

final class SceneExcursionCheckpointGame extends Game
{
    public function __construct(private readonly string $fixtureRoot)
    {
        $this->name = 'Synthetic checkpoint';
        $this->width = 40;
        $this->height = 12;
        $this->audioManager = new RecordingAudioManager($this);
        $manifest = SaveCompatibilityManifest::fromArray('qualification/project', [
            'contentVersion' => 0, 'migrations' => [], 'aliases' => [], 'tombstones' => [],
        ], 'synthetic excursion checkpoint');
        $this->sceneManager = new class($this, $manifest) extends SceneManager {
            public function __construct(Game $game, SaveCompatibilityManifest $manifest)
            {
                $this->game = $game;
                $this->scenes = new ItemList(SceneInterface::class);
                $this->eventManager = EventManager::getInstance($game);
                $this->saveManager = new SaveManager($game, 'saves', 'saves/quick', $manifest);
            }
        };
    }

    public function __destruct() {}
    public function getDataRoot(): string { return $this->fixtureRoot; }
    public function hasIsolatedDataRoot(): bool { return true; }
}

final class SceneExcursionCheckpointFieldState extends GameSceneState
{
    public bool $isSuspended = false;
    public int $suspends = 0;
    public int $resumes = 0;

    public function execute(?SceneStateContext $context = null): void {}
    public function suspend(): void { $this->isSuspended = true; $this->suspends++; }
    public function resume(): void { $this->isSuspended = false; $this->resumes++; }
}

/** Only field setup is synthetic; configure, snapshot, event guard and lifecycle are real. */
final class SceneExcursionCheckpointField extends GameScene
{
    public array $seenProjectState = [];
    public int $configurations = 0;

    public function __construct(SceneManager $manager)
    {
        AbstractScene::__construct($manager, 'Synthetic field owner');
        $this->state = new SceneExcursionCheckpointFieldState(new SceneStateContext($this));
    }

    protected function configureCampaign(GameConfig $config): void
    {
        $this->configurations++;
        $this->seenProjectState = $this->getGame()->getProjectStateRegistry()
            ->getState(SceneExcursionCheckpointParticipant::NAMESPACE);
        $this->config = $config;
        $this->currentMapId = $config->mapId;
        $this->party = $config->party;
        $this->gameState = GameState::fromArray($config->gameState);
        $this->player = new Player($this, 'Synthetic player', clone $config->playerPosition,
            clone $config->playerShape, $config->playerSprite, $config->playerHeading, $config->playerSprites);
        $this->eventInterpreter = new EventInterpreter($this);
    }
}

final class SceneExcursionCheckpointTarget extends AbstractScene
{
    public function __construct(SceneManager $manager, public readonly GameScene $fieldOwner)
    {
        parent::__construct($manager, 'Reusable custom target');
    }

    public function writeCheckpoint(int $slot): SaveSlot
    {
        return $this->sceneManager->saveManager->save($this->fieldOwner, $slot);
    }
}

beforeEach(function () {
    $this->staticState = [];
    foreach ([Console::class, InputManager::class, ConfigStore::class, EventManager::class,
        UIManager::class, ModalManager::class, Debug::class] as $class) {
        $this->staticState[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $this->fixtureRoot = createTestDirectory('scene-excursion-checkpoint-');
    $this->managers = [];
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
    Debug::configure(['log_directory' => $this->fixtureRoot . '/logs']);
    ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['audio' => ['music' => false, 'sfx' => false]]));
    ConfigStore::put(PlaySettings::class, new PlaySettings(['screenDimensions' => ['width' => 40, 'height' => 12]]));
    InputManager::setInputSource(new class implements InputSourceInterface {
        public function poll(): ?KeyCode { return null; }
        public function reset(bool $drainBufferedInput = false): void {}
    });
    Console::setTerminalOutputEnabled(false);
    Console::syncDimensions(40, 12);
    Console::clear();
    $this->game = new SceneExcursionCheckpointGame($this->fixtureRoot);
    new ReflectionProperty(UIManager::class, 'instance')->setValue(null, new class($this->game) extends UIManager {
        public function __construct(Game $game) { parent::__construct($game); }
    });
    $this->managers[] = $this->game->sceneManager;
});

afterEach(function () {
    try {
        foreach ($this->managers as $manager) { $manager->stop(); }
    } finally {
        foreach ($this->staticState as $class => $properties) {
            foreach ($properties as $name => $value) {
                new ReflectionProperty($class, $name)->setValue(null, $value);
            }
        }
    }
});

it('durably checkpoints a retained field owner through a custom excursion and fresh Game reload without bypassing story guards', function () {
    $namespace = SceneExcursionCheckpointParticipant::NAMESPACE;
    $this->game->registerProjectStateParticipant(new SceneExcursionCheckpointParticipant());
    $registry = $this->game->getProjectStateRegistry();
    $party = new Party();
    $party->location = new PartyLocation('Synthetic field', 'Synthetic region');
    $story = new GameState();
    $story->recordStoryEvent('synthetic_boundary');
    $story->setSwitch('synthetic_gate', true);
    $story->setVariable('synthetic_progress', 3);
    $config = new GameConfig('qualification/field', $party, new Vector2(6, 4), new Rect(0, 0, 1, 1),
        MovementHeading::EAST, events: $story->storyEvents, playerSprite: ['>'], gameState: $story->toArray());
    $manager = $this->game->sceneManager;
    $field = new SceneExcursionCheckpointField($manager);
    $field->configure($config);
    $target = new SceneExcursionCheckpointTarget($manager, $field);
    $manager->addScenes($field, $target)->loadScene(SceneExcursionCheckpointField::class);
    $player = $field->player;
    $camera = $field->camera;
    $state = $field->state;
    $getFieldData = static fn(GameConfig $snapshot): array => [
        $snapshot->mapId, $snapshot->party->location?->name, $snapshot->party->location?->region,
        $snapshot->playerPosition->x, $snapshot->playerPosition->y, $snapshot->playerHeading,
        $snapshot->playerSprite, $snapshot->events, $snapshot->gameState,
    ];
    $expectedField = $getFieldData($config);
    $session = $manager->beginSceneExcursion($field, SceneExcursionCheckpointTarget::class, $config);
    expect($manager->currentScene)->toBe($target)->and($target->fieldOwner)->toBe($field)
        ->and(new ReflectionProperty(SceneManager::class, 'suspendedCaller')->getValue($manager))->toBe($field)
        ->and($field->isStarted())->toBeTrue()->and($state->isSuspended)->toBeTrue()
        ->and($field->hasUnstableEventSession())->toBeFalse()->and($session->outcome)->toBeNull();

    $choice = 'resource-a';
    $progress = ['runId' => 'synthetic-run', 'phase' => 'in-progress', 'step' => 2,
        'pending' => ['actionId' => 'synthetic-action', 'choices' => [&$choice, 'resource-b'], 'cursor' => 0]];
    $expectedProgress = ['runId' => 'synthetic-run', 'phase' => 'in-progress', 'step' => 2,
        'pending' => ['actionId' => 'synthetic-action', 'choices' => ['resource-a', 'resource-b'], 'cursor' => 0]];
    $registry->replaceStates([$namespace => $progress]);
    $checkpoint = $field->createSnapshot();
    $slot = $target->writeCheckpoint(1);
    $bytes = file_get_contents($slot->path);
    expect(substr($bytes, 0, 4))->toBe('IED1')->and(substr($bytes, 4, 2))->toBe("\x1f\x8b")
        ->and(gzdecode(substr($bytes, 4)))->not->toBeFalse()
        ->and($slot->locationName)->toBe($party->location->name)
        ->and($getFieldData($checkpoint))->toBe($expectedField)
        ->and($checkpoint->projectState)->toBe([$namespace => ['version' => 1, 'data' => $expectedProgress]])
        ->and($manager->currentScene)->toBe($target)->and($state->isSuspended)->toBeTrue()
        ->and($session->outcome)->toBeNull();

    // Later live writes and borrowed PHP references cannot rewrite the checkpoint.
    $choice = 'resource-after-checkpoint';
    expect($registry->getState($namespace))->toBe($expectedProgress);
    $laterProgress = $registry->getState($namespace);
    $laterProgress['step'] = 9;
    $laterProgress['pending']['choices'][0] = 'resource-later';
    $registry->replaceStates([$namespace => $laterProgress]);
    $borrowed = $registry->getState($namespace);
    $borrowed['pending']['cursor'] = 99;
    expect($registry->getState($namespace))->toBe($laterProgress)
        ->and($checkpoint->projectState[$namespace]['data'])->toBe($expectedProgress)
        ->and(file_get_contents($slot->path))->toBe($bytes);

    $fresh = new SceneExcursionCheckpointGame($this->fixtureRoot);
    $this->managers[] = $fresh->sceneManager;
    $fresh->registerProjectStateParticipant(new SceneExcursionCheckpointParticipant());
    $freshRegistry = $fresh->getProjectStateRegistry();
    $loaded = $fresh->sceneManager->saveManager->loadSaveFile($slot->path);
    expect($freshRegistry)->not->toBe($registry)->and($freshRegistry->getState($namespace))->toBe([])
        ->and($loaded->slot->path)->toBe($slot->path)->and($loaded->slot->locationName)->toBe($slot->locationName)
        ->and($getFieldData($loaded->config))->toBe($expectedField)
        ->and($loaded->config->projectState)->toBe($checkpoint->projectState);
    $restored = new SceneExcursionCheckpointField($fresh->sceneManager);
    $restored->configure($loaded->config);
    $fresh->sceneManager->addScenes($restored)->loadScene(SceneExcursionCheckpointField::class);
    expect($restored)->not->toBe($field)->and($restored->player)->not->toBe($player)
        ->and($restored->party)->not->toBe($party)->and($restored->seenProjectState)->toBe($expectedProgress)
        ->and($freshRegistry->getState($namespace))->toBe($expectedProgress)
        ->and($getFieldData($restored->createSnapshot()))->toBe($expectedField)
        ->and($restored->createSnapshot()->projectState)->toBe($checkpoint->projectState)
        ->and($registry->getState($namespace))->toBe($laterProgress);

    $result = new class implements SceneExcursionResultInterface {};
    $outcome = SceneExcursionOutcome::complete($result);
    $manager->finishSceneExcursion($session, $outcome);
    expect($session->outcome)->toBe($outcome)->and($manager->currentScene)->toBe($field)
        ->and([$field->player, $field->party, $field->camera, $field->state])->toBe([$player, $party, $camera, $state])
        ->and($field->configurations)->toBe(1)->and($field->isStarted())->toBeTrue()
        ->and([$state->isSuspended, $state->suspends, $state->resumes])->toBe([false, 1, 1])
        ->and($getFieldData($field->createSnapshot()))->toBe($expectedField)
        ->and($target->isStarted())->toBeFalse();

    try {
        $event = $field->startEventScript([
            ['type' => 'wait', 'seconds' => 1.0],
            ['type' => 'set_variable', 'name' => 'synthetic_continued', 'value' => 1],
        ], 'synthetic-active-story');
        $guardedSession = $manager->beginSceneExcursion($field, SceneExcursionCheckpointTarget::class, $config);
        expect($event->status)->toBe(EventExecutionStatus::YIELDED)->and($field->hasUnstableEventSession())->toBeTrue()
            ->and($manager->currentScene)->toBe($target)->and($state->isSuspended)->toBeTrue();
        expect(fn() => $target->writeCheckpoint(1))->toThrow(ActiveEventSaveException::class);
        expect(file_get_contents($slot->path))->toBe($bytes)
            ->and($manager->saveManager->getSaveFiles(true))->toBe([$slot->path])
            ->and($guardedSession->outcome)->toBeNull()->and($event->status)->toBe(EventExecutionStatus::YIELDED);
        $manager->finishSceneExcursion($guardedSession, SceneExcursionOutcome::cancel($result));
        expect($manager->currentScene)->toBe($field)->and($target->fieldOwner)->toBe($field)
            ->and($field->hasUnstableEventSession())->toBeTrue()
            ->and($field->gameState->getVariable('synthetic_continued'))->toBe(0)
            ->and([$state->isSuspended, $state->suspends, $state->resumes])->toBe([false, 2, 2]);
    } finally {
        // Stop the owned waiting event before shared teardown removes its diagnostic directory.
        $manager->stop();
    }
});
