<?php

namespace Ichiloto\Engine\Inn;

/**
 * How an offered inn stay ended.
 *
 * @package Ichiloto\Engine\Inn
 */
enum InnStayOutcome: string
{
  /** The party paid, rested and recovered. */
  case STAYED = 'stayed';
  /** The party said no. */
  case DECLINED = 'declined';
  /** The party said yes but could not pay. */
  case UNAFFORDABLE = 'unaffordable';
}
