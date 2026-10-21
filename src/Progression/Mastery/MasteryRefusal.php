<?php

namespace Ichiloto\Engine\Progression\Mastery;

use InvalidArgumentException;

/**
 * A node the character cannot learn, with the reason in a form menus word
 * for themselves; the message is for logs.
 *
 * @package Ichiloto\Engine\Progression\Mastery
 */
final class MasteryRefusal extends InvalidArgumentException
{
  public function __construct(public readonly MasteryRefusalReason $reason, string $message)
  {
    parent::__construct($message);
  }
}
