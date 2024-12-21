<?php

namespace Ichiloto\Engine\Entities\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class AsCharacter
{
  public function __construct(
    public string $name,
    
  )
  {
  }
}