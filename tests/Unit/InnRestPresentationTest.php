<?php

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStageSession;
use Ichiloto\Engine\Cutscenes\Presentation\PartyStageSelection;
use Ichiloto\Engine\Entities\Actions\FieldActionContext;
use Ichiloto\Engine\Entities\Actions\SleepAction;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Events\Interpreter\Commands\InnCommand;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Inn\InnOffer;
use Ichiloto\Engine\Inn\InnStay;
use Ichiloto\Engine\Inn\InnStayOutcome;
use Ichiloto\Engine\Inn\Presentation\InnRestPresentation;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Messaging\Dialogue\ConfirmDialogue;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

class InnRestTestGame extends Game
{
  private RendererRuntime $testRuntime;

  public function __construct(string $root)
  {
    // Constructing a runtime does not start its transport; these tests never call start().
    $this->testRuntime = new RendererRuntime(new RendererRuntimeConfig(
      new RendererProcessConfig(['/never-launch-an-inn-test']), $root));
    $this->audioManager = new RecordingAudioManager($this);
  }

  public function getRendererRuntime(): ?RendererRuntime { return $this->testRuntime; }
  public function __destruct() {}
}

class InnRestTestPlayer extends Player
{
  public int $renders = 0;
  public array $wakeSprites = [];
  public function __construct() { $this->position = new Vector2(3, 2); }
  public function render(): void { $this->renders++; }
  public function renderEventCues(): void {}
  public function setFacingSprite(array $sprite, ?MovementHeading $heading = null): void { $this->wakeSprites[] = $sprite; }
}

class InnRestTestMap extends MapManager
{
  public int $renders = 0;
  public function __construct(Game $game, GameScene $scene)
  {
    parent::__construct($game, $scene);
    $this->backgroundMusic = 'field-theme';
  }
  public function render(?int $x = null, ?int $y = null): void { $this->renders++; }
  public function setMusicForTest(string $track): void { $this->backgroundMusic = $track; }
}

class InnRestTestScene extends GameScene
{
  public function __construct(InnRestTestGame $game)
  {
    $this->sceneManager = makeBareScene(SceneManager::class);
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->sceneManager, $game);
    new ReflectionProperty(SceneManager::class, 'currentScene')->setValue($this->sceneManager, $this);
    $this->currentMapId = 'synthetic-inn';
    $this->gameState = new GameState();
    $this->party = new Party();
    $this->party->credit(50);
    $this->party->addMember(new Character('Synthetic guest', 0,
      new Stats(currentHp: 5, currentMp: 1, totalHp: 100, totalMp: 20)));
    $this->player = new InnRestTestPlayer();
    $this->mapManager = new InnRestTestMap($game, $this);
    $this->camera = makeBareScene(Camera::class);
    $this->camera->screen = new Rect(0, 0, 40, 15);
    $this->cinematicStage = new CinematicStageManager($this);
    $this->cinematicPresentation = new CinematicPresentationManager($this);
  }

  public function setMapForTest(string $id): void { $this->currentMapId = $id; }
  public function setPartyForTest(Party $party): void { $this->party = $party; }
}

class InnRestTestModals extends ModalManager
{
  public int $choice = 0;
  public int $alerts = 0;
  public function __construct() {}
  public function select(string $message, array $options, string $title = '', int $default = 0,
    ?Vector2 $position = null, int $width = DEFAULT_SELECT_DIALOG_WIDTH, int $height = DEFAULT_SELECT_DIALOG_HEIGHT): int
  { return $this->choice; }
  public function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void { $this->alerts++; }
}

function makeInnStageSource(): array
{
  return ['fps' => 30, 'lengthFrames' => 10, 'restFrame' => 4,
    'stage' => ['canvas' => ['width' => 400, 'height' => 300], 'startFrame' => 0, 'restoreFrame' => 9,
      'camera' => [['id' => 'still', 'frame' => 0, 'focus' => ['x' => 200, 'y' => 150], 'zoom' => 1, 'easing' => 'hold']],
      'subjects' => [['id' => 'guest', 'position' => ['x' => 200, 'y' => 240], 'size' => ['width' => 80, 'height' => 120]]]],
    'tracks' => [
      ['id' => 'body', 'type' => 'image', 'asset' => 'body.png', 'sheet' => ['columns' => 2, 'rows' => 1],
        'anchor' => 'stage', 'placement' => ['subject' => 'guest'], 'pivot' => ['x' => .5, 'y' => 1],
        'keyframes' => [['frame' => 0, 'duration' => 5], ['frame' => 5, 'duration' => 4, 'sourceFrame' => 1]]],
      ['id' => 'glow', 'type' => 'image', 'asset' => 'glow.png', 'anchor' => 'stage', 'zIndex' => 20,
        'placement' => ['subject' => 'guest', 'position' => ['x' => 0, 'y' => -60], 'size' => ['width' => 40, 'height' => 40]],
        'keyframes' => [['frame' => 0, 'duration' => 9, 'opacity' => .5]]],
    ]];
}

