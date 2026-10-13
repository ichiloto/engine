<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Actions\FieldActionContext;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Entities\Interfaces\ActionInterface;
use Ichiloto\Engine\Field\FieldActionRegistry;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Tests\Support\Input\FakeHeldInputSource;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeHeldInputSource.php';

class SecondaryFieldRecordingAction implements ActionInterface
{
  public static array $contexts = [];
  public static ?Closure $onExecute = null;
  public function execute(ActionContextInterface $context): void
  {
    self::$contexts[] = $context;
    expect(Input::isButtonDown('secondary_action'))->toBeFalse();
    (self::$onExecute)?->__invoke($context);
  }
}

abstract class SecondaryFieldAbstractAction extends SecondaryFieldRecordingAction {}
class SecondaryFieldRequiredConstructor extends SecondaryFieldRecordingAction
{
  public function __construct(string $required) {}
}

beforeEach(function () {
  $this->inputState = new ReflectionClass(InputManager::class)->getStaticProperties();
  new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
  FieldActionRegistry::reset();
  SecondaryFieldRecordingAction::$contexts = [];
  SecondaryFieldRecordingAction::$onExecute = null;
});

afterEach(function () {
  FieldActionRegistry::reset();
  SecondaryFieldRecordingAction::$contexts = [];
  SecondaryFieldRecordingAction::$onExecute = null;
  foreach ($this->inputState as $name => $value) {
    new ReflectionProperty(InputManager::class, $name)->setValue(null, $value);
  }
});

function createSecondaryFieldProject(?string $contents): string
{
  $root = createTestDirectory('ichiloto-secondary-action-');
  mkdir($root . '/Data');
  if ($contents !== null) { file_put_contents($root . '/' . FieldActionRegistry::PROJECT_FILE, $contents); }
  return $root;
}

it('supplies a secondary action without stealing authored keys', function (array $occupied, array $available) {
  $authored = ['custom' => ['keys' => $occupied]];
  InputManager::setBindings($authored);
  expect(InputManager::getBindings()['custom'])->toBe($authored['custom'])
    ->and(InputManager::getBindings()['secondary_action']['keys'])->toBe($available)
    ->and(InputManager::getBindings()['secondary_action']['controllers'])->toBe(InputManager::WEST_FACE_CONTROLLERS);
})->with([
  'unused' => [[], [KeyCode::g, KeyCode::G]],
  'lowercase occupied' => [[KeyCode::g], [KeyCode::G]],
  'uppercase occupied' => [[KeyCode::G], [KeyCode::g]],
  'both occupied' => [[KeyCode::g, KeyCode::G], []],
]);

it('preserves explicit secondary bindings including an intentional unbinding', function (array $binding) {
  InputManager::setBindings(['secondary_action' => $binding]);
  expect(InputManager::getBindings()['secondary_action'])->toBe($binding);
})->with([
  'rebound' => [['description' => 'Challenge.', 'keys' => [KeyCode::F2]]],
  'unbound' => [['keys' => []]],
  'empty' => [[]],
]);

it('loads only a project-owned handler and forgets it on another project', function () {
  $root = createSecondaryFieldProject('<?php return ["secondary_action" => SecondaryFieldRecordingAction::class];');
  FieldActionRegistry::configureFromProject($root);
  $scene = makeBareScene(GameScene::class);
  $context = new FieldActionContext(makeBareScene(Player::class), $scene, new Vector2(2, 3));
  expect(FieldActionRegistry::executeSecondaryAction($context))->toBeTrue()
    ->and(SecondaryFieldRecordingAction::$contexts)->toBe([$context]);
  FieldActionRegistry::configureFromProject(createSecondaryFieldProject(null));
  expect(FieldActionRegistry::executeSecondaryAction($context))->toBeFalse()
    ->and(SecondaryFieldRecordingAction::$contexts)->toHaveCount(1);
});

