<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventDialoguePresentationInterface;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\Interpreter\ModalEventPresentation;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Dialogue\Dialogue;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueCanvasPresentation;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class EmotionTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

final class EmotionTestScene extends GameScene
{
  public int $restores = 0;
  public function __construct(private Game $testGame)
  {
    $this->gameState = new GameState();
    $this->party = new Party();
    $this->currentMapId = 'synthetic-map';
  }
  public function getGame(): Game { return $this->testGame; }
  public function onEventSessionStarted(EventExecutionSession $session): void {}
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void {}
  public function restoreFieldAfterOverlay(): void { $this->restores++; }
}

class EmotionLegacyPresentation implements EventPresentationInterface
{
  public array $lines = [];
  public bool $complete = false;
  public ?int $choice = null;
  public function beginText(string $text, string $speaker): void
  { $this->lines[] = [$text, $speaker]; $this->complete = false; }
  public function beginChoice(string $prompt, array $options, string $title = ''): void
  { $this->complete = false; }
  public function update(): void {}
  public function render(): void {}
  public function isComplete(): bool { return $this->complete; }
  public function choiceResult(): ?int { return $this->choice; }
  public function reset(): void { $this->complete = false; $this->choice = null; }
}

final class EmotionContextPresentation extends EmotionLegacyPresentation implements EventDialoguePresentationInterface
{
  public array $contexts = [];
  public ?DialogueContext $current = null;
  public function beginDialogue(string $text, string $speaker, DialogueContext $context): void
  { parent::beginText($text, $speaker); $this->contexts[] = $this->current = $context; }
  public function reset(): void { parent::reset(); $this->current = null; }
}

// The inherited factory must not require a fourth constructor argument from existing extensions.
final class EmotionLegacyDialogue extends Dialogue
{
  public function __construct(string $name, string $text, array $face = [])
  { parent::__construct($name, $text, $face); }
}

function getEmotionTestModal(ModalEventPresentation $presentation): ?TextBoxModal
{
  return new ReflectionProperty(ModalEventPresentation::class, 'modal')->getValue($presentation);
}

beforeEach(function () {
  $this->states = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, EventManager::class,
    Debug::class, PngAssetPreflight::class] as $class) {
    $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = createTestDirectory('text-emotion-');
  Debug::configure(['log_directory' => $this->root]);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
  putSceneAudioConfig([]);
  ob_start();
  Console::syncDimensions(80, 24);
  $this->scene = new EmotionTestScene(new EmotionTestGame());
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->states as $class => $state) {
    foreach ($state as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
});

it('passes line-scoped emotion through events and cinematics without changing text or speaker', function (bool $cinematic) {
  $presentation = new EmotionContextPresentation();
  $interpreter = new EventInterpreter($this->scene, $presentation);
  $commands = [
    ['type' => 'text', 'name' => 'Display Speaker', 'text' => 'Synthetic first.', 'emotion' => 'Focused.Custom'],
    ['type' => 'text', 'name' => 'Other Speaker', 'text' => 'Synthetic next.'],
    ['type' => 'set_switch', 'name' => 'finished', 'value' => true],
  ];
  $session = $cinematic
    ? $interpreter->runCinematic(CinematicDefinition::fromArrays(['id' => 'emotion-synthetic', 'name' => 'Synthetic'], $commands))
    : $interpreter->run($commands);
  $context = $presentation->current;
  $interpreter->update(.2);
  expect($presentation->current)->toBe($context)
    ->and($context->emotion)->toBe('Focused.Custom')->and($context->actorId)->toBeNull()
    ->and($this->scene->gameState->getSwitch('finished'))->toBeFalse();
  $presentation->complete = true;
  $interpreter->update(.2);
  expect($presentation->current->emotion)->toBe('Neutral')->and($presentation->current)->not->toBe($context)
    ->and($presentation->lines)->toBe([['Synthetic first.', 'Display Speaker'], ['Synthetic next.', 'Other Speaker']]);
  $presentation->complete = true;
  $interpreter->update(.2);
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)->and($presentation->current)->toBeNull()
    ->and($this->scene->gameState->getSwitch('finished'))->toBeTrue();
})->with([false, true]);

