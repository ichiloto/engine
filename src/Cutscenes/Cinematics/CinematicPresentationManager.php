<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Animations\Field\FieldEffectSprite;
use Ichiloto\Engine\Animations\Field\FieldScreenSprite;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\ScreenTransitionSession;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Accessibility;

/** Renders temporary cinematic overlays and reusable field animation frames. */
final class CinematicPresentationManager
{
  protected ?array $overlay = null;
  /** @var array<string, array{session: FieldEffectSession, screenSpace: bool}> */
  private array $effects = [];
  protected ?ScreenTransitionSession $transitionSession = null;
  protected ?string $fieldCover = null;

  public function __construct(protected GameScene $gameScene)
  {
  }

  public function showOverlay(string $kind, string $text, string $title = ''): void
  {
    $this->overlay = ['kind' => $kind, 'text' => $text, 'title' => $title];
  }

  public function clearOverlay(): void { $this->overlay = null; }

  public function presentEffect(FieldEffectSession $session, bool $screenSpace = false): void
  {
    if (isset($this->effects[$session->id])) { $this->effects[$session->id]['session']->playback->pause(); }
    $this->effects[$session->id] = ['session' => $session, 'screenSpace' => $screenSpace];
  }

  public function hasEffect(string $id): bool { return isset($this->effects[$id]); }

  public function removeEffect(string $id): void
  {
    if (isset($this->effects[$id])) { $this->effects[$id]['session']->playback->pause(); }
    unset($this->effects[$id]);
  }

  public int $effectCount { get => count($this->effects); }

  /** @return list<FieldEffectSprite> */
  public function getEffectSprites(?FieldViewport $viewport): array
  {
    if ($viewport === null) { return []; }
    $sprites = [];
    foreach ($this->effects as $effect) {
      $position = $effect['session']->anchor->cell;
      if ($position === null) { continue; }
      foreach ($effect['session']->getSprites($position, Accessibility::prefersReducedMotion()) as $sprite) {
        if (!$effect['screenSpace']) { $sprites[] = $sprite; continue; }
        $definition = $sprite->getGraphicalSpriteDefinition();
        $point = $sprite->getGraphicalSpriteWorldPosition();
        $size = FieldViewport::TILE_SIZE * $viewport->zoom;
        $width = $viewport->grid->columns * $viewport->grid->cellWidth;
        $height = $viewport->grid->rows * $viewport->grid->cellHeight;
        $sprites[] = new FieldScreenSprite($sprite->getGraphicalSpriteId(),
          new GraphicalSpriteDefinition($definition->asset, (int)round($definition->width * $viewport->zoom),
            (int)round($definition->height * $viewport->zoom), $definition->anchor, $definition->layer,
            $definition->sourceRect),
          new Vector2((int)round((($width - $viewport->columns * $size) / 2 + ($point->x + .5) * $size)
              / $viewport->grid->cellWidth - .5),
            (int)round((($height - $viewport->rows * $size) / 2 + ($point->y + 1) * $size)
              / $viewport->grid->cellHeight - 1)));
      }
    }
    return $sprites;
  }

  public function hideField(string $fill = '█'): void
  {
    $this->fieldCover = $fill !== '' ? $fill : '█';
  }

  public function presentTransition(ScreenTransitionSession $session): void
  {
    $this->transitionSession = $session;
  }

  public function finishTransition(string $direction): void
  {
    $this->transitionSession = null;

    if (strtolower($direction) === 'in') {
      $this->fieldCover = null;
    } else {
      $this->hideField();
    }
  }

  public function clearTransition(): void
  {
    $this->transitionSession = null;
    $this->fieldCover = null;
  }

  public function hasTransitionCover(): bool
  {
    return $this->fieldCover !== null || $this->transitionSession?->hasRenderedFrame() === true;
  }

  public function clear(): void
  {
    $this->clearOverlay();
    foreach (array_keys($this->effects) as $id) { $this->removeEffect($id); }
    $this->clearTransition();
  }

  public function render(): void
  {
    $this->gameScene->renderFieldEffects();
    $this->renderEffects();
    Console::withLayer('cinematic-overlay', fn() => $this->renderOverlay(),
      PresentationLayerPolicy::UI + PresentationPriority::MODAL->value);
    Console::withLayer('cinematic-cover', fn() => $this->renderTransition(), PresentationLayerPolicy::TRANSITION);
  }

  protected function renderEffects(): void
  {
    $presentation = $this->gameScene->isGraphicalFieldPresented() ? EffectPresentation::GRAPHICAL : EffectPresentation::TERMINAL;
    foreach ($this->effects as $effect) {
      $position = $effect['session']->anchor->cell;
      if ($position === null) { continue; }
      $origin = $effect['screenSpace'] ? $position : $this->gameScene->camera->getScreenSpacePosition($position);
      Console::withLayer($effect['session']->id, fn() => $effect['session']->renderText(
        $this->gameScene->camera, $origin, $presentation, Accessibility::prefersReducedMotion()),
        $effect['screenSpace'] ? PresentationLayerPolicy::UI : PresentationLayerPolicy::FIELD_EFFECT_FRONT);
    }
  }

  protected function renderOverlay(): void
  {
    if ($this->overlay === null) {
      return;
    }

    $screenWidth = $this->gameScene->camera->screen->getWidth();
    $screenHeight = $this->gameScene->camera->screen->getHeight();
    $width = min(max(20, intval($screenWidth * 0.6)), max(20, $screenWidth - 4));
    $text = trim(strval($this->overlay['text'] ?? ''));
    $title = trim(strval($this->overlay['title'] ?? ''));
    $kind = strval($this->overlay['kind'] ?? 'narration');
    $alignment = $kind === 'title_card' ? 'center' : 'left';
    $lines = TerminalText::wrapToWidth($text, max(1, $width - 4));
    $border = '+' . str_repeat('-', max(1, $width - 2)) . '+';
    $rows = [$border];

    if ($title !== '') {
      $rows[] = '| ' . TerminalText::fit(
        '<options=bold>' . $title . '</>',
        $width - 4,
        $alignment,
      ) . ' |';
    }

    foreach ($lines as $line) {
      $rows[] = '| ' . TerminalText::fit($line, $width - 4, $alignment) . ' |';
    }

    $rows[] = $border;
    $x = max(0, intdiv($screenWidth - $width, 2));
    $y = $kind === 'title_card'
      ? max(0, intdiv($screenHeight - count($rows), 2))
      : 0;
    $this->gameScene->camera->draw($rows, $x, $y);
  }

  protected function renderTransition(): void
  {
    if ($this->transitionSession?->hasRenderedFrame()) {
      $this->transitionSession->renderCurrentFrame();
      return;
    }

    if ($this->fieldCover === null) {
      return;
    }

    $width = $this->gameScene->camera->screen->getWidth();
    $height = $this->gameScene->camera->screen->getHeight();
    $this->gameScene->camera->draw(
      array_fill(0, $height, str_repeat($this->fieldCover, $width)),
      0,
      0,
    );
  }
}