function makeInnRestParty(string ...$actorIds): Party
{
  $party = new Party();
  $party->credit(50);
  foreach ($actorIds as $id) {
    $party->addMember(new Character('Same guest name', 0,
      new Stats(currentHp: 5, currentMp: 1, totalHp: 100, totalMp: 20), actorId: $id));
  }
  return $party;
}

function writeInnRestStage(string $root, string $id, string $subject): void
{
  $source = makeInnStageSource();
  $source['stage']['subjects'][0]['id'] = $subject;
  foreach ($source['tracks'] as &$track) { $track['placement']['subject'] = $subject; }
  unset($track);
  mkdir($root . '/Animations/' . $id, 0700, true);
  file_put_contents($root . '/Animations/' . $id . '/' . $id . '.timeline.php',
    '<?php return ' . var_export($source, true) . ';');
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, Timers::class, ConfigStore::class, AudioManager::class, ModalManager::class, Debug::class] as $class) {
    $this->savedStatics[$class] = (new ReflectionClass($class))->getStaticProperties();
  }
  Timers::clear();
  Timers::setFrameTick(null);
  Console::setTerminalOutputEnabled(false);
  // Borrow a headless screen even if an earlier shutdown handed it back.
  Console::enterAlternateScreen();
  Console::setLayerTracking(true);
  Console::syncDimensions(40, 15);
  putSceneAudioConfig(['inn' => ['sleep_time' => 1], 'graphics' => ['inn' => ['presentation' => 'rest']],
    'audio' => ['bgm' => ['sleep' => 'rest-theme']]]);
  ConfigStore::put(PlaySettings::class, new SceneAudioConfigStub(['screen' => ['width' => 40, 'height' => 15]]));
  $this->root = createTestDirectory('ichiloto-inn-rest-');
  Debug::configure(['log_directory' => $this->root . '/logs']);
  writeTestPng($this->root . '/body.png', 16, 16);
  writeTestPng($this->root . '/glow.png', 8, 8);
  $this->source = makeInnStageSource();
  mkdir($this->root . '/Animations/rest', 0700, true);
  file_put_contents($this->root . '/Animations/rest/rest.timeline.php', '<?php return ' . var_export($this->source, true) . ';');
  $this->library = new EffectTimelineLibrary($this->root);
  $this->game = new InnRestTestGame($this->root);
  $this->scene = new InnRestTestScene($this->game);
  $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(40, 15, 10, 20),
    static fn(string $capability): bool => true));
  $this->modals = new InnRestTestModals();
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $this->modals);
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $this->game->audioManager);
  $this->scene->refreshFieldMusic();
  $this->game->audioManager->calls = [];
});

