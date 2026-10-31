<?php

use Ichiloto\Engine\Core\GameLaunchContext;
use Ichiloto\Engine\Scenes\Arena\LaunchTroopHandOver;

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-handover-' . bin2hex(random_bytes(6));
  mkdir($this->root);
});

afterEach(function () {
  foreach (glob($this->root . '/' . LaunchTroopHandOver::DIRECTORY . '/*') ?: [] as $file) { unlink($file); }
  if (is_dir($this->root . '/' . LaunchTroopHandOver::DIRECTORY)) { rmdir($this->root . '/' . LaunchTroopHandOver::DIRECTORY); }
  rmdir($this->root);
});

it('hands kept troops to the launching editor through the data root, each taken once and in order', function () {
  $keeper = new LaunchTroopHandOver($this->root);
  $first = [['enemy' => 'Bat', 'position' => [10, 5]]];
  $second = [['enemy' => 'Wolf', 'position' => [20, 5], 'graphicalPlacement' => ['x' => 300.0, 'depth' => 40.0, 'width' => 120.0, 'height' => 90.0]]];

  expect($keeper->keepTroop($first))->toContain('editor');
  $keeper->keepTroop($second);
  // A file being written is never taken half done.
  file_put_contents($this->root . '/' . LaunchTroopHandOver::DIRECTORY . '/9-partial.part', '{');

  // Whole canvas units read back as whole numbers, which a placement accepts as it does the decimals written.
  expect(LaunchTroopHandOver::takeKept($this->root))->toEqual([$first, $second])
    ->and(LaunchTroopHandOver::takeKept($this->root))->toBe([])
    ->and(fn() => $keeper->keepTroop([]))->toThrow(RuntimeException::class, 'member');
  unlink($this->root . '/' . LaunchTroopHandOver::DIRECTORY . '/9-partial.part');
});

it('reads a launch that keeps the troops its battle test composes, only with a battle test and a data root', function () {
  $context = GameLaunchContext::fromArray(['battleTest' => ['troop' => 'Bats'], 'dataRoot' => $this->root, 'keepTroops' => true]);

  expect($context->keepTroops)->toBeTrue()
    ->and(GameLaunchContext::fromArray(['battleTest' => []])->keepTroops)->toBeFalse()
    ->and(fn() => GameLaunchContext::fromArray(['battleTest' => [], 'keepTroops' => true]))->toThrow(InvalidArgumentException::class, 'data root')
    ->and(fn() => GameLaunchContext::fromArray(['mode' => 'title', 'dataRoot' => $this->root, 'keepTroops' => true]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => GameLaunchContext::fromArray(['battleTest' => [], 'dataRoot' => $this->root, 'keepTroops' => 'yes']))->toThrow(InvalidArgumentException::class, 'true or false');
});
