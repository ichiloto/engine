<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Menu\MainMenu\ConfigMenu;
use Ichiloto\Engine\Core\Menu\MainMenu\MainMenuSettingsManager;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\ConfigDetailPanel;
use Ichiloto\Engine\Core\Menu\MainMenu\Windows\ConfigSelectionWindow;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\UI\Presentation\ConfigMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowLayout;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

final class ConfigPresentationMemoryConfig extends ProjectConfig
{
  public int $writes = 0;
  public ?string $failure = null;
  protected function load(): array { return $this->options; }
  public function persist(): void
  {
    $this->writes++;
    if ($this->failure !== null) { throw new RuntimeException($this->failure); }
  }
}

function configPresentationPng(string $path, int $width, int $height): void
{
  $chunk = static fn(string $type, string $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xAD\xBE\xCF\xFF", $width), $height))) . $chunk('IEND', ''));
}

function configPresentationTheme(bool $art = false): array
{
  $data = ['schema' => 'ichiloto.menu/1', 'showInputHints' => false];
  if (!$art) { return $data; }
  $surface = ['asset' => 'surface.png', 'cuts' => [2, 2, 2, 2]];
  return [...$data, 'colors' => ['text' => [30, 40, 50], 'panel' => [230, 240, 210], 'accent' => [140, 40, 10]],
    'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 34, 'panelPadding' => 16, 'sectionGap' => 8],
    'rowMetrics' => ['padding' => 24, 'cursorWidth' => 10, 'cursorHeight' => 20, 'cursorTravel' => 3, 'cursorPeriod' => 2],
    'frames' => ['panel' => $surface, 'slider.track' => $surface, 'slider.thumb' => ['asset' => 'thumb.png'],
      'scroll.track' => $surface, 'scroll.thumb' => $surface],
    'rowArtwork' => ['selected' => $surface, 'focus' => $surface],
    'icons' => ['navigation.previous' => 'arrow.png', 'navigation.next' => 'arrow.png',
      'navigation.up' => 'arrow.png', 'navigation.down' => 'arrow.png', 'decoration.divider' => 'arrow.png']];
}

function configPresentationText(PresentationCanvas $frame): array
{
  $text = [];
  foreach ($frame->textLayers as $layer) {
    $runs = array_filter($layer->runs, fn($run) => $run->foreground !== null);
    if ($runs !== []) { $text[$layer->id] = implode('', array_column($runs, 'text')); }
  }
  return $text;
}

function configPresentationKey(ConfigMenu $menu, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $menu->update();
}

/** Text batching may combine IDs; verify the painted run and its actual coordinates. */
function getConfigTextPosition(PresentationCanvas $frame, string $text, ?float $y = null): array
{
  foreach ($frame->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      $top = $layer->y + $run->row * $layer->grid->cellHeight;
      if ($run->text === $text && $run->foreground !== null && ($y === null || abs($top - $y) <= 1)) {
        return ['x' => $layer->x + $run->column * $layer->grid->cellWidth, 'y' => $top,
          'width' => mb_strlen($text) * $layer->grid->cellWidth, 'height' => $layer->grid->cellHeight,
          'layer' => $layer->layer, 'color' => $run->foreground];
      }
    }
  }
  throw new RuntimeException('Expected visible Config text: ' . $text);
}

beforeEach(function () {
  $this->statics = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, AudioManager::class, ActionHints::class, Debug::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-config-menu-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root]);
  foreach (['surface', 'arrow', 'thumb'] as $file) { configPresentationPng($this->root . '/' . $file . '.png', 71, 39); }
  $this->config = new ConfigPresentationMemoryConfig(['audio' => ['master_volume' => 75, 'music' => false, 'sfx' => false]]);
  ConfigStore::put(ProjectConfig::class, $this->config);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]], 'cancel' => ['keys' => [KeyCode::ESCAPE]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]]]);
  $this->manager = new MainMenuSettingsManager();
  $this->backs = 0;
  $this->menu = new ConfigMenu($this->manager,
    new ConfigSelectionWindow(new Rect(0, 0, 80, 20), $this->manager),
    new ConfigDetailPanel(new Rect(0, 20, 80, 8)), function () { $this->backs++; });
  ob_start();
  $this->menu->enter();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->statics as $class => $values) {
    foreach ($values as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  foreach (glob($this->root . '/*') as $path) { unlink($path); }
  rmdir($this->root);
});