afterEach(function () {
  $this->scene->cinematicPresentation->clear();
  Timers::setFrameTick(null);
  new ReflectionProperty(Console::class, 'game')->setValue(null, null);
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, null);
  $this->scene = $this->game = $this->modals = null;
  gc_collect_cycles();
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('loads a consumer-neutral stage without acquiring battle cues or the field cache', function () {
  $timeline = $this->library->loadStage('rest');
  expect($timeline->defaults['stage']['subjects'][0]['id'])->toBe('guest')
    ->and($timeline->cueSchedule)->toBeEmpty()
    ->and(fn() => $this->library->load('rest'))->toThrow(InvalidArgumentException::class);
});

it('maps all graphical layers onto the consumers exact duration without changing the source', function (float $duration) {
  $timeline = $this->library->compileStage('rest', $this->source);
  $session = new CinematicStageSession($timeline, $this->root, $duration);
  expect($session->timing->durationSeconds)->toEqualWithDelta($duration, .0000001)
    ->and($timeline->fps)->toBe(30);
  $session->advanceTo($duration * .5);
  $canvas = $session->getCanvas(400, 300);
  expect($session->currentFrame)->toBe(5)->and($canvas->images)->toHaveCount(2)
    ->and($canvas->images[0]->sourceRect->x)->toBe(8)
    ->and($canvas->images[1]->opacity)->toBe(.5)
    ->and($canvas->images[1]->layer)->toBeGreaterThan($canvas->images[0]->layer);
  $session->advanceTo($duration);
  expect($session->currentFrame)->toBe(9);
})->with([.2, 3.0, 10.0]);

it('accepts repeated progress but refuses nonmonotonic or nonfinite consumer callbacks', function (float $bad) {
  $session = new CinematicStageSession($this->library->loadStage('rest'), $this->root, 3);
  $session->advanceTo(1.5);
  $session->advanceTo(1.5);
  expect($session->currentFrame)->toBe(5)
    ->and(fn() => $session->advanceTo($bad))->toThrow(InvalidArgumentException::class);
})->with([1.4, -1.0, INF, NAN]);

it('holds the safe reduced-motion image frame without altering duration or audio', function () {
  $session = new CinematicStageSession($this->library->loadStage('rest'), $this->root, 3);
  $session->advanceTo(2.4);
  $normal = $session->getCanvas(400, 300);
  $reduced = $session->getCanvas(400, 300, true);
  expect($session->currentFrame)->toBe(8)->and($normal->images[0]->sourceRect->x)->toBe(8)
    ->and($reduced->images[0]->sourceRect->x)->toBe(0)
    ->and($session->timing->durationSeconds)->toEqualWithDelta(3, .000001)
    ->and($this->game->audioManager->calls)->toBeEmpty();
});

it('rejects unsupported standalone stage content at the shared admission boundary', function (Closure $change) {
  $change($this->source);
  expect(fn() => $this->library->compileStage('rest', $this->source))->toThrow(InvalidArgumentException::class);
})->with([
  'missing stage' => [static function (&$s) { unset($s['stage']); }],
  'loop' => [static function (&$s) { $s['playback'] = 'loop'; }],
  'gameplay cue' => [static function (&$s) { $s['cues'] = [['id' => 'heal', 'frame' => 3, 'type' => 'applyEffect', 'payload' => []]]; }],
  'audio cue' => [static function (&$s) { $s['cues'] = [['id' => 'sound', 'frame' => 3, 'type' => 'playSound', 'payload' => ['sound' => 'tone']]]; }],
  'field track' => [static function (&$s) { $s['tracks'][0]['anchor'] = 'target'; }],
  'unsafe rest' => [static function (&$s) { $s['restFrame'] = 9; }],
]);

it('does not load unselected graphical dependencies for the independent Terminal variant', function () {
  unlink($this->root . '/body.png');
  $graphical = $this->source;
  $graphical['stage']['camera'][0]['zoom'] = 'invalid';
  $terminal = ['fps' => 5, 'lengthFrames' => 5, 'tracks' => [['id' => 'glyph', 'type' => 'glyph',
    'keyframes' => [['frame' => 0, 'duration' => 5, 'content' => 'Z']]]]];
  $compiled = $this->library->compile('rest', ['presentations' => ['terminal' => $terminal, 'graphical' => $graphical]],
    presentation: EffectPresentation::TERMINAL, forStage: true);
  expect($compiled->defaults)->not->toHaveKey('stage')->and($compiled->playbackSegments[0]['layer'])->toBe('glyph');
});

it('releases only one handle and keeps its parent cover and sibling stage owned', function () {
  $manager = $this->scene->cinematicPresentation;
  $manager->hideField();
  $first = $manager->beginStagePresentation($this->library->loadStage('rest'), $this->root, 3, ['first-z']);
  $second = $manager->beginStagePresentation($this->library->loadStage('rest'), $this->root, 3, ['second-z']);
  $manager->releaseStagePresentation($second);
  $manager->releaseStagePresentation($second);
  expect($manager->hasStagePresentation($first))->toBeTrue()->and($second->isReleased)->toBeTrue()
    ->and($manager->hasTransitionCover())->toBeTrue()->and($manager->getStageExcludedLayers())->toBe(['first-z']);
  $canvas = $this->scene->getPresentationOverlay(400, 300);
  expect($canvas->images)->toHaveCount(2)->and($canvas->composites)->toHaveCount(2)
    ->and($canvas->images[0]->layer)->toBeGreaterThan($canvas->composites[0]->layer);
  $manager->releaseStagePresentation($first);
  expect($manager->getStageCanvas(400, 300))->toBeNull()->and($manager->hasTransitionCover())->toBeTrue();
});

it('invalidates released handles on transfer, same-map teardown and shutdown instead of resurrecting them', function (string $boundary) {
  $manager = $this->scene->cinematicPresentation;
  $session = $manager->beginStagePresentation($this->library->loadStage('rest'), $this->root, 3);
  match ($boundary) {
    'transfer' => $this->scene->setMapForTest('another-map'),
    'same-map' => $this->scene->cinematicStage->clear(),
    'shutdown' => new ReflectionProperty(GameScene::class, 'isStopping')->setValue($this->scene, true),
    'clear' => $manager->clear(),
  };
  $session->advanceTo(2);
  expect($session->getCanvas(400, 300))->toBeNull();
  expect($manager->hasStagePresentation($session))->toBeFalse()->and($session->isReleased)->toBeTrue();
  $session->advanceTo(2);
  expect($session->getCanvas(400, 300))->toBeNull()->and($manager->getStageCanvas(400, 300))->toBeNull();
})->with(['transfer', 'same-map', 'shutdown', 'clear']);

it('reads replacement art dimensions and releases only graphical replacement on a later asset failure', function () {
  $manager = $this->scene->cinematicPresentation;
  $session = $manager->beginStagePresentation($this->library->loadStage('rest'), $this->root, 3, ['inn-rest']);
  $before = $manager->getStageCanvas(400, 300);
  writeTestPng($this->root . '/body.png', 32, 32);
  $after = $manager->getStageCanvas(400, 300);
  expect($after->images[0]->sourceRect->width)->toBe(16)
    ->and($after->images[0]->destination->toArray())->toBe($before->images[0]->destination->toArray());
  unlink($this->root . '/body.png');
  expect($manager->getStageCanvas(400, 300))->toBeNull()->and($session->hasPresentationFailure)->toBeTrue()
    ->and($manager->getStageExcludedLayers())->toBeEmpty()->and($manager->hasStagePresentation($session))->toBeTrue();
});

it('keeps both inn entry routes on five Terminal beats and the same graphical recovery lifetime', function (string $entry) {
  $rows = $frames = [];
  $budgets = [];
  $ticks = 0;
  $member = $this->scene->party->members->toArray()[0];
  Timers::setFrameTick(function () use (&$ticks, $member) {
    $ticks++;
    expect($member->stats->currentHp)->toBe(5)->and($this->scene->party->accountBalance)->toBe(20);
  }, function () use (&$rows, &$frames, &$budgets) {
    $rows[] = trim(implode('', Console::getBuffer()));
    $entries = new ReflectionProperty(CinematicPresentationManager::class, 'stages')->getValue($this->scene->cinematicPresentation);
    $session = reset($entries)['session'];
    $frames[] = $session->currentFrame;
    $budgets[] = $session->timing->durationSeconds;
    $canvas = $this->scene->getPresentationOverlay(400, 300);
    expect($canvas)->not->toBeNull();
  });
  $data = ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'cost' => 30,
    'spawnPoint' => ['x' => 9, 'y' => 7], 'spawnSprite' => ['wake'], 'resultVariable' => 'rest-result'];
  if ($entry === 'script') {
    (new InnCommand())->execute(new ScriptCommandContext($this->scene), $data);
    expect($this->scene->gameState->getVariable('rest-result'))->toBe('stayed');
  } else {
    $trigger = makeBareScene(SleepEventTrigger::class);
    new ReflectionProperty(SleepEventTrigger::class, 'offer')->setValue($trigger, InnOffer::fromData($data));
    $action = new SleepAction($trigger);
    $this->scene->player->availableAction = $action;
    $action->execute(new FieldActionContext($this->scene->player, $this->scene, $this->scene->player->position));
  }
  expect(array_values(array_unique($rows)))->toBe(['Z', 'Zz', 'ZzZ', 'ZzZz', 'ZzZzZ'])
    ->and(array_values(array_unique($budgets)))->toBe([1.0])->and($ticks)->toBeGreaterThan(0)
    ->and(min($frames))->toBe(0)->and(max($frames))->toBe(9);
  $sorted = $frames;
  sort($sorted);
  expect($frames)->toBe($sorted)->and([$member->stats->currentHp, $member->stats->currentMp])->toBe([100, 20])
    ->and([$this->scene->player->position->x, $this->scene->player->position->y])->toEqual([9, 7])
    ->and($this->scene->player->wakeSprites)->toBe([['wake']])->and($this->scene->player->availableAction)->toBeNull()
    ->and($this->scene->mapManager->renders)->toBe(1)->and($this->scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull()
    ->and($this->game->audioManager->calls)->toBe([['playBackgroundMusic', 'rest-theme'], ['playBackgroundMusic', 'field-theme']]);
})->with(['script', 'sleep-action']);

it('does not start or pay for a declined or unaffordable rest', function (bool $affordable) {
  if ($affordable) { $this->modals->choice = 1; } else { $this->scene->party->debit(50); }
  $ticks = 0;
  Timers::setFrameTick(function () use (&$ticks) { $ticks++; });
  $outcome = (new InnStay(new InnOffer(new ConfirmDialogue('', 'Synthetic rest?'), 30, presentation: 'rest')))->perform($this->scene);
  expect($outcome)->toBe($affordable ? InnStayOutcome::DECLINED : InnStayOutcome::UNAFFORDABLE)
    ->and($ticks)->toBe(0)->and($this->scene->party->accountBalance)->toBe($affordable ? 50 : 0)
    ->and($this->scene->party->members->toArray()[0]->stats->currentHp)->toBe(5)
    ->and($this->game->audioManager->calls)->toBeEmpty()->and($this->modals->alerts)->toBe($affordable ? 0 : 1)
    ->and($this->scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull();
})->with([true, false]);

it('preserves Terminal recovery when graphical capabilities or optional assets are unavailable', function (bool $terminal) {
  if ($terminal) {
    $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(40, 15, 10, 20),
      static fn(string $capability): bool => false, graphical: false));
  }
  $outcome = (new InnStay(new InnOffer(new ConfirmDialogue('', 'Synthetic rest?'), 30, presentation: 'missing')))->perform($this->scene);
  expect($outcome)->toBe(InnStayOutcome::STAYED)->and($this->scene->party->members->toArray()[0]->stats->currentHp)->toBe(100)
    ->and($this->scene->cinematicPresentation->getStageExcludedLayers())->toBeEmpty();
})->with([true, false]);

