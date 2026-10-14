<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\UI\Presentation\MenuActionHints;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuTextLayout;
use Ichiloto\Engine\UI\Presentation\MenuTextWrap;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/** Persistent field affordance. Availability and playback remain with SkitManager. */
final class SkitPromptPresentation
{
  public const string LAYER = 'field-skit-prompt';
  private ?MenuPresentationCatalog $theme = null;
  private ?string $themeRoot = null;
  private array $diagnostics = [];

  public function renderTerminal(?SkitPrompt $prompt, ActionHint $hint): void
  {
    if ($prompt === null) { Console::removeOverlay(self::LAYER); return; }
    $width = min(44, Console::getWidth() - 2);
    if ($width < 1) { return; }
    $lines = MenuTextWrap::lines($prompt->title, $width);
    array_push($lines, ...MenuTextWrap::lines(($hint->control?->label ?? 'Unbound') . ': ' . $hint->label, $width));
    $lines = array_map(static fn($line) => TerminalText::padLeft($line, $width), $lines);
    Console::replaceOverlay(self::LAYER, $lines, Console::getWidth() - $width - 1,
      max(0, Console::getHeight() - count($lines) - 1), PresentationLayerPolicy::UI);
  }

  public function getCanvas(SkitPrompt $prompt, ActionHint $hint, RendererRuntime $runtime,
    int $width, int $height): ?PresentationCanvas
  {
    if (!array_all([...MenuPresentationCatalog::CAPABILITIES, RendererSessionConfig::CANVAS_OVERLAY], $runtime->supports(...))) {
      return null;
    }
    try {
      $root = $runtime->getAssetRoot();
      if ($this->themeRoot !== $root) {
        $this->themeRoot = $root;
        $this->theme = MenuPresentationCatalog::load($root);
      }
      return $this->theme === null ? null : self::compose($prompt, $hint, $this->theme, $width, $height);
    } catch (Throwable $error) {
      if (!isset($this->diagnostics[$error->getMessage()])) {
        Debug::warn('Skit prompt retains terminal presentation: ' . $error->getMessage());
        $this->diagnostics[$error->getMessage()] = true;
      }
      return null;
    }
  }

  public static function compose(SkitPrompt $prompt, ActionHint $hint, MenuPresentationCatalog $theme,
    int $width, int $height): PresentationCanvas
  {
    $m = $theme->metrics;
    $margin = min(24, (int)floor(min($width, $height) / 20));
    $padding = $m->panelPadding;
    $w = min(444, $width - 2 * $margin);
    $contentWidth = $w - 2 * $padding;
    $title = new MenuTextLayout($prompt->title, (int)floor($contentWidth / $m->cellWidth));
    $hintHeight = MenuActionHints::height([$hint], $theme, $contentWidth, required: true);
    $h = 2 * $padding + count($title->lines) * $m->cellHeight + $m->sectionGap + $hintHeight;
    $box = new CanvasRectangle($width - $margin - $w, $height - $margin - $h, $w, $h);
    $view = new MenuCanvas($theme, $width, $height, background: false);
    $view->frame(self::LAYER . '-frame', $box, 'quiet');
    $view->prose(self::LAYER . '-title', $prompt->title, new CanvasRectangle($box->x + $padding,
      $box->y + $padding, $contentWidth, count($title->lines) * $m->cellHeight), 'accent');
    $view->hints(self::LAYER . '-control', [$hint], new CanvasRectangle($box->x + $padding,
      $box->y + $h - $padding - $hintHeight, $contentWidth, $hintHeight), required: true);
    $view->protect($box);
    return $view->finish();
  }
}
