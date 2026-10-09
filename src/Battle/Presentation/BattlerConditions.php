<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\States\StateDisposition;
use Ichiloto\Engine\IO\Console\TerminalText;

/** A current projection, never a second state or stat-stage store. */
final class BattlerConditions
{
  private const array STAT_NAMES = ['attack' => 'Attack', 'defence' => 'Defence',
    'magicAttack' => 'Magic attack', 'magicDefence' => 'Magic defence', 'speed' => 'Speed',
    'grace' => 'Grace', 'evasion' => 'Evasion'];
  private const array STAT_LABELS = ['attack' => 'ATK', 'defence' => 'DEF',
    'magicAttack' => 'MAT', 'magicDefence' => 'MDF', 'speed' => 'SPD',
    'grace' => 'GRC', 'evasion' => 'EVA'];

  /** @return list<array{key: string, name: string, description: string, glyph: string,
   * iconKey: string, symbol: string, label: string, polarity: string, magnitude: ?int}>
   */
  public static function getEntries(CharacterInterface $battler): array
  {
    $entries = [];
    foreach ($battler->states ?? [] as $instance) {
      $state = $instance->state;
      $entries[] = ['key' => 'state:' . $state->id, 'name' => $state->name,
        'description' => $state->description,
        'glyph' => trim($state->icon) !== '' ? $state->icon : $state->name,
        'iconKey' => 'status.state.' . $state->id, 'symbol' => 'state:' . $state->id,
        'label' => self::getStateBadgeLabel($state->id, $state->name),
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
      $entries[] = ['key' => 'stat:' . $stat . ':' . $polarity,
        'name' => $name . ' ' . $sign . $stage,
        'description' => sprintf('%s is at stage %s%d for this battle.', $name, $sign, $stage),
        'glyph' => $name . ' ' . $sign . $stage,
        'iconKey' => 'status.stat.' . $stat . '.' . $polarity, 'symbol' => 'stat:' . $stat,
        'label' => self::STAT_LABELS[$stat] ?? mb_strtoupper(mb_substr($stat, 0, 3, 'UTF-8'), 'UTF-8'),
        'polarity' => $polarity, 'magnitude' => abs($stage)];
    }
    return $entries;
  }

  private static function getStateBadgeLabel(string $id, string $name): string
  {
    if ($id === 'poison') { return 'PSN'; }
    if ($id === 'stun') { return 'STN'; }
    $plain = trim(preg_replace('/[\p{Cc}\s]+/u', ' ', TerminalText::stripAnsi($name)) ?? '');
    return mb_substr(mb_strtoupper($plain, 'UTF-8'), 0, 3, 'UTF-8');
  }

  public static function getInfo(CharacterInterface $battler): string
  {
    $entries = self::getEntries($battler);
    if ($entries === []) { return ''; }
    return $battler->name . ': ' . implode(' ', array_map(static fn(array $entry): string =>
      $entry['name'] . ($entry['description'] === '' ? '.' : ': ' . $entry['description']), $entries));
  }
}
