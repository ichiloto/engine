<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Animations\AnimationFrame;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\ScreenTransitionSession;
use Ichiloto\Engine\Scenes\Game\GameScene;

/** Renders temporary cinematic overlays and reusable field animation frames. */
final class CinematicPresentationManager
{
  protected ?array $overlay = null;
  protected ?AnimationFrame $animationFrame = null;
  protected ?Vector2 $animationPosition = null;
  protected bool $animationUsesScreenSpace = false;
  protected ?ScreenTransitionSession $transitionSession = null;
  protected ?string $fieldCover = null;

  public function __construct(protected GameScene $gameScene)
  {
  }

  public function showOverlay(string $kind, string $text, string $title = ''): void
  {
    $this->overlay = ['kind' => $kind, 'text' => $text, 'title' => $title];
  }

  public function showAnimationFrame(AnimationFrame $frame, Vector2 $position, bool $screenSpace = false): void
  {
    $this->animationFrame = $frame;
    $this->animationPosition = $position;
    $this->animationUsesScreenSpace = $screenSpace;
  }

  public function clearAnimation(): void
  {
    $this->animationFrame = null;
    $this->animationPosition = null;
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
    $this->overlay = null;
    $this->clearAnimation();
    $this->clearTransition();
  }

  public function render(): void
  {
    $this->renderAnimation();
    $this->renderOverlay();
    $this->renderTransition();
  }

  protected function renderAnimation(): void
  {
    if ($this->animationFrame === null || $this->animationPosition === null) {
      return;
    }

    $origin = $this->animationUsesScreenSpace
      ? $this->animationPosition
      : $this->gameScene->camera->getScreenSpacePosition($this->animationPosition);

    foreach ($this->animationFrame->getCells() as $cell) {
      $symbol = $cell->color !== null && $cell->color !== ''
        ? sprintf('<fg=%s>%s</>', $cell->color, $cell->symbol)
        : $cell->symbol;
      $this->gameScene->camera->draw($symbol, intval($origin->x) + $cell->x, intval($origin->y) + $cell->y);
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
    $lines = TerminalText::wrapToWidth($text, max(1, $width - 4));
    $border = '+' . str_repeat('-', max(1, $width - 2)) . '+';
    $rows = [$border];

    if ($title !== '') {
      $rows[] = '| ' . TerminalText::padRight('<options=bold>' . $title . '</>', $width - 4) . ' |';
    }

    foreach ($lines as $line) {
      $rows[] = '| ' . TerminalText::padRight($line, $width - 4) . ' |';
    }

    $rows[] = $border;
    $x = max(0, intdiv($screenWidth - $width, 2));
    $y = $kind === 'title_card'
      ? max(0, intdiv($screenHeight - count($rows), 2))
      : max(0, $screenHeight - count($rows) - 2);
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
