<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

enum AssetHoldRejection: string
{
  case SCHEMA = 'schema';
  case INVALID_ID = 'invalid_id';
  case INVALID_REVISION = 'invalid_revision';
  case INVALID_ASSET = 'invalid_asset';
  case INVALID_RECT = 'invalid_rect';
  case INVALID_BASE = 'invalid_base';
  case INVALID_DRAW_SIZE = 'invalid_draw_size';
  case INVALID_CANVAS = 'invalid_canvas';
  case CAPABILITY_MISSING = 'capability_missing';
  case LEASE_LIMIT = 'lease_limit';
  case TRANSACTION_LIMIT = 'transaction_limit';
}
