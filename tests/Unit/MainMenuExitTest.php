<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\Commands\OpenQuitMenuCommand;
use Ichiloto\Engine\Core\Menu\Interfaces\MenuInterface;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\PassiveGame;

final class MainMenuExitGame extends PassiveGame
{
  public int $quitCalls = 0;
  public bool $stopped = false;
  public function quit(): void { $this->quitCalls++; $this->stopped = true; }
  public function hasStopped(): bool { return $this->stopped; }
}

final class MainMenuExitDialogs extends ModalManager
{
  public array $answers = [];
  public array $dialogs = [];
  public ?Closure $onSelect = null;
  public function select(string $message, array $options, string $title = '', int $default = 0,
    ?Vector2 $position = null, int $width = DEFAULT_SELECT_DIALOG_WIDTH, int $height = DEFAULT_SELECT_DIALOG_HEIGHT): int
  {
    $this->dialogs[] = compact('message', 'options', 'title', 'default');
    ($this->onSelect)?->__invoke(count($this->dialogs));
    if ($this->answers === []) { throw new RuntimeException('Unexpected exit dialog.'); }
    return array_shift($this->answers);
  }
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, ConfigStore::class, ModalManager::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  putSceneAudioConfig([]);
  $this->game = new MainMenuExitGame();
  $this->scenes = $this->createMock(SceneManager::class);
  new ReflectionProperty($this->game, 'sceneManager')->setValue($this->game, $this->scenes);
  $this->scene = $this->createStub(SceneInterface::class);
  $this->scene->method('getGame')->willReturn($this->game);
  $this->menu = $this->createStub(MenuInterface::class);
  $this->menu->method('getScene')->willReturn($this->scene);
  $this->dialogs = new MainMenuExitDialogs($this->game);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $this->game->bindModalManager($this->dialogs);
  $this->command = new OpenQuitMenuCommand($this->menu);
  $this->context = new MenuCommandExecutionContext([], new NullOutput(), $this->menu, $this->scene);
});

afterEach(function () {
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('opens on Cancel and leaves play untouched when the destination picker is cancelled', function (int $answer) {
  $this->scenes->expects($this->never())->method('loadScene');
  $this->dialogs->answers = [$answer];
  $this->command->execute($this->context);
  expect($this->game->quitCalls)->toBe(0)->and($this->dialogs->dialogs)->toHaveCount(1)
    ->and($this->dialogs->dialogs[0]['options'])->toBe(['To Title', 'Exit', 'Cancel'])
    ->and($this->dialogs->dialogs[0]['default'])->toBe(2);
})->with([-1, 2]);

it('returns to the chosen destination when its confirmation is cancelled', function (int $destination, int $answer) {
  $this->scenes->expects($this->never())->method('loadScene');
  $this->dialogs->answers = [$destination, $answer, 2];
  $this->command->execute($this->context);
  $confirmation = $this->dialogs->dialogs[1];
  expect($this->game->quitCalls)->toBe(0)->and($this->dialogs->dialogs)->toHaveCount(3)
    ->and($confirmation['message'])->toBe('Unsaved progress will be lost.')
    ->and($confirmation['options'])->toBe(['Cancel', $destination === 0 ? 'To Title' : 'Exit'])
    ->and($confirmation['default'])->toBe(0)
    ->and($confirmation['title'])->toBe(get_engine_message($destination === 0 ? 'confirm.to_title' : 'confirm.quit'))
    ->and($this->dialogs->dialogs[2]['default'])->toBe($destination);
})->with([[0, -1], [0, 0], [1, -1], [1, 0]]);

it('executes the selected real destination once after explicit confirmation', function (int $destination) {
  $this->dialogs->answers = [$destination, 1];
  if ($destination === 0) {
    $this->scenes->expects($this->once())->method('loadScene')->with(TitleScene::class)->willReturnSelf();
  } else {
    $this->scenes->expects($this->never())->method('loadScene');
  }
  $this->command->execute($this->context);
  expect($this->game->quitCalls)->toBe($destination === 1 ? 1 : 0)
    ->and($this->dialogs->dialogs)->toHaveCount(2);
})->with([0, 1]);

it('does not reopen a cancelled confirmation or execute its action after host shutdown', function (int $answer) {
  $this->scenes->expects($this->never())->method('loadScene');
  $this->dialogs->answers = [1, $answer];
  $this->dialogs->onSelect = function (int $dialog): void {
    if ($dialog === 2) { $this->game->stopped = true; }
  };
  $this->command->execute($this->context);
  expect($this->game->quitCalls)->toBe(0)->and($this->dialogs->dialogs)->toHaveCount(2);
})->with([-1, 0, 1]);

it('uses project messages and vocabulary through the shared modal route', function () {
  putSceneAudioConfig(['vocab' => ['game' => ['to_title' => 'Frontispiece', 'shutdown' => 'Close Chronicle'],
    'command' => ['cancel' => 'Keep playing']], 'messages' => ['confirm' => ['to_title' => 'Leave this chapter?',
      'unsaved_progress' => 'Record your journey before leaving.']]]);
  $this->scenes->expects($this->never())->method('loadScene');
  $this->dialogs->answers = [0, 0, 2];
  $this->command->execute($this->context);
  expect($this->dialogs->dialogs[0]['options'])->toBe(['Frontispiece', 'Close Chronicle', 'Keep playing'])
    ->and($this->dialogs->dialogs[1]['message'])->toBe('Record your journey before leaving.')
    ->and($this->dialogs->dialogs[1]['title'])->toBe('Leave this chapter?')
    ->and($this->dialogs->dialogs[1]['options'])->toBe(['Keep playing', 'Frontispiece']);
});

it('does not open another modal if the host stops during destination selection', function (int $answer) {
  $this->scenes->expects($this->never())->method('loadScene');
  $this->dialogs->answers = [$answer];
  $this->dialogs->onSelect = function (): void { $this->game->stopped = true; };
  $this->command->execute($this->context);
  expect($this->game->quitCalls)->toBe(0)->and($this->dialogs->dialogs)->toHaveCount(1);
})->with([0, 1]);
