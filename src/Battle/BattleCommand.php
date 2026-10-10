<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

/** A menu command is an identity and display label, not an executable attack. */
final readonly class BattleCommand
{
  public string $name;

  public function __construct(public BattleCommandType $type, ?string $name = null)
  {
    $this->name = $name ?? $type->label();
  }

  public function __toString(): string
  {
    return $this->name;
  }
}
