<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapKnowledge;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\MapOverview;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\LocalMapState;
use Ichiloto\Engine\Scenes\Game\States\MapState;
use Ichiloto\Engine\Scenes\Interfaces\SceneStateInterface;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

final class LocalAtlasStateProbe extends LocalMapState
{
    public ?SceneStateInterface $next = null;
    public int $redraws = 0;
    public function __construct(private GameScene $scene) {}
    public function getGameScene(): GameScene { return $this->scene; }
    public function setState(SceneStateInterface $state): void { $this->next = $state; }
    protected function refreshUI(): void { $this->redraws++; }
    public function draw(int $width, int $height): array { return $this->buildContent($width, $height); }
    public function select(int $index): void { $this->selected = $index; }
    public function getDetailPage(): int { return $this->detailPage; }
}

function makeLocalAtlasStateProbe(): LocalAtlasStateProbe
{
    $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $manager = new ReflectionClass(MapManager::class)->newInstanceWithoutConstructor();
    $player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
    new ReflectionProperty($player, 'position')->setValue($player, new Vector2(9, 3));
    new ReflectionProperty($player, 'heading')->setValue($player, MovementHeading::WEST);
    $party = new Party();
    $party->location = new PartyLocation('Synthetic harbour', 'Synthetic region');
    $tiles = array_fill(0, 6, array_fill(0, 20, '.'));
    $map = ['events' => [], 'landmarks' => []];
    $state = new GameState();
    for ($index = 0; $index < 20; $index++) {
        $marker = chr(65 + $index);
        $map['events'][] = ['marker' => $marker, 'class' => TransferPlayerTrigger::class,
            'area' => ['cells' => [[$index, 1]]]];
        $map['landmarks'][] = ['id' => 'door-' . $index, 'kind' => 'entrance', 'anchor' => ['event' => $marker],
            'name' => ['message' => 'maps.synthetic.door_' . $index, 'default' => 'Place-'.$index.' '.str_repeat('Readable place name ', 12)]];
        $state->revealLandmark('synthetic', 'door-' . $index);
    }
    new ReflectionProperty($manager, 'overview')->setValue($manager, new MapOverview($tiles));
    new ReflectionProperty($manager, 'mapKnowledge')->setValue($manager, new MapKnowledge('synthetic', MapLandmarkCatalog::readMap($map, $tiles)));
    foreach (['mapManager' => $manager, 'player' => $player, 'party' => $party, 'gameState' => $state,
        'fieldState' => new ReflectionClass(FieldState::class)->newInstanceWithoutConstructor(),
        'regionMapState' => new ReflectionClass(MapState::class)->newInstanceWithoutConstructor()] as $name => $value) {
        new ReflectionProperty($scene, $name)->setValue($scene, $value);
    }
    return new LocalAtlasStateProbe($scene);
}

it('keeps the whole map and focused name within narrow and full Terminal layouts', function (int $width, int $height) {
    $probe = makeLocalAtlasStateProbe();
    $probe->select(19);
    $rows = $probe->draw($width, $height);
    expect($rows)->toHaveCount($height)->and(implode("\n", $rows))->toContain('20/20')->toContain('Readable place name');
    expect(implode("\n",$rows))->toContain('Place-19')->not->toContain('Place-18');
    foreach ($rows as $row) { expect(TerminalText::displayWidth($row))->toBeLessThanOrEqual($width); }
})->with([[133, 32], [78, 20], [40, 16]]);

it('switches map ownership with semantic press edges and preserves the next unread choice', function () {
    $prior = InputManager::getBindings();
    $source = InputManager::getInputSource();
    try {
        InputManager::setBindings(['map' => ['keys' => [KeyCode::m]], 'back' => ['keys' => [KeyCode::ESCAPE]]]);
        $input = new FakeInputSource(KeyCode::v, KeyCode::DOWN, KeyCode::ESCAPE, KeyCode::m);
        InputManager::setInputSource($input);
        $probe = makeLocalAtlasStateProbe();
        InputManager::handleInput();
        $probe->execute();
        expect($probe->next)->toBe($probe->getGameScene()->regionMapState)
            ->and(InputManager::getPressedKeyCode())->toBeNull()->and($input->keys)->toHaveCount(3);
        InputManager::handleInput();
        expect(InputManager::getPressedKeyCode())->toBe(KeyCode::DOWN);
        InputManager::handleInput();
        $probe->execute();
        expect($probe->next)->toBe($probe->getGameScene()->fieldState)
            ->and(InputManager::getPressedKeyCode())->toBeNull()->and($input->keys)->toBe([KeyCode::m]);
    } finally {
        InputManager::setBindings($prior);
        InputManager::setInputSource($source);
    }
});

it('moves the physical cursor using directions and leaves names hidden until focused',function() {
    $prior=InputManager::getBindings(); $source=InputManager::getInputSource();
    try {
        InputManager::setBindings(['right'=>['keys'=>[KeyCode::RIGHT]],'left'=>['keys'=>[KeyCode::LEFT]]]);
        InputManager::setInputSource(new FakeInputSource(KeyCode::RIGHT,KeyCode::LEFT));
        $probe=makeLocalAtlasStateProbe();
        expect(implode('',$probe->draw(80,24)))->toContain('Place-0')->not->toContain('Place-1');
        InputManager::handleInput(); $probe->execute();
        expect(implode('',$probe->draw(80,24)))->toContain('Place-1')->not->toContain('Place-0');
        InputManager::handleInput(); $probe->execute();
        expect(implode('',$probe->draw(80,24)))->toContain('Place-0')->not->toContain('Place-1');
    } finally { InputManager::setBindings($prior); InputManager::setInputSource($source); }
});
