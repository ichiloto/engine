<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Messaging\Notifications\Notification;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationCanvasPresentation;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationContentOverflow;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationPlacement;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';
require_once __DIR__ . '/../Fixtures/Rendering/ScreenTransitions.php';

final class NotificationPresentationGameProbe extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

interface NotificationCanvasSceneProbe extends SceneInterface, CanvasProviderInterface {}
interface NotificationCanvasOverlaySceneProbe extends NotificationCanvasSceneProbe,
  \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasOverlayProviderInterface {}

function notificationCanvasText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(static fn($layer) => implode('', array_column($layer->runs, 'text')), $canvas->textLayers));
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, Debug::class, Time::class,
    EventManager::class, NotificationManager::class, AudioManager::class, ModalManager::class] as $class) {
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
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, null);
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
    $caps = $supported ? [...MenuPresentationCatalog::CAPABILITIES, RendererSessionConfig::CANVAS_OVERLAY,
      RendererSessionConfig::CANVAS_COMPOSITING] : [];
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

it('keeps notices live above every transition phase and scene replacement without restarting or baking them in', function (bool $themed, bool $fullCanvas) {
  if (!$themed) { unlink($this->root . '/Data/Presentation/menus.php'); }
  ($this->startRenderer)();
  $scene = null;
  if ($fullCanvas) {
    $scene = $this->createStub(NotificationCanvasSceneProbe::class);
    $scene->method('getPresentationCanvas')->willReturn(new PresentationCanvas(1350, 720));
    $scene->method('getUI')->willReturn($this->createStub(\Ichiloto\Engine\UI\UIManager::class));
  } else { Console::withLayer('field', fn() => Console::write('Outgoing field', 1, 12)); }
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Original notice', 'Before transfer', .5, animationDuration: 0);
  $this->manager->notify($notice);
  $this->runtime->present($scene, $this->manager);
  $deadline = new ReflectionProperty(NotificationManager::class, 'nextNotificationShowTime')->getValue($this->manager);
  $this->runtime->beginScreenHandoff(new \Ichiloto\Engine\Rendering\ScreenTransitionTreatment(getScreenTransitionFixture()));
  foreach ([\Ichiloto\Engine\Rendering\ScreenTransitionPhase::GATHER,
    \Ichiloto\Engine\Rendering\ScreenTransitionPhase::COVER,
    \Ichiloto\Engine\Rendering\ScreenTransitionPhase::HOLD] as $phase) {
    $this->runtime->setScreenHandoffPhase($phase, .5);
    $this->runtime->present($scene); // The scene callback need not forward the queue.
    $frames = RetainedFrameState::replay($this->transport->sent);
    $canvas = end($frames)['canvas'];
    $noticeLayers = array_filter($canvas['textLayers'], static fn($layer) => str_starts_with($layer['id'], $notice->getPresentationId()));
    expect($noticeLayers)->not->toBeEmpty();
    foreach ($noticeLayers as $layer) {
      expect($layer['layer'])->toBeGreaterThan(max(array_column($canvas['composites'], 'layer')));
    }
  }
  EventManager::getInstance($this->game)->dispatchEvent(new \Ichiloto\Engine\Events\SceneEvent(
    \Ichiloto\Engine\Events\Enumerations\SceneEventType::LOAD_START));
  Console::recomposeFrame(fn() => Console::write('Incoming field', 2, 12));
  EventManager::getInstance($this->game)->dispatchEvent(new \Ichiloto\Engine\Events\MapEvent(
    \Ichiloto\Engine\Events\Enumerations\MapEventType::LOAD));
  EventManager::getInstance($this->game)->dispatchEvent(new \Ichiloto\Engine\Events\SceneEvent(
    \Ichiloto\Engine\Events\Enumerations\SceneEventType::LOAD_END));
  $this->runtime->replaceScreenHandoff();
  $this->runtime->present($scene);
  expect(new ReflectionProperty(NotificationManager::class, 'nextNotificationShowTime')->getValue($this->manager))->toBe($deadline)
    ->and($this->audio->sounds)->toBe(1);
  Time::setElapsedTime(.51);
  $this->manager->update();
  $this->runtime->present($scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  $canvas = end($frames)['canvas'];
  expect(array_any($canvas['textLayers'], static fn($layer) => str_starts_with($layer['id'], $notice->getPresentationId())))->toBeFalse();
  $next = new Notification($this->game, NotificationChannel::INFO, 'During transition', 'Live delivery', 2.0, animationDuration: 0);
  $this->manager->notify($next);
  foreach ([\Ichiloto\Engine\Rendering\ScreenTransitionPhase::HOLD, \Ichiloto\Engine\Rendering\ScreenTransitionPhase::REVEAL] as $phase) {
    $this->runtime->setScreenHandoffPhase($phase, .5);
    $this->runtime->present($scene);
    $frames = RetainedFrameState::replay($this->transport->sent);
    $canvas = end($frames)['canvas'];
    $text = implode('', array_merge(...array_map(static fn($layer) => array_column($layer['runs'], 'text'), $canvas['textLayers'])));
    expect($text)->toContain('During transition')->not->toContain('Original notice');
  }
  $this->runtime->endScreenHandoff();
  $this->runtime->present($scene);
  expect($next->isFinished())->toBeFalse()->and($this->audio->sounds)->toBe(2);
})->with([true, false])->with([true, false]);

it('paints scene-owned covers over an opaque canvas with notifications still above them', function () {
  ($this->startRenderer)();
  $cover = new \Ichiloto\Engine\Rendering\ScreenTransition(
    \Ichiloto\Engine\Rendering\Enumerations\TransitionStyle::FADE)->composeCover(1350, 720, 0.5);
  $scene = $this->createStub(NotificationCanvasOverlaySceneProbe::class);
  $scene->method('getPresentationCanvas')->willReturn(new PresentationCanvas(1350, 720,
    images: [new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage('scene-image', 'Panel.png', new CanvasRectangle(0, 0, 1350, 720))]));
  $scene->method('getPresentationOverlay')->willReturn($cover);
  $scene->method('getUI')->willReturn($this->createStub(\Ichiloto\Engine\UI\UIManager::class));
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Still visible', 'Above cover', animationDuration: 0);
  $this->manager->notify($notice);
  $this->runtime->present($scene, $this->manager);
  $canvas = RetainedFrameState::getLatestFrame($this->transport->sent)['canvas'];
  $image = array_column($canvas['images'], null, 'id')['scene-image'];
  $composite = $canvas['composites'][0];
  expect($composite['opacity'])->toBe(0.5)->and($composite['layer'])->toBeGreaterThan($image['layer']);
  foreach ($canvas['textLayers'] as $layer) {
    expect($layer['layer'])->toBeGreaterThan($composite['layer']);
  }
  expect(array_any($canvas['textLayers'], static fn($layer) => str_starts_with($layer['id'], $notice->getPresentationId())))->toBeTrue();
});

it('projects a real GameScene cinematic cover while preserving terminal fallback and owned cleanup', function (bool $supported) {
  ($this->startRenderer)($supported);
  $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  $camera = new \Ichiloto\Engine\Rendering\Camera($scene, 135, 36);
  new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $camera);
  $scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(135, 36, 10, 20),
    $this->runtime->supports(...), fieldActive: false));
  $presentation = new \Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager($scene);
  new ReflectionProperty(GameScene::class, 'cinematicPresentation')->setValue($scene, $presentation);
  $presentation->hideField('#');
  Console::recomposeFrame(fn() => $presentation->render());
  expect(Console::charAt(5, 5))->toBe('#');
  $overlay = $scene->getPresentationOverlay(1350, 720);
  if ($supported) {
    expect($overlay->composites[0]->opacity)->toBe(1.0)
      ->and($scene->getExcludedOverlayLayers())->toBe(['cinematic-cover', 'transition']);
    $this->runtime->present($scene);
    $frame = RetainedFrameState::getLatestFrame($this->transport->sent);
    expect($frame['canvas']['composites'])->toHaveCount(1)
      ->and(array_column($frame['textLayers'], 'id'))->not->toContain('cinematic-cover', 'transition')
      ->and(Console::charAt(5, 5))->toBe('#');
  } else {
    expect($overlay)->toBeNull()->and($scene->getExcludedOverlayLayers())->toBe([]);
  }
  $presentation->clearTransition();
  expect($scene->getPresentationOverlay(1350, 720))->toBeNull()
    ->and($scene->getExcludedOverlayLayers())->toBe([]);
  if ($supported) {
    Console::recomposeFrame(fn() => $presentation->render());
    $this->runtime->present($scene);
    expect(RetainedFrameState::getLatestFrame($this->transport->sent))->not->toHaveKey('canvas');
  }
})->with([true, false]);

