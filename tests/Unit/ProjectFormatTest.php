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

it('refuses an older project by listing every pending change and pointing to the upgrade', function (mixed $recorded, array $pending, array $done) {
  try {
    ProjectFormat::assertSupported($recorded);
    throw new RuntimeException('An outdated project was accepted.');
  } catch (UnsupportedProjectFormatException $error) {
    expect($error->getMessage())->toContain('Run `ichiloto upgrade`', ...$pending);
    foreach ($done as $change) { expect($error->getMessage())->not->toContain($change); }
  }
})->with([
  'missing' => [null, [ProjectFormat::CHANGES[1], ProjectFormat::CHANGES[2]], []],
  'format zero' => [0, [ProjectFormat::CHANGES[1], ProjectFormat::CHANGES[2]], []],
  'format one' => [1, [ProjectFormat::CHANGES[2]], [ProjectFormat::CHANGES[1]]],
]);

it('refuses a project from a newer engine instead of misreading it', function () {
  expect(fn() => ProjectFormat::assertSupported(ProjectFormat::CURRENT + 1))
    ->toThrow(UnsupportedProjectFormatException::class, 'This project uses format 3, which is newer than this engine reads (2).');
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
  'older format' => ['{"id":"format-fixture","format":1}', ProjectFormat::CHANGES[2]],
  'newer format' => ['{"id":"format-fixture","format":3}', 'newer than this engine reads'],
]);
