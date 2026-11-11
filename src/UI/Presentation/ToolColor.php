<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** Semantic chrome roles shared by Ichiloto's tools. */
enum ToolColor: string
{
  case BACKGROUND = 'background';
  case PANEL = 'panel';
  case PANEL_RAISED = 'panel_raised';
  case BORDER = 'border';
  case TEXT = 'text';
  case TEXT_MUTED = 'text_muted';
  case ACCENT = 'accent';
  case SELECTION = 'selection';
  case WARNING = 'warning';
  case DANGER = 'danger';
  case CANVAS = 'canvas';
  case HOVER = 'hover';
  case CONTROL_BORDER = 'control_border';
  case FOCUS = 'focus';
  case SUCCESS = 'success';
  case DANGER_FILL = 'danger_fill';
  case GRID = 'grid';
}