it('keeps live notices above a full-budget menu canvas and through its return to the field', function () {
  ($this->startRenderer)();
  $layers = array_map(fn($i) => new CanvasTextLayer('dense-menu-' . $i, 30,
    ($i % 8) * 160, intdiv($i, 8) * 40, new RendererGridConfig(12, 1, 10, 20),
    [new PresentationTextRun(0, 0, 'Record ' . $i, $this->theme->colors['text'])]), range(0, 63));
  $scene = $this->createStub(NotificationCanvasSceneProbe::class);
  $scene->method('getPresentationCanvas')->willReturn(new PresentationCanvas(1350, 720, textLayers: $layers));
  $scene->method('getUI')->willReturn($this->createStub(\Ichiloto\Engine\UI\UIManager::class));
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Achievement unlocked', 'A full menu',
    2.0, animationDuration: 0);
  $this->manager->notify($notice);
  expect($this->runtime->present($scene, $this->manager))->toBeTrue();
  $canvas = RetainedFrameState::getLatestFrame($this->transport->sent)['canvas'];
  expect(count($canvas['textLayers']))->toBeLessThanOrEqual(64);
  $text = implode('', array_merge(...array_map(fn($layer) => array_column($layer['runs'], 'text'), $canvas['textLayers'])));
  foreach (range(0, 63) as $i) { expect($text)->toContain('Record ' . $i); }
  expect($text)->toContain('Achievement unlocked', 'A full menu');
  $notices = array_filter($canvas['textLayers'], fn($layer) => str_starts_with($layer['id'], $notice->getPresentationId()));
  expect($notices)->not->toBeEmpty();
  foreach ($notices as $layer) { expect($layer['layer'])->toBeGreaterThan(30); }
  Console::recomposeFrame(fn() => Console::write('Back in the field', 1, 16));
  $this->runtime->present(null, $this->manager);
  $frame = RetainedFrameState::getLatestFrame($this->transport->sent);
  expect(array_any($frame['canvas']['textLayers'], fn($layer) => str_starts_with($layer['id'], 'dense-menu-')))->toBeFalse()
    ->and(array_any($frame['canvas']['textLayers'], fn($layer) => str_starts_with($layer['id'], $notice->getPresentationId())))->toBeTrue()
    ->and($notice->isFinished())->toBeFalse()->and($this->audio->sounds)->toBe(1);
});

