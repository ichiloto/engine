<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Sprites\PngAssetBounds;
use Ichiloto\Engine\Rendering\Sprites\PngAssetFactsCache;
use Ichiloto\Engine\Rendering\Sprites\SpriteFactsPreparation;

if (PHP_SAPI !== 'cli') { exit(1); }

try {
  $loader = require $argv[1];
  // Use the same package that owns this script when multiple Engine versions are installed.
  $loader->setPsr4('Ichiloto\\Engine\\', dirname(__DIR__) . '/src');
  $bytes = stream_get_contents(STDIN, SpriteFactsPreparation::MAX_REQUEST_BYTES + 1);
  if ($bytes === false || strlen($bytes) > SpriteFactsPreparation::MAX_REQUEST_BYTES) {
    throw new RuntimeException('Sprite facts request exceeds its byte limit.');
  }
  $request = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
  $images = array_map(CanvasImage::fromArray(...), $request['images']);
  PngAssetBounds::setFactsCache(new PngAssetFactsCache($request['cacheDirectory']));
  foreach (PngAssetBounds::getVisibleBoundsForImages($request['assetRoot'], $images) as $index => $result) {
    if ($result['diagnostic'] !== null) {
      throw new RuntimeException($images[$index]->asset . ': ' . $result['diagnostic']);
    }
  }
} catch (Throwable $failure) {
  fwrite(STDERR, $failure->getMessage());
  exit(1);
}
