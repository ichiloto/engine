<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Formation;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use InvalidArgumentException;

/** Optional battle-only recipient geometry and single-target cover policy. */
final readonly class BattleTargeting
{
  public function __construct(public BattleTargetShape $shape = BattleTargetShape::SCOPE, public bool $ignoreCover = false) {}

  public static function fromArray(mixed $data): ?self
  {
    if ($data === null) { return null; }
    if (!is_array($data) || array_diff(array_keys($data), ['shape', 'ignoreCover']) !== []
      || !is_string($data['shape'] ?? 'scope') || ($shape = BattleTargetShape::tryFrom($data['shape'] ?? 'scope')) === null
      || !is_bool($data['ignoreCover'] ?? false)) {
      throw new InvalidArgumentException('battleTargeting requires a known shape and boolean ignoreCover.');
    }
    return new self($shape, $data['ignoreCover'] ?? false);
  }

  public function validateScope(ItemScope $scope, Occasion $occasion): void
  {
    if ($this->shape === BattleTargetShape::SCOPE && !$this->ignoreCover) { return; }
    if ($occasion !== Occasion::BATTLE_SCREEN) { throw new InvalidArgumentException('battleTargeting requires a battle-only skill.'); }
    if (($this->shape === BattleTargetShape::LINE && $scope->number !== ItemScopeNumber::ONE)
      || (in_array($this->shape, [BattleTargetShape::FRONT_ROW, BattleTargetShape::BACK_ROW], true) && $scope->number !== ItemScopeNumber::ALL)) {
      throw new InvalidArgumentException('Line targeting selects one anchor; row targeting requires all recipients in its scope.');
    }
  }

  public function toArray(): array
  {
    return ['shape' => $this->shape->value, 'ignoreCover' => $this->ignoreCover];
  }
}
