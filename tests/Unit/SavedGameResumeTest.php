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
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Rendering\ScreenTransitionSession;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\SavedGameResumeContext;
use Ichiloto\Engine\Scenes\Game\SavedGameResumeHandlerInterface;
use Ichiloto\Engine\Scenes\Game\SavedGameResumeRegistry;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneExcursionResultInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneExcursionOutcome;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

final class SavedResumeParticipant implements ProjectStateParticipantInterface
{
    public string $namespace { get => 'synthetic/activity'; }
    public int $version { get => 1; }
    public function migrateState(array $data, int $fromVersion): array { throw new RuntimeException('No older schema.'); }
    public function validateState(array $data): void {}
}

final class SavedResumeGame extends Game
{
    public function __construct(private readonly string $root)
    {
        $this->name = 'Synthetic resume';
        $this->width = 40;
        $this->height = 12;
        $this->audioManager = new RecordingAudioManager($this);
        $this->sceneManager = new class($this) extends SceneManager {
            public function __construct(Game $game)
            {
                $this->game = $game;
                $this->scenes = new ItemList(SceneInterface::class);
                $this->eventManager = EventManager::getInstance($game);
                $manifest = SaveCompatibilityManifest::fromArray('synthetic/project',
                    ['contentVersion' => 0, 'migrations' => [], 'aliases' => [], 'tombstones' => []], 'resume fixture');
                $this->saveManager = new SaveManager($game, 'saves', 'saves/quick', $manifest);
            }
            public function findScene(string $className): ?SceneInterface
            {
                return parent::findScene($className === GameScene::class ? SavedResumeField::class : $className);
            }
        };
        $this->registerProjectStateParticipant(new SavedResumeParticipant());
    }
    public function __destruct() {}
    public function getDataRoot(): string { return $this->root; }
    public function hasIsolatedDataRoot(): bool { return true; }
}

final class SavedResumeFieldState extends FieldState
{
    public int $inputs = 0;
    public function enter(): void {}
    public function resume(): void {}
    public function renderTheField(bool $forceFullRepaint = false): void {}
    public function execute(?SceneStateContext $context = null): void { $this->inputs++; }
}

final class SavedResumePlayer extends Player
{
    public int $automaticEvents = 0;
    public function evaluateAutomaticTriggersAtCurrentPosition(): void { $this->automaticEvents++; }
}

/** Campaign/map data is synthetic; transaction, saved-load handoff and scene/excursion lifecycle are real. */
final class SavedResumeField extends GameScene
{
    public ?Closure $afterHydration = null;
    public bool $failHydration = false;
    public function __construct(SceneManager $manager) { parent::__construct($manager, 'Synthetic field'); }
    protected function configureCampaign(GameConfig $config): void
    {
        $this->config = $config;
        $this->party = $config->party;
        $this->currentMapId = $config->mapId;
        $this->gameState = GameState::fromArray($config->gameState);
        if ($this->locationHUDWindow !== null) { $this->uiManager->removeElement($this->locationHUDWindow); }
        $this->locationHUDWindow = new LocationHUDWindow(new Vector2(), MovementHeading::NONE);
        $this->uiManager->uiElements->add($this->locationHUDWindow);
        $this->player = new SavedResumePlayer($this, 'Synthetic player', clone $config->playerPosition,
            clone $config->playerShape, ['@'], $config->playerHeading);
        $this->eventInterpreter = new EventInterpreter($this);
        $this->fieldState = new SavedResumeFieldState(new SceneStateContext($this));
        $this->player->activate();
        $this->setState($this->fieldState);
        if ($this->failHydration) { throw new RuntimeException('Synthetic map hydration failed.'); }
        ($this->afterHydration)?->__invoke($this);
        $this->startConfiguredFieldEvents();
    }
}

