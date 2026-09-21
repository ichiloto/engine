<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use InvalidArgumentException;

/** One read-only view of the modal owner, never a second selection or outcome model. */
final readonly class ModalPresentation
{
  /** @var non-empty-list<string> */
  public array $choices;

  /** @param non-empty-list<string> $choices */
  public function __construct(public string $title, public string $message, array $choices,
    public int $activeIndex, public bool $vertical = false, public bool $singleConfirmation = false,
    public ?QuantityPresentation $quantity = null)
  {
    if (!array_is_list($choices) || $choices === [] || !isset($choices[$activeIndex])) {
      throw new InvalidArgumentException('Modal presentation requires choices and a valid owner-selected index.');
    }
    if ($singleConfirmation && ($vertical || count($choices) !== 1)) {
      throw new InvalidArgumentException('A single-confirmation presentation requires one horizontal choice.');
    }
    $copy = [];
    foreach ($choices as $choice) {
      if (!is_string($choice) || trim($choice) === '') {
        throw new InvalidArgumentException('Modal presentation choices must be nonempty labels.');
      }
      $copy[] = $choice;
    }
    $this->choices = $copy;
  }
}
