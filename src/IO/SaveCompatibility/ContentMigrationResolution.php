<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

/** Determines when the host must provide executable project migration classes. */
enum ContentMigrationResolution
{
  /** Normal game startup validates every registered class. */
  case EAGER;

  /** Project-code-free hosts resolve only steps required by the loaded save. */
  case WHEN_REQUIRED;
}