final class SavedResumeTarget extends AbstractScene
{
    public ?SceneConfigurationInterface $configuration = null;
    public function __construct(SceneManager $manager) { parent::__construct($manager, 'Synthetic activity'); }
    public function configure(SceneConfigurationInterface $config): void { $this->configuration = $config; }
}

class SavedResumeHandler implements SavedGameResumeHandlerInterface
{
    public static array $contexts = [];
    public static ?Closure $onResume = null;
    public function resumeSavedGame(SavedGameResumeContext $context): void
    {
        self::$contexts[] = $context;
        (self::$onResume)?->__invoke($context);
    }
}
abstract class SavedResumeAbstractHandler extends SavedResumeHandler {}
final class SavedResumeRequiredConstructor extends SavedResumeHandler
{
    public function __construct(string $required) {}
}

function createSavedResumeConfig(array $data = []): GameConfig
{
    $party = new Party();
    $party->location = new PartyLocation('Synthetic clearing', 'Synthetic region');
    return new GameConfig('synthetic/clearing', $party, new Vector2(2, 3), new Rect(0, 0, 1, 1),
        MovementHeading::SOUTH, playerSprite: ['@'], projectState: ['synthetic/activity' => ['version' => 1, 'data' => $data]]);
}

function createSavedResumeWorld(object $test): array
{
    $game = new SavedResumeGame($test->root);
    new ReflectionProperty(UIManager::class, 'instance')->setValue(null, new class($game) extends UIManager {
        public function __construct(Game $game) { parent::__construct($game); }
    });
    $test->managers[] = $game->sceneManager;
    $field = new SavedResumeField($game->sceneManager);
    $target = new SavedResumeTarget($game->sceneManager);
    $game->sceneManager->addScenes($field, $target)->loadScene(SavedResumeField::class);
    $game->getSavedGameResumeRegistry()->configure(SavedResumeHandler::class);
    return [$game, $field, $target];
}

beforeEach(function () {
    $this->statics = [];
    foreach ([Console::class, InputManager::class, ConfigStore::class, EventManager::class,
        UIManager::class, ModalManager::class, Debug::class, SaveManager::class, GameLoader::class] as $class) {
        $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $this->root = createTestDirectory('saved-game-resume-');
    $this->managers = [];
    foreach ([EventManager::class, ModalManager::class, SaveManager::class] as $class) {
        new ReflectionProperty($class, 'instance')->setValue(null, null);
    }
    Debug::configure(['log_directory' => $this->root . '/logs']);
    ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub(['audio' => ['music' => false, 'sfx' => false]]));
    ConfigStore::put(PlaySettings::class, new PlaySettings(['screenDimensions' => ['width' => 40, 'height' => 12]]));
    InputManager::setInputSource(new FakeInputSource());
    Console::setTerminalOutputEnabled(false);
    Console::syncDimensions(40, 12);
    Console::clear();
    SavedResumeHandler::$contexts = [];
    SavedResumeHandler::$onResume = null;
});

afterEach(function () {
    try {
        foreach ($this->managers as $manager) {
            new ReflectionProperty(SceneManager::class, 'sceneTransition')->setValue($manager, null);
            $manager->stop();
        }
    } finally {
        SavedResumeHandler::$contexts = [];
        SavedResumeHandler::$onResume = null;
        foreach ($this->statics as $class => $values) {
            foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
        }
    }
});

