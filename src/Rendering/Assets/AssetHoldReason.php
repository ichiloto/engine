<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

enum AssetHoldReason: string
{
  case OVER_BUDGET = 'over_budget';
  case MISSING = 'missing';
  case ESCAPES_ROOT = 'escapes_root';
  case NOT_REGULAR_FILE = 'not_regular_file';
  case UNREADABLE = 'unreadable';
  case ENCODED_TOO_LARGE = 'encoded_too_large';
  case INVALID_HEADER = 'invalid_header';
  case INVALID_PNG = 'invalid_png';
  case DIMENSIONS_EXCEED = 'dimensions_exceed';
  case RECT_OUTSIDE_IMAGE = 'rect_outside_image';
  case REGION_TOO_LARGE = 'region_too_large';
  case CHANGED = 'changed';
}