it('retains the same terminal notice above transfers and transition text until its normal deadline', function () {
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Still visible', 'Same notice', .5, animationDuration: 0);
  $this->manager->notify($notice);
  $this->manager->render();
  $id = $notice->getPresentationId();
  Console::recomposeFrame(fn() => Console::withLayer('transition-cover', fn() => Console::write(str_repeat('X', 135), 0, 1),
    PresentationLayerPolicy::TRANSITION));
  $layers = array_column(Console::presentationSnapshot()->textLayers, null, 'id');
  expect($layers[$id]->layer)->toBeGreaterThan($layers['transition-cover']->layer)
    ->and(implode('', Console::getBuffer()))->toContain('Still visible');
  Console::recomposeFrame(fn() => Console::write('Different scene', 1, 10));
  expect(implode('', Console::getBuffer()))->toContain('Still visible', 'Different scene');
  Time::setElapsedTime(.51);
  $this->manager->update();
  expect(array_column(Console::presentationSnapshot()->textLayers, 'id'))->not->toContain($id)
    ->and(implode('', Console::getBuffer()))->toContain('Different scene')->not->toContain('Still visible');
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

it('skins formatted notices without losing highlighted text or falling back to Terminal', function (bool $reducedMotion) {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36,
    'accessibility' => ['reducedMotion' => $reducedMotion]]));
  ($this->startRenderer)();
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Task available',
    "A named task\nUse " . Color::apply('T', Color::YELLOW) . ' to continue.');
  $this->manager->notify($notice);
  Time::setElapsedTime(1);
  $this->manager->update();
  $canvas = $this->manager->composePresentation(null, 1350, 720, []);
  expect($canvas)->not->toBeNull()
    ->and($this->manager->hasGraphicalPresentation())->toBeTrue()
    ->and(array_column($canvas->images, 'asset'))->toContain('Panel.png', 'Icon.png')
    ->and(notificationCanvasText($canvas))->toContain('Task available', 'A named task', 'Use ', ' to continue.')
    ->not->toContain("\e")
    ->and($this->audio->sounds)->toBe(1)
    ->and($notice->getContentText())->toContain(Color::YELLOW->value);
  $body = array_find($canvas->textLayers, fn($layer) => $layer->id === $notice->getPresentationId() . '-body');
  $hintColor = SgrColorParser::parse(Color::YELLOW->value . 'x')['foreground'];
  $hint = array_find($body->runs, fn($run) => $run->foreground == $hintColor);
  expect($hint)->not->toBeNull()
    ->and($hint->text)->not->toBe('')
    ->and(implode('', array_column($body->runs, 'text')))
    ->toBe(str_replace("\n", '', TerminalText::stripAnsi($notice->getContentText())));
})->with([false, true]);