it('restores a real saved campaign once and opens its recorded activity before automatic events or input', function () {
    [$original, $source] = createSavedResumeWorld($this);
    $source->configure(createSavedResumeConfig(['ownerId' => 'actor-recorded', 'rulesId' => 'rules-recorded']));
    $slot = $original->sceneManager->saveManager->save($source, 1);
    [$game, $field, $target] = createSavedResumeWorld($this);
    SavedResumeHandler::$onResume = function (SavedGameResumeContext $context) use ($game, $field, $target) {
        expect($context->scene)->toBe($field)->and($field->player->automaticEvents)->toBe(0)
            ->and($field->fieldState->inputs)->toBe(0)->and($field->isStarted())->toBeTrue();
        $field->update();
        expect($field->fieldState->inputs)->toBe(0)->and($field->player->automaticEvents)->toBe(0);
        $data = $game->getProjectStateRegistry()->getState('synthetic/activity');
        // This write proves the handler is outside the restoration transaction.
        $game->getProjectStateRegistry()->replaceStates(['synthetic/activity' => $data]);
        $config = createSavedResumeConfig($data);
        $game->sceneManager->beginSceneExcursion($field, SavedResumeTarget::class, $config);
        expect($target->configuration->projectState['synthetic/activity']['data'])
            ->toBe(['ownerId' => 'actor-recorded', 'rulesId' => 'rules-recorded']);
        $game->sceneManager->saveManager->save($field, 2);
    };
    $save = $game->sceneManager->saveManager->loadSaveFile($slot->path);
    $game->sceneManager->loadSavedGame($save->config);
    $session = $game->sceneManager->getSceneExcursionSession($target);
    expect($game->sceneManager->currentScene)->toBe($target)->and($session)->not->toBeNull()
        ->and($game->sceneManager->getSceneExcursionCaller($target))->toBe($field)
        ->and($field->player->automaticEvents)->toBe(0)->and(SavedResumeHandler::$contexts)->toHaveCount(1);
    $game->sceneManager->update();
    expect($field->fieldState->inputs)->toBe(0);
    $game->sceneManager->finishSceneExcursion($session,
        SceneExcursionOutcome::complete(new class implements SceneExcursionResultInterface {}));
    $game->sceneManager->update();
    $game->sceneManager->update();
    expect($game->sceneManager->currentScene)->toBe($field)->and($field->player->automaticEvents)->toBe(1)
        ->and($field->fieldState->inputs)->toBe(2)->and(SavedResumeHandler::$contexts)->toHaveCount(1);
});

it('resumes the exact registered field when several fields share a class', function () {
    [$game, $first] = createSavedResumeWorld($this);
    $second = new SavedResumeField($game->sceneManager);
    $game->sceneManager->addScenes($second)->loadScene(2);
    expect($game->sceneManager->findScene(SavedResumeField::class))->toBe($first)
        ->and($game->sceneManager->currentScene)->toBe($second)
        ->and($game->sceneManager->hasRegisteredScene($second))->toBeTrue()
        ->and($game->sceneManager->hasRegisteredScene(clone $second))->toBeFalse();
    $second->configureSavedGame(createSavedResumeConfig(['owner' => 'second']));
    expect(SavedResumeHandler::$contexts)->toHaveCount(1)
        ->and(SavedResumeHandler::$contexts[0]->scene)->toBe($second)
        ->and($second->player->automaticEvents)->toBe(1);
});

it('leaves save decoding, slot inspection, GameLoader and ordinary/new-game configuration read-only or hook-free', function () {
    [$game, $field] = createSavedResumeWorld($this);
    $config = createSavedResumeConfig(['active' => true]);
    $loader = new class($config) extends GameLoader {
        public function __construct(private GameConfig $fixture) {}
        public function loadNewGame(?\Ichiloto\Engine\Core\GameStartLocation $start = null): GameConfig { return $this->fixture; }
    };
    $game->sceneManager->startNewGame($loader);
    $slot = $game->sceneManager->saveManager->save($field, 1);
    $before = $game->getProjectStateRegistry()->captureSnapshot();
    $bytes = file_get_contents($slot->path);
    $game->sceneManager->saveManager->loadSlot(1);
    $game->sceneManager->saveManager->canLoadSaveFile($slot->path);
    $game->sceneManager->saveManager->getSaveSlots();
    $game->sceneManager->saveManager->loadSaveFile($slot->path);
    new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $game->sceneManager->saveManager);
    $reader = makeBareScene(GameLoader::class);
    new ReflectionProperty(GameLoader::class, 'game')->setValue($reader, $game);
    $reader->loadSavedGame($slot->path);
    expect(SavedResumeHandler::$contexts)->toBe([])->and(file_get_contents($slot->path))->toBe($bytes)
        ->and($game->getProjectStateRegistry()->captureSnapshot())->toBe($before)
        ->and($field->player->automaticEvents)->toBe(1);
    $game->getSavedGameResumeRegistry()->configure(null);
    $field->configureSavedGame($config);
    expect($field->player->automaticEvents)->toBe(1)->and(SavedResumeHandler::$contexts)->toBe([]);
});

