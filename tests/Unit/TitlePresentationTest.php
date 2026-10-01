<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Title\TitleOptionsSettingsManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\UI\Presentation\SaveLoadMenuPresentation;
use Ichiloto\Engine\UI\Presentation\SettingsMenuContent;
use Ichiloto\Engine\UI\Presentation\SettingsMenuPresentation;
use Ichiloto\Engine\UI\Presentation\TitleMenuPresentation;
use Ichiloto\Engine\UI\Presentation\TitlePlayback;
use Ichiloto\Engine\UI\Presentation\TitlePresentationCatalog;
use Ichiloto\Engine\UI\Presentation\TitleSpritePresentation;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

function writeTitleTestPng(string $path, int $width, int $height): void
{
  $chunk = static fn(string $type, string $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xAB\xBC\xCD\xFF", $width), $height))) . $chunk('IEND', ''));
  clearstatcache(true, $path);
}

function getTitleTestData(): array
{
  return ['schema' => 'ichiloto.title/1', 'logo' => 'logo.png',
    'theme' => ['schema' => 'ichiloto.menu/1', 'showInputHints' => false, 'cursor' => 'bird.png'],
    'scenes' => [
      'day' => ['background' => 'day.png', 'sprites' => [
        'banner' => ['asset' => 'banner.png', 'grid' => [6, 4], 'frames' => 24, 'frameSeconds' => 0.2,
          'destination' => [70, 92, 114, 368]],
        'bird' => ['asset' => 'bird.png', 'grid' => [4, 1], 'frames' => 4, 'frameSeconds' => 0.16,
          'destination' => [785, 273, 8, 8], 'travel' => [240, 0], 'period' => 38, 'visibleSeconds' => 9,
          'phaseSeconds' => 8, 'frameOffset' => 2, 'opacity' => 0.72, 'fadeFraction' => 0.125, 'reducedVisible' => false],
      ]], 'night' => ['background' => 'night.png'],
    ]];
}

function getTitleTestText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(static fn($layer) => implode('', array_map(static fn($run) =>
    $run->foreground === null ? '' : $run->text, $layer->runs)), $canvas->textLayers));
}

function advanceTitleTestClock(TitlePlayback $clock, float $start, float $end, string $local, bool $reduced = false): void
{
  for ($tick = (int)round($start * 100) + 1; $tick <= (int)round($end * 100); $tick++) {
    $clock->advance($tick / 100, new DateTimeImmutable($local), $reduced);
  }
}

final class TitlePresentationMemoryConfig extends ProjectConfig
{
  protected function load(): array { return $this->options; }
  public function persist(): void {}
}

final class TitlePresentationMenuProbe extends TitleMenu
{
  public function __construct() { $this->activeIndex = 3; }
  public function render(?int $x = null, ?int $y = null): void {}
}

final class TitlePresentationSceneProbe extends TitleScene
{
  public function __construct() { $this->menu = new TitlePresentationMenuProbe(); }
  public function syncContinueAvailability(): void {}
  public function renderHeader(): void {}
  public function getSelectedIndex(): int { return $this->menu->activeIndex; }
  public function initializePresentation(): void { $this->resetTitlePresentation(); }
  public function releasePresentation(): void { $this->stopTitlePresentation(); }
}

beforeEach(function () {
  $this->statics = [];
  foreach ([Console::class, ConfigStore::class, Debug::class] as $class) { $this->statics[$class] = new ReflectionClass($class)->getStaticProperties(); }
  Console::setTerminalOutputEnabled(false);
  ConfigStore::put(ProjectConfig::class, new TitlePresentationMemoryConfig(['audio' => ['master_volume' => 0, 'music' => false, 'sfx' => false]]));
  $this->root = sys_get_temp_dir() . '/ichiloto-title-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root]);
  foreach (['day', 'night', 'logo', 'banner', 'bird'] as $file) { writeTitleTestPng($this->root . '/' . $file . '.png', 61, 41); }
});

