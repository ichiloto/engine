<?php

use Ichiloto\Engine\Scenes\Arena\{ArenaSetupEditor, BattleTestSetup, BattleTestPartySetting};

function selectBattleTestPartySetting(ArenaSetupEditor $editor, BattleTestPartySetting $setting): void
{
  if ($editor->focus !== ArenaSetupEditor::PARTY) { $editor->movePane(1); }
  $editor->moveVertical(BattleTestSetup::MAX_MEMBERS - $editor->memberIndex);
  $editor->confirm();
  $index = array_search($setting, $editor->partySettings, true);
  if ($index === false) { throw new LogicException('The requested party setting is unavailable.'); }
  $editor->moveVertical($index - $editor->managementIndex);
}