it('defers a one-shot handoff while a transition or modal owns the field and blocks field work', function (string $obstacle) {
    [$game, $field] = createSavedResumeWorld($this);
    $transition = new ReflectionProperty(SceneManager::class, 'sceneTransition');
    $modals = new ReflectionProperty(ModalManager::class, 'modals');
    $modalManager = ModalManager::getInstance($game);
    $modal = null;
    $field->afterHydration = function () use ($game, $field, $transition, $modals, $modalManager, $obstacle, &$modal) {
        if ($obstacle === 'transition') { $transition->setValue($game->sceneManager, makeBareScene(ScreenTransitionSession::class)); }
        elseif ($obstacle === 'presentation') {
            $modal = makeBareScene(AlertModal::class);
            new ReflectionProperty(AlertModal::class, 'isShowing')->setValue($modal, true);
            $field->getUI()->present($modal);
            $field->suspend();
        }
        else {
            $modals->getValue($modalManager)->push(makeBareScene(AlertModal::class));
            $field->suspend();
        }
    };
    $field->configureSavedGame(createSavedResumeConfig());
    $field->update();
    expect(SavedResumeHandler::$contexts)->toBe([])->and($field->player->automaticEvents)->toBe(0)
        ->and($field->fieldState->inputs)->toBe(0);
    $transition->setValue($game->sceneManager, null);
    if ($obstacle === 'modal') { $modals->getValue($modalManager)->pop(); }
    if ($modal !== null) { $field->getUI()->dismiss($modal); $field->getUI()->commitPresentationChanges(); }
    $field->update();
    expect(SavedResumeHandler::$contexts)->toHaveCount(1)->and($field->player->automaticEvents)->toBe(1)
        ->and($field->fieldState->inputs)->toBe(0);
    $field->update();
    expect($field->fieldState->inputs)->toBe(1)->and(SavedResumeHandler::$contexts)->toHaveCount(1);
})->with(['transition', 'modal', 'presentation']);

it('refuses active story hydration and retains the existing active-event save guard', function () {
    [$game, $field] = createSavedResumeWorld($this);
    $field->configure(createSavedResumeConfig(['before' => true]));
    $field->startEventScript([['type' => 'wait', 'seconds' => 1.0]], 'synthetic/story');
    expect(fn() => $field->configureSavedGame(createSavedResumeConfig(['after' => true])))->toThrow(LogicException::class)
        ->and(fn() => $game->sceneManager->saveManager->save($field, 1))->toThrow(ActiveEventSaveException::class)
        ->and($game->getProjectStateRegistry()->getState('synthetic/activity'))->toBe(['before' => true])
        ->and($field->hasUnstableEventSession())->toBeTrue()->and(SavedResumeHandler::$contexts)->toBe([]);
});

it('waits for a story session started during hydration without bypassing or replacing it', function () {
    [$game, $field] = createSavedResumeWorld($this);
    $field->afterHydration = function (SavedResumeField $scene) {
        $scene->startEventScript([['type' => 'wait', 'seconds' => 1.0],
            ['type' => 'set_variable', 'name' => 'story-finished', 'value' => 1]], 'synthetic/hydration-story');
        $scene->update();
        expect($scene->fieldState->inputs)->toBe(0);
    };
    $field->configureSavedGame(createSavedResumeConfig());
    expect($field->hasUnstableEventSession())->toBeTrue()->and(SavedResumeHandler::$contexts)->toBe([]);
    $field->updateEventSession(1.1);
    expect($field->gameState->getVariable('story-finished'))->toBe(1)
        ->and($field->player->automaticEvents)->toBe(0)->and(SavedResumeHandler::$contexts)->toBe([]);
    $field->update();
    expect(SavedResumeHandler::$contexts)->toHaveCount(1)->and($field->player->automaticEvents)->toBe(1)
        ->and($field->fieldState->inputs)->toBe(0);
});

