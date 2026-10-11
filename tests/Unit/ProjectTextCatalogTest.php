<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\Text\FontReference;
use Ichiloto\Engine\Rendering\Presentation\Text\ProjectTextCatalog;
use Ichiloto\Engine\Rendering\Presentation\Text\TextRole;
use Ichiloto\Engine\Rendering\Presentation\Text\TextSpec;

function getProjectTextRoleData(array $replace = []): array
{
  return array_replace([
    'fonts' => [['asset' => 'synthetic/first.otc', 'faceIndex' => 2], ['asset' => 'synthetic/fallback.ttf']],
    'fontSize' => 12.5, 'foreground' => ['kind' => 'rgb', 'r' => 30, 'g' => 60, 'b' => 90],
  ], $replace);
}

function getProjectTextCatalogData(array $roles = []): array
{
  return ['schema' => 'ichiloto.text/1', 'roles' => $roles];
}

/** Dataset names remain safe for evidence reports even when fixture bytes are malformed. */
function nameProjectTextCases(array $values): array
{
  $cases = [];
  foreach ($values as $index => $value) { $cases['case-' . $index] = [$value]; }
  return $cases;
}

function createProjectTextAssetRoot(): string
{
  $root = createTestDirectory('project-text-');
  mkdir($root . '/Data/Presentation', 0700, true);
  return $root;
}

function writeProjectTextCatalog(string $root, array $data): void
{
  file_put_contents($root . '/' . ProjectTextCatalog::FILE, '<?php return ' . var_export($data, true) . ';');
}

it('documents the explicit text schema and round trips ordered font role intent without decoding assets', function () {
  $catalog = ProjectTextCatalog::fromArray(getProjectTextCatalogData(['label.primary' => getProjectTextRoleData()]));
  $role = $catalog->getRole('label.primary');
  expect(ProjectTextCatalog::FILE)->toBe('Data/Presentation/text.php')
    ->and($role)->toBeInstanceOf(TextRole::class)
    ->and($role->fonts)->toEqual([new FontReference('synthetic/first.otc', 2), new FontReference('synthetic/fallback.ttf')])
    ->and($role->fontSize)->toBe(12.5)
    ->and($role->foreground)->toEqual(PresentationColor::rgb(30, 60, 90))
    ->and($role->glyphEffects)->toBeNull()
    ->and($catalog->toArray())->toBe(['schema' => 'ichiloto.text/1', 'roles' => ['label.primary' => [
      'fonts' => [['asset' => 'synthetic/first.otc', 'faceIndex' => 2], ['asset' => 'synthetic/fallback.ttf', 'faceIndex' => 0]],
      'fontSize' => 12.5, 'foreground' => ['kind' => 'rgb', 'r' => 30, 'g' => 60, 'b' => 90],
    ]]])
    ->and(ProjectTextCatalog::fromArray($catalog->toArray())->toArray())->toBe($catalog->toArray());
});

it('keeps locale and translated label supplied by the consumer rather than the role', function () {
  $role = TextRole::fromArray(getProjectTextRoleData());
  $first = $role->createSpec('Synthetic label', 'en');
  $translated = $role->createSpec("\u{5408}\u{6210}\u{30e9}\u{30d9}\u{30eb}", 'ja');
  expect($first)->toBeInstanceOf(TextSpec::class)
    ->and($first->text)->toBe('Synthetic label')->and($first->locale)->toBe('en')
    ->and($translated->text)->toBe("\u{5408}\u{6210}\u{30e9}\u{30d9}\u{30eb}")->and($translated->locale)->toBe('ja')
    ->and($translated->fonts)->toBe($first->fonts)->and($translated->foreground)->toBe($first->foreground)
    ->and($translated->fontSize)->toBe($first->fontSize)
    ->and($role->createSpec('', 'x-private')->text)->toBe('')
    ->and($role->createSpec("Line one\nLine two", 'und')->text)->toBe("Line one\nLine two")
    ->and(array_keys($role->toArray()))->toBe(['fonts', 'fontSize', 'foreground']);
});

