<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/**
 * The focused place under the Mastery board: its name, one line of what it
 * is or why it cannot be learned yet, its price shown once, and the Learn
 * button while it can still be learned.
 */
final readonly class MasteryMenuSelection
{
  /**
   * @param string $name The name in large type.
   * @param string|null $subtitle What it is ("Diamonds") or its status ("Learned", "Unlocks at Lv 5").
   * @param string|null $price Its price with the points label ("45 SP"), shown once.
   * @param string|null $action The button's label ("Learn"), or null when there is nothing to learn here.
   * @param bool $enabled Whether the button can be used now.
   */
  public function __construct(
    public string $name,
    public ?string $subtitle = null,
    public ?string $price = null,
    public ?string $action = null,
    public bool $enabled = false,
  ) {}
}