it('keeps existing two-argument text adapters compatible', function () {
  $presentation = new EmotionLegacyPresentation();
  $interpreter = new EventInterpreter($this->scene, $presentation);
  $session = $interpreter->run([['type' => 'text', 'name' => 'Unchanged', 'text' => 'Unchanged text.', 'emotion' => 'Concerned']]);
  expect($presentation->lines)->toBe([['Unchanged text.', 'Unchanged']]);
  $presentation->complete = true;
  $interpreter->update(0);
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED);
});

it('cleans up expression context on interruption and does not leak through choices or later sessions', function () {
  $presentation = new EmotionContextPresentation();
  $interpreter = new EventInterpreter($this->scene, $presentation);
  $session = $interpreter->run([['type' => 'text', 'text' => 'Interrupted.', 'emotion' => 'Alert']]);
  $interpreter->failActiveSession('synthetic interruption');
  expect($session->status)->toBe(EventExecutionStatus::FAILED)->and($presentation->current)->toBeNull();
  $interpreter->run([
    ['type' => 'choice', 'options' => [['text' => 'Continue', 'then' => [['type' => 'text', 'text' => 'Default.']]]]],
  ]);
  expect($presentation->current)->toBeNull();
  $presentation->choice = 0; $presentation->complete = true;
  $interpreter->update(0);
  expect($presentation->current->emotion)->toBe('Neutral');
  $interpreter->failActiveSession('test cleanup');
});

it('rejects invalid emotion at the shared parser, cinematic hydration and ordinary event boundary', function (mixed $emotion) {
  $line = ['type' => 'text', 'text' => 'Synthetic.', 'emotion' => $emotion];
  expect(fn() => DialogueContext::getFromText($line))->toThrow(InvalidArgumentException::class)
    ->and(fn() => Dialogue::fromArray($line))->toThrow(InvalidArgumentException::class)
    ->and(fn() => CinematicDefinition::fromArrays(['id' => 'invalid-emotion', 'name' => 'Synthetic'], [
      ['type' => 'sequence', 'commands' => [$line]],
    ]))->toThrow(InvalidArgumentException::class, '/emotion');
  $presentation = new EmotionContextPresentation();
  $session = new EventInterpreter($this->scene, $presentation)->run([$line,
    ['type' => 'set_switch', 'name' => 'unsafe', 'value' => true]]);
  expect($session->status)->toBe(EventExecutionStatus::FAILED)->and($presentation->contexts)->toBe([])
    ->and($this->scene->gameState->getSwitch('unsafe'))->toBeFalse();
})->with(['empty' => [''], 'whitespace' => ['  '], 'null' => [null], 'array' => [[]], 'boolean' => [false], 'integer' => [1]]);

it('preserves arbitrary case-sensitive catalogue keys and authored omission in schema and factories', function () {
  $line = ['type' => 'text', 'name' => 'A label', 'text' => 'A line.', 'futureMetadata' => ['keep' => true]];
  $definition = CinematicDefinition::fromArrays(['id' => 'round-trip-contract', 'name' => 'Synthetic'], [$line]);
  $dialogue = EmotionLegacyDialogue::fromObject((object) ($line + ['emotion' => 'custom.Expression']));
  expect($definition->commands)->toBe([$line])->and($dialogue->name)->toBe('A label')
    ->and($dialogue->text)->toBe('A line.')->and($dialogue->face)->toBe([])
    ->and($dialogue->presentation->emotion)->toBe('custom.Expression')
    ->and(new Dialogue('A label', 'A line.')->presentation->emotion)->toBe('Neutral')
    ->and(CinematicCommandSchema::export()['textPresentation']['fields'])->toBe(['emotion'])
    ->and(CinematicCommandSchema::export()['textPresentation']['emotion']['default'])->toBe('Neutral');
});

it('validates emotion in every nested authoring shape without flattening the command tree', function (string $shape) {
  $line = ['type' => 'text', 'text' => 'Synthetic.', 'emotion' => 'Custom.Expression'];
  $wrap = static fn(array $line): array => match ($shape) {
    'sequence' => ['type' => 'sequence', 'commands' => [$line]],
    'parallel' => ['type' => 'parallel', 'lanes' => [['id' => 'speaker', 'commands' => [$line]]]],
    'branch' => ['type' => 'branch', 'then' => [$line], 'else' => []],
    'choice' => ['type' => 'choice', 'options' => [['text' => 'Continue', 'then' => [$line]]]],
    'cancel' => ['type' => 'choice', 'options' => [], 'cancel' => [$line]],
  };
  $commands = [$wrap($line)];
  expect(CinematicDefinition::fromArrays(['id' => 'nested-expression', 'name' => 'Synthetic'], $commands)->commands)
    ->toBe($commands);
  $line['emotion'] = null;
  expect(fn() => CinematicDefinition::fromArrays(['id' => 'nested-expression', 'name' => 'Synthetic'], [$wrap($line)]))
    ->toThrow(InvalidArgumentException::class, '/emotion');
})->with(['sequence', 'parallel', 'branch', 'choice', 'cancel']);