it('rejects invalid declarations at bootstrap and clears the former project', function (string $contents) {
  FieldActionRegistry::configure(SecondaryFieldRecordingAction::class);
  $root = createSecondaryFieldProject($contents);
  expect(fn() => FieldActionRegistry::configureFromProject($root))->toThrow(RuntimeException::class, $root);
  $context = new FieldActionContext(makeBareScene(Player::class), makeBareScene(GameScene::class), new Vector2());
  expect(FieldActionRegistry::executeSecondaryAction($context))->toBeFalse();
})->with([
  'list' => ['<?php return [SecondaryFieldRecordingAction::class];'],
  'scalar' => ['<?php return 12;'],
  'primary override' => ['<?php return ["action" => SecondaryFieldRecordingAction::class];'],
  'unknown class' => ['<?php return ["secondary_action" => "UnknownFieldAction"];'],
  'wrong interface' => ['<?php return ["secondary_action" => stdClass::class];'],
  'abstract' => ['<?php return ["secondary_action" => SecondaryFieldAbstractAction::class];'],
  'required constructor' => ['<?php return ["secondary_action" => SecondaryFieldRequiredConstructor::class];'],
  'wrong type' => ['<?php return ["secondary_action" => ["handler"]];'],
]);

it('dispatches the remapped edge once without primary dialogue or continued field movement', function (bool $held, bool $handoff) {
  $scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()
    ->onlyMethods(['reconcileFieldPresentation', 'hasUnstableEventSession'])->getMock();
  $scene->expects($this->atLeastOnce())->method('hasUnstableEventSession')->willReturn(false);
  $manager = makeBareScene(SceneManager::class);
  new ReflectionProperty(GameScene::class, 'sceneManager')->setValue($scene, $manager);
  new ReflectionProperty(SceneManager::class, 'currentScene')->setValue($manager, $scene);
  $player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()
    ->onlyMethods(['completeArrival', 'interact', 'refreshTalkTarget'])->getMock();
  $player->expects($this->never())->method('interact');
  new ReflectionProperty(Player::class, 'position')->setValue($player, new Vector2(4, 6));
  new ReflectionProperty(GameScene::class, 'player')->setValue($scene, $player);
  $field = $this->getMockBuilder(FieldState::class)->setConstructorArgs([new SceneStateContext($scene)])
    ->onlyMethods(['renderPresentationOverlay', 'handleNavigation'])->getMock();
  $field->expects($this->never())->method('handleNavigation');
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($scene, $field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
  if ($handoff) {
    SecondaryFieldRecordingAction::$onExecute = static function () use ($manager): void {
      new ReflectionProperty(SceneManager::class, 'currentScene')->setValue($manager, makeBareScene(GameScene::class));
    };
  }
  FieldActionRegistry::configure(SecondaryFieldRecordingAction::class);
  InputManager::setBindings(['secondary_action' => ['keys' => [KeyCode::F2]]]);
  expect(ActionHints::resolve('secondary_action', 'Challenge')->control->label)->toBe('F2');
  $source = $held ? new FakeHeldInputSource() : new FakeInputSource(KeyCode::F2);
  if ($held) { $source->press('challenge', KeyCode::F2); }
  InputManager::setInputSource($source);
  InputManager::handleInput();
  $field->execute();
  expect(SecondaryFieldRecordingAction::$contexts)->toHaveCount(1)
    ->and(SecondaryFieldRecordingAction::$contexts[0]->scene)->toBe($scene)
    ->and(SecondaryFieldRecordingAction::$contexts[0]->player)->toBe($player)
    ->and($field->movementGeneration)->toBe(1)
    ->and(Input::isButtonDown('secondary_action'))->toBeFalse();
  InputManager::handleInput();
  // Running a suspended field must not dispatch again, even if its entry control is held.
  if ($handoff) { $field->execute(); }
  expect(Input::isButtonDown('secondary_action'))->toBeFalse()
    ->and(SecondaryFieldRecordingAction::$contexts)->toHaveCount(1);
})->with([false, true])->with([false, true]);
