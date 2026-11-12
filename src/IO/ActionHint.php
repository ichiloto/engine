<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO;

use InvalidArgumentException;

/** A semantic action and its resolved display control, snapshotted for one measured redraw. */
final readonly class ActionHint
{
  /**
   * @param ControlHint|null $pairedControl The control of a paired action that shares the label, such as the next of a
   *   previous/next pair ("Q / E Suits"); null for a single action.
   * @param string|null $pairedAction That paired action.
   */
  public function __construct(public string $action, public string $label, public ?ControlHint $control,
    public ?ControlHint $pairedControl = null, public ?string $pairedAction = null)
  {
    foreach ([$action, $label] as $text) {
      if (trim($text) === '' || preg_match('//u', $text) !== 1 || preg_match('/\p{Cc}/u', $text) === 1) {
        throw new InvalidArgumentException('Action hints require nonempty UTF-8 action and label text without controls.');
      }
    }
  }
}
