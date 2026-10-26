<?php

namespace Ichiloto\Engine\Scenes;

use LogicException;

/** Identity handle and read-only completion receipt; it retains no scene or event resources. */
final class SceneExcursionSession
{
  public private(set) ?SceneExcursionOutcome $outcome = null;

  private function __construct() {}

  /**
   * @internal The manager retains the resolver; consumers receive only the session.
   * @return array{self, \Closure(SceneExcursionOutcome): void}
   */
  public static function createOwnedSession(): array
  {
    $session = new self();
    $resolve = static function (SceneExcursionOutcome $outcome) use ($session): void {
      if ($session->outcome !== null) {
        throw new LogicException('The scene excursion has already finished.');
      }
      $session->outcome = $outcome;
    };

    return [$session, $resolve];
  }
}
