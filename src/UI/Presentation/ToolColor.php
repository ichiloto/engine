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
  case OVERLAY_DOOR = 'overlay_door';
  case OVERLAY_WALK = 'overlay_walk';
  case OVERLAY_ENTRANCE = 'overlay_entrance';
  case OVERLAY_NO_ENTRANCE = 'overlay_no_entrance';
  case OVERLAY_PLACED = 'overlay_placed';
  case OVERLAY_WALK_START = 'overlay_walk_start';
  case OVERLAY_WALK_WAYPOINT = 'overlay_walk_waypoint';
  case OVERLAY_WALK_ENDPOINT = 'overlay_walk_endpoint';
  case SCRIM = 'scrim';
  case OVERLAY_NAMED_AREA = 'overlay_named_area';
  case COLLISION_OPEN = 'collision_open';
  case COLLISION_SOLID = 'collision_solid';
  case COLLISION_COUNTER = 'collision_counter';
  case COLLISION_OTHER = 'collision_other';
  case GROUND_APPEARANCE_GROUND = 'ground_appearance_ground';
  case GROUND_APPEARANCE_WATER = 'ground_appearance_water';
  case GROUND_APPEARANCE_BARRIER = 'ground_appearance_barrier';
  case GROUND_APPEARANCE_OVERHEAD = 'ground_appearance_overhead';
  case PASSAGE_INVISIBLE_WALL = 'passage_invisible_wall';
  case PASSAGE_WALK_THROUGH = 'passage_walk_through';
  case PASSAGE_FOOTPRINT = 'passage_footprint';
  case PASSAGE_UNJUDGED = 'passage_unjudged';
}