it('preserves formatted titles and bodies for every notification channel at normal and compact sizes', function (NotificationChannel $channel, int $width, int $height) {
  $title = Color::apply('Available', Color::GREEN);
  $body = "A named task\nUse " . Color::apply('key', Color::YELLOW) . ' to continue.';
  $notice = new Notification($this->game, $channel, $title, $body, animationDuration: 0);
  $notice->open();
  $surface = NotificationCanvasPresentation::compose($notice, $this->theme, $width, $height);
  expect(notificationCanvasText($surface->canvas))->toBe("Available\nA named taskUse key to continue.")
    ->and($surface->canvas->images)->toHaveCount(2)
    ->and($notice->getContentTitle())->toBe($title)->and($notice->getContentText())->toBe($body);
  foreach ($surface->canvas->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      expect($run->text)->not->toContain("\e");
      $run->assertFits($layer->grid->columns, $layer->grid->rows);
    }
  }
})->with(NotificationChannel::cases())->with([[1280, 720], [800, 480]]);

it('removes long-form toast rendering without clipping long names or reward counts', function () {
  $title = 'A named assignment with a longer but readable title';
  $body = "Recovered relay evidence from the north approach\n19/20, 123,456 G, LongNamedItem x12";
  $notice = new Notification($this->game, NotificationChannel::QUEST, $title, $body, animationDuration: 0);
  $notice->open();
  expect(fn() => NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720))
    ->toThrow(NotificationContentOverflow::class)
    ->and($notice->getContentTitle())->toBe($title)->and($notice->getContentText())->toBe($body);
});

it('reports unrepresentable content rather than truncating its immutable message', function () {
  $body = str_repeat('Complete diagnostic line.\n', 100);
  $notice = new Notification($this->game, NotificationChannel::ERROR, 'Diagnostic', $body, animationDuration: 0);
  $notice->open();
  expect(fn() => NotificationCanvasPresentation::compose($notice, $this->theme, 800, 480))
    ->toThrow(NotificationContentOverflow::class)->and($notice->getContentText())->toBe($body);
});

it('removes alternate-corner placement and retains the fixed top-right anchor after obstruction', function () {
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', 'Body', animationDuration: 0);
  $notice->open();
  $right = new CanvasRectangle(700, 0, 580, 300);
  expect(NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, [$right])->bounds)
    ->toEqual(NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720)->bounds);
  $left = new CanvasRectangle(0, 0, 500, 300);
  $surface = NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, [$left]);
  expect($surface->bounds->x)->toBe(812.0)->and($surface->bounds->y)->toBe(24.0);
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

it('routes oversized content to an acknowledged alert rather than indefinitely queuing a toast', function (bool $graphical) {
  if ($graphical) { ($this->startRenderer)(); }
  $body = str_repeat("Complete diagnostic line.\n", 100);
  $notice = new Notification($this->game, NotificationChannel::ERROR, 'Diagnostic', $body, animationDuration: 0);
  $this->manager->notify($notice);
  $this->manager->composePresentation(null, 1350, 720, []);
  Time::setElapsedTime(20);
  $this->manager->update();
  $this->manager->composePresentation(null, 1350, 720, []);
  $modalManager = ModalManager::getInstance($this->game);
  expect(new ReflectionProperty($modalManager, 'pendingAlerts')->getValue($modalManager))
    ->toBe([['message' => $body, 'title' => 'Diagnostic']])
    ->and(new ReflectionProperty($this->manager, 'notifications')->getValue($this->manager)->isNotEmpty())->toBeFalse()
    ->and($this->audio->sounds)->toBe(0)->and($notice->getContentText())->toBe($body);
})->with([false, true]);

