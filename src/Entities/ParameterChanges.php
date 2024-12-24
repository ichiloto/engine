<?php

namespace Ichiloto\Engine\Entities;

use JsonSerializable;
use Serializable;

/**
 * Represents the parameter changes.
 *
 * @package Ichiloto\Engine\Entities
 */
class ParameterChanges implements Serializable, JsonSerializable
{
  public function __construct(
    protected(set) int $attack = 0,
    protected(set) int $defence = 0,
    protected(set) int $magicAttack = 0,
    protected(set) int $magicDefence = 0,
    protected(set) int $speed = 0,
    protected(set) int $grace = 0,
    protected(set) int $evasion = 0,
    protected(set) int $totalHp = 0,
    protected(set) int $totalMp = 0,
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function serialize(): string
  {
    return json_encode($this, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
  }

  /**
   * @inheritDoc
   */
  public function unserialize(string $data): void
  {
    $this->bindDataToProperties(json_decode($data, true));
  }

  public function __serialize(): array
  {
    return $this->jsonSerialize();
  }

  /**
   * @inheritDoc
   */
  public function __unserialize(array $data): void
  {
    $this->bindDataToProperties($data);
  }

  /**
   * @inheritDoc
   */
  public function jsonSerialize(): array
  {
    return [
      'attack' => $this->attack,
      'defence' => $this->defence,
      'magicAttack' => $this->magicAttack,
      'magicDefence' => $this->magicDefence,
      'speed' => $this->speed,
      'grace' => $this->grace,
      'evasion' => $this->evasion,
      'maxHp' => $this->totalHp,
      'maxMp' => $this->totalMp,
    ];
  }

  /**
   * Binds the data to the properties.
   *
   * @param array<string, int> $data
   * @return void
   */
  protected function bindDataToProperties(array $data): void
  {
    $this->attack = $data['attack'] ?? 0;
    $this->defence = $data['defence'] ?? 0;
    $this->magicAttack = $data['magicAttack'] ?? 0;
    $this->magicDefence = $data['magicDefence'] ?? 0;
    $this->speed = $data['speed'] ?? 0;
    $this->grace = $data['grace'] ?? 0;
    $this->evasion = $data['evasion'] ?? 0;
    $this->totalHp = $data['maxHp'] ?? 0;
    $this->totalMp = $data['maxMp'] ?? 0;
  }
}