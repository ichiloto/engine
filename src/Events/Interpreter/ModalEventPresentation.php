<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\Modal\TextBoxModal;

/**
 * Drives the existing terminal modals one game-loop tick at a time.
 */
final class ModalEventPresentation implements EventPresentationInterface
{
  protected ?ModalInterface $modal = null;

  public function __construct(protected GameScene $gameScene)
  {
  }

  public function beginText(string $text, string $speaker): void
  {
    $this->reset();
    $this->modal = new TextBoxModal(
      $this->gameScene->getGame(),
      $text,
      $speaker,
      charactersPerSecond: dialogue_speed(),
    );
    $this->modal->show();
  }

  public function beginChoice(string $prompt, array $options, string $title = ''): void
  {
    $this->reset();
    $width = DEFAULT_SELECT_DIALOG_WIDTH;
    $height = max(DEFAULT_SELECT_DIALOG_HEIGHT, count($options) + 4);
    $position = new Vector2(
      intval((get_screen_width() - $width) / 2),
      intval((get_screen_height() - $height) / 2),
    );
    $this->modal = new SelectModal(
      $this->gameScene->getGame(),
      $prompt,
      $options,
      $title,
      rect: new Rect($position->x, $position->y, $width, $height),
    );
    $this->modal->show();
  }

  public function update(): void
  {
    if ($this->modal?->isShowing()) {
      $this->modal->update();
    }
  }

  public function render(): void
  {
    if ($this->modal?->isShowing()) {
      $this->modal->render();
    }
  }

  public function isComplete(): bool
  {
    return $this->modal !== null && ! $this->modal->isShowing();
  }

  public function choiceResult(): ?int
  {
    return $this->modal instanceof SelectModal ? $this->modal->getValue() : null;
  }

  public function reset(): void
  {
    if ($this->modal?->isShowing()) {
      $this->modal->hide();
    }

    $this->modal = null;
  }
}
