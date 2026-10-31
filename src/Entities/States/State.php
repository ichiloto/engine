<?php

namespace Ichiloto\Engine\Entities\States;

use InvalidArgumentException;
use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * An authored battler state (poison, sleep, guard-up, …).
 *
 * Definitions are pure data, authored in the project's
 * `assets/Data/states.php`; every battler tracks its own live
 * {@see StateInstance} list.
 *
 * Outside battle a state may also act while the party walks: every
 * `fieldTickSteps` steps count as one turn, applying the tick formula and
 * advancing the duration. Field ticks stop at 1 HP unless the state is
 * authored to knock out (`fieldTickKnocksOut`). Resting at an inn or a full
 * party recovery cures the states authored as `curedByRest`.
 *
 * @package Ichiloto\Engine\Entities\States
 */
class State
{
  /** Declared default preserves states restored from older saves. */
  protected(set) float $atbRate = 1.0;
  /** The badge label length derived from the name when none is authored. */
  protected const int DERIVED_LABEL_LENGTH = 3;

  /**
   * @var string The short label badges show when no icon art is bound: the authored label, or the name's first letters.
   */
  public string $badgeLabel {
    get {
      if (trim($this->label) !== '') {
        return trim($this->label);
      }

      $plain = trim(preg_replace('/[\p{Cc}\s]+/u', ' ', TerminalText::stripAnsi($this->name)) ?? '');

      return mb_substr(mb_strtoupper($plain, 'UTF-8'), 0, self::DERIVED_LABEL_LENGTH, 'UTF-8');
    }
  }

  /**
   * @param string $id The state id.
   * @param string $name The display name.
   * @param string $icon The icon shown next to afflicted battlers.
   * @param string $description The description.
   * @param int|null $durationTurns Turns before the state expires; null lasts until cured.
   * @param string|null $tickFormula HP delta applied each turn (negative harms); same formula language as skill effects, with `$target` bound to the afflicted battler.
   * @param bool $preventsAction True when the afflicted battler cannot act.
   * @param bool $persistsAfterBattle True when the state survives the end of battle (classic poison).
   * @param StateDisposition $disposition Whether the state harms, enhances or is neutral to its recipient.
   * @param string $label The short badge label (for example PSN); empty derives it from the name.
   * @param int|null $fieldTickSteps Steps walked in the field that count as one turn; null means the state does not act in the field.
   * @param bool $fieldTickKnocksOut True when field ticks may reduce HP to 0; otherwise they stop at 1 HP.
   * @param bool $curedByRest True when resting at an inn or a full party recovery cures the state.
   * @param float $damageTakenRate Scales every hit a guard could block (0.5 halves it, 0 blocks it outright, 1 leaves it unchanged).
   * @param bool $coversAllies True when the bearer takes the single-target hits meant for its weakest ally (Cover).
   * @param int $confusionChancePercent The chance, 0 to 100, that on each of its turns the bearer loses track of friend and
   *   foe and makes its ordinary attack on a random battler of either side instead of acting (Confusion); 0 never.
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
    protected(set) StateDisposition $disposition = StateDisposition::HARMFUL,
    protected(set) ?CounterAttackRule $counterAttack = null,
    protected(set) string $label = '',
    protected(set) ?int $fieldTickSteps = null,
    protected(set) bool $fieldTickKnocksOut = false,
    protected(set) bool $curedByRest = true,
    protected(set) float $damageTakenRate = 1.0,
    protected(set) bool $coversAllies = false,
    protected(set) int $confusionChancePercent = 0,
    float $atbRate = 1.0,
  )
  {
    if ($fieldTickSteps !== null && $fieldTickSteps < 1) {
      throw new InvalidArgumentException(sprintf('State "%s" fieldTickSteps must be at least 1 step.', $id));
    }
    if ($damageTakenRate < 0.0) {
      throw new InvalidArgumentException(sprintf('State "%s" damageTakenRate cannot be negative.', $id));
    }
    if ($confusionChancePercent < 0 || $confusionChancePercent > 100) {
      throw new InvalidArgumentException(sprintf('State "%s" confusionChancePercent must be from 0 to 100.', $id));
    }
    if (!is_finite($atbRate) || $atbRate < 0.0) {
      throw new InvalidArgumentException(sprintf('State "%s" atbRate must be a finite non-negative multiplier.', $id));
    }
    $this->atbRate = $atbRate;
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
    $disposition = $data['disposition'] ?? StateDisposition::HARMFUL;
    if (!$disposition instanceof StateDisposition) {
      $disposition = is_string($disposition) ? StateDisposition::tryFrom($disposition) : null;
      if ($disposition === null) {
        throw new InvalidArgumentException('State disposition must be harmful, beneficial or neutral.');
      }
    }

    $fieldTickSteps = $data['fieldTickSteps'] ?? null;
    if ($fieldTickSteps !== null && (! is_int($fieldTickSteps) || $fieldTickSteps < 1)) {
      throw new InvalidArgumentException(sprintf('State "%s" fieldTickSteps must be a whole number of steps, at least 1.', $id));
    }

    foreach (['fieldTickKnocksOut', 'curedByRest', 'coversAllies'] as $flag) {
      if (array_key_exists($flag, $data) && ! is_bool($data[$flag])) {
        throw new InvalidArgumentException(sprintf('State "%s" %s must be true or false.', $id, $flag));
      }
    }

    $damageTakenRate = $data['damageTakenRate'] ?? 1.0;
    if (! is_int($damageTakenRate) && ! is_float($damageTakenRate)) {
      throw new InvalidArgumentException(sprintf('State "%s" damageTakenRate must be a number.', $id));
    }

    $confusionChance = $data['confusionChancePercent'] ?? 0;
    if (! is_int($confusionChance)) {
      throw new InvalidArgumentException(sprintf('State "%s" confusionChancePercent must be a whole percentage.', $id));
    }
    $atbRate = $data['atbRate'] ?? 1.0;
    if (!is_int($atbRate) && !is_float($atbRate)) {
      throw new InvalidArgumentException(sprintf('State "%s" atbRate must be a number.', $id));
    }

    return new self(
      $id,
      strval($data['name'] ?? $id),
      strval($data['icon'] ?? ''),
      strval($data['description'] ?? ''),
      is_numeric($duration) ? max(1, intval($duration)) : null,
      isset($data['tickFormula']) ? strval($data['tickFormula']) : null,
      (bool) ($data['preventsAction'] ?? false),
      (bool) ($data['persistsAfterBattle'] ?? false),
      $disposition,
      CounterAttackRule::fromArray($data['counterAttack'] ?? null),
      strval($data['label'] ?? ''),
      $fieldTickSteps,
      $data['fieldTickKnocksOut'] ?? false,
      $data['curedByRest'] ?? true,
      floatval($damageTakenRate),
      $data['coversAllies'] ?? false,
      $confusionChance,
      (float) $atbRate,
    );
  }
}
