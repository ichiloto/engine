<?php

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use Ichiloto\Engine\Rendering\Launch\PackagedRendererExecutableResolver;
use Ichiloto\Engine\Rendering\Launch\RendererDescriptor;
use Ichiloto\Engine\Rendering\Launch\RendererExecutableResolverInterface;
use Ichiloto\Engine\Rendering\Launch\RendererLaunchIntent;
use Ichiloto\Engine\Rendering\Launch\RendererRegistry;
use Ichiloto\Engine\Rendering\Launch\RendererUnavailableException;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class LaunchIntentGameProbe extends Game
{
  public function __construct(?RendererRegistry $registry = null)
  {
    $this->name = 'Launch selection';
    $this->width = 135;
    $this->height = 36;
    $this->observers = new ItemList(ObserverInterface::class);
    $this->staticObservers = new ItemList(StaticObserverInterface::class);
    new ReflectionProperty(Game::class, 'rendererRegistry')->setValue($this, $registry);
  }

  public function __destruct() {}
  public function startInput(): void { $this->startInputSession(); }
}

beforeEach(function () {
  $this->environment = getenv('ICHILOTO_RENDERER');
  putenv('ICHILOTO_RENDERER');
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  $this->inputState = new ReflectionClass(InputManager::class)->getStaticProperties();
  $this->stdinBlocked = stream_get_meta_data(STDIN)['blocked'];
  $this->temporary = sys_get_temp_dir() . '/ichiloto-renderer-manifest-' . bin2hex(random_bytes(8));
  mkdir($this->temporary);
  $this->transport = new FakeRendererTransport();
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), __DIR__, 10, 20), $this->transport);
  $this->creations = 0;
  $this->assetRoot = null;
  $this->registry = new RendererRegistry(descriptors: [
    new RendererDescriptor('terminal', fn(string $root) => null),
    new RendererDescriptor('gpui', function (string $root) {
      $this->creations++;
      $this->assetRoot = $root;
      return $this->runtime;
    }),
  ]);
  InputManager::setInputSource(new TerminalInputSource());
  ob_start();
});

