<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

/** The host available to a script, or a reusable script awaiting its caller. */
enum EventScriptContext: string
{
  case FIELD = 'field';
  case BATTLE = 'battle';
  case CINEMATIC = 'cinematic';
  case REUSABLE = 'reusable';

  public function getScope(): ?EventExecutionScope
  {
    return match ($this) {
      self::FIELD, self::CINEMATIC => EventExecutionScope::FIELD,
      self::BATTLE => EventExecutionScope::BATTLE,
      self::REUSABLE => null,
    };
  }

  public static function getForScope(EventExecutionScope $scope, bool $hasCinematic = false): self
  {
    return match ($scope) {
      EventExecutionScope::FIELD => $hasCinematic ? self::CINEMATIC : self::FIELD,
      EventExecutionScope::BATTLE => self::BATTLE,
    };
  }
}
