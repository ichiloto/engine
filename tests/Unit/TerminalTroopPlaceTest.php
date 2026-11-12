<?php

use Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects;
use Ichiloto\Engine\Core\Vector2;

/** A synthetic sprite of a width and height, in cells. */
function troopSprite(int $width, int $height): array
{
  return array_fill(0, $height, str_repeat('#', $width));
}

it('stands a first member in the middle of the troop zone, inside the field and clear of the party', function () {
  $field = new TerminalBattleEffects();
  $place = $field->findTroopPlace([], troopSprite(10, 4));

  // The zone runs from column 3 to the party's side, less the side gap, the enemy step and the sprite (3 to 83), rows 2 to 25.
  expect($place)->toEqual(new Vector2(43, 13))
    ->and($field->getTroopAvailableWidth($place))->toBeGreaterThanOrEqual(10);
});

it('stands each further member beside those there, never on them, and least on them when the zone is full', function () {
  $field = new TerminalBattleEffects();
  $sprite = troopSprite(10, 4);
  $members = [];
  foreach (range(1, 6) as $ignored) {
    $place = $field->findTroopPlace($members, $sprite);
    foreach ($members as $member) {
      // A cell clear around every sprite and its name row.
      $apart = $place->x + 10 < $member['position']->x || $member['position']->x + 10 < $place->x
        || $place->y + 4 < $member['position']->y - 1 || $member['position']->y + 4 < $place->y - 1;
      expect($apart)->toBeTrue();
    }
    $members[] = ['position' => $place, 'sprite' => $sprite];
  }
  // A sprite as large as the zone overlaps; it still stands inside the field.
  $giant = $field->findTroopPlace($members, troopSprite(80, 20));
  expect($giant->x)->toBeGreaterThanOrEqual(3)->and($giant->y)->toBeGreaterThanOrEqual(2)->and($giant->y + 20)->toBeLessThanOrEqual(29);
});

it('names what an authored place leaves out, and finds nothing wrong with a place it chose itself', function () {
  $field = new TerminalBattleEffects();
  $sprite = troopSprite(10, 4);
  expect($field->getTroopPlaceProblems($field->findTroopPlace([], $sprite), $sprite))->toBe([])
    ->and($field->getTroopPlaceProblems(new Vector2(3, 2), $sprite))->toBe([])
    ->and($field->getTroopPlaceProblems(new Vector2(3, 1), $sprite))->toBe(['leaves no room above it for its target badge'])
    ->and($field->getTroopPlaceProblems(new Vector2(2, 2), $sprite))->toBe(['leaves no room beside it for the focus marker'])
    ->and($field->getTroopPlaceProblems(new Vector2(84, 2), $sprite))->toBe(['reaches the party\'s side when it steps forward'])
    ->and($field->getTroopPlaceProblems(new Vector2(3, 26), $sprite))->toBe(['crosses the bottom of the battlefield']);
});
