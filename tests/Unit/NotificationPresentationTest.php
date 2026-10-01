<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Messaging\Notifications\Notification;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationCanvasPresentation;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationContentOverflow;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationPlacement;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

final class NotificationPresentationGameProbe extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

function notificationCanvasText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(static fn($layer) => implode('', array_column($layer->runs, 'text')), $canvas->textLayers));
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, Debug::class, Time::class,
    EventManager::class, NotificationManager::class, AudioManager::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/notification-ui-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root]);
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(135, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  $this->game = new NotificationPresentationGameProbe();
  $audio = new class extends AudioManager {
    public int $sounds = 0;
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void { $this->sounds++; }
  };
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $audio);
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $audio);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  new ReflectionProperty(NotificationManager::class, 'instance')->setValue(null, null);
  $this->audio = $audio;
  $this->manager = NotificationManager::getInstance($this->game);
  $this->runtime = null;
  $this->themeData = ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'frames' => ['panel' => ['asset' => 'Panel.png']],
    'icons' => array_fill_keys(['notification.quest', 'notification.achievement', 'notification.info',
      'notification.system', 'notification.warning', 'notification.error', 'notification.debug',
      'notification.save', 'notification.reward'], 'Icon.png'),
    'notifications' => ['colors' => ['quest.complete' => [1, 2, 3]]]];
  writeTestPng($this->root . '/Panel.png', 64, 64);
  writeTestPng($this->root . '/Icon.png', 32, 32);
  mkdir($this->root . '/Data/Presentation', 0777, true);
  file_put_contents($this->root . '/Data/Presentation/menus.php', '<?php return ' . var_export($this->themeData, true) . ';');
  $this->theme = MenuPresentationCatalog::load($this->root);
  $this->startRenderer = function (bool $supported = true): void {
    $caps = $supported ? [...MenuPresentationCatalog::CAPABILITIES, RendererSessionConfig::CANVAS_OVERLAY] : [];
    $this->transport = new FakeRendererTransport();
    $this->transport->batches = [[RendererEvent::fromJson(json_encode(['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps]))]];
    $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']),
      $this->root, cellWidth: 10, cellHeight: 20, requiredCapabilities: $caps), $this->transport);
    $this->runtime->start('Isolated notification', 135, 36);
    $this->game->useRendererRuntime($this->runtime);
  };
  Time::setElapsedTime(0);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.1);
  ob_start();
});

