<?php

namespace Ichiloto\Engine\UI\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Events\Interfaces\SubjectInterface;

/**
 * The ModalInterface interface.
 *
 * @package Ichiloto\Engine\UI\Interfaces
 */
interface ModalInterface extends CanUpdate, CanRender, SubjectInterface
{
  /**
   * @var string $title The title of the modal.
   */
  public string $title {
    get;
    set;
  }

  /**
   * @var string $message The content of the modal.
   */
  public string $message {
    get;
    set;
  }

  /**
   * @var string[] $buttons The buttons of the modal.
   */
  public array $buttons {
    get;
    set;
  }

  /**
   * @var string $help The help of the modal.
   */
  public string $activeButton {
    get;
  }

  /**
   * Shows the modal.
   *
   * @return void
   */
  public function show(): void;

  /**
   * Hides the modal.
   *
   * @return void
   */
  public function hide(): void;

  /**
   * Opens the modal and returns the value when closed.
   *
   * @return mixed
   */
  public function open(): mixed;

  /**
   * Closes the modal and returns the value.
   *
   * @return mixed
   */
  public function close(): mixed;
}