afterEach(function () {
  foreach ($this->statics as $class => $properties) {
    foreach ($properties as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('selects player-local day and night at exact boundaries', function (string $local, float $night) {
  $clock = new TitlePlayback();
  $clock->advance(0, new DateTimeImmutable($local), false);
  expect($clock->nightWeight)->toBe($night)->and($clock->entryOpacity)->toBe(0.0);
})->with([['2026-09-22 05:59:59+02:00', 1.0], ['2026-09-22 06:00:00+02:00', 0.0],
  ['2026-09-22 17:59:59+02:00', 0.0], ['2026-09-22 18:00:00+02:00', 1.0]]);

it('reconciles boundaries without replaying entry or catching up hidden time', function () {
  $clock = new TitlePlayback();
  $clock->advance(0, new DateTimeImmutable('2026-09-22 17:59:59.950'), false);
  advanceTitleTestClock($clock, 0, 0.05, '2026-09-22 18:00:00');
  advanceTitleTestClock($clock, 0.05, 1.05, '2026-09-22 18:00:01');
  expect($clock->nightWeight)->toBe(1.0)->and($clock->entryOpacity)->toBe(1.0);
  $elapsed = $clock->elapsed;
  $clock->setObscured(true, 1.05);
  $clock->advance(20, new DateTimeImmutable('2026-09-23 10:00:00'), false);
  expect($clock->elapsed)->toBe($elapsed)->and($clock->nightWeight)->toBe(1.0);
  $clock->setObscured(false, 30);
  $clock->advance(30, new DateTimeImmutable('2026-09-23 10:00:00'), false);
  expect($clock->elapsed)->toBe($elapsed);
  advanceTitleTestClock($clock, 30, 31, '2026-09-23 10:00:01');
  expect($clock->nightWeight)->toBeLessThan(0.00001)->and($clock->entryOpacity)->toBe(1.0);
});

it('initializes correctly after pre-entry suspension and shortens reduced-motion transitions', function () {
  $clock = new TitlePlayback();
  $clock->setObscured(true, 0);
  $clock->advance(1, new DateTimeImmutable('2026-09-22 12:00:00'), true);
  $clock->setObscured(false, 2);
  $clock->advance(2, new DateTimeImmutable('2026-09-22 18:00:00'), true);
  expect($clock->nightWeight)->toBe(1.0)->and($clock->entryOpacity)->toBe(1.0);
  advanceTitleTestClock($clock, 2, 3, '2026-09-23 12:00:00', true);
  advanceTitleTestClock($clock, 3, 3.16, '2026-09-23 12:00:00', true);
  expect($clock->nightWeight)->toBeLessThan(0.00001);
  expect(fn() => $clock->advance(0, null, true))->toThrow(InvalidArgumentException::class);
});

it('projects all five real button labels centered without any oscillating cursor', function () {
  $catalog = new TitlePresentationCatalog($this->root, getTitleTestData());
  $clock = new TitlePlayback();
  $clock->advance(0, new DateTimeImmutable('2026-09-22 12:00:00'), false);
  advanceTitleTestClock($clock, 0, 0.3, '2026-09-22 12:00:00');
  $commands = [];
  foreach (['New Game', 'Load Game', 'Options', 'Credits', 'Exit'] as $index => $label) {
    $commands[] = new MenuRow('command-' . $index, $label, kind: MenuRowKind::BUTTON,
      selected: $index === 1, focused: $index === 1, disabled: $index === 1);
  }
  $frame = TitleMenuPresentation::compose($catalog, $clock, $commands);
  foreach ($commands as $command) { expect(getTitleTestText($frame))->toContain($command->label); }
  foreach ($frame->images as $image) { expect($image->id)->not->toContain('cursor'); }
  foreach ($frame->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      if ($run->foreground === null || !in_array($run->text, array_column($commands, 'label'), true)) { continue; }
      $center = $layer->x + ($run->column + mb_strlen($run->text) / 2) * $layer->grid->cellWidth;
      expect($center)->toBe(675.0);
    }
  }
  $elapsed = $clock->elapsed;
  $again = TitleMenuPresentation::compose($catalog, $clock, $commands);
  expect($again)->toEqual($frame)->and($clock->elapsed)->toBe($elapsed);
  $destination = TitleMenuPresentation::compose($catalog, $clock, $commands, false);
  expect(array_column($destination->images, 'id'))->not->toContain('title-logo')->and($destination->textLayers)->toBe([]);
});

it('uses current artwork dimensions and correct bird phase fade and reduced-motion frame zero', function () {
  $data = getTitleTestData();
  $sprite = $data['scenes']['day']['sprites']['bird'];
  $bird = TitleSpritePresentation::getImage($this->root, 'bird', $sprite, 0, 1, false);
  expect($bird->destination->x)->toBeGreaterThan(998)->and($bird->opacity)->toBeGreaterThan(0.63)->toBeLessThan(0.65);
  expect($bird->sourceRect->x)->toBe(30);
  expect(TitleSpritePresentation::getImage($this->root, 'bird', $sprite, 2, 1, false))->toBeNull();
  expect(TitleSpritePresentation::getImage($this->root, 'bird', $sprite, 0, 1, true))->toBeNull();
  $banner = $data['scenes']['day']['sprites']['banner'];
  $original = TitleSpritePresentation::getImage($this->root, 'banner', $banner, 1, 1, true);
  expect($original->sourceRect->x)->toBe(0)->and($original->sourceRect->width)->toBe(10);
  writeTitleTestPng($this->root . '/banner.png', 73, 49);
  $replacement = TitleSpritePresentation::getImage($this->root, 'banner', $banner, 1, 1, true);
  expect($replacement->sourceRect->width)->toBe(12)->and($replacement->destination)->toEqual($original->destination);
});

it('keeps logo registration unchanged across shimmer boundaries and replacement artwork', function (int $width, int $height) {
  writeTitleTestPng($this->root . '/logo.png', $width, $height);
  $data = getTitleTestData();
  $data['gleam'] = [
    'delay' => 1, 'period' => 4, 'duration' => 0.5, 'overscan' => 100, 'bandWidth' => 96, 'tilt' => 0.2,
    'regions' => [[0, 0, 1, 1]],
    'stops' => [
      ['offset' => 0, 'color' => [255, 245, 208], 'opacity' => 0],
      ['offset' => 0.5, 'color' => [255, 252, 228], 'opacity' => 0.66],
      ['offset' => 1, 'color' => [255, 245, 208], 'opacity' => 0],
    ],
  ];
  $catalog = new TitlePresentationCatalog($this->root, $data);
  $clock = new TitlePlayback();
  $local = '2026-09-22 12:00:00';
  $clock->advance(0, new DateTimeImmutable($local), false);
  $previous = 0.0;
  $resting = null;
  foreach ([[0.99, false], [1.01, true], [1.25, true], [1.51, false],
    [4.99, false], [5.01, true], [5.25, true], [5.51, false]] as [$time, $active]) {
    advanceTitleTestClock($clock, $previous, $time, $local);
    $previous = $time;
    $frame = TitleMenuPresentation::compose($catalog, $clock, []);
    $logos = array_values(array_filter([...$frame->images, ...$frame->composites],
      static fn($image) => $image->id === 'title-logo'));
    expect($logos)->toHaveCount(1);
    $logo = $logos[0];
    $resting ??= $logo;
    expect($logo)->toBeInstanceOf($active ? CanvasComposite::class : CanvasImage::class)
      ->and($logo->destination)->toEqual($resting->destination)
      ->and($logo->layer)->toBe($resting->layer)
      ->and($logo->opacity)->toBe(1.0);
    if ($logo instanceof CanvasComposite) {
      expect($logo->width)->toBe((int)ceil($resting->destination->width))
        ->and($logo->height)->toBe((int)ceil($resting->destination->height));
    }
  }
  $clock->advance($previous + 0.01, new DateTimeImmutable($local), true);
  $reduced = TitleMenuPresentation::compose($catalog, $clock, []);
  $logo = array_values(array_filter($reduced->images, static fn($image) => $image->id === 'title-logo'))[0];
  expect($logo->destination)->toEqual($resting->destination);
})->with([[61, 41], [97, 53], [53, 97]]);

it('supports a distinct image-backed title theme without changing button geometry or label alignment', function () {
  $data = getTitleTestData();
  $data['theme']['colors'] = ['text' => [30, 40, 50], 'panel' => [230, 240, 210], 'accent' => [140, 40, 10]];
  $data['theme']['metrics'] = ['cellWidth' => 12, 'cellHeight' => 26];
  foreach (['normal', 'selected', 'disabled', 'focus'] as $role) {
    writeTitleTestPng($this->root . '/' . $role . '.png', 61, 41);
    $data['theme']['rowArtwork']['command.' . $role] = ['asset' => $role . '.png', 'cuts' => [6, 6, 6, 6]];
  }
  $catalog = new TitlePresentationCatalog($this->root, $data);
  $clock = new TitlePlayback();
  $clock->advance(0, new DateTimeImmutable('2026-09-22 12:00:00'), true);
  $commands = [];
  foreach (['New Game', 'Load Game', 'Options', 'Credits', 'Exit'] as $index => $label) {
    $commands[] = new MenuRow('command-' . $index, $label, kind: MenuRowKind::BUTTON,
      selected: $index === 2, focused: $index === 2, disabled: $index === 1);
  }
  $frame = TitleMenuPresentation::compose($catalog, $clock, $commands);
  expect(array_column($frame->images, 'asset'))->toContain('normal.png', 'selected.png', 'disabled.png', 'focus.png');
  foreach ($frame->images as $image) { expect($image->id)->not->toContain('cursor'); }
  $labels = [];
  foreach ($frame->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      if ($run->foreground === null || !in_array($run->text, array_column($commands, 'label'), true)) { continue; }
      $labels[] = $run->text;
      $center = $layer->x + ($run->column + mb_strlen($run->text) / 2) * $layer->grid->cellWidth;
      expect($center)->toBe(675.0);
      expect($run->foreground)->toEqual($catalog->theme->colors[$run->text === 'Load Game' ? 'disabled' : 'text']);
    }
  }
  expect($labels)->toBe(array_column($commands, 'label'));
  $selected = array_values(array_filter($frame->images, fn($image) => $image->asset === 'selected.png'));
  expect(min(array_map(fn($image) => $image->destination->x, $selected)))->toBe(531.0);
  expect(max(array_map(fn($image) => $image->destination->x + $image->destination->width, $selected)))->toBe(819.0);
  expect(min(array_map(fn($image) => $image->destination->y, $selected)))->toBe(498.0);
  expect(max(array_map(fn($image) => $image->destination->y + $image->destination->height, $selected)))->toBe(542.0);
});

it('admits plain replaceable catalogs and rejects invalid paths registrations and budgets', function () {
  expect(TitlePresentationCatalog::load($this->root))->toBeNull();
  mkdir($this->root . '/Data/Presentation', 0777, true);
  file_put_contents($this->root . '/' . TitlePresentationCatalog::FILE, '<?php return ' . var_export(getTitleTestData(), true) . ';');
  expect(TitlePresentationCatalog::getRequestedCapabilities($this->root))->toBe(TitlePresentationCatalog::CAPABILITIES);
  expect(TitlePresentationCatalog::load($this->root))->toBeInstanceOf(TitlePresentationCatalog::class);
  foreach ([['logo' => '../outside.png'], ['logoPlacement' => [0, 700, 532]], ['buttons' => [0, 0, 1000, 44, 8]],
    ['scenes' => ['day' => ['background' => 'day.png']]], ['schema' => 'bad']] as $changes) {
    expect(fn() => new TitlePresentationCatalog($this->root, array_replace(getTitleTestData(), $changes)))->toThrow(InvalidArgumentException::class);
  }
  $oversized = getTitleTestData();
  $oversized['scenes']['day']['sprites'] = array_fill(0, 33, $oversized['scenes']['day']['sprites']['banner']);
  expect(fn() => new TitlePresentationCatalog($this->root, $oversized))->toThrow(InvalidArgumentException::class);
  $invalid = getTitleTestData();
  $invalid['scenes']['day']['sprites']['banner']['frameSeconds'] = '0.2';
  expect(fn() => new TitlePresentationCatalog($this->root, $invalid))->toThrow(InvalidArgumentException::class);
});

it('shows all five save cards including empty incompatible and long-duration records with full-card selection', function () {
  $theme = new MenuPresentationCatalog($this->root, getTitleTestData()['theme']);
  $slots = [new SaveSlot(1, '', false, 'Happyville', 'Kaelion', 6, 360000), SaveSlot::empty(2, ''),
    SaveSlot::incompatible(3, '', 'Old format'), SaveSlot::empty(4, ''), SaveSlot::empty(5, '')];
  $frame = SaveLoadMenuPresentation::compose($slots, 2, $theme, new MenuInfoText());
  $text = getTitleTestText($frame);
  foreach (['File 1', 'File 2', 'File 3', 'File 4', 'File 5', 'Happyville', 'Kaelion Lv 6', '100:00:00', 'Old format'] as $label) {
    expect($text)->toContain($label);
  }
  $selection = array_find($frame->textLayers, fn($layer) => $layer->id === 'save-slot-3-location-selected');
  expect($selection)->not->toBeNull()->and($selection->clipRect->height)->toBe(92.0);
  expect($slots[2]->isLoadable)->toBeFalse();
});

it('wraps long save details and keeps focused records in view without truncation', function () {
  $theme = new MenuPresentationCatalog($this->root, getTitleTestData()['theme']);
  $slots = array_map(fn($i) => new SaveSlot($i, '', false, str_repeat('Faraway meadow ', 15),
    str_repeat('Companion ', 10), 99), range(1, 5));
  $frame = SaveLoadMenuPresentation::compose($slots, 4, $theme, new MenuInfoText(), 'Cannot load this file.');
  expect(getTitleTestText($frame))->toContain('File 5', 'Cannot load this file.')->not->toContain('...');
});

it('shares settings controls and scrolls every title option including Voice and Auto into view', function () {
  $manager = new TitleOptionsSettingsManager();
  $settings = $manager->getOptions();
  $theme = new MenuPresentationCatalog($this->root, getTitleTestData()['theme']);
  foreach (range(0, count($settings)) as $active) {
    $content = new SettingsMenuContent('Options', $settings, array_map($manager->getCurrentChoiceIndex(...), $settings),
      $active, new MenuInfoText(), backLabel: 'Back', backFocused: $active === count($settings), bounds: new CanvasRectangle(225, 60, 900, 600));
    $frame = SettingsMenuPresentation::compose($content, $theme);
    if (isset($settings[$active])) { expect(getTitleTestText($frame))->toContain($settings[$active]->label); }
    expect(getTitleTestText($frame))->toContain('Back')->not->toContain('Battle Message Pace');
  }
  expect($settings)->toHaveCount(9);
  expect(array_column($settings, 'key'))->toContain('voice', 'dialogue_auto', 'notification_duration');
});

it('preserves title focus through modal suspension and cancels canvas ownership on stop', function () {
  $scene = new TitlePresentationSceneProbe();
  $scene->initializePresentation();
  $scene->suspend();
  $scene->resume();
  expect($scene->getSelectedIndex())->toBe(3);
  $scene->releasePresentation();
  expect($scene->getPresentationCanvas())->toBeNull();
});


it('keeps the day scene and commands when an unused night image is missing', function () {
  $data = getTitleTestData();
  $clock = new TitlePlayback();
  $clock->advance(0, new DateTimeImmutable('2026-09-22 12:00:00'), false);
  advanceTitleTestClock($clock, 0, 0.3, '2026-09-22 12:00:00');
  $commands = [new MenuRow('new', 'New Game', kind: MenuRowKind::BUTTON, selected: true, focused: true)];
  $before = TitleMenuPresentation::compose(new TitlePresentationCatalog($this->root, $data), $clock, $commands);
  unlink($this->root . '/night.png');
  $catalog = new TitlePresentationCatalog($this->root, $data);
  expect(file_exists($this->root . '/warning.log'))->toBeFalse();
  $frame = TitleMenuPresentation::compose($catalog, $clock, $commands);
  expect($frame)->toEqual($before)
    ->and(array_column($frame->images, 'asset'))->toContain('day.png', 'logo.png', 'banner.png')
    ->and(getTitleTestText($frame))->toContain('New Game')
    ->and(file_exists($this->root . '/warning.log'))->toBeFalse();
});

it('omits only unavailable title artwork while preserving healthy siblings and commands', function (string $asset) {
  $data = getTitleTestData();
  $clock = new TitlePlayback();
  $clock->advance(0, new DateTimeImmutable('2026-09-22 12:00:00'), false);
  advanceTitleTestClock($clock, 0, 0.3, '2026-09-22 12:00:00');
  $commands = [];
  foreach (['New Game', 'Load Game', 'Options', 'Credits', 'Exit'] as $index => $label) {
    $commands[] = new MenuRow('command-' . $index, $label, kind: MenuRowKind::BUTTON,
      selected: $index === 1, focused: $index === 1, disabled: $index === 1);
  }
  $before = TitleMenuPresentation::compose(new TitlePresentationCatalog($this->root, $data), $clock, $commands);
  unlink($this->root . '/' . $asset);
  $catalog = new TitlePresentationCatalog($this->root, $data);
  expect(file_exists($this->root . '/warning.log'))->toBeFalse();
  $elapsed = $clock->elapsed;
  $frame = TitleMenuPresentation::compose($catalog, $clock, $commands);
  // Overlay layer numbers follow the highest surviving scene layer; content and relative ordering stay unchanged.
  $getTextLayers = static function (PresentationCanvas $canvas): array {
    $baseLayer = min(array_column($canvas->textLayers, 'layer'));
    return array_map(static function ($layer) use ($baseLayer): array {
      $values = get_object_vars($layer);
      $values['layer'] -= $baseLayer;
      return $values;
    }, $canvas->textLayers);
  };
  expect($frame->images)->toEqual(array_values(array_filter($before->images, static fn($image) => $image->asset !== $asset)))
    ->and($getTextLayers($frame))->toEqual($getTextLayers($before))
    ->and(min(array_column($frame->textLayers, 'layer')))->toBeGreaterThan(max(array_column($frame->images, 'layer')))
    ->and(array_column($frame->images, 'asset'))->toContain('day.png', 'bird.png')->not->toContain($asset)
    ->and($clock->elapsed)->toBe($elapsed)
    ->and(file_get_contents($this->root . '/warning.log'))->toContain($asset);
  foreach ($commands as $command) { expect(getTitleTestText($frame))->toContain($command->label); }
})->with(['logo.png', 'banner.png']);