afterEach(function () {
  $this->runtime?->shutdown();
  ob_end_clean();
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('uses channel meanings and exact authored text on normal and compact notification surfaces', function (NotificationChannel $channel, int $width, int $height) {
  $notice = new Notification($this->game, $channel, 'Exact title', "A named task\n2/5 rats, 500 G, S-Mana x2", animationDuration: 0);
  $notice->open();
  $surface = NotificationCanvasPresentation::compose($notice, $this->theme, $width, $height);
  expect(notificationCanvasText($surface->canvas))->toContain('Exact title', 'A named task', '2/5 rats, 500 G, S-Mana x2')
    ->and($surface->canvas->images)->toHaveCount(2)
    ->and($surface->bounds->x)->toBeGreaterThan(0)
    ->and($this->game->getRendererRuntime())->toBeNull();
  foreach ($surface->canvas->textLayers as $layer) { expect($layer->id)->not->toStartWith('menu-background'); }
})->with(NotificationChannel::cases())->with([[1280, 720], [800, 480]]);

it('uses explicit save reward and completion roles rather than interpreting translated titles', function () {
  foreach (['save', 'reward', 'quest.complete'] as $role) {
    $notice = new Notification($this->game, NotificationChannel::QUEST, 'Translated title', 'No keyword heuristics.',
      animationDuration: 0, presentationRole: $role);
    $notice->open();
    $surface = NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720);
    expect($notice->getPresentationRole())->toBe($role)->and(notificationCanvasText($surface->canvas))->toContain('Translated title');
    if ($role === 'quest.complete') {
      expect($surface->canvas->textLayers[0]->runs[0]->foreground->toArray())->toBe(['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3]);
    }
  }
});

it('wraps complete long names and preserves counts instead of clipping or ellipsizing them', function () {
  $title = 'A named assignment with a longer but readable title';
  $body = "Recovered relay evidence from the north approach\n19/20, 123,456 G, LongNamedItem x12";
  $notice = new Notification($this->game, NotificationChannel::QUEST, $title, $body, animationDuration: 0);
  $notice->open();
  $surface = NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720);
  expect(notificationCanvasText($surface->canvas))->toContain($title, '19/20, 123,456 G, LongNamedItem x12');
});

it('reports unrepresentable content rather than truncating its immutable message', function () {
  $body = str_repeat('Complete diagnostic line.\n', 100);
  $notice = new Notification($this->game, NotificationChannel::ERROR, 'Diagnostic', $body, animationDuration: 0);
  $notice->open();
  expect(fn() => NotificationCanvasPresentation::compose($notice, $this->theme, 800, 480))
    ->toThrow(NotificationContentOverflow::class)->and($notice->getContentText())->toBe($body);
});

it('selects a clear corner at entry and does not chase protected content with repeated relocation', function () {
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', 'Body', animationDuration: 0);
  $notice->open();
  $right = new CanvasRectangle(700, 0, 580, 300);
  $surface = NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, [$right]);
  expect($surface->bounds->x)->toBe(24.0);
  $left = new CanvasRectangle(0, 0, 500, 300);
  expect(NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, [$left], $surface->bounds))->toBeNull();
});

it('accepts a distinct theme and replacement image dimensions without changing notification identity', function () {
  $data = $this->themeData;
  $data['colors'] = ['text' => [20, 30, 40], 'panel' => [240, 235, 220]];
  $data['metrics'] = ['cellWidth' => 8, 'cellHeight' => 20];
  $data['notifications'] = ['width' => 400, 'maxWidth' => 480, 'padding' => 20,
    'colors' => ['info' => [60, 80, 100]]];
  writeTestPng($this->root . '/Icon.png', 48, 24);
  $theme = new MenuPresentationCatalog($this->root, $data);
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Identity', 'Complete body', animationDuration: 0);
  $id = $notice->getPresentationId();
  $notice->open();
  $surface = NotificationCanvasPresentation::compose($notice, $theme, 1280, 720);
  expect($surface->bounds->width)->toBe(400.0)
    ->and($surface->canvas->textLayers[0]->grid->cellWidth)->toBe(8)
    ->and($surface->canvas->textLayers[0]->runs[0]->foreground->toArray())->toBe(['kind' => 'rgb', 'r' => 60, 'g' => 80, 'b' => 100])
    ->and($surface->canvas->images[1]->destination->width)->toBe(32.0)
    ->and($surface->canvas->images[1]->destination->height)->toBe(16.0)
    ->and($notice->getPresentationId())->toBe($id);
});

it('retains a readable generic panel when replaceable artwork is unavailable and reports the missing files', function () {
  ($this->startRenderer)();
  unlink($this->root . '/Panel.png');
  unlink($this->root . '/Icon.png');
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Fallback', 'Complete body', animationDuration: 0);
  $this->manager->notify($notice);
  $canvas = $this->manager->composePresentation(null, 1350, 720, []);
  expect(notificationCanvasText($canvas))->toContain('Fallback', 'Complete body')
    ->and($canvas->images)->toBeEmpty()->and($this->manager->hasGraphicalPresentation())->toBeTrue()
    ->and(file_get_contents($this->root . '/warning.log'))->toContain('Panel.png', 'Icon.png');
});

