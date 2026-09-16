<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/** Terminal projection of the same facts and stage order as the graphical view. */
final class BattleResultsText
{
  /** @return list<string> */
  public static function lines(BattleResultsPlayback $playback): array
  {
    $stage = $playback->currentStage();
    $rewards = $playback->rewards;
    if ($stage['kind'] !== 'primary') {
      return array_column(BattleResultsContent::eventLines($playback), 'text');
    }
    switch ($stage['kind']) {
      case 'primary':
        $lines = [sprintf('EXP per member: %d   Gold: %d G', $rewards->experiencePerMember, $rewards->gold), 'Party Progress'];
        foreach ($rewards->progression as $member) {
          if ($member->after === null) { continue; }
          $lines[] = sprintf('%s: Lv %d -> %d  +%d EXP', $member->after->name,
            $member->oldLevel, $member->newLevel, $member->experienceAwarded);
        }
        $lines[] = 'Rewards';
        foreach ($rewards->items as $item) {
          $lines[] = sprintf('%s x%d', $item['name'], $item['quantity'])
            . (isset($item['received']) && $item['received'] !== $item['quantity']
              ? sprintf(' (retained %d)', $item['received']) : '');
        }
        if ($rewards->items === []) { $lines[] = 'No item drops.'; }
        foreach ($rewards->summary as $label => $value) { $lines[] = $label . ': ' . $value; }
        return $lines;
      default:
        return [];
    }
  }
}