it('restores audio and closes only the rest handle when a blocked frame fails', function () {
  $manager = $this->scene->cinematicPresentation;
  $manager->hideField();
  $parent = $manager->beginStagePresentation($this->library->loadStage('rest'), $this->root, 5);
  Timers::setFrameTick(static fn() => throw new RuntimeException('synthetic frame failure'));
  expect(fn() => (new InnStay(new InnOffer(new ConfirmDialogue('', 'Rest?'), 30)))->perform($this->scene))
    ->toThrow(RuntimeException::class, 'synthetic frame failure');
  expect($manager->hasStagePresentation($parent))->toBeTrue()->and($manager->hasTransitionCover())->toBeTrue()
    ->and($manager->getStageExcludedLayers())->toBeEmpty()->and($this->scene->party->accountBalance)->toBe(20)
    ->and($this->scene->party->members->toArray()[0]->stats->currentHp)->toBe(5)
    ->and(new ReflectionProperty(GameScene::class, 'fieldMusicHolds')->getValue($this->scene))->toBe(0)
    ->and($this->game->audioManager->currentBackgroundMusic)->toBe('field-theme');
});

it('aborts stale wake and recovery after teardown during the wait', function (string $boundary) {
  Timers::setFrameTick(function () use ($boundary) {
    if ($boundary === 'transfer') { $this->scene->setMapForTest('another-map'); }
    else { $this->scene->cinematicPresentation->clear(); }
  });
  $stay = new InnStay(new InnOffer(new ConfirmDialogue('', 'Rest?'), 30, new Vector2(9, 7), ['wake']));
  expect(fn() => $stay->perform($this->scene))->toThrow(RuntimeException::class, 'Inn stay interrupted');
  expect($this->scene->party->members->toArray()[0]->stats->currentHp)->toBe(5)
    ->and([$this->scene->player->position->x, $this->scene->player->position->y])->toEqual([3, 2])
    ->and($this->scene->player->wakeSprites)->toBeEmpty()->and($this->scene->mapManager->renders)->toBe(0)
    ->and($this->scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull()
    ->and(new ReflectionProperty(GameScene::class, 'fieldMusicHolds')->getValue($this->scene))->toBe(0);
})->with(['transfer', 'shutdown-clear']);

it('uses a local stable reference instead of the project default and rejects unsafe reference shapes', function () {
  $offer = InnOffer::fromData(['confirmDialogue' => ['text' => 'Rest?'], 'presentation' => 'rest']);
  putSceneAudioConfig(['graphics' => ['inn' => ['presentation' => 'missing']]]);
  $presentation = new InnRestPresentation($this->scene, $offer->presentation, 3);
  expect($this->scene->getPresentationOverlay(400, 300)->images)->toHaveCount(2);
  $presentation->release();
  foreach (['../rest', '', ['rest']] as $bad) {
    expect(fn() => InnOffer::fromData(['confirmDialogue' => ['text' => 'Rest?'], 'presentation' => $bad]))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('derives the visual budget from the existing clamped and microsecond-rounded inn intervals', function (float $configured, float $expected) {
  putSceneAudioConfig(['inn' => ['sleep_time' => $configured], 'graphics' => ['inn' => ['presentation' => 'rest']]]);
  $budget = null;
  Timers::setFrameTick(function () use (&$budget) {
    $entries = new ReflectionProperty(CinematicPresentationManager::class, 'stages')->getValue($this->scene->cinematicPresentation);
    $budget = reset($entries)['session']->timing->durationSeconds;
    throw new RuntimeException('budget inspected');
  });
  expect(fn() => (new InnStay(new InnOffer(new ConfirmDialogue('', 'Rest?'))))->perform($this->scene))
    ->toThrow(RuntimeException::class, 'budget inspected');
  expect($budget)->toEqualWithDelta($expected, .00000001);
})->with([[.5, 1.0], [12.0, 10.0], [3.1415926, 3.14159]]);

it('guards recovery when transfer occurs in the very last presentation pump', function () {
  Timers::setFrameTick(null, function () {
    $entries = new ReflectionProperty(CinematicPresentationManager::class, 'stages')->getValue($this->scene->cinematicPresentation);
    $session = reset($entries)['session'];
    $elapsed = new ReflectionProperty(CinematicStageSession::class, 'elapsedSeconds')->getValue($session);
    if ($elapsed >= $session->timing->durationSeconds) { $this->scene->setMapForTest('another-map'); }
  });
  expect(fn() => (new InnStay(new InnOffer(new ConfirmDialogue('', 'Rest?'), 30, new Vector2(9, 7))))->perform($this->scene))
    ->toThrow(RuntimeException::class, 'Inn stay interrupted');
  expect($this->scene->party->members->toArray()[0]->stats->currentHp)->toBe(5)
    ->and([$this->scene->player->position->x, $this->scene->player->position->y])->toEqual([3, 2])
    ->and($this->scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull();
});

it('detaches exclusion ownership and keeps overshooting progress monotonic and bounded', function () {
  $layer = 'original-z';
  $session = new CinematicStageSession($this->library->loadStage('rest'), $this->root, 3, [&$layer]);
  $layer = 'changed-z';
  $session->advanceTo(5);
  expect($session->excludedLayers)->toBe(['original-z'])->and($session->currentFrame)->toBe(9)
    ->and(fn() => $session->advanceTo(4))->toThrow(InvalidArgumentException::class);
  $session->advanceTo(PHP_FLOAT_MAX);
  expect($session->currentFrame)->toBe(9);
});

it('keeps unsupported stage capabilities out of overlay replacement', function (string $missing) {
  $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(40, 15, 10, 20),
    static fn(string $capability): bool => $capability !== $missing));
  $presentation = new InnRestPresentation($this->scene, 'rest', 3);
  expect($this->scene->canPresentCinematicStage())->toBeFalse()
    ->and($this->scene->getPresentationOverlay(400, 300))->toBeNull()
    ->and($this->scene->getExcludedOverlayLayers())->toBeEmpty();
  $presentation->advanceTo(3);
  $presentation->release();
})->with([RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_OVERLAY,
  RendererSessionConfig::CANVAS_COMPOSITING, RendererSessionConfig::CANVAS_CLIP_OPACITY,
  RendererSessionConfig::SPRITE_SOURCE_RECT]);

it('does not resurrect originating music after transfer or shutdown hands away ownership', function (string $boundary) {
  Timers::setFrameTick(function () use ($boundary) {
    if ($boundary === 'transfer') {
      $this->scene->setMapForTest('another-map');
      $this->scene->mapManager->setMusicForTest('destination-theme');
    } else {
      new ReflectionProperty(GameScene::class, 'isStopping')->setValue($this->scene, true);
      $this->game->audioManager->stopBackgroundMusic();
    }
  });
  expect(fn() => (new InnStay(new InnOffer(new ConfirmDialogue('', 'Rest?'), 30)))->perform($this->scene))
    ->toThrow(RuntimeException::class, 'Inn stay interrupted');
  expect($this->game->audioManager->currentBackgroundMusic)->toBe($boundary === 'transfer' ? 'destination-theme' : null)
    ->and($this->game->audioManager->calls)->not->toContain(['playBackgroundMusic', 'field-theme'])
    ->and(new ReflectionProperty(GameScene::class, 'fieldMusicHolds')->getValue($this->scene))->toBe(0)
    ->and($this->scene->party->members->toArray()[0]->stats->currentHp)->toBe(5);
})->with(['transfer', 'shutdown']);

it('refreshes actual party selection without restarting the rest clock or releasing sibling ownership', function () {
  writeInnRestStage($this->root, 'alpha-rest', 'alpha-sleep-role');
  writeInnRestStage($this->root, 'beta-rest', 'beta-sleep-role');
  writeInnRestStage($this->root, 'pair-rest', 'pair-role');
  $this->scene->setPartyForTest(makeInnRestParty('alpha'));
  $manager = $this->scene->cinematicPresentation;
  $manager->hideField();
  $sibling = $manager->beginStagePresentation($this->library->loadStage('rest'), $this->root, 5);
  $selection = new PartyStageSelection(PartyStageSelection::PARTY, parties: [
    ['actors' => ['alpha'], 'timeline' => 'alpha-rest'],
    ['actors' => ['beta'], 'timeline' => 'beta-rest'],
    ['actors' => ['alpha', 'beta'], 'timeline' => 'pair-rest'],
  ]);
  $presentation = new InnRestPresentation($this->scene, $selection, 3);
  $property = new ReflectionProperty(InnRestPresentation::class, 'session');
  $solo = $property->getValue($presentation);
  $presentation->advanceTo(.6);
  expect($solo->timeline->defaults['stage']['subjects'][0]['id'])->toBe('alpha-sleep-role')
    ->and($solo->currentFrame)->toBe(2);
  $this->scene->party->addMember(makeInnRestParty('beta')->leader);
  $presentation->advanceTo(1.5);
  $pair = $property->getValue($presentation);
  expect($solo->isReleased)->toBeTrue()->and($manager->hasStagePresentation($solo))->toBeFalse()
    ->and($pair->timeline->sourceId)->toBe('pair-rest')->and($pair->currentFrame)->toBe(5)
    ->and($pair->timing->durationSeconds)->toBe(3.0);
  $this->scene->party->swapMembers(0, 1);
  $presentation->advanceTo(1.5);
  expect($property->getValue($presentation))->toBe($pair);
  $this->scene->setPartyForTest(makeInnRestParty('beta'));
  $presentation->advanceTo(2.4);
  $leader = $property->getValue($presentation);
  expect($pair->isReleased)->toBeTrue()->and($leader->currentFrame)->toBe(8)
    ->and($leader->timeline->defaults['stage']['subjects'][0]['id'])->toBe('beta-sleep-role')
    ->and($leader->timing->durationSeconds)->toBe(3.0);
  $this->scene->setPartyForTest(makeInnRestParty('unknown', 'alpha'));
  $presentation->advanceTo(2.4);
  expect($leader->isReleased)->toBeTrue()->and($property->getValue($presentation))->toBeNull()
    ->and($manager->getStageExcludedLayers())->toBeEmpty();
  $this->scene->setPartyForTest(makeInnRestParty('alpha'));
  $presentation->advanceTo(2.7);
  $rebound = $property->getValue($presentation);
  expect($rebound->currentFrame)->toBe(9)->and($manager->hasStagePresentation($sibling))->toBeTrue()
    ->and($manager->hasTransitionCover())->toBeTrue();
  $presentation->release();
  $presentation->release();
  expect($rebound->isReleased)->toBeTrue()->and($property->getValue($presentation))->toBeNull()
    ->and($manager->hasStagePresentation($sibling))->toBeTrue()->and($sibling->isReleased)->toBeFalse()
    ->and($manager->hasTransitionCover())->toBeTrue();
});

it('can acquire a later valid selection after an initially unbound missing or ambiguous party', function (string $initial) {
  $selection = new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'rest', 'missing' => 'missing-rest']);
  $this->scene->setPartyForTest(match ($initial) {
    'ambiguous' => makeInnRestParty('alpha', 'alpha'),
    'empty' => makeInnRestParty(),
    default => makeInnRestParty($initial),
  });
  $presentation = new InnRestPresentation($this->scene, $selection->toArray(), 3);
  $presentation->advanceTo(1.2);
  expect($this->scene->getExcludedOverlayLayers())->toBeEmpty();
  $this->scene->setPartyForTest(makeInnRestParty('alpha'));
  $presentation->advanceTo(1.5);
  $session = new ReflectionProperty(InnRestPresentation::class, 'session')->getValue($presentation);
  expect($session->currentFrame)->toBe(5)->and($session->timing->durationSeconds)->toBe(3.0)
    ->and($this->scene->getPresentationOverlay(400, 300)->images)->toHaveCount(2);
  $presentation->release();
})->with(['unknown', 'missing', 'ambiguous', 'empty']);

it('keeps elapsed time monotonic across selection replacement and absent graphics', function (bool $graphical) {
  $this->scene->setPartyForTest(makeInnRestParty('unknown'));
  if (!$graphical) {
    $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(40, 15, 10, 20),
      static fn(string $capability): bool => false, graphical: false));
  }
  $presentation = new InnRestPresentation($this->scene, new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'rest']), 3);
  $presentation->advanceTo(1.5);
  $this->scene->setPartyForTest(makeInnRestParty('alpha'));
  foreach ([1.4, -1.0, INF, NAN] as $bad) {
    expect(fn() => $presentation->advanceTo($bad))->toThrow(InvalidArgumentException::class, 'finite and monotonic');
  }
  $presentation->advanceTo(1.5);
  $presentation->release();
})->with([true, false]);

