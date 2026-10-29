<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\States\StateDisposition;

/** A current projection, never a second state or stat-stage store. */
final class BattlerConditions
{
  private const array STAT_NAMES = ['attack' => 'Attack', 'defence' => 'Defence',
    'magicAttack' => 'Magic attack', 'magicDefence' => 'Magic defence', 'speed' => 'Speed',
    'grace' => 'Grace', 'evasion' => 'Evasion'];
  private const array STAT_LABELS = ['attack' => 'ATK', 'defence' => 'DEF',
    'magicAttack' => 'MAT', 'magicDefence' => 'MDF', 'speed' => 'SPD',
    'grace' => 'GRC', 'evasion' => 'EVA'];

  /** A stat's short label (ATK, DEF ...), as condition badges and revealed stats show it. */
  public static function getStatLabel(string $stat): string
  {
    return self::STAT_LABELS[$stat] ?? mb_strtoupper(mb_substr($stat, 0, 3, 'UTF-8'), 'UTF-8');
  }

  /** The badge is the compact terminal form: a state's icon or label, a stage's label and change.
   * @return list<array{key: string, name: string, description: string, glyph: string,
   * iconKey: string, symbol: string, label: string, badge: string, polarity: string, magnitude: ?int}>
   */
  public static function getEntries(CharacterInterface $battler): array
  {
    $entries = [];
    if ($battler instanceof Character && ($ability = $battler->crisisAbility) !== null && $ability->isActive($battler)) {
      $entries[] = ['key' => 'crisis', 'name' => $ability->name, 'description' => $ability->description,
        'glyph' => $ability->label, 'iconKey' => 'status.crisis', 'symbol' => 'crisis',
        'label' => $ability->label, 'badge' => $ability->label, 'polarity' => 'positive', 'magnitude' => null];
    }
    foreach ($battler->states ?? [] as $instance) {
      $state = $instance->state;
      $entries[] = ['key' => 'state:' . $state->id, 'name' => $state->name,
        'description' => $state->description,
        'glyph' => trim($state->icon) !== '' ? $state->icon : $state->name,
        'iconKey' => 'status.state.' . $state->id, 'symbol' => 'state:' . $state->id,
        'label' => $state->badgeLabel, 'badge' => trim($state->icon) !== '' ? $state->icon : $state->badgeLabel,
        'polarity' => match ($state->disposition) {
          StateDisposition::BENEFICIAL => 'positive', StateDisposition::HARMFUL => 'negative',
          StateDisposition::NEUTRAL => 'neutral',
        }, 'magnitude' => null];
    }
    foreach ($battler->statStages ?? [] as $stat => $stage) {
      if ($stage === 0) { continue; }
      $name = self::STAT_NAMES[$stat] ?? $stat;
      $sign = $stage > 0 ? '+' : '';
      $polarity = $stage > 0 ? 'positive' : 'negative';
      $label = self::getStatLabel($stat);
      $entries[] = ['key' => 'stat:' . $stat . ':' . $polarity,
        'name' => $name . ' ' . $sign . $stage,
        'description' => sprintf('%s is at stage %s%d for this battle.', $name, $sign, $stage),
        'glyph' => $name . ' ' . $sign . $stage,
        'iconKey' => 'status.stat.' . $stat . '.' . $polarity, 'symbol' => 'stat:' . $stat,
        'label' => $label, 'badge' => $label . $sign . $stage,
        'polarity' => $polarity, 'magnitude' => abs($stage)];
    }
    return $entries;
  }

  public static function getInfo(CharacterInterface $battler): string
  {
    $entries = self::getEntries($battler);
    if ($entries === []) { return ''; }
    return $battler->name . ': ' . implode(' ', array_map(static fn(array $entry): string =>
      $entry['name'] . ($entry['description'] === '' ? '.' : ': ' . $entry['description']), $entries));
  }
}
