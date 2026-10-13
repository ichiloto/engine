<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\InputSourceInterface;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationSlideDirection;
use Ichiloto\Engine\Messaging\Notifications\Notification;
use Ichiloto\Engine\Progress\AchievementManager;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneExcursionResultInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneExcursionOutcome;
use Ichiloto\Engine\Scenes\SceneExcursionStatus;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;

final class FieldHudLifecycleGame extends Game
{
    public function __construct()
    {
        $this->name = 'Synthetic HUD lifecycle';
        $this->width = 80;
        $this->height = 24;
        $this->audioManager = new RecordingAudioManager($this);
        $this->sceneManager = new class($this) extends SceneManager {
            public function __construct(Game $game)
            {
                $this->game = $game;
                $this->scenes = new ItemList(SceneInterface::class);
                $this->eventManager = EventManager::getInstance($game);
            }
        };
    }

    public function __destruct() {}
}

final class FieldHudLifecycleUI extends UIManager
{
    public function __construct(Game $game) { parent::__construct($game); }
    public function getRenderedElementIds(): array { return array_keys($this->renderedUiElements); }
    public function getSuppressedElementIds(): array { return array_keys($this->suppressedUiElements); }
}

/** Keep the real FieldState enter/resume; replace only its field drawing. */
final class FieldHudLifecycleState extends FieldState
{
    public array $hudActiveDuringRenders = [];

    public function renderTheField(bool $forceFullRepaint = false): void
    {
        $this->hudActiveDuringRenders[] = $this->getGameScene()->locationHUDWindow?->isActive;
        $this->getGameScene()->getUI()->render();
    }
}

/** Configure the real campaign/HUD path without maps, story, assets or native rendering. */
final class FieldHudLifecycleField extends GameScene
{
    public function __construct(SceneManager $manager) { parent::__construct($manager, 'Synthetic field'); }

    public function initializeGameSceneStates(): void
    {
        $this->fieldState = new FieldHudLifecycleState(new SceneStateContext($this));
    }

    protected function createPlayer(GameConfig $config): Player
    {
        return new class($this, 'Synthetic player', $config->playerPosition, $config->playerShape,
            $config->playerSprite, $config->playerHeading) extends Player {
            public function activate(): void { $this->isActive = true; }
        };
    }

    public function loadMap(string $mapFilename, Player $player): void { $this->currentMapId = $mapFilename; }
    protected function startConfiguredFieldEvents(): void {}
}

final class FieldHudLifecycleTarget extends AbstractScene
{
    public string $failAt = '';
    public ?bool $hudActiveAtConfigure = null;

    public function __construct(SceneManager $manager, private readonly GameScene $field)
    {
        parent::__construct($manager, 'Synthetic target');
    }

    public function configure(SceneConfigurationInterface $config): void
    {
        $this->hudActiveAtConfigure = $this->field->locationHUDWindow?->isActive;
        if ($this->failAt === 'configure') { throw new RuntimeException('Synthetic target configure failed.'); }
    }

    public function start(): void
    {
        parent::start();
        if ($this->failAt === 'start') { throw new RuntimeException('Synthetic target start failed.'); }
    }
}

function createFieldHudLifecycleConfig(): GameConfig
{
    return new GameConfig('synthetic-field', new Party(), new Vector2(), new Rect(0, 0, 1, 1), MovementHeading::SOUTH);
}

function createFieldHudLifecycleOutcome(): SceneExcursionOutcome
{
    return SceneExcursionOutcome::complete(new class implements SceneExcursionResultInterface {});
}

