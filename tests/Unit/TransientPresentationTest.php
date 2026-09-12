<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationPlayer;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutscenePlayer;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\NotificationInterface;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Enumerations\TransitionStyle;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleStartState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Modal\Modal;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

/** Isolates target placement, while using production popup storage, formatting and drawing. */
final class TransientBattleField extends BattleFieldWindow
{
  public function __construct()
  {
    $this->position = new Vector2();
    $this->width = 80;
    $this->height = 24;
  }
  protected function resolveStatChangePopupAnchor(CharacterInterface $battler): ?array
  {
    return ['x' => 10, 'y' => 5];
  }
}

final class TransientBattleScreen extends BattleScreen
{
  public function __construct()
  {
    $this->screenDimensions = new Rect(0, 0, 80, 24);
    $this->fieldWindow = new TransientBattleField();
  }
  public function hideMessage(): void { Console::write(str_repeat(' ', 80), 0, 0); }
  public function showMessage(string $text): void { Console::write($text, 0, 0); }
  public function refresh(): void { $this->fieldWindow->renderStatChangePopups(); }
  public function refreshField(): void
  {
    Console::recomposeFrame(fn() => $this->fieldWindow->renderStatChangePopups());
  }
}

trait DismissAfterOnePresentedStep
{
  private int $inputCount = 0;
  protected function handleInput(): void
  {
    if (++$this->inputCount > 1) { $this->hide(); }
  }
}

final class TransientModal extends Modal
{
  use DismissAfterOnePresentedStep;
  public function update(): void { $this->content = ['Fresh modal']; }
}

final class TransientSelectModal extends SelectModal
{
  use DismissAfterOnePresentedStep;
  public function update(): void { $this->activeOptionIndex = 1; }
}

final class TransientTextBox extends TextBoxModal
{
  use DismissAfterOnePresentedStep;
  public function update(): void
  {
    // Deterministically advance a real typewriter step without waiting on wall-clock typing.
    $this->currentCharacterIndex = $this->messageLength;
    $this->updateContent();
  }
}

