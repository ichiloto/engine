<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\Spellbook;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\MagicMenuState;
use Ichiloto\Engine\Scenes\SceneStateContext;

it('opens one-ally target selection before spending MP or applying effects', function () {
  [$state, $caster, $ally, $spell] = makeMagicMenuTargetingState();
  $startingMp = $caster->stats->currentMp;

  invokeMagicMenuMethod($state, 'useSelectedSpell');

  expect(readMagicMenuProperty($state, 'pendingUseSpell'))->toBe($spell)
    ->and(readMagicMenuProperty($state, 'targetCandidates'))->toBe([$caster, $ally])
    ->and(readMagicMenuProperty($state, 'activeTargetIndex'))->toBe(0)
    ->and($caster->stats->currentMp)->toBe($startingMp)
    ->and($ally->stats->currentHp)->toBe(30)
    ->and(invokeMagicMenuMethod($state, 'getListPanelTitle'))->toBe('Choose Target')
    ->and(invokeMagicMenuMethod($state, 'getInfoHelpText'))->toBe('enter:Cast  c:Back');
});

it('commits the selected target through the field-skill executor', function () {
  [$state, $caster, $ally, $spell] = makeMagicMenuTargetingState();
  invokeMagicMenuMethod($state, 'useSelectedSpell');

  $result = invokeMagicMenuMethod($state, 'commitFieldSpell', [$spell, $ally]);

  expect($result->succeeded)->toBeTrue()
    ->and($caster->stats->currentMp)->toBe(17)
    ->and($ally->stats->currentHp)->toBe(55)
    ->and(readMagicMenuProperty($state, 'statusMessage'))->toBe('Liora cast Cure on Kaelion.');
});

it('cancels target selection without changing party resources', function () {
  [$state, $caster, $ally] = makeMagicMenuTargetingState();
  $startingMp = $caster->stats->currentMp;
  invokeMagicMenuMethod($state, 'useSelectedSpell');

  invokeMagicMenuMethod($state, 'cancelTargetSelection');

  expect(readMagicMenuProperty($state, 'pendingUseSpell'))->toBeNull()
    ->and(readMagicMenuProperty($state, 'targetCandidates'))->toBe([])
    ->and($caster->stats->currentMp)->toBe($startingMp)
    ->and($ally->stats->currentHp)->toBe(30);
});

/**
 * @return array{MagicMenuState, Character, Character, MagicSkill}
 */
function makeMagicMenuTargetingState(): array
{
  $spell = new MagicSkill(
    'Cure',
    'Restores one ally.',
    '+',
    3,
    0,
    new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ONE),
    Occasion::ALWAYS,
    effects: [new HPRecoverSkillEffect('25', variance: 0.0)],
  );
  $caster = new Character('Liora', 0, new Stats(), spellbook: new Spellbook([$spell]));
  $ally = new Character('Kaelion', 0, new Stats());

  foreach ([$caster, $ally] as $character) {
    $character->stats->totalHp = 100;
    $character->stats->totalMp = 20;
  }

  $caster->stats->currentHp = 80;
  $caster->stats->currentMp = 20;
  $ally->stats->currentHp = 30;
  $ally->stats->currentMp = 10;

  $party = new Party();
  $party->addMember($caster);
  $party->addMember($ally);
  $scene = (new ReflectionClass(GameScene::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty(GameScene::class, 'party'))->setValue($scene, $party);
  $state = new MagicMenuState(new SceneStateContext($scene));
  $state->character = $caster;

  return [$state, $caster, $ally, $spell];
}

function invokeMagicMenuMethod(MagicMenuState $state, string $method, array $arguments = []): mixed
{
  return (new ReflectionMethod(MagicMenuState::class, $method))->invokeArgs($state, $arguments);
}

function readMagicMenuProperty(MagicMenuState $state, string $property): mixed
{
  return (new ReflectionProperty(MagicMenuState::class, $property))->getValue($state);
}
