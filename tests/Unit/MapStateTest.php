<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\PartyLocation;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\{MapState,LocalMapState,FieldState};
use Ichiloto\Engine\Scenes\Interfaces\SceneStateInterface;
use Ichiloto\Engine\Util\Config\{ConfigStore,PlaySettings};
use Tests\Support\Input\FakeInputSource;

require_once __DIR__.'/../Support/Input/FakeInputSource.php';

final class RegionMapDrawingProbe extends MapState
{
    private GameScene $scene;
    public ?SceneStateInterface $next=null;
    public function __construct(private array $graph)
    {
        $this->selectedId='square';
        $this->scene=new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
        $party=new Party(); $party->location=new PartyLocation('Square','Synthetic region');
        foreach (['party'=>$party,'currentMapId'=>'square','gameState'=>new GameState(),
            'fieldState'=>new ReflectionClass(FieldState::class)->newInstanceWithoutConstructor(),
            'mapState'=>new ReflectionClass(LocalMapState::class)->newInstanceWithoutConstructor()] as $name=>$value) {
            new ReflectionProperty(GameScene::class,$name)->setValue($this->scene,$value);
        }
    }
    public function getGameScene(): GameScene { return $this->scene; }
    protected function getKnownGraph(): array { return $this->graph; }
    public function setState(SceneStateInterface $state): void { $this->next=$state; }
    public function draw(int $width,int $height): array { return $this->buildMapContent($width,$height); }
    public function getDetails(int $width): array { return $this->buildInfoContent($width); }
    public function moveCursor(int $x,int $y): bool { return $this->moveFocus($x,$y); }
    public function select(string $id): void { $this->selectedId=$id; }
    public function setDetailPage(int $page): void { $this->detailPage=$page; }
    public function showPanels(): array { $this->refreshUI(); return [$this->mapPanel,$this->infoPanel]; }
}

function makeRegionDiagramGraph(): array
{
    return ['places'=>[
        'square'=>['position'=>[1000000,1000000],'name'=>'Square','visited'=>true],
        'home'=>['position'=>[0,0],'name'=>'House','visited'=>true],
        'shop'=>['position'=>[1000000,2000000],'name'=>'Shop','visited'=>true],
        'inn'=>['position'=>[2000000,2000000],'name'=>null,'visited'=>false],
    ],'links'=>[
        ['from'=>'square','to'=>'home'],['from'=>'home','to'=>'square'],
        ['from'=>'square','to'=>'shop'],['from'=>'shop','to'=>'square'],
        ['from'=>'square','to'=>'inn'],
    ]];
}

it('replaces large name boxes and empty station gaps with single-cell connected places',function(int $width,int $height) {
    $probe=new RegionMapDrawingProbe(makeRegionDiagramGraph());
    $rows=$probe->draw($width,$height);
    $plain=TerminalText::stripAnsi(implode("\n",$rows));
    expect($rows)->toHaveCount($height)->and(substr_count($plain,'■'))->toBe(4)
        ->and($plain)->not->toContain('Square')->not->toContain('House')->not->toContain('Shop')
        ->and($plain)->toContain('─')->toContain('│');
    foreach($rows as $row) { expect(TerminalText::displayWidth($row))->toBe($width); }
    expect(implode('',$probe->getDetails($width)))->toContain('Square')->toContain('You are here')->not->toContain('House');
})->with([[133,32],[78,20],[40,16],[36,8]]);

it('moves focus spatially and shows only the focused name including unnamed known routes',function() {
    $probe=new RegionMapDrawingProbe(makeRegionDiagramGraph());
    expect($probe->moveCursor(0,-1))->toBeTrue()
        ->and(implode('',$probe->getDetails(80)))->toContain('House')->not->toContain('Square');
    expect($probe->moveCursor(1,0))->toBeTrue()
        ->and(implode('',$probe->getDetails(80)))->toContain('Square');
    $probe->select('inn');
    expect(implode('',$probe->getDetails(80)))->toContain('Unknown location')->not->toContain('House');
    expect($probe->moveCursor(1,0))->toBeFalse();
});

it('pages the full focused name instead of leaking other location names into the map',function() {
    $graph=makeRegionDiagramGraph();
    $graph['places']['square']['name']=str_repeat('Long readable location ',12).'Finalword';
    $probe=new RegionMapDrawingProbe($graph);
    $all='';
    for($page=0;$page<12;$page++) {
        $probe->setDetailPage($page);
        foreach($probe->getDetails(24) as $line) { expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual(24); $all.=$line; }
    }
    expect($all)->toContain('Finalword')->not->toContain('House')->not->toContain('Shop');
});

it('keeps panels within the Terminal and uses semantic input without consuming the next press',function() {
    $old=ConfigStore::has(PlaySettings::class)?ConfigStore::get(PlaySettings::class):null;
    $snapshots=[];
    foreach([Console::class,InputManager::class] as $class) { $snapshots[$class]=new ReflectionClass($class)->getStaticProperties(); }
    ConfigStore::put(PlaySettings::class,new PlaySettings(['width'=>80,'height'=>24]));
    foreach(['width'=>80,'height'=>24,'buffer'=>[],'frameDepth'=>0,'frameRows'=>[],'terminalHandedBack'=>false] as $name=>$value) {
        new ReflectionProperty(Console::class,$name)->setValue(null,$value);
    }
    ob_start();
    try {
        $probe=new RegionMapDrawingProbe(makeRegionDiagramGraph());
        [$map,$info]=$probe->showPanels();
        foreach([$map,$info] as $panel) {
            expect($panel->getPosition()->x+$panel->getWidth())->toBeLessThanOrEqual(80)
                ->and($panel->getPosition()->y+$panel->getHeight())->toBeLessThanOrEqual(24);
        }
        InputManager::setBindings(['right'=>['keys'=>[KeyCode::RIGHT]],'back'=>['keys'=>[KeyCode::ESCAPE]]]);
        $keys=new FakeInputSource(KeyCode::RIGHT,KeyCode::v,KeyCode::ESCAPE);
        InputManager::setInputSource($keys);
        InputManager::handleInput(); $probe->execute();
        expect(implode('',$probe->getDetails(80)))->toContain('Unknown location');
        InputManager::handleInput(); $probe->execute();
        expect($probe->next)->toBe($probe->getGameScene()->mapState)
            ->and(InputManager::getPressedKeyCode())->toBeNull()->and($keys->keys)->toBe([KeyCode::ESCAPE]);
    } finally {
        ob_end_clean();
        foreach($snapshots as $class=>$properties) {
            foreach($properties as $name=>$value) { new ReflectionProperty($class,$name)->setValue(null,$value); }
        }
        $old===null?ConfigStore::remove(PlaySettings::class):ConfigStore::put(PlaySettings::class,$old);
    }
});
