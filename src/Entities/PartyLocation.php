<?php

namespace Ichiloto\Engine\Entities;

/**
 * The PartyLocation class.
 *
 * @package Ichiloto\Engine\Entities
 */
class PartyLocation
{
  /**
   * The default location name.
   */
  public const string DEFAULT_LOCATION_NAME = 'N/A';
  /**
   * The default location region.
   */
  public const string DEFAULT_LOCATION_REGION = 'N/A';

  /**
   * @var string The party location's name in a human-readable format.
   */
  public string $prettyLocation {
    get {
      return "{$this->region} - {$this->name}";
    }
  }

  /**
   * The PartyLocation constructor.
   *
   * @param string $name The party location's name.
   * @param string $region The party location's region.
   */
  public function __construct(
    public string $name = self::DEFAULT_LOCATION_NAME,
    public string $region = self::DEFAULT_LOCATION_REGION,
  )
  {
  }
}