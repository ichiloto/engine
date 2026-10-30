<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\Interpreter\Commands\CommitNpcPlacementCommand;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Field\NpcPlacementSet;
use Ichiloto\Engine\Field\Validation\MapNpcPlacementRule;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Tests\Support\PassiveGame;

final class NamedNpcPlacementMap extends MapManager
{
    public bool $blocked = false;
    public function __construct() {}
    public function getCollision(int $x, int $y): CollisionType { return $this->blocked ? CollisionType::SOLID : CollisionType::NONE; }
}

final class NamedNpcPlacementScene extends GameScene
{
    private PassiveGame $fixtureGame;
    public function __construct()
    {
        $this->fixtureGame = new PassiveGame();
        $this->gameState = new GameState(); $this->party = new Party(); $this->currentMapId = 'synthetic/arrival';
        $this->mapManager = new NamedNpcPlacementMap(); $this->npcManager = new NpcManager($this);
        $this->cinematicStage = new CinematicStageManager($this);
    }
    public function getGame(): Game { return $this->fixtureGame; }
    public function onEventSessionStarted(\Ichiloto\Engine\Events\Interpreter\EventExecutionSession $session): void {}
    public function onEventSessionFinished(\Ichiloto\Engine\Events\Interpreter\EventExecutionSession $session, bool $completed): void {}
}

function namedPlacementEntry(): array
{
    return ['id' => 'guide', 'name' => 'Guide', 'x' => 1, 'y' => 2, 'facing' => 'south',
        'placements' => ['arrived' => ['x' => 4, 'y' => 3, 'facing' => 'west']]];
}

it('commits the real arrived subject and reloads its stable destination using current source geometry', function () {
    $scene = new NamedNpcPlacementScene(); $entry = namedPlacementEntry();
    $scene->npcManager->applyPreparedNpcs($scene->npcManager->prepareNpcs([$entry], $scene->currentMapId));
    $npc = $scene->npcManager->findById('guide');
    expect([(int)$npc->position->x, (int)$npc->position->y])->toBe([1, 2]);
    $scene->cinematicStage->captureSubject($npc);
    $npc->restoreFieldTransform(new Vector2(4, 3), MovementHeading::NORTH, $npc->sprite);
    (new CommitNpcPlacementCommand())->execute(new ScriptCommandContext($scene), ['npc' => 'guide', 'placement' => 'arrived']);
    $scene->cinematicStage->restoreSubjectTransforms();
    expect([(int)$npc->position->x, (int)$npc->position->y])->toBe([4, 3])->and($npc->heading)->toBe(MovementHeading::WEST)
        ->and($npc->placementState->selection)->toBe('arrived');
    $saved = GameState::fromArray($scene->gameState->toArray());
    $restored = new NamedNpcPlacementScene(); new ReflectionProperty(GameScene::class, 'gameState')->setValue($restored, $saved);
    $entry['placements']['arrived']['x'] = 7;
    $prepared = $restored->npcManager->prepareNpcs([$entry], $restored->currentMapId);
    expect([(int)$prepared[0]->position->x, (int)$prepared[0]->position->y])->toBe([7, 3])
        ->and($prepared[0]->heading)->toBe(MovementHeading::WEST)
        ->and($saved->getVariable(NpcPlacementSet::getStateKey($scene->currentMapId, 'guide')))->toBe('arrived')
        ->and($scene->npcManager->findById('guide'))->toBe($npc);
});

it('leaves saved placement unchanged on interrupted routes, blocked cells, invalid names or wrong map ownership', function () {
    $scene = new NamedNpcPlacementScene(); $entry = namedPlacementEntry();
    $scene->npcManager->applyPreparedNpcs($scene->npcManager->prepareNpcs([$entry], $scene->currentMapId));
    $npc = $scene->npcManager->findById('guide'); $before = $scene->gameState->toArray();
    $scene->cinematicStage->captureSubject($npc); $npc->restoreFieldTransform(new Vector2(2, 2), $npc->heading, $npc->sprite);
    expect(fn() => $npc->placementState->commitPlacement($scene, 'arrived'))->toThrow(RuntimeException::class, 'reach');
    $scene->cinematicStage->restoreSubjectTransforms();
    expect([(int)$npc->position->x, (int)$npc->position->y])->toBe([1, 2])->and($scene->gameState->toArray())->toBe($before);
    $npc->restoreFieldTransform(new Vector2(4, 3), $npc->heading, $npc->sprite); $scene->mapManager->blocked = true;
    expect(fn() => $npc->placementState->commitPlacement($scene, 'arrived'))->toThrow(RuntimeException::class, 'blocks');
    $scene->mapManager->blocked = false;
    expect(fn() => $npc->placementState->commitPlacement($scene, 'unknown'))->toThrow(RuntimeException::class, 'declared');
    new ReflectionProperty(GameScene::class, 'currentMapId')->setValue($scene, 'synthetic/other');
    expect(fn() => $npc->placementState->commitPlacement($scene, 'arrived'))->toThrow(RuntimeException::class, 'same current')
        ->and($scene->gameState->toArray())->toBe($before);
});

