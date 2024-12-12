<?php

namespace Ichiloto\Engine\Entities\Inventory\Item;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;

/**
 * The ItemScope class.
 *
 * @package Ichiloto\Engine\Entities\Inventory\Item
 */
class ItemScope
{
  /**
   * The maximum random number.
   */
  public const int MAX_RANDOM_NUMBER = 100;
  /**
   * The minimum random number.
   */
  public const int MIN_RANDOM_NUMBER = 1;

  /**
   * @var int $randomNumber The random number.
   */
  public int $randomNumber {
    get {
      return clamp($this->randomNumber, self::MIN_RANDOM_NUMBER,self::MAX_RANDOM_NUMBER);
    }
  }
  /**
   * The ItemScope constructor.
   *
   * @param ItemScopeSide $side The side.
   * @param ItemScopeNumber $number The number.
   * @param ItemScopeStatus $status The status.
   * @param int $randomNumber The random number. This applies if the status is ItemScopeStatus::RANDOM. Default is 1.
   */
  public function __construct(
    protected(set) ItemScopeSide $side = ItemScopeSide::NONE,
    protected(set) ItemScopeNumber $number = ItemScopeNumber::ONE,
    protected(set) ItemScopeStatus $status = ItemScopeStatus::ALIVE,
    int $randomNumber = 1,
  )
  {
    $this->randomNumber = $randomNumber;
  }

  /**
   * Tries to create an instance of the ItemScope class from the given data.
   *
   * @param array<string, mixed> $data The data.
   * @return static The new instance.
   */
  public static function fromArray(array $data): static
  {
    $side = match (true) {
      $data['side'] instanceof ItemScopeSide => $data['side'],
      is_string($data['side']) => ItemScopeSide::tryFrom($data['side']),
      default => ItemScopeSide::NONE,
    };

    $number = match (true) {
      $data['number'] instanceof ItemScopeNumber => $data['number'],
      is_string($data['number']) => ItemScopeNumber::tryFrom($data['number']),
      default => ItemScopeNumber::ONE,
    };

    $status = match (true) {
      $data['status'] instanceof ItemScopeStatus => $data['status'],
      is_string($data['status']) => ItemScopeStatus::tryFrom($data['status']),
      default => ItemScopeStatus::ALIVE,
    };

    $randomNumber = $data['randomNumber'] ?? 1;

    return new ItemScope(
      $side,
      $number,
      $status,
      $randomNumber
    );
  }

  /**
   * Tries to create an instance of the ItemScope class from the given object.
   *
   * @param object $data The data.
   * @return self The new instance.
   */
  public static function fromObject(object $data): static
  {
    return self::fromArray((array) $data);
  }
}