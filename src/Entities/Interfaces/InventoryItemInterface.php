<?php

namespace Ichiloto\Engine\Entities\Interfaces;

interface InventoryItemInterface
{
  public string $name {
    get;
  }

  public string $description {
    get;
  }

  public string $icon {
    get;
  }

  public int $price {
    get;
  }

  public int $quantity {
    get;
    set;
  }
}