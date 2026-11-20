<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Navigation;

/** Tool-level intents. The input profile owns controls; the active editor owns cancel and guarded quit. */
enum ToolNavigationAction: string
{
  case NEXT_PANE = 'tool_pane_next';
  case PREVIOUS_PANE = 'tool_pane_previous';
  case CANCEL = 'tool_cancel';
  case QUIT = 'tool_quit';
}