it('projects every live setting value and full description through both themes at finite viewport sizes', function (bool $art, int $width, int $height) {
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme($art));
  $settings = $this->menu->selection->getSettings();
  expect($settings)->toHaveCount(11);
  foreach ($settings as $index => $setting) {
    $frame = ConfigMenuPresentation::compose($this->menu, $theme, width: $width, height: $height);
    $text = configPresentationText($frame);
    $layers = array_column($frame->textLayers, null, 'id');
    $label = getConfigTextPosition($frame, $setting->label);
    $value = getConfigTextPosition($frame, $this->manager->getCurrentChoiceLabel($setting), $label['y']);
    $page = $this->menu->menuInfoText->lastPage;
    $description = getConfigTextPosition($frame, $page->lines[0]);
    foreach ($page->lines as $line) { getConfigTextPosition($frame, $line); }
    expect(implode('', $page->lines))->toBe($setting->description);
    $cancel = getConfigTextPosition($frame, 'Cancel');
    expect($this->menu->menuInfoText->lastPage->source)->toBe($setting->description)
      ->and(count($frame->textLayers))->toBeLessThanOrEqual(64)
      ->and(implode(' ', array_keys($layers)))->not->toContain('config-hints', 'cancel-cursor', 'cancel-separator');
    $ids = [...array_column($frame->textLayers, 'id'), ...array_column($frame->images, 'id')];
    expect(count($ids))->toBe(count(array_unique($ids)));
    foreach ($frame->images as $image) { $image->destination->assertWithin($width, $height); }
    $host = \Ichiloto\Engine\UI\Presentation\MenuLayout::getBounds($width, $height);
    $buttonWidth = \Ichiloto\Engine\UI\Presentation\MenuLayout::getButtonWidth($theme, 'Cancel', $host->width - 2 * $theme->metrics->panelPadding);
    expect($cancel['x'] + $cancel['width'] / 2)->toBe($host->x + $host->width - $theme->metrics->panelPadding - $buttonWidth / 2);
    $treatments = array_filter([...$frame->textLayers, ...$frame->images],
      fn($piece) => str_contains($piece->id, 'config-setting-' . $index . '-selected'));
    foreach ($treatments as $piece) { expect($value['layer'])->toBeGreaterThan($piece->layer); }
    expect($value['y'] + $value['height'])->toBeLessThanOrEqual($description['y']);
    $this->menu->selection->selectNext();
  }
  expect($this->config->writes)->toBe(0);
})->with([false, true])->with([[1350, 720], [960, 540], [736, 414]]);

it('keeps adjustment clamping wrapping persistence and semantic cancel in the existing controller', function () {
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme());
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::X]], 'cancel' => ['keys' => [KeyCode::Q]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]],
    'left' => ['keys' => [KeyCode::LEFT]], 'right' => ['keys' => [KeyCode::RIGHT]]]);
  $this->config->set('audio.master_volume', 100);
  configPresentationKey($this->menu, KeyCode::X);
  expect($this->config->get('audio.master_volume'))->toBe(100)->and($this->config->writes)->toBe(1);
  $this->config->set('audio.master_volume', 0);
  configPresentationKey($this->menu, KeyCode::LEFT);
  expect($this->config->get('audio.master_volume'))->toBe(0);
  configPresentationKey($this->menu, KeyCode::RIGHT);
  expect($this->config->get('audio.master_volume'))->toBe(5)
    ->and(getConfigTextPosition(ConfigMenuPresentation::compose($this->menu, $theme), 'Volume set to 5%.')['color'])
    ->toBe($theme->colors['disabled']);
  configPresentationKey($this->menu, KeyCode::DOWN);
  expect($this->menu->getStatusMessage())->toBeNull();
  configPresentationKey($this->menu, KeyCode::LEFT);
  expect($this->config->get('audio.music'))->toBeTrue();
  configPresentationKey($this->menu, KeyCode::X);
  expect($this->config->get('audio.music'))->toBeFalse();
  configPresentationKey($this->menu, KeyCode::Q);
  expect($this->backs)->toBe(1)->and($this->config->writes)->toBe(5);
});