it('reuses shared text validation for locale and label rather than bypassing it', function (array $arguments) {
  $role = TextRole::fromArray(getProjectTextRoleData());
  expect(fn() => $role->createSpec(...$arguments))->toThrow(InvalidArgumentException::class);
})->with(nameProjectTextCases([
  ['label', ''], ['label', str_repeat('a', 129)], ['label', "en\0"], ['label', "\xFF"],
  ["a\tb", 'en'], ["a\rb", 'en'], ["a\0b", 'en'], ["\xFF", 'en'],
  [str_repeat('a', 524289), 'en'], [str_repeat("\n", 32768), 'en'],
]));

it('detaches reference aliases throughout admitted and serialized roles', function () {
  $asset = 'synthetic/original.ttf';
  $face = 1;
  $size = 14;
  $red = 10;
  $font = ['asset' => &$asset, 'faceIndex' => &$face];
  $fonts = [&$font, &$font];
  $foreground = ['kind' => 'rgb', 'r' => &$red, 'g' => 20, 'b' => 30];
  $definition = ['fonts' => &$fonts, 'fontSize' => &$size, 'foreground' => &$foreground];
  $roles = ['label' => &$definition];
  $data = ['schema' => 'ichiloto.text/1', 'roles' => &$roles];
  $catalog = ProjectTextCatalog::fromArray($data);
  $before = $catalog->toArray();
  $asset = 'synthetic/replacement.otf';
  $face = 31;
  $size = 200;
  $red = 255;
  $fonts[] = ['asset' => 'synthetic/third.ttf'];
  $definition = getProjectTextRoleData();
  $roles = [];
  $serialized = $catalog->toArray();
  $serialized['roles']['label']['fonts'][0]['asset'] = 'synthetic/edited.ttf';
  $serialized['roles']['label']['foreground']['r'] = 0;
  expect($catalog->toArray())->toBe($before)
    ->and($catalog->getRole('label')->fontSize)->toBe(14.0)
    ->and($catalog->getRole('label')->fonts)->toHaveCount(2);

  $reference = new FontReference('synthetic/direct.ttf');
  $typedFonts = [&$reference];
  $direct = new TextRole($typedFonts, 12, PresentationColor::ansi16(0));
  $reference = new FontReference('synthetic/direct-replaced.ttf');
  expect($direct->fonts[0]->asset)->toBe('synthetic/direct.ttf')
    ->and(fn() => $direct->fonts = [])->toThrow(Error::class)
    ->and(fn() => $direct->fontSize = 20)->toThrow(Error::class)
    ->and(fn() => $direct->foreground = PresentationColor::ansi16(1))->toThrow(Error::class)
    ->and(fn() => $direct->glyphEffects = null)->toThrow(Error::class);
});

it('lets projects replace role intent under the same identity without modifying earlier catalogs', function () {
  $data = getProjectTextCatalogData(['label' => getProjectTextRoleData()]);
  $original = ProjectTextCatalog::fromArray($data);
  $data['roles']['label'] = getProjectTextRoleData([
    'fonts' => [['asset' => 'synthetic/other.otf']], 'fontSize' => 24,
    'foreground' => ['kind' => 'ansi256', 'index' => 200],
  ]);
  $replacement = ProjectTextCatalog::fromArray($data);
  expect(array_keys($replacement->toArray()['roles']))->toBe(['label'])
    ->and($replacement->getRole('label')->fonts[0]->asset)->toBe('synthetic/other.otf')
    ->and($replacement->getRole('label')->fontSize)->toBe(24.0)
    ->and($original->getRole('label')->fonts[0]->asset)->toBe('synthetic/first.otc');
});

