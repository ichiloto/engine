<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameConfig;

function makeSaveManagerTestGame(): Game
{
  return (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
}

function makeSaveManagerTestConfig(): GameConfig
{
  $party = new Party();
  $party->location = new PartyLocation('Happyville Town Square', 'Happyville');

  return new GameConfig(
    mapId: 'happyville/town-square',
    party: $party,
    playerPosition: new Vector2(12, 8),
    playerShape: new Rect(0, 0, 1, 1),
    playerHeading: MovementHeading::SOUTH,
    playerStats: [],
    events: [],
    playerSprite: ['v'],
    playerSprites: [
      'north' => ['^'],
      'east' => ['>'],
      'south' => ['v'],
      'west' => ['<'],
    ],
    playTimeSeconds: 229,
  );
}

function cleanupSaveManagerTestFiles(string $slotPath): void
{
  $saveDirectory = dirname($slotPath);
  $quickSaveDirectory = $saveDirectory . '/quick';

  if (file_exists($slotPath)) {
    unlink($slotPath);
  }

  if (is_dir($quickSaveDirectory)) {
    rmdir($quickSaveDirectory);
  }

  if (is_dir($saveDirectory)) {
    rmdir($saveDirectory);
  }
}

it('loads binary .iedata save files and resolves slot summaries', function () {
  $slug = 'save-manager-' . uniqid();
  $manager = new SaveManager(
    makeSaveManagerTestGame(),
    "./tests/Support/Data/{$slug}",
    "./tests/Support/Data/{$slug}/quick"
  );
  $slotPath = $manager->getSlotPath(2);
  $slot = new SaveSlot(
    slot: 2,
    path: $slotPath,
    isEmpty: false,
    locationName: 'Happyville Town Square',
    leaderName: 'Ralph',
    leaderLevel: 1,
    playTimeSeconds: 229,
    savedAt: 1234567890,
  );
  $payload = serialize([
    'slot' => $slot,
    'config' => makeSaveManagerTestConfig(),
  ]);

  file_put_contents($slotPath, 'IED1' . gzencode($payload, 9));

  $loadedSave = $manager->loadSlot(2);
  $slots = $manager->getSaveSlots(3);

  expect($loadedSave->slot->slot)->toBe(2)
    ->and($loadedSave->slot->locationName)->toBe('Happyville Town Square')
    ->and($loadedSave->config->mapId)->toBe('happyville/town-square')
    ->and($slots[0]->isEmpty)->toBeTrue()
    ->and($slots[1]->isEmpty)->toBeFalse()
    ->and($slots[1]->getLeaderSummary())->toBe('Ralph Lv 1')
    ->and($slots[2]->isEmpty)->toBeTrue();

  cleanupSaveManagerTestFiles($slotPath);
});
