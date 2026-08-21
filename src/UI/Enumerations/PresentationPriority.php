<?php

namespace Ichiloto\Engine\UI\Enumerations;

/**
 * Establishes the relative precedence of screen-space presentation layers.
 */
enum PresentationPriority: int
{
  /** Informational field overlays that may yield to modal interaction. */
  case FIELD_HUD = 10;

  /** Dialogue, choices, alerts, and other modal interaction. */
  case MODAL = 20;
}
