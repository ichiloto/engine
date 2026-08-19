<?php

namespace Ichiloto\Engine\UI\Interfaces;

/**
 * Opt-in contract for UI elements that yield to intersecting higher layers.
 *
 * Ordinary project-defined UIElementInterface implementations keep their
 * existing rendering contract unless they explicitly adopt this interface.
 */
interface LayeredUIElementInterface extends UIElementInterface, LayeredPresentationInterface
{
  /**
   * Returns whether this element currently intends to occupy its bounds.
   *
   * This is separate from presentation suppression: project settings or a
   * scene may make an active element ineligible to draw.
   */
  public function isPresentationVisible(): bool;
}
