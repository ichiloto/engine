<?php

namespace Ichiloto\Engine\Progress\Knowledge;

/** Player-visible lifecycle of one authored report. */
enum KnowledgeReportStatus: string
{
  case ACTIVE = 'active';
  case AMENDED = 'amended';
  case WITHDRAWN = 'withdrawn';
  case SUPERSEDED = 'superseded';
}