it('pages the entire live persistence failure without resizing or inventing rollback', function () {
  $this->config->failure = str_repeat('A complete persistence detail. ', 6);
  configPresentationKey($this->menu, KeyCode::RIGHT);
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme());
  foreach ([$this->config->failure, str_repeat('Long full error ', 150)] as $failure) {
    if ($failure !== $this->config->failure) {
      $this->config->failure = $failure;
      configPresentationKey($this->menu, KeyCode::RIGHT);
    }
    $seen = '';
    $footer = null;
    do {
      $frame = ConfigMenuPresentation::compose($this->menu, $theme);
      $page = $this->menu->menuInfoText->lastPage;
      $cancel = getConfigTextPosition($frame, 'Cancel');
      $footer ??= $cancel;
      expect($cancel)->toEqual($footer)->and($page->rows)->toBe(2);
      foreach ($page->lines as $line) {
        $position = getConfigTextPosition($frame, $line);
        expect($position['y'] + $position['height'])->toBeLessThanOrEqual($cancel['y']);
        if ($line !== $this->menu->selection->getActiveSetting()->description) {
          expect($position['color'])->toBe($theme->colors['decrease']);
        }
        $seen .= $line;
      }
      $this->menu->menuInfoText->advance();
    } while ($page->nextOffset !== 0);
    expect($seen)->toBe($this->menu->selection->getActiveSetting()->description . 'Could not save settings: ' . $failure);
  }
  expect($this->config->get('audio.master_volume'))->toBe(85)->and($this->menu->hasStatusError())->toBeTrue();
});

it('wraps long live labels values and descriptions without cropping any selected record', function () {
  $setting = new GameSetting('music', str_repeat('Long setting label ', 4), str_repeat('Full description ', 12),
    [str_repeat('Long current choice ', 4) => false, 'Other' => true]);
  $this->menu->selection->setSettings([$setting]);
  $frame = ConfigMenuPresentation::compose($this->menu, new MenuPresentationCatalog($this->root, configPresentationTheme()));
  $text = configPresentationText($frame);
  expect($text['config-setting-0-text'])->toBe($setting->label)
    ->and($text['config-value-0'])->toBe(array_key_first($setting->choices))
    ->and($text['config-description'])->toBe($setting->description);
  $layers = array_column($frame->textLayers, null, 'id');
  expect($layers['config-setting-0-text']->clipRect->height)->toBeGreaterThan(40)
    ->and($layers['config-value-0']->bounds->y + $layers['config-value-0']->bounds->height)
    ->toBeLessThanOrEqual($layers['config-setting-0-text']->clipRect->y + $layers['config-setting-0-text']->clipRect->height);
});

it('loads the four optional surfaces and exact control roles using current replaceable image facts', function () {
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme(true));
  $first = ConfigMenuPresentation::compose($this->menu, $theme, width: 960, height: 540);
  $thumb = array_values(array_filter($first->images, fn($image) => $image->id === 'config-level-0-thumb-1-1'))[0];
  expect($thumb->destination->width / $thumb->destination->height)->toEqualWithDelta(71 / 39, 0.0001);
  configPresentationPng($this->root . '/thumb.png', 13, 83);
  $frame = ConfigMenuPresentation::compose($this->menu, $theme, width: 960, height: 540);
  $thumb = array_values(array_filter($frame->images, fn($image) => $image->id === 'config-level-0-thumb-1-1'))[0];
  expect($thumb->destination->width / $thumb->destination->height)->toEqualWithDelta(13 / 83, 0.0001)
    ->and($thumb->destination->height)->toBeLessThanOrEqual($theme->metrics->cellHeight);
  foreach (['config-level-0-track', 'config-scroll-track', 'config-scroll-thumb'] as $id) {
    expect(array_filter($frame->images, fn($image) => str_starts_with($image->id, $id)))->not->toBeEmpty();
  }
  $sliced = configPresentationTheme(true);
  $sliced['frames']['slider.thumb']['cuts'] = [2, 2, 2, 2];
  $frame = ConfigMenuPresentation::compose($this->menu, new MenuPresentationCatalog($this->root, $sliced));
  expect(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'config-level-0-thumb-')))->toHaveCount(9);
  $data = configPresentationTheme(true);
  $data['frames']['slider.thumb'] = ['asset' => 'absent.png'];
  $theme = new MenuPresentationCatalog($this->root, $data);
  expect(file_exists($this->root . '/warning.log'))->toBeFalse();
  $writes = $this->config->writes;
  $frame = ConfigMenuPresentation::compose($this->menu, $theme, width: 960, height: 540);
  expect(array_column($frame->textLayers, 'id'))->toContain('config-level-0-thumb')
    ->and(array_column($frame->images, 'asset'))->toContain('surface.png', 'arrow.png')->not->toContain('absent.png')
    ->and(configPresentationText($frame)['config-setting-0-text'])->not->toBe('')
    ->and($this->config->writes)->toBe($writes)
    ->and(file_get_contents($this->root . '/warning.log'))->toContain('absent.png');
});

