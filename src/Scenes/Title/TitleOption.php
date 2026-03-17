<?php

namespace Ichiloto\Engine\Scenes\Title;

/**
 * Describes a configurable option shown in the title-screen options menu.
 *
 * @package Ichiloto\Engine\Scenes\Title
 */
readonly class TitleOption
{
  /**
   * @param string $key The internal setting key.
   * @param string $label The label shown in the menu.
   * @param array<string, mixed> $choices The available choices keyed by label.
   */
  public function __construct(
    public string $key,
    public string $label,
    public array $choices,
  )
  {
  }
}
