<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use InvalidArgumentException;

/** A dismissible announcement; current theme bindings own its optional artwork. */
final readonly class NoticeContent
{
  public function __construct(public string $title, public string $message,
    public ?string $actorId = null, public ?string $iconRole = null, public ?SystemSound $sound = null)
  {
    if (trim($title . $message) === '') { throw new InvalidArgumentException('A notice requires visible text.'); }
    foreach ([$actorId, $iconRole] as $reference) {
      if ($reference !== null && (trim($reference) === '' || $reference !== trim($reference))) {
        throw new InvalidArgumentException('Notice artwork references must be nonempty stable identities.');
      }
    }
    if ($actorId !== null && $iconRole !== null) {
      throw new InvalidArgumentException('A notice selects an actor portrait or an icon role.');
    }
  }
}
