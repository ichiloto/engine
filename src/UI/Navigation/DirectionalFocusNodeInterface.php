<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Navigation;

/** Shared navigation data, independent of activation, rendering and input. */
interface DirectionalFocusNodeInterface
{
  public string $id { get; }
  public bool $enabled { get; }

  public function getNeighbourId(FocusDirection $direction): ?string;
}