it('diagnoses invalid theme metrics and retains the original terminal fallback', function () {
  ($this->startRenderer)();
  $data = $this->themeData;
  $data['notifications']['padding'] = -1;
  file_put_contents($this->root . '/Data/Presentation/menus.php', '<?php return ' . var_export($data, true) . ';');
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Invalid theme fallback', 'Exact body', animationDuration: 0);
  $this->manager->notify($notice);
  $this->manager->render();
  expect($this->manager->hasGraphicalPresentation())->toBeFalse()
    ->and(implode('', Console::getBuffer()))->toContain('Invalid theme fallback', 'Exact body')
    ->and(file_get_contents($this->root . '/warning.log'))->toContain('Notification metrics');
});

it('preserves oversized content and its queue hold while issuing one actionable diagnostic', function () {
  ($this->startRenderer)();
  $body = str_repeat("Complete diagnostic line.\n", 100);
  $notice = new Notification($this->game, NotificationChannel::ERROR, 'Diagnostic', $body, animationDuration: 0);
  $this->manager->notify($notice);
  $this->manager->composePresentation(null, 1350, 720, []);
  Time::setElapsedTime(20);
  $this->manager->update();
  $this->manager->composePresentation(null, 1350, 720, []);
  expect($notice->isFinished())->toBeFalse()->and($notice->getContentText())->toBe($body)
    ->and(substr_count(file_get_contents($this->root . '/warning.log'), 'Notification presentation:'))->toBe(1);
  $notice->setContentText('A corrected brief message');
  expect(notificationCanvasText($this->manager->composePresentation(null, 1350, 720, [])))
    ->toContain('A corrected brief message');
});

it('defers over transition overlays instead of placing a graphical toast above them', function () {
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', 'Body', animationDuration: 0);
  $notice->open();
  $text = new ConsolePresentationSnapshot(128, 30, [
    new PresentationTextLayer('transition', PresentationLayerPolicy::TRANSITION, [
      new PresentationTextRun(0, 0, str_repeat(' ', 128)),
      new PresentationTextRun(1, 0, str_repeat(' ', 128)),
    ]),
  ]);
  $areas = NotificationPlacement::getProtectedAreas(null, $text, [], new RendererGridConfig(128, 30, 10, 24));
  expect(NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, $areas))->toBeNull();
});

it('preserves entry hold and queue order while a dense menu defers notification delivery', function () {
  ($this->startRenderer)();
  $first = new Notification($this->game, NotificationChannel::INFO, 'First', 'Message', NotificationDuration::SHORT);
  $second = new Notification($this->game, NotificationChannel::INFO, 'Second', 'Message', NotificationDuration::SHORT);
  $this->manager->notify($first);
  $this->manager->notify($second);
  $blocked = [new CanvasRectangle(0, 0, 1350, 720)];
  expect($this->manager->composePresentation(null, 1350, 720, $blocked))->toBeNull();
  Time::setElapsedTime(20);
  $this->manager->update();
  expect($first->isFinished())->toBeFalse()->and($first->getPresentationOpacity())->toBe(0.0)
    ->and($this->manager->getExcludedPresentationLayers())->toBe([$first->getPresentationId()]);
  $this->manager->composePresentation(null, 1350, 720, []);
  Time::setElapsedTime(20.15);
  $this->manager->update();
  expect($first->getPresentationOpacity())->toBeGreaterThan(0.49)->toBeLessThan(0.51);
  Time::setElapsedTime(20.3);
  $this->manager->update();
  Time::setElapsedTime(24.2);
  $this->manager->update();
  expect($first->isFinished())->toBeFalse()->and($first->getPresentationOpacity())->toBe(1.0);
  Time::setElapsedTime(24.31);
  $this->manager->update();
  Time::setElapsedTime(24.62);
  $this->manager->update();
  expect($first->isFinished())->toBeTrue()->and($this->manager->getExcludedPresentationLayers())->toBe([$second->getPresentationId()])
    ->and($this->audio->sounds)->toBe(2);
});