it('uses chevrons for controllable values without borrowing comparison or unknown art', function () {
  $data = configPresentationTheme();
  $data['icons'] = ['unknown' => 'arrow.png', 'comparison.previous' => 'arrow.png', 'comparison.next' => 'arrow.png'];
  $frame = ConfigMenuPresentation::compose($this->menu, new MenuPresentationCatalog($this->root, $data));
  expect($frame->images)->toBeEmpty()
    ->and(configPresentationText($frame)['config-previous-0'])->toBe("\u{2039}")
    ->and(configPresentationText($frame)['config-next-0'])->toBe("\u{203A}");
  $small = ConfigMenuPresentation::compose($this->menu, new MenuPresentationCatalog($this->root, $data), width: 960, height: 540);
  expect(configPresentationText($small)['config-scroll-up'])->toBe("\u{2227}")
    ->and(configPresentationText($small)['config-scroll-down'])->toBe("\u{2228}");
});

it('keeps slider fill inside the track and centers the independent thumb at every limit', function (float $ratio, bool $art) {
  $data = configPresentationTheme($art);
  $data['metrics'] = [...($data['metrics'] ?? []), 'sliderTrackHeight' => 10, 'sliderFillHeight' => 2, 'sliderThumbSize' => 22];
  if ($art) { configPresentationPng($this->root . '/thumb.png', 24, 24); }
  $theme = new MenuPresentationCatalog($this->root, $data);
  $controls = new \Ichiloto\Engine\UI\Presentation\MenuControls($theme);
  $box = new CanvasRectangle(50, 50, 240, 30);
  $controls->renderLevel('level', $box, $ratio, $theme->colors['increase']);
  $frame = $controls->finish(400, 200);
  $text = array_column($frame->textLayers, null, 'id');
  $track = new CanvasRectangle(61, 60, 218, 10);
  if ($art) {
    $images = array_column($frame->images, null, 'id');
    $thumb = $images['level-thumb-1-1']->destination;
    foreach (array_filter($frame->images, fn($image) => str_starts_with($image->id, 'level-track-')) as $image) {
      expect($image->clipRect)->toEqual($track);
    }
  } else {
    $thumb = $text['level-thumb']->clipRect;
    expect($text['level-track']->clipRect)->toEqual($track);
  }
  expect($thumb)->toEqual(new CanvasRectangle(50 + 218 * $ratio, 54, 22, 22));
  if ($ratio === 0.0) { expect($text)->not->toHaveKey('level-fill'); }
  else {
    $fill = $text['level-fill']->clipRect;
    expect($fill)->toEqual(new CanvasRectangle(61, 64, 218 * $ratio, 2))
      ->and($fill->x + $fill->width)->toEqualWithDelta($thumb->x + $thumb->width / 2, 0.0001);
    $thumbLayer = $art ? $images['level-thumb-1-1']->layer : $text['level-thumb']->layer;
    expect($thumbLayer)->toBeGreaterThan($text['level-fill']->layer);
  }
  $frame->toArray();
})->with([0.0, 0.5, 1.0])->with([false, true]);

