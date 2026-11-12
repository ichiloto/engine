<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\States\State;
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

  /** The theme icon role group every state badge draws from: `status.state.<stateId>`. */
  public const string STATE_ICON_ROLE_PREFIX = 'status.state.';
  /** The suffix of the role a pending state's badge draws: `status.state.<stateId>.pending`. */
  public const string PENDING_STATE_ICON_ROLE_SUFFIX = '.pending';

  /** The theme icon role a state's badge draws: the state's own, or the one for its pending badge. */
  public static function getStateIconRole(string $stateId, bool $pending = false): string
  {
    $stateId = trim($stateId);
    if ($stateId === '') { throw new \InvalidArgumentException('A state icon role requires a state id.'); }
    return self::STATE_ICON_ROLE_PREFIX . $stateId . ($pending ? self::PENDING_STATE_ICON_ROLE_SUFFIX : '');
  }

  /**
   * Every optional theme icon role the condition badges of these states draw,
   * shared with theme authoring: each state's own role, then its pending role.
   * A role the theme leaves unbound shows the state's icon or label instead.
   *
   * @param iterable<string|State> $states The project's states, or their ids.
   * @return list<string>
   */
  public static function listStateIconRoles(iterable $states): array
  {
    $roles = [];
    foreach ($states as $state) {
      $id = $state instanceof State ? $state->id : $state;
      array_push($roles, self::getStateIconRole($id), self::getStateIconRole($id, pending: true));
    }
    return array_values(array_unique($roles));
  }

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
      if ($instance->isPending) {
        $label = $state->badgeLabel . '?';
        $entries[] = ['key' => 'state-pending:' . $state->id, 'name' => $state->name . ' (pending)',
          'description' => '', 'glyph' => $label, 'iconKey' => self::getStateIconRole($state->id, pending: true),
          'symbol' => 'state-pending:' . $state->id, 'label' => $label, 'badge' => $label,
          'polarity' => 'neutral', 'magnitude' => null];
        continue;
      }
      $entries[] = ['key' => 'state:' . $state->id, 'name' => $state->name,
        'description' => $state->description,
        'glyph' => trim($state->icon) !== '' ? $state->icon : $state->name,
        'iconKey' => self::getStateIconRole($state->id), 'symbol' => 'state:' . $state->id,
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