it('uses zero motion with a full stationary hold under reduced motion', function () {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true]]);
  ($this->startRenderer)();
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Still', 'Body', NotificationDuration::SHORT);
  $this->manager->notify($notice);
  $this->manager->composePresentation(null, 1350, 720, []);
  expect($notice->getAnimationDuration())->toBe(0.0)->and($notice->getPresentationOpacity())->toBe(1.0);
  Time::setElapsedTime(3.99); $this->manager->update();
  expect($notice->isFinished())->toBeFalse();
  Time::setElapsedTime(4.01); $this->manager->update();
  expect($notice->isFinished())->toBeTrue();
});

it('protects transformed actors prompts and screen HUD without treating terrain as UI', function () {
  $grid = new RendererGridConfig(80, 30, 16, 24);
  $viewport = new PresentationViewport(2, 0, 0, new CanvasRectangle(0, 0, 1280, 720),
    [PresentationLayerPolicy::FIELD_PROMPT_ID], ['actor']);
  $sprite = new PresentationSprite('actor', 'Icon.png', 10, 1, 48, 48, lift: 6);
  $text = new ConsolePresentationSnapshot(80, 30, [
    new PresentationTextLayer('world', 0, [new PresentationTextRun(0, 0, 'terrain')]),
    new PresentationTextLayer('hud', 1000, [new PresentationTextRun(0, 0, 'Location')]),
    new PresentationTextLayer(PresentationLayerPolicy::FIELD_PROMPT_ID, 1000, [new PresentationTextRun(0, 10, '!')]),
  ]);
  $areas = NotificationPlacement::getProtectedAreas(null, $text, [$sprite], $grid, $viewport);
  expect($areas)->toHaveCount(3)->and(NotificationPlacement::isClear(new CanvasRectangle(960, 24, 40, 60), $areas))->toBeFalse()
    ->and(NotificationPlacement::isClear(new CanvasRectangle(0, 0, 100, 24), $areas))->toBeFalse();
});

it('composes above safe opaque surfaces and defers when their actual panels occupy both corners', function () {
  ($this->startRenderer)();
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', 'Body', animationDuration: 0);
  $this->manager->notify($notice);
  $view = new MenuCanvas($this->theme);
  $view->frame('menu-body', new CanvasRectangle(125, 300, 1100, 400));
  $base = $view->finish();
  $grid = new RendererGridConfig(135, 36, 10, 20);
  $canvas = $this->manager->composePresentation($base, 1350, 720, NotificationPlacement::getProtectedAreas($base, null, [], $grid));
  expect(notificationCanvasText($canvas))->toContain('Title', 'Body')->and(count($canvas->images))->toBeGreaterThan(count($base->images));
  $full = new MenuCanvas($this->theme);
  $full->frame('shop', new CanvasRectangle(125, 10, 1100, 700));
  $dense = $full->finish();
  expect($this->manager->composePresentation($dense, 1350, 720, NotificationPlacement::getProtectedAreas($dense, null, [], $grid)))->toBe($dense);
});

it('retains terminal notifications when graphical capabilities are unavailable', function () {
  ($this->startRenderer)(false);
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Terminal notice', 'Exact body', animationDuration: 0);
  $this->manager->notify($notice);
  $this->manager->render();
  expect($this->manager->hasGraphicalPresentation())->toBeFalse()
    ->and(implode('', Console::getBuffer()))->toContain('Terminal notice', 'Exact body')
    ->and($this->manager->getExcludedPresentationLayers())->toBe([]);
});

it('uses the real retained renderer path and removes its graphical surface after dismissal', function () {
  ($this->startRenderer)();
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Retained notice', 'Exact body', 1.0, animationDuration: 0);
  $this->manager->notify($notice);
  $this->runtime->present(null, $this->manager);
  $frames = RetainedFrameState::replay($this->transport->sent);
  $text = implode('', array_merge(...array_map(static fn($layer) => array_column($layer['runs'], 'text'), end($frames)['canvas']['textLayers'])));
  expect($text)->toContain('Retained notice', 'Exact body');
  Time::setElapsedTime(1.01); $this->manager->update();
  $this->runtime->present(null, $this->manager);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect(end($frames))->not->toHaveKey('canvas');
});