it('rejects invalid slider geometry without changing settings', function () {
  foreach ([['sliderFillHeight' => 0], ['sliderTrackHeight' => 1], ['sliderThumbSize' => 7], ['sliderThumbSize' => 65]] as $metrics) {
    expect(fn() => new MenuPresentationCatalog($this->root, [...configPresentationTheme(), 'metrics' => $metrics]))
      ->toThrow(InvalidArgumentException::class);
  }
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme());
  foreach ([-0.1, 1.1, NAN, INF] as $ratio) {
    $controls = new \Ichiloto\Engine\UI\Presentation\MenuControls($theme);
    expect(fn() => $controls->renderLevel('level', new CanvasRectangle(0, 0, 100, 24), $ratio, $theme->colors['accent']))
      ->toThrow(InvalidArgumentException::class);
  }
  expect($this->config->writes)->toBe(0);
});

it('shows remapped optional hints without changing current actions or adding a button cursor', function () {
  $data = configPresentationTheme();
  $data['showInputHints'] = true;
  $theme = new MenuPresentationCatalog($this->root, $data);
  InputManager::setBinding('cancel', [KeyCode::Q]);
  $frame = ConfigMenuPresentation::compose($this->menu, $theme);
  expect(implode(' ', configPresentationText($frame)))->toContain('Q', 'Cancel', 'Enter', 'Next');
  expect(array_filter([...$frame->textLayers, ...$frame->images], fn($piece) => str_contains($piece->id, 'cancel-cursor')))->toBeEmpty();
  expect($this->config->writes)->toBe(0);
});

it('handles empty and extended catalogs without fake setting records', function () {
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme());
  $this->menu->selection->setSettings([]);
  $text = configPresentationText(ConfigMenuPresentation::compose($this->menu, $theme));
  expect($text['config-settings-range'])->toBe('0 / 0')->and($text['config-description'])->toBe('No settings available.')
    ->and(array_filter(array_keys($text), fn($id) => str_starts_with($id, 'config-setting-')))->toBeEmpty();
  $entries = [];
  for ($i = 0; $i < 80; $i++) { $entries[] = new GameSetting('music', 'Authored ' . $i, 'Live ' . $i, ['Off' => false, 'On' => true]); }
  $this->menu->selection->setSettings($entries);
  for ($i = 0; $i < 79; $i++) { $this->menu->selection->selectNext(); }
  $text = configPresentationText(ConfigMenuPresentation::compose($this->menu, $theme));
  expect($text['config-setting-79-text'])->toBe('Authored 79')->and($text['config-description'])->toBe('Live 79')
    ->and($text['config-settings-range'])->toEndWith('80 / 80');
});

it('keeps default range and rows output unchanged while allowing a caller owned range rectangle', function () {
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme());
  $viewport = new CanvasRectangle(20, 80, 300, 104);
  $header = new CanvasRectangle(320, 20, 300, 24);
  $default = new MenuCanvas($theme);
  $explicit = new MenuCanvas($theme);
  $custom = new MenuCanvas($theme);
  expect($default->visibleRange('test', [40, 40, 40, 40], $viewport, 2))->toBe([1, 2])
    ->and($explicit->visibleRange('test', [40, 40, 40, 40], $viewport, 2, null))->toBe([1, 2])
    ->and($custom->visibleRange('test', [40, 40, 40, 40], $viewport, 2, $header))->toBe([1, 2])
    ->and($default->finish()->toArray())->toBe($explicit->finish()->toArray());
  $layer = array_column($custom->finish()->textLayers, null, 'id')['test-range'];
  expect($layer->clipRect)->toBe($header)->and($layer->runs[0]->text)->toBe('2-3 / 4')
    ->and($layer->runs[0]->column)->toBe(23);
  $rows = new MenuCanvas($theme);
  $rows->rows('test', array_map(fn($i) => new MenuRow((string)$i, 'Entry ' . $i), range(0, 3)), new MenuRowLayout($viewport), 2);
  $range = array_column($rows->finish()->textLayers, null, 'id')['test-range'];
  expect($range)->toEqual(array_column($default->finish()->textLayers, null, 'id')['test-range'])
    ->and($range->clipRect)->toEqual(new CanvasRectangle(20, 160, 300, 24));
});

