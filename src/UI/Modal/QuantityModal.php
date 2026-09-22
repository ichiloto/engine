<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\QuantitySelector;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\UI\Text\TextViewport;

/** Selects an amount only. The caller owns confirmation and any inventory mutation. */
class QuantityModal extends Modal
{
  protected QuantitySelector $selector;

  public int $quantity { get => $this->selector->quantity; }
  public int $maximum { get => $this->selector->maximum; }

  public function __construct(Game $game, string $message, int $maximum,
    string $title = '', int $initial = 1, int $width = DEFAULT_DIALOG_WIDTH)
  {
    $this->selector = new QuantitySelector($maximum, initial: $initial);
    parent::__construct($game, $message, $title, new Rect(0, 0, $width, DEFAULT_DIALOG_HEIGHT), ['Continue']);
  }

  public function getModalPresentation(): ?ModalPresentation
  {
    return new ModalPresentation($this->title, $this->message, $this->buttons, $this->activeIndex,
      quantity: new QuantityPresentation($this->selector->minimum, $this->maximum, $this->quantity));
  }

  public function update(): void
  {
    if (Input::isButtonDown('cancel') || Input::isButtonDown('back')) {
      $this->cancel();
      return;
    }
    $vertical = Input::getAxis(AxisName::VERTICAL);
    $horizontal = Input::getAxis(AxisName::HORIZONTAL);
    if ($vertical != 0 || $horizontal != 0) {
      if ($this->selector->adjustForAxes($vertical, $horizontal)) {
        $this->playInteractionSound(SystemSound::CURSOR);
        $this->fitContentToWidth();
      }
      return;
    }
    if (Input::isButtonDown('confirm')) { $this->submit(); }
  }

  public function render(): void
  {
    $hints = [];
    foreach (['up' => '+1', 'down' => '-1', 'right' => '+10', 'left' => '-10', 'confirm' => 'Continue', 'cancel' => 'Cancel'] as $action => $label) {
      $hint = ActionHints::resolve($action, $label);
      if ($hint->control !== null) { $hints[] = $hint->control->label . ': ' . $label; }
    }
    $this->help = implode('  ', $hints);
    parent::render();
  }

  protected function submit(): void
  {
    parent::submit();
    $this->value = $this->selector->quantity;
  }

  protected function fitContentToWidth(): void
  {
    $text = $this->message . "\n" . sprintf('Quantity: %0*d / %d', max(2, strlen((string)$this->maximum)), $this->quantity, $this->maximum);
    $viewport = new TextViewport();
    $viewport->setText($text);
    $this->content = $viewport->page(max(1, $this->rect->getWidth() - 2), PHP_INT_MAX)->lines;
    $this->contentHeight = count($this->content);
    $this->rect->setHeight($this->contentHeight + 3);
    $this->rebuildWindow();
  }
}
