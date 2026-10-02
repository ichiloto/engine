<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\FieldAnimationOperation;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

beforeEach(function () {
  $this->configBefore = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => false]]);
  [, $this->audio] = makeSceneAudioGame();
  $this->audioBefore = AudioManager::getCurrentInstance();
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $this->audio);
  $this->presentation = new CinematicPresentationManager(makeBareScene(GameScene::class));
  $this->frame = new ReflectionProperty(CinematicPresentationManager::class, 'animationFrame');
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
  expect($this->frame->getValue($this->presentation))->toBe($animation->getFrame(1))
    ->and(new ReflectionProperty(CinematicPresentationManager::class, 'animationPosition')->getValue($this->presentation))->toBe($position)
    ->and(new ReflectionProperty(CinematicPresentationManager::class, 'animationUsesScreenSpace')->getValue($this->presentation))->toBeTrue()
    ->and($this->audio->calls)->toBe([['playSoundEffect', 'start']]);
  expect($operation->update(.119))->toBeFalse()
    ->and($this->frame->getValue($this->presentation)?->index)->toBe(1);
  expect($operation->update(.001))->toBeFalse()
    ->and($this->frame->getValue($this->presentation)?->index)->toBe(2)
    ->and($this->frame->getValue($this->presentation)?->getCells())->toBeEmpty();
  expect($operation->update(.36))->toBeFalse()
    ->and($this->frame->getValue($this->presentation)?->index)->toBe(5)
    ->and($this->audio->calls)->toBe([['playSoundEffect', 'start'], ['playSoundEffect', 'late']]);
  expect($operation->update(.12))->toBeFalse()
    ->and($this->frame->getValue($this->presentation))->toBe($animation->getFrame(6));
  expect($operation->update(.12))->toBeTrue()->and($operation->isComplete)->toBeTrue()
    ->and($this->frame->getValue($this->presentation))->toBeNull()
    ->and($operation->update(100))->toBeTrue()
    ->and($this->audio->calls)->toBe([['playSoundEffect', 'start'], ['playSoundEffect', 'late'], ['playSoundEffect', 'finish']])
    ->and($animation->toArray())->toBe($source);
});

it('keeps a cinematic field operation paused with the shared playhead and retires only its animation on cancellation', function () {
  $animation = new Animation(62, 'Field pause', maxFrames: 3);
  $animation->setCue(3, new AnimationCue(soundEffect: 'not-after-cancel'));
  $this->presentation->showOverlay('narration', 'Independent overlay');
  $operation = new FieldAnimationOperation($animation, $this->presentation, new Vector2(2, 3), secondsPerFrame: .173);
  $session = new ReflectionProperty(FieldAnimationOperation::class, 'session')->getValue($operation);
  $session->playback->pause();
  expect($operation->update(10))->toBeFalse()
    ->and($this->frame->getValue($this->presentation)?->index)->toBe(1);
  $session->playback->resume();
  expect($operation->update(.173))->toBeFalse()
    ->and($this->frame->getValue($this->presentation)?->index)->toBe(2);
  $operation->cancel();
  expect($operation->update(10))->toBeTrue()->and($operation->isComplete)->toBeTrue()
    ->and($session->playback->isPaused)->toBeTrue()
    ->and($this->frame->getValue($this->presentation))->toBeNull()
    ->and($this->audio->calls)->toBeEmpty()
    ->and(new ReflectionProperty(CinematicPresentationManager::class, 'overlay')->getValue($this->presentation))
    ->toBe(['kind' => 'narration', 'text' => 'Independent overlay', 'title' => '']);
});