it('serializes nondivisible and prime canvas fills with exact disjoint clipped coverage', function (int $width, int $height) {
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme());
  $view = new MenuCanvas($theme, $width, $height);
  $panel = new CanvasRectangle(23.5, 19.5, $width - 33, $height - 25);
  $view->surface('fractional-panel', $panel, 'panel', 9);
  $frame = $view->finish();
  $wire = json_decode(json_encode($frame->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
  expect($wire['width'])->toBe($width)->and($wire['height'])->toBe($height)
    ->and(count($wire['textLayers']))->toBeLessThanOrEqual(8);
  foreach ($frame->textLayers as $layer) {
    $layer->bounds->assertWithin($width, $height);
    $layer->paintBounds->assertWithin($width, $height);
  }
  foreach (['menu-background' => new CanvasRectangle(0, 0, $width, $height), 'fractional-panel' => $panel] as $id => $expected) {
    $pieces = array_values(array_filter($frame->textLayers, fn($layer) => str_starts_with($layer->id, $id)));
    expect(count($pieces))->toBeLessThanOrEqual(4)
      ->and(array_sum(array_map(fn($piece) => $piece->clipRect->width * $piece->clipRect->height, $pieces)))
      ->toBe($expected->width * $expected->height);
    foreach ($pieces as $i => $piece) {
      $a = $piece->clipRect;
      expect($a->x)->toBeGreaterThanOrEqual($expected->x)->and($a->y)->toBeGreaterThanOrEqual($expected->y)
        ->and($a->x + $a->width)->toBeLessThanOrEqual($expected->x + $expected->width)
        ->and($a->y + $a->height)->toBeLessThanOrEqual($expected->y + $expected->height);
      foreach (array_slice($pieces, $i + 1) as $other) {
        $b = $other->clipRect;
        expect(max(0, min($a->x + $a->width, $b->x + $b->width) - max($a->x, $b->x))
          * max(0, min($a->y + $a->height, $b->y + $b->height) - max($a->y, $b->y)))->toEqual(0.0);
      }
    }
    foreach ([-0.001, 0.0, 0.25, 0.5, 0.75, 0.99999, 1.001] as $fx) {
      foreach ([-0.001, 0.0, 0.25, 0.5, 0.75, 0.99999, 1.001] as $fy) {
        $x = $expected->x + $expected->width * $fx;
        $y = $expected->y + $expected->height * $fy;
        $hits = 0;
        foreach ($pieces as $layer) {
          $clip = $layer->clipRect;
          if ($x < $clip->x || $x >= $clip->x + $clip->width || $y < $clip->y || $y >= $clip->y + $clip->height) { continue; }
          $column = (int)floor(($x - $layer->x) / $layer->grid->cellWidth);
          $row = (int)floor(($y - $layer->y) / $layer->grid->cellHeight);
          expect($layer->runs[$row]->text[$column])->toBe(' ')
            ->and($layer->runs[$row]->background)->toBe($theme->colors[$id === 'menu-background' ? 'background' : 'panel']);
          $hits++;
        }
        expect($hits)->toBe($fx >= 0 && $fx < 1 && $fy >= 0 && $fy < 1 ? 1 : 0);
      }
    }
  }
  $overlay = MenuCanvas::overlay(new PresentationCanvas($width, $height), $frame, $theme);
  expect(array_filter($overlay->textLayers, fn($layer) => str_starts_with($layer->id, 'menu-background')))->toBeEmpty();
})->with([[736, 414], [503, 307]]);

it('preserves the valid standard canvas fill wire exactly', function () {
  $theme = new MenuPresentationCatalog($this->root, configPresentationTheme());
  $frame = new MenuCanvas($theme)->finish();
  $expected = new \Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer('menu-background', 0, 0, 0,
    new \Ichiloto\Engine\Rendering\Transport\RendererGridConfig(6, 3, 225, 240),
    array_map(fn($row) => new \Ichiloto\Engine\Rendering\Presentation\PresentationTextRun($row, 0, '      ',
      background: $theme->colors['background']), range(0, 2)), new CanvasRectangle(0, 0, 1350, 720));
  expect($frame->toArray())->toBe(new PresentationCanvas(1350, 720, textLayers: [$expected])->toArray());
});
