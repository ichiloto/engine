<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicTextPresentation;
use Ichiloto\Engine\Cutscenes\Cinematics\FieldAnimationOperation;
use Ichiloto\Engine\Cutscenes\Cinematics\TimedPresentationOperation;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Sprites\ScreenSpaceSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class FieldEffectRecordingCamera extends Camera
{
  public array $draws = [];
  public function __construct() { $this->screen = new Rect(0, 0, 20, 10); }
  public function draw(iterable|string $content, int $x = 0, int $y = 0): void { $this->draws[] = [$content, $x, $y]; }
}

function getCinematicEffectSession(FieldAnimationOperation $operation): FieldEffectSession
{
  return new ReflectionProperty(FieldAnimationOperation::class, 'session')->getValue($operation);
}

beforeEach(function () {
  $this->configBefore = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => false]]);
  [, $this->audio] = makeSceneAudioGame();
  $this->audioBefore = AudioManager::getCurrentInstance();
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $this->audio);
  $this->scene = makeBareScene(GameScene::class);
  $this->camera = new FieldEffectRecordingCamera();
  new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
  $this->presentation = new CinematicPresentationManager($this->scene);
  $this->effectRoot = createTestDirectory('ichiloto-field-operation-');
  writeTestPng($this->effectRoot . '/sheet.png', 16, 8);
  $this->effects = new EffectTimelineLibrary($this->effectRoot);
});

afterEach(function () {
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $this->audioBefore);
  if ($this->configBefore === null) {
    ConfigStore::remove(ProjectConfig::class);
  } else {
    ConfigStore::put(ProjectConfig::class, $this->configBefore);
  }
});

it('keeps the legacy field cadence and delivers crossed cues exactly once through the shared clock', function () {
  $animation = new Animation(61, 'Field compatibility', maxFrames: 6);
  $animation->setCell(1, -1, 0, '*', 'cyan');
  $animation->setCell(6, 0, 1, '+', 'white');
  $animation->setCue(1, new AnimationCue(soundEffect: 'start'));
  $animation->setCue(5, new AnimationCue(soundEffect: 'late'));
  $animation->setCue(6, new AnimationCue(soundEffect: 'finish'));
  $source = $animation->toArray();
  $position = new Vector2(7, 9);
  $operation = new FieldAnimationOperation($animation, $this->presentation, $position, true, .12);
  $session = getCinematicEffectSession($operation);
  $draw = $session->getActiveSegments()[0]['drawCommands'][0];
  expect($session->playback->currentFrame)->toBe(0)
    ->and($draw['content'])->toBe('*')->and($draw['color'])->toBe('cyan')
    ->and($draw['position'])->toBe(['x' => -1, 'y' => 0])
    ->and($session->anchor->cell->x)->toBe($position->x)->and($session->anchor->cell->y)->toBe($position->y)
    ->and($this->audio->calls)->toBe([['playSoundEffect', 'start']]);
  $session->renderText($this->camera, $position, EffectPresentation::TERMINAL);
  expect($this->camera->draws)->toBe([['<fg=cyan>*</>', 6, 9]]);
  expect($operation->update(.119))->toBeFalse()
    ->and($session->playback->currentFrame)->toBe(0);
  expect($operation->update(.001))->toBeFalse()
    ->and($session->playback->currentFrame)->toBe(1)
    ->and($session->getActiveSegments())->toBeEmpty();
  expect($operation->update(.36))->toBeFalse()
    ->and($session->playback->currentFrame)->toBe(4)
    ->and($this->audio->calls)->toBe([['playSoundEffect', 'start'], ['playSoundEffect', 'late']]);
  expect($operation->update(.12))->toBeFalse()
    ->and($session->playback->currentFrame)->toBe(5)
    ->and($session->getActiveSegments()[0]['drawCommands'][0]['content'])->toBe('+')
    ->and($session->getActiveSegments()[0]['drawCommands'][0]['color'])->toBe('white')
    ->and($session->getActiveSegments()[0]['drawCommands'][0]['position'])->toBe(['x' => 0, 'y' => 1]);
  expect($operation->update(.12))->toBeTrue()->and($operation->isComplete)->toBeTrue()
    ->and($this->presentation->effectCount)->toBe(0)
    ->and($operation->update(100))->toBeTrue()
    ->and($this->audio->calls)->toBe([['playSoundEffect', 'start'], ['playSoundEffect', 'late'], ['playSoundEffect', 'finish']])
    ->and($animation->toArray())->toBe($source);
});