it('keeps party-resolved project defaults on both inn routes with unchanged beats recovery wake and music', function (string $entry) {
  $this->scene->setPartyForTest(makeInnRestParty('beta', 'alpha'));
  putSceneAudioConfig(['inn' => ['sleep_time' => 1], 'graphics' => ['inn' => ['presentation' => [
    'treatment' => 'party',
    'parties' => [['actors' => ['alpha', 'beta'], 'timeline' => 'rest']],
  ]]], 'audio' => ['bgm' => ['sleep' => 'rest-theme']]]);
  $rows = [];
  Timers::setFrameTick(null, function () use (&$rows) {
    $rows[] = trim(implode('', Console::getBuffer()));
    $entries = new ReflectionProperty(CinematicPresentationManager::class, 'stages')->getValue($this->scene->cinematicPresentation);
    $session = reset($entries)['session'];
    expect($this->scene->getPresentationOverlay(400, 300)->images)->toHaveCount($session->currentFrame < 9 ? 2 : 0);
  });
  $data = ['confirmDialogue' => ['text' => 'Rest?'], 'cost' => 30,
    'spawnPoint' => ['x' => 9, 'y' => 7], 'spawnSprite' => ['wake']];
  if ($entry === 'script') {
    (new InnCommand())->execute(new ScriptCommandContext($this->scene), $data);
  } else {
    $trigger = makeBareScene(SleepEventTrigger::class);
    new ReflectionProperty(SleepEventTrigger::class, 'offer')->setValue($trigger, InnOffer::fromData($data));
    (new SleepAction($trigger))->execute(new FieldActionContext($this->scene->player, $this->scene, $this->scene->player->position));
  }
  expect(array_values(array_unique($rows)))->toBe(['Z', 'Zz', 'ZzZ', 'ZzZz', 'ZzZzZ'])
    ->and($this->scene->party->accountBalance)->toBe(20)
    ->and([$this->scene->player->position->x, $this->scene->player->position->y])->toEqual([9, 7])
    ->and($this->scene->player->wakeSprites)->toBe([['wake']])
    ->and($this->game->audioManager->calls)->toBe([['playBackgroundMusic', 'rest-theme'], ['playBackgroundMusic', 'field-theme']])
    ->and($this->scene->cinematicPresentation->getStageExcludedLayers())->toBeEmpty();
  foreach ($this->scene->party->members as $member) {
    expect([$member->stats->currentHp, $member->stats->currentMp])->toBe([100, 20]);
  }
})->with(['script', 'sleep-action']);