it('supports all existing foreground kinds with their exact bounded integer descriptors', function (array $color) {
  $role = TextRole::fromArray(getProjectTextRoleData(['foreground' => $color]));
  expect($role->toArray()['foreground'])->toBe($color)
    ->and($role->createSpec('label', 'en')->foreground)->toBe($role->foreground);
})->with(nameProjectTextCases([
  ['kind' => 'rgb', 'r' => 0, 'g' => 128, 'b' => 255],
  ['kind' => 'ansi16', 'index' => 0], ['kind' => 'ansi16', 'index' => 15],
  ['kind' => 'ansi256', 'index' => 0], ['kind' => 'ansi256', 'index' => 255],
]));

it('retains optional typed glyph effects for PHP round trips and serializes them only in text specs', function () {
  $effects = new CanvasGlyphEffects(0.5, PresentationColor::ansi16(0), -1, 2, 0.25, 0.75, PresentationColor::rgb(1, 2, 3));
  foreach ([[], ['glyphEffects' => null], ['glyphEffects' => $effects]] as $optional) {
    $catalog = ProjectTextCatalog::fromArray(getProjectTextCatalogData(['label' => getProjectTextRoleData($optional)]));
    $role = $catalog->getRole('label');
    $roundTrip = ProjectTextCatalog::fromArray($catalog->toArray());
    expect($roundTrip->toArray())->toBe($catalog->toArray())
      ->and($role->glyphEffects)->toBe($optional['glyphEffects'] ?? null);
    if ($role->glyphEffects !== null) {
      expect($role->toArray()['glyphEffects'])->toBe($effects)
        ->and($role->createSpec('label', 'en')->toArray()['glyphEffects'])->toBe($effects->toArray());
    } else {
      expect(array_key_exists('glyphEffects', $role->toArray()))->toBeFalse();
    }
  }
});

it('accepts empty catalogs and the role count identifier font count and model size boundaries', function () {
  $empty = ProjectTextCatalog::fromArray(getProjectTextCatalogData());
  expect($empty->toArray())->toBe(getProjectTextCatalogData())
    ->and(fn() => $empty->getRole('missing'))->toThrow(InvalidArgumentException::class, "Unknown project text role 'missing'");
  $roles = [];
  for ($index = 0; $index < 128; $index++) { $roles['role-' . $index] = getProjectTextRoleData(); }
  expect(ProjectTextCatalog::fromArray(getProjectTextCatalogData($roles))->toArray()['roles'])->toHaveCount(128);
  $id = str_repeat('a', 128);
  foreach ([1, 256] as $size) {
    $data = getProjectTextRoleData(['fonts' => array_fill(0, 16, ['asset' => str_repeat('a', 4096), 'faceIndex' => 31]), 'fontSize' => $size]);
    $role = ProjectTextCatalog::fromArray(getProjectTextCatalogData([$id => $data]))->getRole($id);
    expect($role->fonts)->toHaveCount(16)->and($role->fontSize)->toBe((float)$size)
      ->and($role->fonts[0]->asset)->toHaveLength(4096);
  }
  expect(TextRole::fromArray(getProjectTextRoleData(['fonts' => [['asset' => 'synthetic/single.ttf', 'faceIndex' => 0]]]))->fonts)->toHaveCount(1);
});

