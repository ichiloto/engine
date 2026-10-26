<?php

use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeHeldInputSource;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\PassiveGame;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeHeldInputSource.php';

/** Exercise real dialogue polling/dismissal without opening a window or drawing to the terminal. */
final class InputHandoffTextBox extends TextBoxModal
{
  public array $restorationInputs = [];
  public function render(?int $x = null, ?int $y = null): void {}
  public function erase(?int $x = null, ?int $y = null): void
  {
    $this->restorationInputs[] = [Input::isButtonDown('confirm'), Input::isButtonDown('cancel')];
  }
}

beforeEach(function () {
  $this->handoffStatics = [];
  foreach ([InputManager::class, ConfigStore::class, EventManager::class, Timers::class] as $class) {
    $this->handoffStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Timers::setFrameTick(null);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['audio' => ['music' => false, 'sfx' => false,
    'voice' => false, 'master_volume' => 0]]));
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'cancel' => ['keys' => [KeyCode::ESCAPE]],
    'right' => ['keys' => [KeyCode::RIGHT]]]);
});

afterEach(function () {
  foreach ($this->handoffStatics as $class => $values) {
    foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('consumes dialogue dismissal before caller restoration and preserves unread choices', function (KeyCode $closing) {
  $source = new FakeInputSource($closing, KeyCode::RIGHT, null, KeyCode::ENTER);
  InputManager::setInputSource($source);
  $modal = new InputHandoffTextBox(new PassiveGame(), 'X');
  $modal->open();
  expect($modal->restorationInputs)->toBe([[false, false]])
    ->and(Input::isButtonDown('confirm'))->toBeFalse()
    ->and(Input::isButtonDown('cancel'))->toBeFalse()
    ->and(InputManager::getPressedKeyCode())->toBeNull()
    ->and($source->resets)->not->toContain(true);
  InputManager::handleInput();
  expect(Input::isButtonDown('right'))->toBeTrue();
  InputManager::handleInput();
  InputManager::handleInput();
  expect(Input::isButtonDown('confirm'))->toBeTrue();
  // Repeated cleanup of an already hidden modal cannot consume its caller's later action.
  $modal->close();
  expect(Input::isButtonDown('confirm'))->toBeTrue();
})->with([[KeyCode::ENTER], [KeyCode::ESCAPE]]);

it('consumes native press edges while preserving held control identity until release', function () {
  $source = new FakeHeldInputSource();
  InputManager::setInputSource($source);
  $source->press('return', KeyCode::ENTER);
  $modal = new InputHandoffTextBox(new PassiveGame(), 'X');
  $modal->open();
  expect($modal->restorationInputs)->toBe([[false, false]])
    ->and(Input::isButtonDown('confirm'))->toBeFalse()
    ->and(InputManager::wasButtonPressed('confirm'))->toBeFalse()
    ->and(Input::isButtonHeld('confirm'))->toBeTrue();
  $source->release('return');
  InputManager::handleInput();
  expect(Input::isButtonHeld('confirm'))->toBeFalse();
  $source->press('return', KeyCode::ENTER);
  InputManager::handleInput();
  expect(Input::isButtonDown('confirm'))->toBeTrue()
    ->and(InputManager::wasButtonPressed('confirm'))->toBeTrue();
});