it('does not reattempt or repeat diagnostics for the same missing optional party timeline every frame', function () {
  $this->scene->setPartyForTest(makeInnRestParty('alpha'));
  $presentation = new InnRestPresentation($this->scene, new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'missing-rest']), 3);
  $before = file_get_contents($this->root . '/logs/warning.log');
  $presentation->advanceTo(.6);
  $presentation->advanceTo(1.2);
  expect(file_get_contents($this->root . '/logs/warning.log'))->toBe($before)
    ->and($this->scene->getExcludedOverlayLayers())->toBeEmpty();
  $presentation->release();
});

it('releases ambiguous live membership and can rebind the same valid identity without resetting elapsed time', function () {
  $this->scene->setPartyForTest(makeInnRestParty('alpha'));
  $presentation = new InnRestPresentation($this->scene, new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'rest']), 3);
  $property = new ReflectionProperty(InnRestPresentation::class, 'session');
  $first = $property->getValue($presentation);
  $presentation->advanceTo(.6);
  $this->scene->party->addMember(makeInnRestParty('alpha')->leader);
  $presentation->advanceTo(1.2);
  expect($first->isReleased)->toBeTrue()->and($property->getValue($presentation))->toBeNull()
    ->and($this->scene->getExcludedOverlayLayers())->toBeEmpty();
  $this->scene->setPartyForTest(makeInnRestParty('alpha'));
  $presentation->advanceTo(1.5);
  expect($property->getValue($presentation)->currentFrame)->toBe(5);
  $presentation->release();
});

