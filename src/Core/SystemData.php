<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Battle\Enumerations\BattleEngineType;
use Ichiloto\Engine\Exceptions\RequiredFieldException;

readonly class SystemData
{
  public function __construct(
    public string $title,
    public object $currency,
    public array $startingParty,
    public array $startingInventory,
    public object $startingPositions,
    public object $battle,
  )
  {
  }

  public static function fromArray(array $data): static
  {
    return new self(
      $data['title'] ?? throw new RequiredFieldException('title'),
      (object) ($data['currency'] ?? throw new RequiredFieldException('currency')),
      $data['startingParty'] ?? [],
      $data['startingInventory'] ?? [],
      json_decode(json_encode($data['startingPositions'])) ?? throw new RequiredFieldException('startingPositions'),
      self::normalizeBattleSettings($data['battle'] ?? []),
    );
  }

  public function getBattleEngineType(): BattleEngineType
  {
    return BattleEngineType::fromValue($this->battle->engine ?? null);
  }

  public function getActiveTimeSettings(): object
  {
    return $this->battle->activeTime;
  }

  private static function normalizeBattleSettings(mixed $battleData): object
  {
    $battleArray = is_array($battleData) ? $battleData : [];
    $activeTime = is_array($battleArray['activeTime'] ?? null) ? $battleArray['activeTime'] : [];

    return json_decode(json_encode([
      'engine' => BattleEngineType::fromValue($battleArray['engine'] ?? null)->value,
      'activeTime' => [
        'mode' => 'wait',
        'baseFillRate' => max(1, intval($activeTime['baseFillRate'] ?? 35)),
        'speedFactorPercent' => max(0, intval($activeTime['speedFactorPercent'] ?? 100)),
        'openingVariance' => max(0, intval($activeTime['openingVariance'] ?? 24)),
        'openingSpeedFactorPercent' => max(0, intval($activeTime['openingSpeedFactorPercent'] ?? 250)),
        'surpriseAttackChancePercent' => min(100, max(0, intval($activeTime['surpriseAttackChancePercent'] ?? 8))),
        'backAttackChancePercent' => min(100, max(0, intval($activeTime['backAttackChancePercent'] ?? 6))),
      ],
    ]));
  }
}