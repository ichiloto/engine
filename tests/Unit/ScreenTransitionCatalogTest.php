<?php

use Ichiloto\Engine\Rendering\ScreenTransitionCatalog;
use Ichiloto\Engine\Rendering\ScreenTransitionTreatment;

require_once __DIR__ . '/../Fixtures/Rendering/ScreenTransitions.php';

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/transition-catalog-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/Data/Presentation', 0777, true);
  $this->writeImage = function (string $name, int $width = 48, int $height = 48): void {
    // Header-only fixtures validate PHP's asset contract, not native PNG decoding.
    file_put_contents($this->root . '/' . $name, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', $width, $height));
  };
});

afterEach(function () {
  foreach (glob($this->root . '/Data/Presentation/*') ?: [] as $file) { unlink($file); }
  rmdir($this->root . '/Data/Presentation');
  rmdir($this->root . '/Data');
  foreach (glob($this->root . '/*') ?: [] as $file) { unlink($file); }
  rmdir($this->root);
});

function getImageTransitionFixture(string $asset, bool $selected = true): ScreenTransitionCatalog
{
  $data = getScreenTransitionFixture();
  $data['phases']['gather'][] = ['operation' => ['type' => 'image', 'asset' => $asset,
    'destination' => ['x' => 0, 'y' => 0, 'width' => 1600, 'height' => 900]]];
  $treatment = new ScreenTransitionTreatment($data);
  return new ScreenTransitionCatalog([$treatment->id => $treatment], $selected ? $treatment->id : null);
}

it('loads optional project-owned catalogues and refuses wrong return types or unknown bindings', function () {
  expect(ScreenTransitionCatalog::load($this->root))->toBeNull();
  file_put_contents($this->root . '/' . ScreenTransitionCatalog::FILE, '<?php return [];');
  expect(fn() => ScreenTransitionCatalog::load($this->root))->toThrow(RuntimeException::class, 'must return');
  file_put_contents($this->root . '/' . ScreenTransitionCatalog::FILE,
    '<?php return new \\Ichiloto\\Engine\\Rendering\\ScreenTransitionCatalog([]);');
  expect(ScreenTransitionCatalog::load($this->root)->getBattleTreatment())->toBeNull();
  expect(fn() => new ScreenTransitionCatalog([], 'missing'))->toThrow(InvalidArgumentException::class, 'Unknown battle');
  $treatment = new ScreenTransitionTreatment(getScreenTransitionFixture());
  expect(fn() => new ScreenTransitionCatalog(['wrong' => $treatment]))->toThrow(InvalidArgumentException::class, 'keys');
  $catalog = new ScreenTransitionCatalog([$treatment->id => $treatment], $treatment->id);
  expect($catalog->getChoices())->toBe(['gilded-sweep' => 'gilded-sweep'])
    ->and($catalog->getBattleTreatment())->toBe($treatment);
});

it('validates optional transition artwork even when it is not the selected battle treatment', function () {
  $catalog = getImageTransitionFixture('curtain.png', false);
  expect(fn() => $catalog->validateAssets($this->root))->toThrow(RuntimeException::class, 'Transition gilded-sweep:');
  ($this->writeImage)('curtain.png');
  $catalog->validateAssets($this->root);
  expect($catalog->getBattleTreatment())->toBeNull();
  file_put_contents($this->root . '/curtain.png', 'Not a PNG');
  expect(fn() => $catalog->validateAssets($this->root))->toThrow(RuntimeException::class, 'Invalid PNG');
});

it('allows supported artwork replacements without hashes or duplicate dimensions', function () {
  $catalog = getImageTransitionFixture('curtain.png');
  foreach ([[48, 48], [96, 144], [200, 100]] as $revision => [$width, $height]) {
    ($this->writeImage)('curtain.png', $width, $height);
    touch($this->root . '/curtain.png', 1700000000 + $revision);
    $catalog->validateAssets($this->root);
    expect($catalog->getBattleTreatment()->id)->toBe('gilded-sweep');
  }
});

it('refuses catalogue and PNG symlinks escaping the asset root', function () {
  $outside = sys_get_temp_dir() . '/outside-transition-' . bin2hex(random_bytes(5)) . '.png';
  file_put_contents($outside, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . pack('NN', 48, 48));
  try {
    symlink($outside, $this->root . '/curtain.png');
    expect(fn() => getImageTransitionFixture('curtain.png')->validateAssets($this->root))
      ->toThrow(RuntimeException::class, 'inside assets');
    symlink($outside, $this->root . '/' . ScreenTransitionCatalog::FILE);
    expect(fn() => ScreenTransitionCatalog::load($this->root))->toThrow(RuntimeException::class, 'inside assets');
  } finally { unlink($outside); }
});