it('keeps a cinematic field operation paused with the shared playhead and retires only its animation on cancellation', function () {
  $animation = new Animation(62, 'Field pause', maxFrames: 3);
  $animation->setCue(3, new AnimationCue(soundEffect: 'not-after-cancel'));
  $this->presentation->showOverlay('narration', 'Independent overlay');
  $overlayProperty = new ReflectionProperty(CinematicPresentationManager::class, 'overlay');
  $independentOverlay = $overlayProperty->getValue($this->presentation);
  expect($independentOverlay)->toEqual(new CinematicTextPresentation('narration', 'Independent overlay'));
  $operation = new FieldAnimationOperation($animation, $this->presentation, new Vector2(2, 3), secondsPerFrame: .173);
  $session = getCinematicEffectSession($operation);
  $session->playback->pause();
  expect($operation->update(10))->toBeFalse()
    ->and($session->playback->currentFrame)->toBe(0);
  $session->playback->resume();
  expect($operation->update(.173))->toBeFalse()
    ->and($session->playback->currentFrame)->toBe(1);
  $operation->cancel();
  expect($operation->update(10))->toBeTrue()->and($operation->isComplete)->toBeTrue()
    ->and($session->playback->isPaused)->toBeTrue()
    ->and($this->presentation->effectCount)->toBe(0)
    ->and($this->audio->calls)->toBeEmpty()
    ->and($overlayProperty->getValue($this->presentation))->toBe($independentOverlay);
});

it('holds a complete rest frame under reduced motion for the full authored lifetime without dropping sounds', function () {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true]]);
  $animation = new Animation(63, 'Reduced compatibility', maxFrames: 6);
  $animation->setCell(1, 0, 0, '*', 'cyan');
  $animation->setCell(6, 0, 0, '+', 'white');
  $animation->setCue(6, new AnimationCue(soundEffect: 'last'));
  $operation = new FieldAnimationOperation($animation, $this->presentation, new Vector2(2, 3), secondsPerFrame: .12);
  $session = getCinematicEffectSession($operation);
  expect($operation->isComplete)->toBeFalse()->and($operation->update(.60))->toBeFalse()
    ->and($session->playback->currentFrame)->toBe(5)
    ->and($session->getActiveSegments(true)[0]['drawCommands'][0]['content'])->toBe('*')
    ->and($this->presentation->effectCount)->toBe(1)->and($this->audio->calls)->toBe([['playSoundEffect', 'last']]);
  expect($operation->update(.119))->toBeFalse()->and($operation->update(.001))->toBeTrue()
    ->and($this->presentation->effectCount)->toBe(0);
});

it('composes independent effects and preserves them when a parallel narration finishes', function () {
  $short = new Animation(64, 'Short', maxFrames: 1);
  $long = new Animation(65, 'Long', maxFrames: 4);
  $long->setCue(4, new AnimationCue(soundEffect: 'long-finish'));
  $a = new FieldAnimationOperation($short, $this->presentation, new Vector2(1, 2));
  $b = new FieldAnimationOperation($long, $this->presentation, new Vector2(3, 4));
  $this->presentation->showOverlay('narration', 'Separate');
  $overlay = new TimedPresentationOperation($this->presentation, .01);
  expect($this->presentation->effectCount)->toBe(2)->and($overlay->update(.01))->toBeTrue()
    ->and($this->presentation->effectCount)->toBe(2)->and($a->update(.12))->toBeTrue()
    ->and($this->presentation->effectCount)->toBe(1)->and($b->update(.36))->toBeFalse()
    ->and($this->audio->calls)->toBe([['playSoundEffect', 'long-finish']]);
  $this->presentation->clear();
  expect($b->update(10))->toBeTrue()->and($this->presentation->effectCount)->toBe(0)
    ->and(getCinematicEffectSession($b)->playback->isPaused)->toBeTrue();
});