it('requires the exact field state and an active restored player', function (string $obstacle) {
    [$game, $field] = createSavedResumeWorld($this);
    $field->afterHydration = function (SavedResumeField $scene) use ($obstacle) {
        if ($obstacle === 'player') { new ReflectionProperty(Player::class, 'isActive')->setValue($scene->player, false); }
        else { $scene->setState(new SavedResumeFieldState(new SceneStateContext($scene))); }
    };
    $field->configureSavedGame(createSavedResumeConfig());
    $field->update();
    expect(SavedResumeHandler::$contexts)->toBe([])->and($field->player->automaticEvents)->toBe(0);
    $field->player->activate();
    $field->setState($field->fieldState);
    $field->update();
    expect(SavedResumeHandler::$contexts)->toHaveCount(1)->and($field->player->automaticEvents)->toBe(1);
})->with(['player', 'state']);

it('discards a pending handoff when its owner is removed or no longer current', function (string $obstacle) {
    [$game, $field, $target] = createSavedResumeWorld($this);
    $transition = new ReflectionProperty(SceneManager::class, 'sceneTransition');
    $field->afterHydration = fn() => $transition->setValue($game->sceneManager, makeBareScene(ScreenTransitionSession::class));
    $field->configureSavedGame(createSavedResumeConfig());
    $transition->setValue($game->sceneManager, null);
    if ($obstacle === 'registered') { $game->sceneManager->removeScenes($field); }
    else { $game->sceneManager->currentScene = $target; }
    $field->update();
    $game->sceneManager->currentScene = $field;
    if ($obstacle === 'registered') { $game->sceneManager->addScenes($field); }
    $field->update();
    expect(SavedResumeHandler::$contexts)->toBe([])->and($field->player->automaticEvents)->toBe(0);
})->with(['registered', 'current']);

it('cancels a deferred handoff on stop, navigation, reconfiguration or new-game launch', function (string $action) {
    [$game, $field, $target] = createSavedResumeWorld($this);
    $transition = new ReflectionProperty(SceneManager::class, 'sceneTransition');
    $field->afterHydration = fn() => $transition->setValue($game->sceneManager, makeBareScene(ScreenTransitionSession::class));
    $field->configureSavedGame(createSavedResumeConfig());
    $transition->setValue($game->sceneManager, null);
    $field->afterHydration = null;
    if ($action === 'stop') { $field->stop(); $field->start(); }
    elseif ($action === 'navigation') { $game->sceneManager->loadScene(SavedResumeTarget::class)->loadScene(SavedResumeField::class); }
    elseif ($action === 'excursion') {
        $session = $game->sceneManager->beginSceneExcursion($field, SavedResumeTarget::class, createSavedResumeConfig());
        $game->sceneManager->finishSceneExcursion($session,
            SceneExcursionOutcome::complete(new class implements SceneExcursionResultInterface {}));
    }
    elseif ($action === 'configure') { $field->configure(createSavedResumeConfig()); }
    else {
        $loader = new class extends GameLoader {
            public function __construct() {}
            public function loadNewGame(?\Ichiloto\Engine\Core\GameStartLocation $start = null): GameConfig { return createSavedResumeConfig(); }
        };
        $game->sceneManager->startNewGame($loader);
    }
    $field->update();
    expect(SavedResumeHandler::$contexts)->toBe([]);
})->with(['stop', 'navigation', 'excursion', 'configure', 'new-game']);

