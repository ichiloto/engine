<?php

use Ichiloto\Engine\Rendering\Launch\ApplicationIcon;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-icon-' . bin2hex(random_bytes(6));
  mkdir($this->root . '/Graphics/System', 0777, true);
  file_put_contents($this->root . '/Graphics/System/Game.icns', 'icns');
  file_put_contents($this->root . '/Graphics/System/Game.png', 'png');
  file_put_contents(dirname($this->root) . '/outside-icon.png', 'png');
});

afterEach(function () {
  foreach (['Graphics/System/Game.icns', 'Graphics/System/Game.png'] as $file) { @unlink($this->root . '/' . $file); }
  @rmdir($this->root . '/Graphics/System');
  @rmdir($this->root . '/Graphics');
  @rmdir($this->root);
  @unlink(dirname($this->root) . '/outside-icon.png');
});

it('accepts a readable PNG or ICNS icon inside the assets', function (string $icon) {
  expect(ApplicationIcon::getAssetPath($icon, $this->root))->toBe($icon);
})->with(['Graphics/System/Game.icns', 'Graphics/System/Game.png']);

it('keeps the renderer icon when the project declares none or an unusable one', function (mixed $icon) {
  expect(ApplicationIcon::getAssetPath($icon, $this->root))->toBeNull();
})->with([null, 'Graphics/System/Missing.png', 'Graphics/System/Game.txt', '../outside-icon.png', '/Graphics/System/Game.png', 42]);

it('sends the icon in the renderer hello only when the game has one', function () {
  $session = fn(?string $icon) => new RendererSessionConfig('Game', $this->root, protocol: RendererProtocolVersion::V2, icon: $icon);

  expect($session('Graphics/System/Game.icns')->hello()->payload['icon'])->toBe('Graphics/System/Game.icns')
    ->and($session(null)->hello()->payload)->not->toHaveKey('icon')
    ->and(fn() => $session('../Game.icns'))->toThrow(InvalidArgumentException::class);
});
