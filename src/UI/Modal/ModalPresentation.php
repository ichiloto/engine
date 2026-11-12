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
    public ?QuantityPresentation $quantity = null, public ?NoticeContent $notice = null,
    public ?string $sourceMessage = null, public ?StorybookPresentation $storybook = null)
  {
    if (!array_is_list($choices) || $choices === [] || !isset($choices[$activeIndex])) {
      throw new InvalidArgumentException('Modal presentation requires choices and a valid owner-selected index.');
    }
    if ($singleConfirmation && ($vertical || count($choices) !== 1)) {
      throw new InvalidArgumentException('A single-confirmation presentation requires one horizontal choice.');
    }
    if ($notice !== null && (!$singleConfirmation || $quantity !== null)) {
      throw new InvalidArgumentException('A notice requires a single confirmation without quantity controls.');
    }
    if ($storybook !== null && ($vertical || $quantity !== null || $notice !== null)) {
      throw new InvalidArgumentException('A storybook requires horizontal reader controls without quantity or notice content.');
    }
    if ($storybook !== null && ($message !== $storybook->readingText
      || ($sourceMessage !== null && $sourceMessage !== $storybook->page->text))) {
      throw new InvalidArgumentException('Storybook modal text must match its owner-selected narration.');
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