it('does not acquire a stale manager after ownership changes while optional party graphics are absent', function () {
  $this->scene->setPartyForTest(makeInnRestParty('unknown'));
  $presentation = new InnRestPresentation($this->scene, new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'rest']), 3);
  $old = $this->scene->cinematicPresentation;
  new ReflectionProperty(GameScene::class, 'cinematicPresentation')->setValue($this->scene, new CinematicPresentationManager($this->scene));
  $this->scene->setPartyForTest(makeInnRestParty('alpha'));
  expect(fn() => $presentation->advanceTo(1.5))->toThrow(RuntimeException::class, 'Inn stay interrupted')
    ->and($old->getStageCanvas(400, 300))->toBeNull()
    ->and($this->scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull();
});

it('updates an intentional leader-only shot for a reordered partial party without requiring exact scenes', function () {
  writeInnRestStage($this->root, 'alpha-rest', 'alpha-role');
  writeInnRestStage($this->root, 'beta-rest', 'beta-role');
  $this->scene->setPartyForTest(makeInnRestParty('alpha', 'beta', 'unbound-reserve'));
  $selection = new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'alpha-rest', 'beta' => 'beta-rest']);
  $presentation = new InnRestPresentation($this->scene, $selection, 3);
  $property = new ReflectionProperty(InnRestPresentation::class, 'session');
  $first = $property->getValue($presentation);
  expect($first->timeline->sourceId)->toBe('alpha-rest');
  $presentation->advanceTo(.6);
  $this->scene->party->swapMembers(0, 1);
  $presentation->advanceTo(1.5);
  $next = $property->getValue($presentation);
  expect($first->isReleased)->toBeTrue()->and($next->timeline->sourceId)->toBe('beta-rest')
    ->and($next->currentFrame)->toBe(5)->and($next->timing->durationSeconds)->toBe(3.0);
  $presentation->release();
});