beforeEach(function () {
  $this->states = [];
  foreach ([Console::class, Timers::class, ConfigStore::class, EventManager::class] as $class) {
    $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  Console::syncDimensions(80, 24);
  Console::setLayerTracking(true);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
  $this->transport = new FakeRendererTransport();
  $this->presenter = new RendererPresentation(new RendererClient($this->transport), new RendererGridConfig(80, 24));
  Timers::clear();
  Timers::setFrameTick(null, fn() => $this->presenter->present(Console::presentationSnapshot()));
  ob_start();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->states as $class => $state) {
    foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('presents real action stat popups with their colour during the hold and not after clear', function ($hp, $previousHp, $mp, $previousMp, $text, $index) {
  $state = new ReflectionClass(ActionExecutionState::class)->newInstanceWithoutConstructor();
  $context = new ReflectionClass(TurnStateExecutionContext::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(TurnStateExecutionContext::class, 'ui')->setValue($context, new TransientBattleScreen());
  $target = new Character('Target', 0, new Stats(currentHp: $hp, totalHp: 100, currentMp: $mp, totalMp: 100));
  new ReflectionMethod(ActionExecutionState::class, 'displayStatChanges')
    ->invoke($state, $context, $target, $previousHp, $previousMp, 0.002);
  $during = $this->transport->sent;
  expect($during)->not->toBeEmpty();
  $runs = array_merge(...array_column($during[0]->payload['textLayers'], 'runs'));
  expect(array_any($runs, fn($run) => $run['text'] === $text && $run['foreground'] === ['kind' => 'ansi16', 'index' => $index]))->toBeTrue();
  $this->presenter->present(Console::presentationSnapshot());
  $last = end($this->transport->sent);
  expect(implode('', array_column($last->payload['textLayers'][0]['runs'], 'text')))->not->toContain($text);
})->with([
  [52, 100, 20, 20, '48', 9], [100, 52, 20, 20, '+48', 10], [100, 100, 52, 100, '-48 MP', 14],
]);

it('presents an announcement before its shared action pause clears it', function () {
  $state = new ReflectionClass(ActionExecutionState::class)->newInstanceWithoutConstructor();
  $context = new ReflectionClass(TurnStateExecutionContext::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(TurnStateExecutionContext::class, 'ui')->setValue($context, new TransientBattleScreen());
  new ReflectionMethod(ActionExecutionState::class, 'displayPhase')->invoke($state, $context, 'Turn over!', 0.002, true);
  expect($this->transport->sent[0]->payload['textLayers'][0]['runs'][0]['text'])->toStartWith('Turn over!')
    ->and(Console::snapshot()->rows[0])->not->toContain('Turn over!');
});

it('presents each authored animation frame before advancing', function () {
  new AnimationPlayer(0.01)->play(new Animation(1, 'Test', maxFrames: 3), fn($index) => Console::write((string)$index, 0, 0));
  expect(array_map(fn($message) => $message->payload['textLayers'][0]['runs'][0]['text'][0], $this->transport->sent))->toBe(['1', '2', '3']);
});

it('presents each summon frame and retains PHP cue order', function () {
  $cues = [];
  $cutscene = new SummonCompiledCutscene('test', 'test', fps: 100, cueSchedule: [['frame' => 1]], defaults: ['lengthFrames' => 3]);
  new SummonCutscenePlayer()->play($cutscene, fn($index) => Console::write((string)$index, 0, 0), function ($cue, $index) use (&$cues) { $cues[] = $index; });
  expect(array_map(fn($message) => $message->payload['textLayers'][0]['runs'][0]['text'][0], $this->transport->sent))->toBe(['0', '1', '2'])
    ->and($cues)->toBe([1]);
});

it('presents transition cover above all UI and sprites before it is removed', function () {
  $transition = new class(TransitionStyle::FADE, 20) extends ScreenTransition {
    public function isEnabled(): bool { return true; }
  };
  $transition->out();
  $shades = array_map(fn($message) => mb_substr($message->payload['textLayers'][1]['runs'][0]['text'], 0, 1), $this->transport->sent);
  expect($shades)->toBe(['░', '▒', '▓', '█'])
    ->and($this->transport->sent[0]->payload['textLayers'][1]['layer'])->toBe(3000);
  $transition->in();
  expect(Console::presentationSnapshot()->textLayers)->toHaveCount(1);
});

it('presents battle intro frames before the next intro clear', function () {
  $scene = new ReflectionClass(BattleScene::class)->newInstanceWithoutConstructor();
  $scene->ui = new TransientBattleScreen();
  new ReflectionProperty(BattleScene::class, 'camera')->setValue($scene, new Camera($scene, 80, 24));
  $state = new BattleStartState(new SceneStateContext($scene));
  foreach (['frames' => ['FIRST', 'SECOND'], 'totalFrames' => 2, 'sleepTime' => 2000] as $name => $value) {
    new ReflectionProperty($state, $name)->setValue($state, $value);
  }
  $advance = new ReflectionMethod($state, 'playIntroAnimation');
  $advance->invoke($state);
  $advance->invoke($state);
  expect($this->transport->sent)->toHaveCount(2)
    ->and($this->transport->sent[0]->payload['textLayers'][0]['runs'][0]['text'])->toStartWith('FIRST')
    ->and($this->transport->sent[1]->payload['textLayers'][0]['runs'][0]['text'])->toStartWith('SECOND');
});

it('presents fresh direct modal select and typewriter content in a sparse modal layer before dismissal', function ($kind, $expected) {
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  $modal = match ($kind) {
    'modal' => new TransientModal($game, 'Old content', rect: new Rect(5, 5, 30, 5)),
    'select' => new TransientSelectModal($game, 'Choose', ['First', 'Second'], rect: new Rect(5, 5, 30, 5)),
    'text' => new TransientTextBox($game, 'Fresh dialogue'),
  };
  $modal->open();
  expect($this->transport->sent)->not->toBeEmpty();
  $layers = $this->transport->sent[0]->payload['textLayers'];
  expect($layers)->toHaveCount(2)->and($layers[1]['layer'])->toBe(1020)
    ->and(implode('', array_column($layers[1]['runs'], 'text')))->toContain($expected);
  expect(Console::presentationSnapshot()->textLayers)->toHaveCount(1);
})->with([['modal', 'Fresh modal'], ['select', '> Second'], ['text', 'Fresh dialogue']]);

it('presents final animation callback state after background updates without another simulation tick', function () {
  $order = [];
  Timers::setFrameTick(function () use (&$order) { $order[] = 'update'; }, function () use (&$order) { $order[] = 'present'; });
  Timers::wait(0.001, function ($fraction) use (&$order) { $order[] = $fraction === 1.0 ? 'final draw' : 'draw'; });
  expect(array_slice($order, 0, 3))->toBe(['update', 'draw', 'present'])
    ->and(array_slice($order, -2))->toBe(['final draw', 'present']);
});

it('derives UIManager layers from existing priorities and safely nests direct modal rendering', function () {
  $element = new class implements UIElementInterface, LayeredPresentationInterface {
    public bool $isActive = true;
    public function activate(): void { $this->isActive = true; }
    public function deactivate(): void { $this->isActive = false; }
    public function render(): void { Console::write('HUD', 0, 0); }
    public function erase(): void { Console::write('   ', 0, 0); }
    public function getPresentationBounds(): Rect { return new Rect(0, 0, 3, 1); }
    public function getPresentationPriority(): PresentationPriority { return PresentationPriority::FIELD_HUD; }
  };
  $ui = new ReflectionClass(UIManager::class)->newInstanceWithoutConstructor();
  $elements = new Assegai\Collections\ItemList(UIElementInterface::class);
  $elements->add($element);
  new ReflectionProperty(UIManager::class, 'uiElements')->setValue($ui, $elements);
  $ui->render();
  expect(Console::presentationSnapshot()->textLayers[1]->layer)->toBe(1010);
  $game = new ReflectionClass(Game::class)->newInstanceWithoutConstructor();
  $modal = new TransientModal($game, 'Modal', rect: new Rect(5, 5, 30, 5));
  PresentationLayerPolicy::ui($modal, $modal->render(...));
  expect(array_column(Console::presentationSnapshot()->textLayers, 'layer'))->toBe([0, 1010, 1020]);
});

it('wraps the existing notification renderer above PHP UI without changing its draw arguments', function () {
  $notification = $this->createStub(NotificationInterface::class);
  $notification->method('render')->willReturnCallback(fn($x, $y) => Console::write('Notice', $x, $y));
  $manager = new class($notification) extends NotificationManager {
    public function __construct(private NotificationInterface $notification) {}
    public function __destruct() {}
    protected function getActiveNotification(): ?NotificationInterface { return $this->notification; }
  };
  $manager->render();
  expect(Console::presentationSnapshot()->textLayers[1]->id)->toBe('notifications')
    ->and(Console::presentationSnapshot()->textLayers[1]->layer)->toBe(2000)
    ->and(Console::presentationSnapshot()->textLayers[1]->runs[0]->text)->toBe('Notice');
});
