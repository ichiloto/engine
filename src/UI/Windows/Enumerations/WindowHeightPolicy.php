<?php

namespace Ichiloto\Engine\UI\Windows\Enumerations;

/**
 * Controls how a window reconciles authored content with its vertical
 * footprint.
 */
enum WindowHeightPolicy
{
  /** Grow as needed and retain the largest authoritative render footprint. */
  case GROW_TO_CONTENT;

  /** Keep the authored height for content managed by paging or scrolling. */
  case FIXED;
}