afterEach(function () {
  $this->runtime->shutdown();
  ob_end_clean();
  putenv($this->environment === false ? 'ICHILOTO_RENDERER' : 'ICHILOTO_RENDERER=' . $this->environment);
  stream_set_blocking(STDIN, $this->stdinBlocked);
  foreach ([Console::class => $this->consoleState, InputManager::class => $this->inputState] as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
  foreach (scandir($this->temporary) as $file) {
    if ($file !== '.' && $file !== '..') { unlink($this->temporary . '/' . $file); }
  }
  rmdir($this->temporary);
});

it('reads normalized launch intent and preserves invalid identities for validation', function (?string $value, string $expected) {
  putenv($value === null ? 'ICHILOTO_RENDERER' : 'ICHILOTO_RENDERER=' . $value);
  expect(RendererLaunchIntent::fromEnvironment()->id)->toBe($expected);
})->with([
  [null, 'terminal'], ['', 'terminal'], ['   ', 'terminal'], ['terminal', 'terminal'],
  ['gpui', 'gpui'], ['GPUI', 'gpui'], [" \tGpUi\n", 'gpui'], ['0', '0'], ['foo', 'foo'],
]);

it('registers exactly the stable identities and constructs GPUI through its injected resolver', function () {
  $resolver = new class implements RendererExecutableResolverInterface {
    public array $calls = [];
    public function resolve(string $rendererId): string { $this->calls[] = $rendererId; return PHP_BINARY; }
  };
  $registry = new RendererRegistry($resolver);
  expect(array_map(fn($descriptor) => $descriptor->id, $registry->all()))->toBe(['terminal', 'gpui'])
    ->and($registry->require(' TERMINAL ')->createRuntime(__DIR__))->toBeNull()
    ->and($resolver->calls)->toBe([])->and($registry->find('foo'))->toBeNull();
  $runtime = $registry->require('GPUI')->createRuntime(__DIR__);
  $config = new ReflectionProperty(RendererRuntime::class, 'config')->getValue($runtime);
  expect($resolver->calls)->toBe(['gpui'])
    ->and($config->process->command)->toBe([PHP_BINARY])
    ->and([$config->cellWidth, $config->cellHeight, $config->protocol])->toBe([10, 20, RendererProtocolVersion::V2])
    ->and(new ReflectionProperty(RendererRuntime::class, 'transport')->getValue($runtime))->toBeInstanceOf(ProcessRendererTransport::class);
});

it('rejects unknown and duplicate renderer identities', function () {
  expect(fn() => $this->registry->require('foo'))->toThrow(InvalidArgumentException::class, 'Unknown renderer "foo". Valid renderer IDs: terminal, gpui.')
    ->and(fn() => new RendererRegistry(descriptors: [
      new RendererDescriptor('terminal', fn($root) => null), new RendererDescriptor('terminal', fn($root) => null),
    ]))->toThrow(InvalidArgumentException::class, 'Duplicate renderer ID');
});

it('resolves only an executable packaged for the requested platform', function () {
  $executable = $this->temporary . '/renderer with spaces';
  file_put_contents($executable, '#!/bin/sh');
  chmod($executable, 0755);
  $manifest = $this->temporary . '/manifest.json';
  file_put_contents($manifest, json_encode(['version' => 1, 'renderers' => ['gpui' => ['darwin-arm64' => basename($executable)]]]));
  expect((new PackagedRendererExecutableResolver($manifest, 'darwin-arm64'))->resolve('gpui'))->toBe(realpath($executable))
    ->and(fn() => (new PackagedRendererExecutableResolver($manifest, 'linux-x64'))->resolve('gpui'))
    ->toThrow(RendererUnavailableException::class, 'GPUI renderer is not available for this installation/platform (linux-x64)');
});

it('fails clearly for missing malformed or unusable installed manifests', function (?string $contents) {
  $manifest = $this->temporary . '/manifest.json';
  if ($contents !== null) { file_put_contents($manifest, $contents); }
  $registry = new RendererRegistry(new PackagedRendererExecutableResolver($manifest, 'darwin-arm64'));
  expect(fn() => $registry->require('gpui')->createRuntime(__DIR__))
    ->toThrow(RendererUnavailableException::class, 'GPUI renderer is not available for this installation/platform (darwin-arm64)');
})->with([null, '{', 'null', '{"version":2,"renderers":{}}', '{"version":1,"renderers":{}}',
  '{"version":1,"renderers":{"gpui":{"darwin-arm64":"missing"}}}',
  '{"version":1,"renderers":{"gpui":{"darwin-arm64":"../outside"}}}',
  '{"version":1,"renderers":{"gpui":{"darwin-arm64":"/absolute/path"}}}',
  '{"version":1,"renderers":{"gpui":{"darwin-arm64":"C:\\\\outside"}}}',
]);

it('rejects non-executable files and links outside the package root', function () {
  $manifest = $this->temporary . '/manifest.json';
  file_put_contents($manifest, '{"version":1,"renderers":{"gpui":{"darwin-arm64":"renderer"}}}');
  file_put_contents($this->temporary . '/renderer', 'not executable');
  chmod($this->temporary . '/renderer', 0644);
  $resolver = new PackagedRendererExecutableResolver($manifest, 'darwin-arm64');
  expect(fn() => $resolver->resolve('gpui'))->toThrow(RendererUnavailableException::class);
  unlink($this->temporary . '/renderer');
  symlink(PHP_BINARY, $this->temporary . '/renderer');
  expect(fn() => $resolver->resolve('gpui'))->toThrow(RendererUnavailableException::class);
});

it('automatically attaches one runtime before deciding terminal input ownership', function () {
  putenv('ICHILOTO_RENDERER= GPUI ');
  $game = new LaunchIntentGameProbe($this->registry);
  $before = stream_get_meta_data(STDIN)['blocked'];
  $game->startInput();
  expect($this->creations)->toBe(1)->and($this->assetRoot)->toBe(getcwd() . '/assets')
    ->and($this->transport->session->grid->toArray())->toBe(['columns' => 135, 'rows' => 36, 'cellWidth' => 10, 'cellHeight' => 20])
    ->and(InputManager::getInputSource())->toBeInstanceOf(RendererInputSource::class)
    ->and(new ReflectionProperty(Game::class, 'terminalInputConfigured')->getValue($game))->toBeFalse()
    ->and(stream_get_meta_data(STDIN)['blocked'])->toBe($before);
  $game->quit();
  $game->quit();
  expect($this->transport->shutdowns)->toBe(1)->and(InputManager::getInputSource())->toBeInstanceOf(TerminalInputSource::class);
});

it('keeps absent or terminal intent on the existing terminal input lifecycle', function (?string $id) {
  putenv($id === null ? 'ICHILOTO_RENDERER' : 'ICHILOTO_RENDERER=' . $id);
  $game = new LaunchIntentGameProbe($this->registry);
  try {
    $game->startInput();
    expect(new ReflectionProperty(Game::class, 'rendererRuntime')->getValue($game))->toBeNull()
      ->and(new ReflectionProperty(Game::class, 'terminalInputConfigured')->getValue($game))->toBeTrue()
      ->and(InputManager::requiresTerminalInput())->toBeTrue()
      ->and(stream_get_meta_data(STDIN)['blocked'])->toBeFalse()
      ->and($this->creations)->toBe(0)->and($this->transport->session)->toBeNull();
    putenv('ICHILOTO_RENDERER=gpui');
    expect($this->creations)->toBe(0)
      ->and(fn() => $game->useRendererRuntime($this->runtime))->toThrow(LogicException::class);
  } finally { $game->quit(); }
  expect(stream_get_meta_data(STDIN)['blocked'])->toBeTrue()
    ->and(new ReflectionProperty(Game::class, 'terminalInputConfigured')->getValue($game))->toBeFalse()
    ->and($this->transport->shutdowns)->toBe(0);
})->with([null, '', 'terminal']);

it('honors explicit runtime attachment without consulting even invalid launch intent', function (string $environment) {
  putenv('ICHILOTO_RENDERER=' . $environment);
  $game = new LaunchIntentGameProbe($this->registry);
  $game->useRendererRuntime($this->runtime);
  $game->startInput();
  expect($this->creations)->toBe(0)->and($this->transport->session)->not->toBeNull();
  $game->quit();
  expect($this->transport->shutdowns)->toBe(1);
})->with(['terminal', 'gpui', 'unknown']);

it('fails selection before touching input and never rereads a failed intent as terminal', function (string $id) {
  putenv('ICHILOTO_RENDERER=' . $id);
  $game = new LaunchIntentGameProbe(new RendererRegistry(new PackagedRendererExecutableResolver($this->temporary . '/absent')));
  $exception = $id === 'gpui' ? RendererUnavailableException::class : InvalidArgumentException::class;
  expect(fn() => $game->startInput())->toThrow($exception);
  putenv('ICHILOTO_RENDERER=terminal');
  expect(fn() => $game->startInput())->toThrow($exception)
    ->and(InputManager::requiresTerminalInput())->toBeTrue()
    ->and(new ReflectionProperty(Game::class, 'terminalInputConfigured')->getValue($game))->toBeFalse()
    ->and(stream_get_meta_data(STDIN)['blocked'])->toBe($this->stdinBlocked);
})->with(['gpui', 'foo', '0']);
