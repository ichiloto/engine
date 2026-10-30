<?php

declare(strict_types=1);

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;
use Ichiloto\Engine\Events\Interpreter\Commands\PlayStageCommand;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

final class StageCommandScene extends GameScene
{
  private ScenePresentationContext $testContext;
  public function __construct(string $root, private bool $graphical)
  {
    $this->testContext = new ScenePresentationContext(new RendererGridConfig(28, 15, 48, 48),
      static fn(): bool => true, graphical: $graphical, assetRoot: $root);
    $this->currentMapId = 'synthetic-stage-map';
    $this->gameState = new GameState();
    $this->cinematicStage = new CinematicStageManager($this);
    $this->cinematicPresentation = new CinematicPresentationManager($this);
  }
  public function isGraphicalFieldPresented(): bool { return $this->graphical; }
  public function getPresentationContext(): ?ScenePresentationContext { return $this->testContext; }
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void {}
}

function makeStageCoverSource(): array
{
  return ['fps' => 10, 'lengthFrames' => 10, 'restFrame' => 4, 'tracks' => [],
    'stage' => ['canvas' => ['width' => 100, 'height' => 100], 'background' => null,
      'startFrame' => 0, 'restoreFrame' => 9,
      'camera' => [['id' => 'still', 'frame' => 0, 'focus' => ['x' => 50, 'y' => 50], 'zoom' => 1]],
      'covers' => [
        ['id' => 'begin', 'frame' => 0, 'color' => 'black', 'opacity' => 0],
        ['id' => 'hold', 'frame' => 2, 'color' => 'black', 'opacity' => .45, 'easing' => 'hold'],
        ['id' => 'release', 'frame' => 8, 'color' => 'black', 'opacity' => .45],
        ['id' => 'clear', 'frame' => 9, 'color' => 'black', 'opacity' => 0],
      ]]];
}

beforeEach(function () {
  $this->previousConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => false]]);
  $this->root = createTestDirectory('stage-command');
  mkdir($this->root . '/Animations/cover', 0777, true);
  file_put_contents($this->root . '/Animations/cover/cover.timeline.php', '<?php return ' . var_export(makeStageCoverSource(), true) . ';');
  $this->scene = new StageCommandScene($this->root, true);
  $this->command = new PlayStageCommand();
});
afterEach(function () {
  $this->previousConfig === null ? ConfigStore::remove(ProjectConfig::class)
    : ConfigStore::put(ProjectConfig::class, $this->previousConfig);
});

it('composes a translucent cover without an opaque stage background or dummy image resource', function () {
  $outcome = $this->command->execute(new ScriptCommandContext($this->scene), ['stage' => 'cover', 'seconds' => 1]);
  expect($outcome->operation->update(.2))->toBeFalse();
  $canvas = $this->scene->cinematicPresentation->getStageCanvas(400, 300);
  expect($canvas->images)->toBe([])->and($canvas->composites)->toHaveCount(1)
    ->and($canvas->composites[0]->opacity)->toBe(.45);
  expect($outcome->operation->update(.8))->toBeTrue()
    ->and($this->scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull();
  $source = makeStageCoverSource()['stage']; unset($source['background']);
  expect(CinematicStage::fromArray($source, 10, 4)->data['background'])->toBe('black');
});

it('releases only the owning stage and cancels after the current cinematic generation ends', function () {
  $context = new ScriptCommandContext($this->scene);
  $one = $this->command->execute($context, ['stage' => 'cover', 'seconds' => 1])->operation;
  $two = $this->command->execute($context, ['stage' => 'cover', 'seconds' => 2])->operation;
  $one->update(.2); $two->update(.4);
  expect($this->scene->cinematicPresentation->getStageCanvas(400, 300)->composites)->toHaveCount(2);
  $one->cancel();
  expect($this->scene->cinematicPresentation->getStageCanvas(400, 300)->composites)->toHaveCount(1);
  $this->scene->cinematicStage->clear();
  expect($two->update(.1))->toBeTrue()
    ->and($this->scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull();
});

it('preserves Terminal command timing without inspecting graphical artwork', function () {
  $scene = new StageCommandScene($this->root, false);
  $interpreter = new EventInterpreter($scene, scriptCommands: ScriptCommandCatalog::createEngineCatalog());
  $session = $interpreter->run([
    ['type' => 'play_stage', 'stage' => 'graphical-only-resource', 'seconds' => 1],
    ['type' => 'set_switch', 'name' => 'after_stage', 'value' => true],
  ]);
  $interpreter->update(.5);
  expect($session->status)->not->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getSwitch('after_stage'))->toBeFalse();
  $interpreter->update(.5);
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($scene->gameState->getSwitch('after_stage'))->toBeTrue()
    ->and($scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull();
});

it('retains reduced-motion cover suppression while keeping the authored operation lifetime', function () {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true]]);
  $operation = $this->command->execute(new ScriptCommandContext($this->scene), ['stage' => 'cover', 'seconds' => 1])->operation;
  expect($operation->update(.4))->toBeFalse()
    ->and($this->scene->cinematicPresentation->getStageCanvas(400, 300)->composites)->toBe([])
    ->and($operation->update(.6))->toBeTrue();
});

it('keeps field and battle image admission strict while allowing a cue-free cover-only standalone stage', function () {
  $library = new EffectTimelineLibrary($this->root);
  expect($library->compileStage('cover', makeStageCoverSource())->playbackSegments)->toBe([]);
  foreach ([false, true] as $battle) {
    expect(fn() => $library->compile('invalid', ['fps' => 10, 'lengthFrames' => 10, 'tracks' => []], forBattle: $battle))
      ->toThrow(InvalidArgumentException::class);
  }
  $source = makeStageCoverSource(); $source['cues'] = [['id' => 'sound', 'frame' => 0, 'type' => 'playSound', 'payload' => ['sound' => 'unexpected']]];
  expect(fn() => $library->compileStage('invalid', $source))->toThrow(InvalidArgumentException::class, 'no cues');
});

it('declares the current stage picker and refuses invalid durations and missing graphical sources before acquiring a stage', function () {
  $definition = ScriptCommandCatalog::createEngineCatalog()->findDefinition('play_stage');
  expect($definition->fields[0]->reference)->toBe(ScriptCommandReference::STAGE_TIMELINE);
  $context = new ScriptCommandContext($this->scene);
  foreach ([-1, INF, NAN, '1'] as $seconds) {
    expect(fn() => $this->command->execute($context, ['stage' => 'cover', 'seconds' => $seconds]))->toThrow(RuntimeException::class);
  }
  expect($this->command->execute($context, ['stage' => 'cover', 'seconds' => 0])->operation)->toBeNull()
    ->and(fn() => $this->command->execute($context, ['stage' => 'missing', 'seconds' => 1]))->toThrow(InvalidArgumentException::class)
    ->and($this->scene->cinematicPresentation->getStageCanvas(400, 300))->toBeNull();
});
