<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuModalPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\SaveLoadMenuPresentation;
use Ichiloto\Engine\UI\Text\MenuInfoText;

/** The fixture has an opaque interior and chamfered alpha, without a GD dependency. */
function writeChamferedPanelFixture(string $path, int $width, int $height, array $color): void
{
  $pixels = '';
  for ($y = 0; $y < $height; $y++) {
    $pixels .= "\0";
    for ($x = 0; $x < $width; $x++) {
      $alpha = min($x, $width - 1 - $x) + min($y, $height - 1 - $y) < 6 ? 0 : 255;
      $pixels .= pack('C4', ...[...$color, $alpha]);
    }
  }
  $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data
    . pack('N', crc32($type . $data));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress($pixels)) . $chunk('IEND', ''));
}

function containsPanelTestPoint(CanvasRectangle $box, float $x, float $y): bool
{
  return $x >= $box->x && $x < $box->x + $box->width && $y >= $box->y && $y < $box->y + $box->height;
}

/** Assert emitted paint leaves all four source-alpha cutouts exposed, including selected records. */
function assertPanelCutouts(PresentationCanvas $canvas, string $id, int $sourceWidth, int $sourceHeight): void
{
  $pieces = array_column($canvas->images, null, 'id');
  foreach (['0-0', '0-2', '2-0', '2-2'] as $cell) {
    $corner = $pieces[$id . '-' . $cell];
    $right = str_ends_with($cell, '2');
    $bottom = str_starts_with($cell, '2');
    foreach ([[0.5, 0.5], [2.5, 1.5]] as [$dx, $dy]) {
      $x = $corner->destination->x + ($right ? $corner->destination->width - $dx : $dx);
      $y = $corner->destination->y + ($bottom ? $corner->destination->height - $dy : $dy);
      $source = $corner->sourceRect;
      $sx = (int)floor($source->x + ($x - $corner->destination->x) * $source->width / $corner->destination->width);
      $sy = (int)floor($source->y + ($y - $corner->destination->y) * $source->height / $corner->destination->height);
      expect(min($sx, $sourceWidth - 1 - $sx) + min($sy, $sourceHeight - 1 - $sy))->toBeLessThan(6);
      foreach ($canvas->textLayers as $layer) {
        if ($layer->id === 'scene' || !containsPanelTestPoint($layer->clipRect ?? $layer->bounds, $x, $y)) { continue; }
        foreach ($layer->runs as $run) {
          if ($run->background === null) { continue; }
          $paint = new CanvasRectangle($layer->x + $run->column * $layer->grid->cellWidth,
            $layer->y + $run->row * $layer->grid->cellHeight,
            max(1, mb_strlen($run->text)) * $layer->grid->cellWidth, $layer->grid->cellHeight);
          expect(containsPanelTestPoint($paint, $x, $y), $id . ' cutout covered by ' . $layer->id)->toBeFalse();
        }
      }
    }
  }
  // The center comes from opaque source pixels, so underlying scene content stays covered.
  $center = $pieces[$id . '-1-1'];
  expect($center->sourceRect->x)->toBe(8)->and($center->sourceRect->y)->toBe(8)
    ->and($center->sourceRect->width)->toBe($sourceWidth - 16)
    ->and($center->sourceRect->height)->toBe($sourceHeight - 16)
    ->and($center->opacity)->toBe(1.0);
}

beforeEach(function () {
  $this->panelRoot = sys_get_temp_dir() . '/ichiloto-panel-alpha-' . bin2hex(random_bytes(5));
  mkdir($this->panelRoot);
});

afterEach(function () {
  foreach (glob($this->panelRoot . '/*') as $file) { unlink($file); }
  rmdir($this->panelRoot);
});

it('preserves authored panel cutouts across frames save cards Info and stacked dialogs', function (array $color) {
  writeChamferedPanelFixture($this->panelRoot . '/frame.png', 24, 32, $color);
  $frameArt = ['asset' => 'frame.png', 'cuts' => [8, 8, 8, 8], 'borderWidths' => [8, 8, 8, 8]];
  $theme = new MenuPresentationCatalog($this->panelRoot, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'colors' => ['panel' => $color], 'frames' => ['panel' => $frameArt, 'quiet' => $frameArt]]);
  $scene = new MenuCanvas($theme);
  $scene->surface('scene', new CanvasRectangle(0, 0, 1350, 720), 'accent', 1);
  $base = new PresentationCanvas(1350, 720, textLayers: array_values(array_filter($scene->finish()->textLayers,
    fn($layer) => $layer->id === 'scene')));
  foreach ([[24, 32], [51, 43]] as [$width, $height]) {
    writeChamferedPanelFixture($this->panelRoot . '/frame.png', $width, $height, $color);
    foreach (['panel', 'quiet'] as $role) {
      $view = new MenuCanvas($theme);
      $view->frame('panel-test', new CanvasRectangle(100.5, 120.5, 450, 160), $role);
      $canvas = MenuCanvas::overlay($base, $view->finish(), $theme);
      assertPanelCutouts($canvas, 'panel-test', $width, $height);
    }
    $saves = SaveLoadMenuPresentation::compose([
      new SaveSlot(1, '', false, 'Field Post', 'Actor', 8, 360),
      SaveSlot::empty(2, ''),
    ], 0, $theme, new MenuInfoText());
    $saves = MenuCanvas::overlay($base, $saves, $theme);
    foreach (['save-prompt', 'save-slot-1-frame', 'save-slot-2-frame', 'save-info'] as $id) {
      assertPanelCutouts($saves, $id, $width, $height);
    }
    $dialog = MenuModalPresentation::compose($saves,
      new ModalPresentation('Confirm', 'Continue?', ['OK', 'Cancel'], 0), $theme);
    assertPanelCutouts($dialog, 'menu-modal-frame', $width, $height);
  }
})->with([[[16, 29, 49]], [[230, 220, 200]]]);

it('retains the opaque rectangular fallback when no frame artwork is supplied', function () {
  $theme = new MenuPresentationCatalog($this->panelRoot, ['schema' => 'ichiloto.menu/1']);
  $view = new MenuCanvas($theme);
  $box = new CanvasRectangle(10, 20, 300, 140);
  $view->frame('unskinned-panel', $box);
  $canvas = $view->finish();
  $fill = array_find($canvas->textLayers, fn($layer) => $layer->id === 'unskinned-panel');
  expect($canvas->images)->toBeEmpty()->and($fill->clipRect)->toEqual($box)->and($fill->opacity)->toBe(1.0);
  foreach ($fill->runs as $run) { expect($run->background)->toEqual($theme->colors['panel']); }
});
