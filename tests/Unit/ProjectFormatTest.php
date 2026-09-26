<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Exceptions\UnsupportedProjectFormatException;
use Ichiloto\Engine\Util\Config\ConfigStore;

/** Runs only Game's real configuration bootstrap, without handlers or platform services. */
class ProjectFormatBootstrapGame extends Game
{
  public function __construct()
  {
    $this->options = [];
  }

  public function initializeProjectConfiguration(): void
  {
    new ReflectionMethod(Game::class, 'initializeConfigStore')->invoke($this);
  }

  public function __destruct()
  {
  }
}

it('reads the current format and treats a missing or malformed record as predating the chain', function () {
  expect(ProjectFormat::CURRENT)->toBe(2)
    ->and(ProjectFormat::getVersion(2))->toBe(2)
    ->and(ProjectFormat::getVersion(null))->toBe(0)
    ->and(ProjectFormat::getVersion('2'))->toBe(0)
    ->and(ProjectFormat::getVersion(-1))->toBe(0);
  ProjectFormat::assertSupported(ProjectFormat::CURRENT);
});

it('refuses an older project and points to the upgrade, which explains the changes', function (mixed $recorded, int $version) {
  expect(fn() => ProjectFormat::assertSupported($recorded))->toThrow(UnsupportedProjectFormatException::class,
    "This project's format ({$version}) is older than this engine's (2). Run `ichiloto upgrade` in the project directory");
})->with([
  'missing' => [null, 0],
  'format zero' => [0, 0],
  'format one' => [1, 1],
]);
it('refuses a project from a newer engine instead of misreading it', function () {
  expect(fn() => ProjectFormat::assertSupported(ProjectFormat::CURRENT + 1))
    ->toThrow(UnsupportedProjectFormatException::class, "This project's format (3) is newer than this engine's (2).");
});

it('refuses to boot a project whose ichiloto.json records another format', function (string $json, string $message) {
  $originalDirectory = getcwd();
  $originalConfig = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $root = sys_get_temp_dir() . '/' . uniqid('ichiloto-project-format-', true);
  mkdir($root . '/assets/Data', 0777, true);
  file_put_contents($root . '/ichiloto.json', $json);
  chdir($root);
  try {
    expect(fn() => new ProjectFormatBootstrapGame()->initializeProjectConfiguration())
      ->toThrow(UnsupportedProjectFormatException::class, $message);
  } finally {
    chdir($originalDirectory);
    new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $originalConfig);
    unlink($root . '/ichiloto.json');
    rmdir($root . '/assets/Data');
    rmdir($root . '/assets');
    rmdir($root);
  }
})->with([
  'missing format' => ['{"id":"format-fixture"}', 'ichiloto upgrade'],
  'older format' => ['{"id":"format-fixture","format":1}', "This project's format (1) is older"],
  'newer format' => ['{"id":"format-fixture","format":3}', "This project's format (3) is newer"],
]);