it('promotes a brief notice that cannot fit its graphical line budget without blocking composition', function () {
  ($this->startRenderer)();
  $body = str_repeat('W', 60);
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', $body, animationDuration: 0);
  $this->manager->notify($notice);
  $base = new PresentationCanvas(300, 400);
  expect($this->manager->composePresentation($base, 300, 400, []))->toBe($base)
    ->and(new ReflectionProperty(ModalManager::getInstance($this->game), 'pendingAlerts')
      ->getValue(ModalManager::getInstance($this->game)))->toBe([['message' => $body, 'title' => 'Title']]);
});

it('removes transition obstruction deferral and keeps a toast immediately available', function () {
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', 'Body', animationDuration: 0);
  $notice->open();
  $text = new ConsolePresentationSnapshot(128, 30, [
    new PresentationTextLayer('transition', PresentationLayerPolicy::TRANSITION, [
      new PresentationTextRun(0, 0, str_repeat(' ', 128)),
      new PresentationTextRun(1, 0, str_repeat(' ', 128)),
    ]),
  ]);
  $areas = NotificationPlacement::getProtectedAreas(null, $text, [], new RendererGridConfig(128, 30, 10, 24));
  expect(notificationCanvasText(NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, $areas)->canvas))
    ->toContain('Title', 'Body');
});

it('removes dense-menu deferral while retaining normal entry hold and queue order', function () {
  ($this->startRenderer)();
  $first = new Notification($this->game, NotificationChannel::INFO, 'First', 'Message', NotificationDuration::SHORT);
  $second = new Notification($this->game, NotificationChannel::INFO, 'Second', 'Message', NotificationDuration::SHORT);
  $this->manager->notify($first);
  $this->manager->notify($second);
  $blocked = [new CanvasRectangle(0, 0, 1350, 720)];
  expect($this->manager->composePresentation(null, 1350, 720, $blocked))->not->toBeNull();
  Time::setElapsedTime(0.15);
  $this->manager->update();
  expect($first->getPresentationOpacity())->toBeGreaterThan(0.49)->toBeLessThan(0.51);
  Time::setElapsedTime(0.3);
  $this->manager->update();
  Time::setElapsedTime(4.2);
  $this->manager->update();
  expect($first->isFinished())->toBeFalse()->and($first->getPresentationOpacity())->toBe(1.0);
  Time::setElapsedTime(4.31);
  $this->manager->update();
  Time::setElapsedTime(4.62);
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

it('removes protected-content suppression and overlays both decorative and occupied panels', function () {
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
  $empty = $full->finish();
  expect(notificationCanvasText($this->manager->composePresentation($empty, 1350, 720,
    NotificationPlacement::getProtectedAreas($empty, null, [], $grid))))->toContain('Title', 'Body');
  $full->protect(new CanvasRectangle(125, 10, 1100, 700));
  $dense = $full->finish();
  expect(notificationCanvasText($this->manager->composePresentation($dense, 1350, 720,
    NotificationPlacement::getProtectedAreas($dense, null, [], $grid))))->toContain('Title', 'Body');
});

it('removes menu-edge relocation rather than moving a blocked toast beneath headings or controls', function () {
  $notice = new Notification($this->game, NotificationChannel::ACHIEVEMENT, 'First purchase', 'Achievement unlocked', animationDuration: 0);
  $notice->open();
  $heading = new CanvasRectangle(0, 0, 1280, 180);
  $anchor = NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720)->bounds;
  expect(NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, [$heading])->bounds)->toEqual($anchor);
  $control = new CanvasRectangle(700, 180, 580, 300);
  expect(NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, [$heading, $control])->bounds)->toEqual($anchor);
  $surface = NotificationCanvasPresentation::compose($notice, $this->theme, 1280, 720, [$control]);
  expect($surface->bounds->x)->toBe(812.0)->and($surface->bounds->y)->toBe(24.0)
    ->and(NotificationPlacement::isClear($surface->bounds, [$control]))->toBeTrue();
});

it('keeps the top and right margins when complete content needs the wider compact panel', function () {
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', str_repeat('x', 40), animationDuration: 0);
  $notice->open();
  $surface = NotificationCanvasPresentation::compose($notice, $this->theme, 800, 240);
  expect($surface->bounds->width)->toBe(520.0)->and($surface->bounds->x)->toBe(264.0)
    ->and($surface->bounds->y)->toBe(16.0)->and(notificationCanvasText($surface->canvas))->toContain(str_repeat('x', 40));
});