it('rejects invalid catalog shapes versions unknown fields and excessive roles', function (mixed $data) {
  expect(fn() => ProjectTextCatalog::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(function () {
  $cases = [null, false, 1, 'catalog', new stdClass(), [], ['ichiloto.text/1', []],
    ['roles' => []], ['schema' => 'ichiloto.text/1'], ['schema' => 'ichiloto.text/2', 'roles' => []],
    ['schema' => 'ichiloto.text/1', 'roles' => null], ['schema' => 'ichiloto.text/1', 'roles' => new stdClass()],
    [...getProjectTextCatalogData(), 'machineFonts' => []],
    getProjectTextCatalogData([getProjectTextRoleData()])];
  $roles = [];
  for ($index = 0; $index < 129; $index++) { $roles['role-' . $index] = getProjectTextRoleData(); }
  $cases[] = getProjectTextCatalogData($roles);
  return nameProjectTextCases($cases);
});

it('rejects malformed stable role identifiers both at admission and lookup', function (string $id) {
  expect(fn() => ProjectTextCatalog::fromArray(getProjectTextCatalogData([$id => getProjectTextRoleData()])))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => ProjectTextCatalog::fromArray(getProjectTextCatalogData())->getRole($id))
    ->toThrow(InvalidArgumentException::class);
})->with(nameProjectTextCases(['', '0', 'Uppercase', 'a b', 'a/b', '../a', "a\n", "a\0", "a\x7F", "\xFF", str_repeat('a', 129)]));

it('requires every role style field and rejects descriptor objects implicit defaults and unknown metadata', function (mixed $role) {
  expect(fn() => ProjectTextCatalog::fromArray(getProjectTextCatalogData(['label' => $role])))
    ->toThrow(InvalidArgumentException::class);
})->with(function () {
  $base = getProjectTextRoleData();
  $cases = [null, false, 'role', new stdClass(), [], array_values($base)];
  foreach (array_keys($base) as $field) { $missing = $base; unset($missing[$field]); $cases[] = $missing; }
  foreach (['id', 'fontHash', 'width', 'height', 'locale', 'text', 'rendererHandle'] as $field) {
    $cases[] = [...$base, $field => 'not-project-style'];
  }
  return nameProjectTextCases($cases);
});

it('strictly validates ordered font descriptor shapes types and explicit face indices', function (mixed $fonts) {
  expect(fn() => TextRole::fromArray(getProjectTextRoleData(['fonts' => $fonts])))
    ->toThrow(InvalidArgumentException::class);
})->with(function () {
  $font = ['asset' => 'synthetic/font.ttf'];
  $cases = [null, false, 'font', new stdClass(), [], ['first' => $font], [1 => $font],
    [null], ['synthetic/font.ttf'], [new FontReference('synthetic/font.ttf')], [[]],
    [array_values($font)], [[...$font, 'hash' => 'no-lock']], array_fill(0, 17, $font)];
  foreach ([null, false, 1, [], new stdClass()] as $asset) { $cases[] = [['asset' => $asset]]; }
  foreach ([null, false, '1', 1.0, [], new stdClass(), -1, 32, PHP_INT_MAX] as $index) {
    $cases[] = [[...$font, 'faceIndex' => $index]];
  }
  return nameProjectTextCases($cases);
});

it('uses the shared font reference path safety and byte limits without checking existence', function (string $asset) {
  expect(fn() => TextRole::fromArray(getProjectTextRoleData(['fonts' => [['asset' => $asset]]])))
    ->toThrow(InvalidArgumentException::class);
})->with(nameProjectTextCases([
  '', '/fonts/a.ttf', '../a.ttf', 'fonts/../a.ttf', 'C:/fonts/a.ttf', 'C:\\fonts\\a.ttf',
  'https://example.test/a.ttf', 'fonts\\a.ttf', "fonts/a\0.ttf", "fonts/\xFF.ttf", str_repeat('a', 4097),
]));

it('rejects numeric coercion nonfinite values and out of bounds sizes', function (mixed $size) {
  expect(fn() => TextRole::fromArray(getProjectTextRoleData(['fontSize' => $size])))
    ->toThrow(InvalidArgumentException::class);
})->with(nameProjectTextCases([null, false, true, '12', [], new stdClass(), 0, -1, 0.999, 256.001, INF, -INF, NAN]));

it('rejects invalid foreground kinds shapes coercion unknown fields and component limits', function (mixed $color) {
  expect(fn() => TextRole::fromArray(getProjectTextRoleData(['foreground' => $color])))
    ->toThrow(InvalidArgumentException::class);
})->with(function () {
  $cases = [null, false, 'red', [], [1, 2, 3], new stdClass(), PresentationColor::rgb(1, 2, 3),
    ['kind' => 'rgba', 'r' => 1, 'g' => 2, 'b' => 3, 'a' => 1], ['kind' => 0],
    ['r' => 1, 'g' => 2, 'b' => 3]];
  foreach ([['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3], ['kind' => 'ansi16', 'index' => 1],
    ['kind' => 'ansi256', 'index' => 1]] as $color) {
    $cases[] = [...$color, 'alpha' => 1];
    foreach (array_diff(array_keys($color), ['kind']) as $field) {
      $missing = $color; unset($missing[$field]); $cases[] = $missing;
      foreach ([null, false, '1', 1.0, [], new stdClass(), -1, 256] as $value) {
        $cases[] = array_replace($color, [$field => $value]);
      }
    }
  }
  $cases[] = ['kind' => 'ansi16', 'index' => 16];
  $cases[] = ['kind' => 'ansi256', 'index' => 1, 'r' => 1];
  return nameProjectTextCases($cases);
});

it('does not invent a second glyph effects parser or accept untyped effects', function (mixed $effects) {
  expect(fn() => TextRole::fromArray(getProjectTextRoleData(['glyphEffects' => $effects])))
    ->toThrow(InvalidArgumentException::class);
})->with(nameProjectTextCases([false, true, 1, 'shadow', [], new stdClass(),
  new CanvasGlyphEffects(0, PresentationColor::ansi16(0), 0, 0, 0, 0, PresentationColor::ansi16(0))->toArray()]));

it('validates direct typed role construction through the existing text model', function () {
  foreach ([[], ['font'], ['first' => new FontReference('synthetic/font.ttf')],
    array_fill(0, 17, new FontReference('synthetic/font.ttf'))] as $fonts) {
    expect(fn() => new TextRole($fonts, 12, PresentationColor::ansi16(0)))->toThrow(InvalidArgumentException::class);
  }
  foreach ([0.0, 256.001, INF, -INF, NAN] as $size) {
    expect(fn() => new TextRole([new FontReference('synthetic/font.ttf')], $size, PresentationColor::ansi16(0)))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('loads an optional readable inside-root PHP catalog without pinning mutable font bytes or existence', function () {
  $root = createProjectTextAssetRoot();
  expect(ProjectTextCatalog::load($root))->toBeNull();
  $data = getProjectTextCatalogData(['label' => getProjectTextRoleData(['fonts' => [['asset' => 'synthetic/replaceable.ttf']]])]);
  writeProjectTextCatalog($root, $data);
  $catalog = ProjectTextCatalog::load($root);
  expect($catalog)->toBeInstanceOf(ProjectTextCatalog::class);
  $intent = $catalog->toArray();
  mkdir($root . '/synthetic', 0700);
  foreach (['not decoded here', 'different mutable bytes'] as $bytes) {
    file_put_contents($root . '/synthetic/replaceable.ttf', $bytes);
    expect(ProjectTextCatalog::load($root)->toArray())->toBe($intent)
      ->and($catalog->getRole('label')->createSpec('label', 'en')->fonts[0]->asset)->toBe('synthetic/replaceable.ttf');
  }
  unlink($root . '/synthetic/replaceable.ttf');
  expect(ProjectTextCatalog::load($root)->toArray())->toBe($intent);
});

it('loads actual typed effects authored in PHP rather than effect arrays', function () {
  $root = createProjectTextAssetRoot();
  file_put_contents($root . '/' . ProjectTextCatalog::FILE, <<<'PHP'
    <?php
    use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
    use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
    return ['schema' => 'ichiloto.text/1', 'roles' => ['label' => [
      'fonts' => [['asset' => 'synthetic/missing.ttf']], 'fontSize' => 12,
      'foreground' => ['kind' => 'ansi16', 'index' => 7],
      'glyphEffects' => new CanvasGlyphEffects(1, PresentationColor::ansi16(0), 1, 1, 0, 0.5, PresentationColor::ansi16(0)),
    ]]];
    PHP);
  $catalog = ProjectTextCatalog::load($root);
  expect($catalog->getRole('label')->glyphEffects)->toBeInstanceOf(CanvasGlyphEffects::class)
    ->and(ProjectTextCatalog::fromArray($catalog->toArray())->toArray())->toBe($catalog->toArray());
});

it('rejects nonarray PHP returns with an explicit loading error', function (string $expression) {
  $root = createProjectTextAssetRoot();
  file_put_contents($root . '/' . ProjectTextCatalog::FILE, '<?php return ' . $expression . ';');
  expect(fn() => ProjectTextCatalog::load($root))->toThrow(RuntimeException::class, 'must return a plain theme array');
})->with(['null', 'false', '1', "'theme'", 'new stdClass()']);

it('validates returned PHP arrays using the same strict schema admission', function () {
  $root = createProjectTextAssetRoot();
  writeProjectTextCatalog($root, ['schema' => 'ichiloto.text/2', 'roles' => []]);
  expect(fn() => ProjectTextCatalog::load($root))->toThrow(InvalidArgumentException::class);
});

it('rejects directory and dangling symlink catalog paths rather than treating them as optional absence', function () {
  $root = createProjectTextAssetRoot();
  $path = $root . '/' . ProjectTextCatalog::FILE;
  mkdir($path, 0700);
  expect(fn() => ProjectTextCatalog::load($root))->toThrow(RuntimeException::class, 'readable file inside assets');
  rmdir($path);
  symlink($root . '/missing.php', $path);
  expect(fn() => ProjectTextCatalog::load($root))->toThrow(RuntimeException::class, 'readable file inside assets');
});

it('rejects an unreadable catalog before executing its PHP source', function () {
  $root = createProjectTextAssetRoot();
  $path = $root . '/' . ProjectTextCatalog::FILE;
  file_put_contents($path, '<?php $GLOBALS["projectTextUnreadableExecuted"] = true; return [];');
  unset($GLOBALS['projectTextUnreadableExecuted']);
  chmod($path, 0000);
  clearstatcache(true, $path);
  try {
    expect(is_readable($path))->toBeFalse();
    expect(fn() => ProjectTextCatalog::load($root))->toThrow(RuntimeException::class, 'readable file inside assets');
    expect(array_key_exists('projectTextUnreadableExecuted', $GLOBALS))->toBeFalse();
  } finally {
    chmod($path, 0600);
  }
});

it('rejects catalog and presentation-directory symlink escapes before executing outside PHP', function (bool $directoryLink) {
  $scratch = createTestDirectory('project-text-escape-');
  $root = $scratch . '/assets';
  $outside = $scratch . '/assets-other';
  mkdir($root . '/Data', 0700, true);
  mkdir($outside, 0700);
  $outsideFile = $outside . '/text.php';
  file_put_contents($outsideFile, '<?php $GLOBALS["projectTextOutsideExecuted"] = true; return [];');
  unset($GLOBALS['projectTextOutsideExecuted']);
  if ($directoryLink) {
    symlink($outside, $root . '/Data/Presentation');
  } else {
    mkdir($root . '/Data/Presentation', 0700);
    symlink($outsideFile, $root . '/' . ProjectTextCatalog::FILE);
  }
  expect(fn() => ProjectTextCatalog::load($root))->toThrow(RuntimeException::class, 'readable file inside assets');
  expect(array_key_exists('projectTextOutsideExecuted', $GLOBALS))->toBeFalse();
})->with([false, true]);

it('permits a catalog symlink resolving inside its canonical asset root', function () {
  $root = createProjectTextAssetRoot();
  $data = getProjectTextCatalogData(['label' => getProjectTextRoleData()]);
  file_put_contents($root . '/inside.php', '<?php return ' . var_export($data, true) . ';');
  symlink($root . '/inside.php', $root . '/' . ProjectTextCatalog::FILE);
  expect(ProjectTextCatalog::load($root)->toArray())->toBe(ProjectTextCatalog::fromArray($data)->toArray());
});