beforeEach(function () {
    $this->statics = [];
    foreach ([Console::class, ConfigStore::class, InputManager::class, EventManager::class, UIManager::class,
        ModalManager::class, MapManager::class, QuestManager::class, AchievementManager::class,
        Time::class, Debug::class] as $class) {
        $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $this->oldDirectory = getcwd();
    $this->root = createTestDirectory('field-hud-lifecycle-');
    chdir($this->root);
    Debug::configure(['log_directory' => $this->root . '/logs']);
    putSceneAudioConfig(['audio' => ['music' => false, 'sfx' => false], 'ui' => ['hud' => ['location' => true]]]);
    ConfigStore::put(PlaySettings::class, new PlaySettings(['screenDimensions' => ['width' => 80, 'height' => 24]]));
    ConfigStore::put(KnowledgeCatalog::class, KnowledgeCatalog::empty());
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
    new ReflectionProperty(MapManager::class, 'instance')->setValue(null, null);
    InputManager::setInputSource(new class implements InputSourceInterface {
        public function poll(): ?KeyCode { return null; }
        public function reset(bool $drainBufferedInput = false): void {}
    });
    InputManager::setBindings([]);
    Console::setTerminalOutputEnabled(false);
    Console::syncDimensions(80, 24);
    Console::clear();
    $this->game = new FieldHudLifecycleGame();
    $this->ui = new FieldHudLifecycleUI($this->game);
    new ReflectionProperty(UIManager::class, 'instance')->setValue(null, $this->ui);
    $this->manager = $this->game->sceneManager;
    $this->field = new FieldHudLifecycleField($this->manager);
    $this->field->configure(createFieldHudLifecycleConfig());
    $this->target = new FieldHudLifecycleTarget($this->manager, $this->field);
    $this->manager->addScenes($this->field, $this->target)->loadScene(0);
});

afterEach(function () {
    try {
        $this->manager->stop();
    } finally {
        chdir($this->oldDirectory);
        foreach ($this->statics as $class => $properties) {
            foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
        }
    }
});

it('configures a second same-class field without stealing the first HUD and replaces only its own on reconfigure', function () {
    $firstHud = $this->field->locationHUDWindow;
    $second = new FieldHudLifecycleField($this->manager);
    $this->manager->addScenes($second);
    $second->configure(createFieldHudLifecycleConfig());
    $secondHud = $second->locationHUDWindow;
    expect($second->getUI())->toBe($this->ui)->and($this->field->getUI())->toBe($this->ui)
        ->and($secondHud)->not->toBe($firstHud)->and($this->field->locationHUDWindow)->toBe($firstHud)
        ->and($firstHud->isActive)->toBeTrue()->and($this->ui->uiElements->toArray())->toBe([$firstHud, $secondHud]);

    $second->configure(createFieldHudLifecycleConfig());
    expect($this->field->locationHUDWindow)->toBe($firstHud)->and($firstHud->isActive)->toBeTrue()
        ->and($secondHud->isActive)->toBeFalse()->and($second->locationHUDWindow)->not->toBe($secondHud)
        ->and($this->ui->uiElements->toArray())->toBe([$firstHud, $second->locationHUDWindow]);
    $second->stop();
    $second->stop();
    expect($second->locationHUDWindow)->toBeNull()->and($firstHud->isActive)->toBeTrue()
        ->and($this->ui->uiElements->toArray())->toBe([$firstHud]);
});

it('hides its HUD before any excursion target and restores the same owned instance on return', function (bool $active) {
    $hud = $this->field->locationHUDWindow;
    if (!$active) { $this->ui->deactivateElement($hud); }
    $session = $this->manager->beginSceneExcursion($this->field, 1, createFieldHudLifecycleConfig());
    expect($this->target->hudActiveAtConfigure)->toBeFalse()->and($hud->isActive)->toBeFalse()
        ->and($this->field->locationHUDWindow)->toBe($hud)->and($this->ui->uiElements->toArray())->toBe([$hud]);
    $this->manager->finishSceneExcursion($session, createFieldHudLifecycleOutcome());
    expect($this->manager->currentScene)->toBe($this->field)->and($this->field->locationHUDWindow)->toBe($hud)
        ->and($hud->isActive)->toBe($active)->and($this->field->fieldState->hudActiveDuringRenders)->toBe([true, $active]);
})->with([true, false]);

it('retains the exact field HUD through an excursion into another field of the same class', function () {
    $firstHud = $this->field->locationHUDWindow;
    $second = new FieldHudLifecycleField($this->manager);
    $this->manager->addScenes($second);
    $session = $this->manager->beginSceneExcursion($this->field, 2, createFieldHudLifecycleConfig());
    $secondHud = $second->locationHUDWindow;
    expect($this->manager->currentScene)->toBe($second)->and($this->field->locationHUDWindow)->toBe($firstHud)
        ->and($firstHud->isActive)->toBeFalse()->and($secondHud)->not->toBe($firstHud)
        ->and($secondHud->isActive)->toBeTrue()->and($this->ui->uiElements->toArray())->toBe([$firstHud, $secondHud]);
    $second->suspend();
    $second->resume();
    expect($firstHud->isActive)->toBeFalse()->and($secondHud->isActive)->toBeTrue();
    $this->manager->finishSceneExcursion($session, createFieldHudLifecycleOutcome());
    expect($this->manager->currentScene)->toBe($this->field)->and($this->field->locationHUDWindow)->toBe($firstHud)
        ->and($firstHud->isActive)->toBeTrue()->and($second->locationHUDWindow)->toBeNull()
        ->and($secondHud->isActive)->toBeFalse()->and($this->ui->uiElements->toArray())->toBe([$firstHud]);
});

it('recovers prior HUD visibility after target configure or start failure', function (bool $active, string $failAt) {
    $hud = $this->field->locationHUDWindow;
    if (!$active) { $this->ui->deactivateElement($hud); }
    $this->target->failAt = $failAt;
    expect(fn() => $this->manager->beginSceneExcursion($this->field, 1, createFieldHudLifecycleConfig()))
        ->toThrow(RuntimeException::class, 'Synthetic target ' . $failAt . ' failed.');
    expect($this->manager->currentScene)->toBe($this->field)->and($this->field->locationHUDWindow)->toBe($hud)
        ->and($hud->isActive)->toBe($active)->and($this->target->isStarted())->toBeFalse()
        ->and($this->field->fieldState->hudActiveDuringRenders)->toBe([true, $active]);
    $this->target->failAt = '';
    $session = $this->manager->beginSceneExcursion($this->field, 1, createFieldHudLifecycleConfig());
    $this->manager->finishSceneExcursion($session, createFieldHudLifecycleOutcome());
    expect($hud->isActive)->toBe($active);
})->with([true, false])->with(['configure', 'start']);

it('keeps the original active or inactive intent through repeated suspension and releases on repeated stop', function (bool $active) {
    $hud = $this->field->locationHUDWindow;
    if (!$active) { $this->ui->deactivateElement($hud); }
    $this->field->suspend();
    $this->field->suspend();
    expect($hud->isActive)->toBeFalse();
    $this->field->resume();
    expect($hud->isActive)->toBe($active)->and($this->field->fieldState->hudActiveDuringRenders)->toBe([true, $active]);
    $active ? $this->ui->deactivateElement($hud) : $hud->activate();
    $this->field->suspend();
    $this->field->suspend();
    $this->field->resume();
    expect($hud->isActive)->toBe(!$active);
    $this->field->suspend();
    $this->field->stop();
    $this->field->stop();
    $this->field->suspend();
    $this->field->resume();
    expect($hud->isActive)->toBeFalse()->and($this->field->locationHUDWindow)->toBeNull()
        ->and($this->ui->uiElements->toArray())->toBe([])
        ->and($this->ui->getRenderedElementIds())->not->toContain(spl_object_id($hud));
})->with([true, false]);

it('releases the abandoned caller HUD and allows a fresh configure without stale suspension intent', function () {
    $hud = $this->field->locationHUDWindow;
    $session = $this->manager->beginSceneExcursion($this->field, 1, createFieldHudLifecycleConfig());
    expect($this->manager->abandonSceneExcursion($session))->toBeTrue()
        ->and($session->outcome->status)->toBe(SceneExcursionStatus::ABANDONED)
        ->and($this->field->locationHUDWindow)->toBeNull()->and($hud->isActive)->toBeFalse()
        ->and($this->field->isStarted())->toBeFalse()->and($this->ui->uiElements->toArray())->toBe([]);
    $this->field->configure(createFieldHudLifecycleConfig());
    $this->manager->loadScene(0);
    $replacement = $this->field->locationHUDWindow;
    expect($replacement)->not->toBe($hud)->and($replacement->isActive)->toBeTrue();
    $this->ui->deactivateElement($replacement);
    $this->field->suspend();
    $this->field->resume();
    expect($replacement->isActive)->toBeFalse();
});

it('hides and releases only owned HUD cells while overlapping notifications and unrelated UI remain intact', function (bool $output) {
    $hud = $this->field->locationHUDWindow;
    $other = new class implements UIElementInterface {
        public bool $isActive = true;
        public function activate(): void { $this->isActive = true; }
        public function deactivate(): void { $this->isActive = false; }
        public function render(): void { Console::write('Unrelated', 2, 20); }
        public function erase(): void { throw new RuntimeException('Unrelated UI must not be erased.'); }
    };
    $this->ui->uiElements->add($other);
    $notice = null;
    ob_start();
    try {
        Console::setTerminalOutputEnabled($output);
        Console::setLayerTracking(!$output);
        Console::clear();
        $this->ui->render();
        $notice = new Notification($this->game, NotificationChannel::INFO, 'Synthetic notice', 'Unchanged',
            enterDirection: NotificationSlideDirection::NONE, exitDirection: NotificationSlideDirection::NONE);
        $notice->open(new Vector2(1, 19))->render();
        $overlays = new ReflectionProperty(Console::class, 'overlays')->getValue();
        $noticeCells = array_slice(Console::getBuffer(), 18, 5);
        $this->field->suspend();
        expect($hud->isActive)->toBeFalse()->and($other->isActive)->toBeTrue()
            ->and(new ReflectionProperty(Console::class, 'overlays')->getValue())->toBe($overlays)
            ->and(array_slice(Console::getBuffer(), 18, 5))->toBe($noticeCells)
            ->and($this->ui->getRenderedElementIds())->toBe([spl_object_id($other)]);
        $this->field->resume();
        expect($hud->isActive)->toBeTrue()->and($notice->isFinished())->toBeFalse()
            ->and(new ReflectionProperty(Console::class, 'overlays')->getValue())->toBe($overlays);
        $this->field->stop();
        expect($this->ui->uiElements->toArray())->toBe([$other])->and($other->isActive)->toBeTrue()
            ->and(new ReflectionProperty(Console::class, 'overlays')->getValue())->toBe($overlays)
            ->and($this->ui->getRenderedElementIds())->toBe([spl_object_id($other)])
            ->and($this->ui->getSuppressedElementIds())->toBe([]);
        $notice->dismiss();
        expect(Console::charAt(2, 20))->toBe('U');
    } finally {
        $notice?->dismiss();
        $this->ui->removeElement($other);
        ob_end_clean();
    }
})->with([true, false]);