it('presents current sheet crops through field providers at authored timing and with stable identities', function () {
  $timeline = $this->effects->compile('image', ['fps' => 5, 'lengthFrames' => 2, 'restFrame' => 1,
    'tracks' => [['id' => 'light', 'type' => 'image', 'asset' => 'sheet.png', 'sheet' => ['columns' => 2, 'rows' => 1],
      'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]]]]]);
  $operation = new FieldAnimationOperation($timeline, $this->presentation, new Vector2(2, 3));
  $viewport = new FieldViewport(new RendererGridConfig(80, 24, 8, 16), 2);
  $first = $this->presentation->getEffectSprites($viewport)[0];
  expect($first->getGraphicalSpriteWorldPosition()->x)->toBe(2.0)
    ->and($first->getGraphicalSpriteWorldPosition()->y)->toBe(3.0)
    ->and($first->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
  expect($operation->update(.2))->toBeFalse();
  $next = $this->presentation->getEffectSprites($viewport)[0];
  expect($next->getGraphicalSpriteId())->toBe($first->getGraphicalSpriteId())
    ->and($next->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8);
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true]]);
  expect($this->presentation->getEffectSprites($viewport)[0]->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8)
    ->and($operation->update(.2))->toBeTrue()->and($this->presentation->getEffectSprites($viewport))->toBeEmpty();
});

it('pins screen effects in the text grid outside the field viewport transform', function () {
  $timeline = $this->effects->compile('image', ['fps' => 5, 'lengthFrames' => 1, 'tracks' => [[
    'id' => 'light', 'type' => 'image', 'asset' => 'sheet.png', 'keyframes' => [['frame' => 0]],
  ]]]);
  new FieldAnimationOperation($timeline, $this->presentation, new Vector2(2, 3), screenSpace: true);
  $viewport = new FieldViewport(new RendererGridConfig(80, 24, 8, 16), 2);
  $sprite = $this->presentation->getEffectSprites($viewport)[0];
  expect($sprite)->toBeInstanceOf(ScreenSpaceSpriteProviderInterface::class)
    ->and($sprite->getGraphicalSpriteDefinition()->width)->toBe(96)
    ->and($sprite->getGraphicalSpriteWorldPosition()->x)->toBe(34.0)
    ->and($sprite->getGraphicalSpriteWorldPosition()->y)->toBe(23.0);
});

it('clips offscreen glyphs without clamping them onto the viewport edge or erasing transparent gaps', function () {
  $timeline = $this->effects->compile('glyph', ['fps' => 5, 'lengthFrames' => 1, 'tracks' => [[
    'id' => 'shape', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'content' => "* *\n\n+ +", 'color' => 'cyan']],
  ]]]);
  $operation = new FieldAnimationOperation($timeline, $this->presentation, new Vector2(-1, -1));
  getCinematicEffectSession($operation)->renderText($this->camera, new Vector2(-1, -1), EffectPresentation::TERMINAL);
  expect($this->camera->draws)->toBe([['<fg=cyan>+</>', 1, 1]]);
});

it('refuses a cinematic loop and refuses overriding an authored timeline cadence', function () {
  $data = ['fps' => 5, 'lengthFrames' => 1, 'playback' => 'loop', 'tracks' => [[
    'id' => 'shape', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'content' => '*']],
  ]]];
  $loop = $this->effects->compile('loop', $data);
  expect(fn() => new FieldAnimationOperation($loop, $this->presentation, new Vector2()))
    ->toThrow(InvalidArgumentException::class, 'must play once');
  $once = $this->effects->compile('once', [...$data, 'playback' => 'once']);
  expect(fn() => new FieldAnimationOperation($once, $this->presentation, new Vector2(), secondsPerFrame: .12))
    ->toThrow(InvalidArgumentException::class, 'owns its frame rate');
  expect($this->presentation->effectCount)->toBe(0);
});

it('composes field depth and clear operations after capability filtering just as battles do', function () {
  $timeline = $this->effects->compile('depth', ['fps' => 5, 'lengthFrames' => 1, 'tracks' => [
    ['id' => 'upper', 'type' => 'text', 'keyframes' => [['frame' => 0, 'content' => 'U', 'zIndex' => 2]]],
    ['id' => 'lower', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'content' => 'L', 'zIndex' => -1]]],
    ['id' => 'clear', 'type' => 'glyph', 'presentation' => 'terminal',
      'keyframes' => [['frame' => 0, 'content' => 'C', 'zIndex' => 0, 'payload' => ['clearBeforeDraw' => true]]]],
  ]], presentation: EffectPresentation::TERMINAL);
  $operation = new FieldAnimationOperation($timeline, $this->presentation, new Vector2(2, 3));
  getCinematicEffectSession($operation)->renderText($this->camera, new Vector2(2, 3), EffectPresentation::TERMINAL);
  expect($this->camera->draws)->toBe([['C', 2, 3], ['U', 2, 3]]);
  $this->camera->draws = [];
  getCinematicEffectSession($operation)->renderText($this->camera, new Vector2(2, 3), EffectPresentation::GRAPHICAL);
  expect($this->camera->draws)->toBe([['L', 2, 3], ['U', 2, 3]]);
});