it('removes obstruction deferral for unannotated canvases too', function () {
  $canvas = new PresentationCanvas(1350, 720, [new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage(
    'unannotated-panel', 'Panel.png', new CanvasRectangle(0, 0, 1350, 720))]);
  $areas = NotificationPlacement::getProtectedAreas($canvas, null, [], new RendererGridConfig(135, 36, 10, 20));
  expect($areas)->toHaveCount(1);
  $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', 'Body', animationDuration: 0);
  $notice->open();
  expect(NotificationCanvasPresentation::compose($notice, $this->theme, 1350, 720, $areas))->not->toBeNull();
});

it('preserves each owners protection through annotated unannotated and empty overlay combinations', function () {
  $view = new MenuCanvas($this->theme);
  $view->frame('decorative-panel', new CanvasRectangle(125, 10, 1100, 700));
  $view->protect(new CanvasRectangle(125, 200, 400, 80));
  $annotated = $view->finish();
  $unknown = new PresentationCanvas(1350, 720, textLayers: [new CanvasTextLayer('external-help', 30, 700, 660,
    new RendererGridConfig(4, 1, 10, 20), [new PresentationTextRun(0, 0, 'Back')])]);
  $empty = new PresentationCanvas(1350, 720);
  foreach ([[$annotated, $unknown], [$unknown, $annotated], [$annotated, $empty], [$empty, $annotated]] as [$base, $overlay]) {
    $combined = MenuCanvas::overlay($base, $overlay, $this->theme);
    expect($combined->protectedAreas)->toEqual([...$base->getOverlayProtection(), ...$overlay->getOverlayProtection()])
      ->and($combined->toArray())->not->toHaveKeys(['protectedAreas', 'overlayProtection']);
    $notice = new Notification($this->game, NotificationChannel::INFO, 'Title', 'Body', animationDuration: 0);
    $notice->open();
    $surface = NotificationCanvasPresentation::compose($notice, $this->theme, 1350, 720, $combined->getOverlayProtection());
    expect($surface)->not->toBeNull()->and($surface->bounds->x)->toBe(882.0)->and($surface->bounds->y)->toBe(24.0);
  }
  $opaque = new PresentationCanvas(1350, 720, [new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage(
    'unknown-full-screen', 'Panel.png', new CanvasRectangle(0, 0, 1350, 720))]);
  $combined = MenuCanvas::overlay($annotated, $opaque, $this->theme);
  expect(NotificationCanvasPresentation::compose($notice, $this->theme, 1350, 720, $combined->getOverlayProtection()))->not->toBeNull();
});

it('keeps one anchor and topmost layering across field stacked menus modals and restored field frames', function () {
  ($this->startRenderer)();
  $notice = new Notification($this->game, NotificationChannel::ACHIEVEMENT, 'Achievement unlocked', 'Errand Runner',
    animationDuration: 0);
  $this->manager->notify($notice);
  $view = new MenuCanvas($this->theme);
  $view->frame('menu-decoration', new CanvasRectangle(125, 10, 1100, 700));
  $view->prose('menu-control', 'A real control', new CanvasRectangle(145, 240, 300, 24));
  $menu = MenuCanvas::overlay($view->finish(), new PresentationCanvas(1350, 720, textLayers: [new CanvasTextLayer(
    'additional-help', 100, 700, 660, new RendererGridConfig(4, 1, 10, 20), [new PresentationTextRun(0, 0, 'Back')])]), $this->theme);
  $modal = MenuModalPresentation::compose($menu, new ModalPresentation('Confirm', 'Continue?', ['No', 'Yes'], 0), $this->theme);
  foreach ([null, $menu, $modal, $menu, null] as $base) {
    $scene = null;
    if ($base !== null) {
      $scene = $this->createMock(NotificationCanvasSceneProbe::class);
      $scene->method('getPresentationCanvas')->willReturn($base);
    }
    $this->runtime->present($scene, $this->manager);
    $frames = RetainedFrameState::replay($this->transport->sent);
    $canvas = end($frames)['canvas'];
    $text = array_column($canvas['textLayers'], null, 'id');
    $title = $text[$notice->getPresentationId() . '-title'];
    expect($title['clipRect'])->toBe(['x' => 882.0, 'y' => 24.0, 'width' => 444.0, 'height' => 102.0]);
    if ($base !== null) {
      expect($title['layer'])->toBeGreaterThan(max([...array_column($base->images, 'layer'), ...array_column($base->textLayers, 'layer')]))
        ->and(NotificationPlacement::isClear(new CanvasRectangle(...array_values($title['clipRect'])), $base->getOverlayProtection()))->toBeTrue();
    }
  }
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
