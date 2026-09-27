<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Field\MapManager;

function collisionDictionaryManager(): MapManager
{
  return (new ReflectionClass(MapManager::class))->newInstanceWithoutConstructor();
}

function writeCollisionDictionary(string $body): string
{
  $path = tempnam(sys_get_temp_dir(), 'ichiloto-collisions-');

  if ($path === false) {
    throw new RuntimeException('Unable to create a collision dictionary fixture.');
  }

  file_put_contents($path, "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\nreturn {$body};\n");

  return $path;
}

it('loads numeric tile glyphs after PHP normalizes their array keys to integers', function () {
  $path = writeCollisionDictionary("['8' => CollisionType::SOLID]");

  try {
    $dictionary = collisionDictionaryManager()->loadCollisionDictionary($path);

    expect($dictionary['8'])->toBe(CollisionType::SOLID)
      ->and(collisionDictionaryManager()->generateCollisionMap(['8'], $dictionary))->toBe([
        [CollisionType::SOLID->value],
      ]);
  } finally {
    unlink($path);
  }
});

it('reports invalid dictionary entries without trying to stringify enum objects', function () {
  $path = writeCollisionDictionary('[12 => CollisionType::SOLID]');

  try {
    expect(fn() => collisionDictionaryManager()->loadCollisionDictionary($path))
      ->toThrow(NotFoundException::class, 'int(12) => Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID');
  } finally {
    unlink($path);
  }
});
