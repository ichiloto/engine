<?php

namespace Ichiloto\Engine\Entities\States;

use InvalidArgumentException;

/**
 * An authored battler state (poison, sleep, guard-up, …).
 *
 * Definitions are pure data, authored in the project's
 * `assets/Data/states.php`; every battler tracks its own live
 * {@see StateInstance} list.
 *
 * @package Ichiloto\Engine\Entities\States
 */
class State
{
  /**
   * @param string $id The state id.
   * @param string $name The display name.
   * @param string $icon The icon shown next to afflicted battlers.
   * @param string $description The description.
   * @param int|null $durationTurns Turns before the state expires; null lasts until cured.
   * @param string|null $tickFormula HP delta applied each turn (negative harms); same formula language as skill effects, with `$target` bound to the afflicted battler.
   * @param bool $preventsAction True when the afflicted battler cannot act.
   * @param bool $persistsAfterBattle True when the state survives the end of battle (classic poison).
   */
  public function __construct(
    protected(set) string $id,
    protected(set) string $name,
    protected(set) string $icon = '',
    protected(set) string $description = '',
    protected(set) ?int $durationTurns = null,
    protected(set) ?string $tickFormula = null,
    protected(set) bool $preventsAction = false,
    protected(set) bool $persistsAfterBattle = false,
  )
  {
  }

  /**
   * Hydrates a state from its data-file entry.
   *
   * @param array<string, mixed> $data The state entry.
   * @return self The state.
   */
  public static function fromArray(array $data): self
  {
    $id = trim(strval($data['id'] ?? ''));

    if ($id === '') {
      throw new InvalidArgumentException('States require an id.');
    }

    $duration = $data['durationTurns'] ?? null;

    return new self(
      $id,
      strval($data['name'] ?? $id),
      strval($data['icon'] ?? ''),
      strval($data['description'] ?? ''),
      is_numeric($duration) ? max(1, intval($duration)) : null,
      isset($data['tickFormula']) ? strval($data['tickFormula']) : null,
      (bool) ($data['preventsAction'] ?? false),
      (bool) ($data['persistsAfterBattle'] ?? false),
    );
  }
}