it('isolates registries and refuses foreign, stale or unregistered saved-load callers', function () {
    [$game, $field] = createSavedResumeWorld($this);
    $other = new SavedResumeGame($this->root);
    $this->managers[] = $other->sceneManager;
    expect($other->getSavedGameResumeRegistry()->handlerClass)->toBeNull();
    $foreign = new SavedResumeField($other->sceneManager);
    $game->sceneManager->addScenes($foreign);
    $game->sceneManager->currentScene = $foreign;
    expect(fn() => $foreign->configureSavedGame(createSavedResumeConfig()))->toThrow(LogicException::class);
    $game->sceneManager->currentScene = $field;
    $game->sceneManager->removeScenes($field);
    expect(fn() => $field->configureSavedGame(createSavedResumeConfig()))->toThrow(LogicException::class);
    $game->sceneManager->addScenes($field);
    $field->stop();
    expect(fn() => $field->configureSavedGame(createSavedResumeConfig()))->toThrow(LogicException::class);
});

it('exposes hydration and handler failures without silently resuming field work and preserves transaction rollback', function (string $failure) {
    [$game, $field] = createSavedResumeWorld($this);
    $field->configure(createSavedResumeConfig(['original' => true]));
    if ($failure === 'hydrate') { $field->failHydration = true; }
    else { SavedResumeHandler::$onResume = fn() => throw new RuntimeException('Required activity unavailable.'); }
    expect(fn() => $field->configureSavedGame(createSavedResumeConfig(['loaded' => true])))->toThrow(RuntimeException::class)
        ->and(fn() => $field->update())->toThrow(RuntimeException::class)
        ->and($field->fieldState->inputs)->toBe(0)->and($field->player->automaticEvents)->toBe(0)
        ->and($game->getProjectStateRegistry()->getState('synthetic/activity'))
        ->toBe($failure === 'hydrate' ? ['original' => true] : ['loaded' => true]);
})->with(['hydrate', 'handler']);

it('loads a concise project declaration and clears malformed or absent replacements', function (string $contents) {
    $registry = new SavedGameResumeRegistry();
    mkdir($this->root . '/Data');
    $path = $this->root . '/' . SavedGameResumeRegistry::PROJECT_FILE;
    file_put_contents($path, '<?php return SavedResumeHandler::class;');
    $registry->configureFromProject($this->root);
    expect($registry->handlerClass)->toBe(SavedResumeHandler::class);
    file_put_contents($path, $contents);
    expect(fn() => $registry->configureFromProject($this->root))->toThrow(RuntimeException::class)
        ->and($registry->handlerClass)->toBeNull();
    unlink($path);
    $registry->configureFromProject($this->root);
    expect($registry->handlerClass)->toBeNull();
})->with(['not-array' => '<?php return [];', 'wrong-contract' => '<?php return stdClass::class;',
    'missing' => '<?php return "MissingResumeClass";', 'abstract' => '<?php return SavedResumeAbstractHandler::class;',
    'constructor' => '<?php return SavedResumeRequiredConstructor::class;', 'throwing-file' => '<?php throw new RuntimeException("bad project");']);

it('refuses a declaration changed while its saved-load handoff is pending', function () {
    [$game, $field] = createSavedResumeWorld($this);
    $transition = new ReflectionProperty(SceneManager::class, 'sceneTransition');
    $field->afterHydration = fn() => $transition->setValue($game->sceneManager, makeBareScene(ScreenTransitionSession::class));
    $field->configureSavedGame(createSavedResumeConfig());
    $transition->setValue($game->sceneManager, null);
    $game->getSavedGameResumeRegistry()->configure(null);
    expect(fn() => $field->update())->toThrow(LogicException::class)
        ->and(fn() => $field->update())->toThrow(LogicException::class)
        ->and(SavedResumeHandler::$contexts)->toBe([])->and($field->fieldState->inputs)->toBe(0);
});