it('refuses another real occupant and exposes missing saved identities without reverting to an invented position', function () {
    $scene = new NamedNpcPlacementScene(); $entry = namedPlacementEntry();
    $other = ['id' => 'other', 'name' => 'Other', 'x' => 4, 'y' => 3];
    $scene->npcManager->applyPreparedNpcs($scene->npcManager->prepareNpcs([$entry, $other], $scene->currentMapId));
    $npc = $scene->npcManager->findById('guide'); $npc->restoreFieldTransform(new Vector2(4, 3), $npc->heading, $npc->sprite);
    expect(fn() => $npc->placementState->commitPlacement($scene, 'arrived'))->toThrow(RuntimeException::class, 'occupied');
    $scene->gameState->setVariable(NpcPlacementSet::getStateKey($scene->currentMapId, 'guide'), 'removed-name');
    expect(fn() => $scene->npcManager->prepareNpcs([$entry], $scene->currentMapId))->toThrow(InvalidArgumentException::class, 'migrate')
        ->and($scene->npcManager->findById('guide'))->toBe($npc);
});

it('preflights every named destination against physical floor and preserves ordinary source placement behavior', function () {
    $entry = namedPlacementEntry(); $grid = array_fill(0, 6, array_fill(0, 8, CollisionType::NONE->value));
    expect(MapNpcPlacementRule::inspect('synthetic', ['npcs' => [$entry]], $grid))->toBe([])
        ->and(NpcPlacement::fromArray($entry)->isConditional)->toBeTrue()
        ->and(NpcPlacement::fromArray(['name' => 'Ordinary', 'x' => 1, 'y' => 1])->isConditional)->toBeFalse();
    $grid[3][4] = CollisionType::SOLID->value;
    $issues = MapNpcPlacementRule::inspect('synthetic', ['npcs' => [$entry]], $grid);
    expect($issues)->toHaveCount(1)->and($issues[0]->rule)->toBe(MapNpcPlacementRule::RULE)
        ->and($issues[0]->message)->toContain('arrived');
    foreach ([['arrived' => ['x' => -1, 'y' => 0]], ['default' => ['x' => 0, 'y' => 0]],
        ['arrived' => ['x' => 1, 'y' => 1, 'facing' => 'None']], ['arrived' => ['x' => 1.5, 'y' => 1]]] as $bad) {
        $entry['placements'] = $bad;
        expect(fn() => NpcPlacementSet::fromEntry($entry))->toThrow(InvalidArgumentException::class);
    }
});


it('uses the registered command in the real continuation and rejects a competing movement owner before committing', function () {
    $scene = new NamedNpcPlacementScene();
    $scene->npcManager->applyPreparedNpcs($scene->npcManager->prepareNpcs([namedPlacementEntry()], $scene->currentMapId));
    $npc = $scene->npcManager->findById('guide');
    $npc->restoreFieldTransform(new Vector2(4, 3), $npc->heading, $npc->sprite);
    $interpreter = new \Ichiloto\Engine\Events\Interpreter\EventInterpreter($scene);
    new ReflectionProperty(GameScene::class, 'eventInterpreter')->setValue($scene, $interpreter);
    $session = $interpreter->start([['type' => 'commit_npc_placement', 'npc' => 'guide', 'placement' => 'arrived'],
        ['type' => 'set_switch', 'name' => 'after']]);
    $session->claimMovementSubject($npc);
    $interpreter->update(0);
    expect($session->status)->toBe(\Ichiloto\Engine\Events\Interpreter\EventExecutionStatus::FAILED)
        ->and($scene->gameState->getSwitch('after'))->toBeFalse()->and($npc->placementState->selection)->toBe('default');
    $session->releaseMovementSubject($npc);
    $session = $interpreter->run([['type' => 'commit_npc_placement', 'npc' => 'guide', 'placement' => 'arrived'],
        ['type' => 'set_switch', 'name' => 'after']]);
    expect($session->status)->toBe(\Ichiloto\Engine\Events\Interpreter\EventExecutionStatus::COMPLETED)
        ->and($scene->gameState->getSwitch('after'))->toBeTrue()->and($npc->placementState->selection)->toBe('arrived');
});


it('requires an explicit migration when source removes a saved named-placement catalog', function () {
    $state = new GameState(); $entry = namedPlacementEntry();
    $state->setVariable(NpcPlacementSet::getStateKey('map', 'guide'), 'arrived');
    unset($entry['placements']);
    expect(fn() => NpcPlacement::fromArray($entry, $state, 'map'))->toThrow(InvalidArgumentException::class, 'migrate');
    $state->setVariable(NpcPlacementSet::getStateKey('map', 'guide'), NpcPlacementSet::DEFAULT);
    expect(NpcPlacement::fromArray($entry, $state, 'map')->x)->toBe(1);
});
