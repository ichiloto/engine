<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Exceptions\RequiredFieldException;

readonly class SystemData
{
  /**
   * SystemData constructor.
   *
   * @param string $title The title of the game.
   * @param object{name: string, symbol: string} $currency The currency object.
   * @param string[] $startingParty The starting party.
   * @param array $startingInventory The starting inventory.
   * @param object{player: object{destinationMap: string, spawnPoint: object{x: int, y: int}, spawnSprite: string[]}} $startingPositions The starting positions.
   */
  public function __construct(
    public string $title,
    public object $currency,
    public array $startingParty,
    public array $startingInventory,
    public object $startingPositions,
  )
  {
  }

  /**
   * Creates a SystemData object from an array.
   *
   * @param array $data The data.
   * @return static The SystemData object.
   * @throws RequiredFieldException Thrown when a required field is missing.
   */
  public static function fromArray(array $data): static
  {
    return new self(
      $data['title'] ?? throw new RequiredFieldException('title'),
      (object)$data['currency'] ?? throw new RequiredFieldException('currency'),
      $data['startingParty'] ?? [],
      $data['startingInventory'] ?? [],
      json_decode(json_encode($data['startingPositions'])) ?? throw new RequiredFieldException('startingPositions'),
    );
  }
}