it('retains text when speaker art is unbound and never adds portraits to narration', function (string $speaker) {
  $adapter = new ModalEventPresentation($this->scene);
  $adapter->beginDialogue('Synthetic prose.', $speaker, DialogueContext::getFromText(['emotion' => 'Custom.Expression']));
  $snapshot = getEmotionTestModal($adapter)->getDialogueSnapshot();
  $catalogue = new DialoguePresentationCatalog(theme: new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']));
  $canvas = DialogueCanvasPresentation::compose($snapshot, $catalogue);
  expect($snapshot->speaker)->toBe($speaker)->and($snapshot->page)->toBe('Synthetic prose.')
    ->and($catalogue->resolveSpeakerId(null, $speaker))->toBeNull()
    ->and(array_filter($canvas->images, fn($image) => str_contains($image->id, 'portrait')))->toBe([]);
  $adapter->reset();
})->with(['Unbound display speaker', '']);

it('reaches real modal snapshots and shared portraits independently of terminal text and mutable artwork', function (bool $resource) {
  foreach (['base', 'neutral', 'expression', 'bust', 'replacement'] as $asset) {
    writeTestPng($this->root . '/' . $asset . '.png', 16, 24);
  }
  $record = ['portrait' => 'base.png', 'bust' => 'bust.png', 'emotions' => [
    'Neutral' => ['portrait' => 'neutral.png'], 'Focused.Custom' => ['portrait' => 'expression.png'],
  ]];
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $catalogue = new DialoguePresentationCatalog($resource ? [] : ['stable' => $record], $theme,
    ['Display Speaker' => 'stable'], resources: $resource ? ['stable' => $record] : []);
  $adapter = new ModalEventPresentation($this->scene);
  $context = DialogueContext::getFromText(['emotion' => 'Focused.Custom']);
  $adapter->beginDialogue('Synthetic prose.', 'Display Speaker', $context);
  $snapshot = getEmotionTestModal($adapter)->getDialogueSnapshot();
  $canvas = DialogueCanvasPresentation::compose($snapshot, $catalogue);
  expect($snapshot->context)->toBe($context)->and($snapshot->speaker)->toBe('Display Speaker')
    ->and(array_column($canvas->images, 'asset'))->toContain('expression.png')->not->toContain('bust.png')
    ->and($catalogue->resolveSpeakerId($context->actorId, $snapshot->speaker))->toBe('stable');
  // Rebinding and replacing image dimensions do not alter gameplay identity or the text snapshot.
  $record['emotions']['Focused.Custom']['portrait'] = 'replacement.png';
  writeTestPng($this->root . '/replacement.png', 31, 47);
  $rebound = new DialoguePresentationCatalog($resource ? [] : ['stable' => $record], $theme,
    ['Display Speaker' => 'stable'], resources: $resource ? ['stable' => $record] : []);
  expect(array_column(DialogueCanvasPresentation::compose($snapshot, $rebound)->images, 'asset'))->toContain('replacement.png')
    ->and($rebound->getArtwork('stable', 'Unknown', 'portrait'))->toBe('neutral.png');
  unlink($this->root . '/replacement.png');
  expect($rebound->getArtwork('stable', 'Focused.Custom', 'portrait'))->toBe('neutral.png');
  unlink($this->root . '/neutral.png');
  expect($rebound->getArtwork('stable', 'Focused.Custom', 'portrait'))->toBe('base.png');
  $adapter->beginText('Synthetic prose.', 'Display Speaker');
  $default = getEmotionTestModal($adapter)->getDialogueSnapshot();
  expect($default->context->emotion)->toBe('Neutral')->and($default->page)->toBe($snapshot->page)
    ->and($default->speaker)->toBe($snapshot->speaker);
  $adapter->reset();
  expect(getEmotionTestModal($adapter))->toBeNull()->and($this->scene->restores)->toBeGreaterThan(0);
})->with([false, true]);