it('preserves all rest outcomes when an optional party descriptor cannot present graphics', function (string $failure) {
  $this->scene->setPartyForTest(makeInnRestParty('alpha', 'beta'));
  $selection = match ($failure) {
    'partial-exact-party' => ['treatment' => 'party', 'parties' => [
      ['actors' => ['alpha', 'beta', 'absent'], 'timeline' => 'rest'],
    ]],
    'malformed' => ['treatment' => 'party', 'leaders' => ['alpha' => 'rest']],
    default => ['treatment' => 'leader', 'leaders' => ['alpha' => $failure === 'missing' ? 'missing-rest' : 'rest']],
  };
  if ($failure === 'capability') {
    $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(40, 15, 10, 20),
      static fn(string $capability): bool => false, graphical: false));
  }
  putSceneAudioConfig(['inn' => ['sleep_time' => 1], 'graphics' => ['inn' => ['presentation' => $selection]],
    'audio' => ['bgm' => ['sleep' => 'rest-theme']]]);
  $rows = [];
  Timers::setFrameTick(null, function () use (&$rows) {
    $rows[] = trim(implode('', Console::getBuffer()));
    expect($this->scene->getExcludedOverlayLayers())->toBeEmpty();
  });
  $outcome = (new InnStay(new InnOffer(new ConfirmDialogue('', 'Rest?'), 30, new Vector2(9, 7), ['wake'])))->perform($this->scene);
  expect($outcome)->toBe(InnStayOutcome::STAYED)
    ->and(array_values(array_unique($rows)))->toBe(['Z', 'Zz', 'ZzZ', 'ZzZz', 'ZzZzZ'])
    ->and($this->scene->party->accountBalance)->toBe(20)
    ->and([$this->scene->player->position->x, $this->scene->player->position->y])->toEqual([9, 7])
    ->and($this->scene->player->wakeSprites)->toBe([['wake']])
    ->and($this->game->audioManager->calls)->toBe([['playBackgroundMusic', 'rest-theme'], ['playBackgroundMusic', 'field-theme']]);
  foreach ($this->scene->party->members as $member) {
    expect([$member->stats->currentHp, $member->stats->currentMp])->toBe([100, 20]);
  }
})->with(['partial-exact-party', 'missing', 'capability', 'malformed']);
