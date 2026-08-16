<?php

namespace Ichiloto\Engine\Progress\Knowledge;

/** Evidence depth reached for one stable knowledge subject. */
enum KnowledgeDepth: int
{
  case UNKNOWN = 0;
  case DISCOVERED = 1;
  case OBSERVED = 2;
  case SUPPORTED = 3;
  case CONTESTED = 4;
}
