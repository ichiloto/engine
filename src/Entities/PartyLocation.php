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

  /** @var list<string> Place first, then a distinct region; authored values remain unchanged. */
  public array $displayNames {
    get => array_values(array_unique(array_filter([trim($this->name), trim($this->region)],
      static fn(string $name): bool => $name !== '')));
  }

  /** The region and place in the existing summary order, with each name shown once. */
  public string $prettyLocation {
    get => implode(' - ', array_reverse($this->displayNames));
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