<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureFallback;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;

/** Composes only the local panel over the unchanged, real paused frame. */
final class GraphicalBattlePause
{
  private array $images = [];
  private array $text = [];
  private float $x;
  private float $y;
  private float $scale;
  private float $opacity;

  private function __construct(private BattlePauseSkin $skin, BattlePauseMenu $menu,
    int $canvasWidth, int $canvasHeight, private int $width, private int $height, private ?string $assetRoot)
  {
    $this->scale = $menu->scale();
    $this->opacity = $menu->opacity();
    $this->x = ($canvasWidth - $width * $this->scale) / 2;
    $this->y = ($canvasHeight - $height * $this->scale) / 2;
  }

  /** @param list<CanvasImage> $battlefield */
  public static function preflight(BattlePauseSkin $skin, string $root, array $battlefield = []): void
  {
    CanvasImagePreflight::inspect([...$battlefield, ...CanvasImagePreflight::textures(
      CanvasTextureFallback::getAvailableTextures(array_values($skin->textures), $root))], $root);
    foreach (['panel' => [400, 320], 'normal' => [336, 40], 'selected' => [336, 40],
      'pressed' => [336, 40], 'focus' => [336, 40], 'selector' => [16, 16], 'divider' => [72, 12]] as $role => [$w, $h]) {
      $skin->textures[$role]->images('pause-preflight', new CanvasRectangle(0, 0, $w, $h), 0);
    }
  }

  public static function frame(PresentationCanvas $battlefield, BattlePauseSkin $skin, BattlePauseMenu $menu,
    ?string $assetRoot = null): PresentationCanvas
  {
    if ($menu->isClosed()) { return $battlefield; }
    $confirm = $menu->confirmation !== null;
    $width = $confirm ? 520 : 400;
    $height = $confirm ? 276 : 320;
    if ($battlefield->width < $width || $battlefield->height < $height) {
      throw new InvalidArgumentException('Pause panel exceeds the logical canvas; use the existing minimum-window/uniform-fit policy.');
    }
    $view = new self($skin, $menu, $battlefield->width, $battlefield->height, $width, $height, $assetRoot);
    $view->fill('backing', 0, 0, $width, $height, 'ink', 10000);
    $view->renderImage('panel', 'panel', 0, 0, $width, $height);
    $view->line('heading', $menu->heading(), 0, $confirm ? 28 : 24, $width, 14, 36);
    $dividerY = $confirm ? 75 : 66;
    $view->renderImage('divider', 'divider', ($width - 72) / 2, $dividerY, 72, 12);
    $view->fill('rule-left', 32, $dividerY + 6, ($width - 72) / 2 - 40, 1, 'accent', 10001);
    $view->fill('rule-right', ($width + 72) / 2 + 8, $dividerY + 6, ($width - 72) / 2 - 40, 1, 'accent', 10001);
    if ($confirm) { $view->line('warning', BattlePauseMenu::WARNING, 32, 104, $width - 64, 10, 28); }
    foreach ($menu->labels() as $index => $label) {
      $rowWidth = $confirm ? ($width - 76) / 2 : $width - 64;
      $x = 32 + ($confirm ? $index * ($rowWidth + 12) : 0);
      $y = $confirm ? 164 : 86 + $index * 44;
      $rowHeight = $confirm ? 44 : 40;
      $selected = $menu->selection === $index;
      $view->renderImage('row-' . $index, $selected ? ($menu->isPressed() ? 'pressed' : 'selected') : 'normal', $x, $y, $rowWidth, $rowHeight);
      if ($selected) {
        $view->renderImage('focus', 'focus', $x, $y, $rowWidth, $rowHeight);
        if (!$confirm) {
          $offset = GraphicalBattleHud::cursorOffset($menu->time(), $menu->reducedMotion || !$menu->isReady());
          $view->renderImage('cursor', 'selector', $x + 17 + $offset, $y + ($rowHeight - 16) / 2, 16, 16);
        }
      }
      $view->line('label-' . $index, $label, $x, $y + ($rowHeight - 28) / 2, $rowWidth, 10, 28);
    }
    return new PresentationCanvas($battlefield->width, $battlefield->height,
      [...$battlefield->images, ...$view->images], $battlefield->indicators, [...$battlefield->textLayers, ...$view->text]);
  }

  private function renderImage(string $id, string $role, float $x, float $y, float $width, float $height): void
  {
    $texture = $this->skin->textures[$role];
    $images = $texture->images('pause-' . $id, new CanvasRectangle($x, $y, $width, $height), 10002);
    if (!CanvasTextureFallback::isAvailable($texture, $this->assetRoot)) {
      // Focus is an outline treatment; a solid overlay would obscure a healthy button.
      if ($role === 'focus') {
        $this->fill($id . '-fallback', $x, $y + $height - 2, $width, 2, 'accent', 10002);
      } else {
        $color = in_array($role, ['selector', 'divider'], true) ? 'accent' : 'ink';
        $layer = $role === 'panel' ? 10000 : (in_array($role, ['normal', 'selected', 'pressed'], true) ? 10001 : 10002);
        $this->fill($id . '-fallback', $x, $y, $width, $height, $color, $layer);
      }
      return;
    }
    foreach ($images as $image) {
      $rect = $image->destination;
      $this->images[] = new CanvasImage($image->id, $image->asset, $this->rect($rect->x, $rect->y, $rect->width, $rect->height),
        $image->layer, $image->sourceRect, $this->opacity);
    }
  }

  private function line(string $id, string $label, float $x, float $y, float $width, int $cellWidth, int $cellHeight, string $color = 'text'): void
  {
    $length = mb_strlen($label, 'UTF-8');
    $cw = max(1, (int)round($cellWidth * $this->scale));
    $ch = max(1, (int)round($cellHeight * $this->scale));
    if ($length * $cw > $width * $this->scale - 32) { throw new InvalidArgumentException('Pause label needs a wider panel.'); }
    $this->text[] = new CanvasTextLayer('pause-' . $id, 10003,
      $this->x + ($x + $width / 2) * $this->scale - $length * $cw / 2, $this->y + $y * $this->scale,
      new RendererGridConfig($length, 1, $cw, $ch), [new PresentationTextRun(0, 0, $label, $this->skin->colors[$color])], opacity: $this->opacity);
  }

  private function fill(string $id, float $x, float $y, float $width, float $height, string $color, int $layer): void
  {
    $rect = $this->rect($x, $y, $width, $height);
    $this->text[] = CanvasTextureFallback::createFill('pause-' . $id, $rect, $layer, $this->skin->colors[$color], $this->opacity);
  }

  private function rect(float $x, float $y, float $width, float $height): CanvasRectangle
  {
    return new CanvasRectangle($this->x + $x * $this->scale, $this->y + $y * $this->scale, $width * $this->scale, $height * $this->scale);
  }
}
