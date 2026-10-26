<?php

use Ichiloto\Engine\Core\{GameState,Vector2};
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Entities\{Party,PartyLocation};
use Ichiloto\Engine\Field\{MapLayer,MapLayerSet,MapManager,MapOverview,Player};
use Ichiloto\Engine\Rendering\Presentation\{PresentationWorld,ScenePresentationContext};
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\{FieldState,LocalMapState};
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Presentation\MapAtlasSize;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\{ConfigStore,ProjectConfig};

it('uses the host map source and replaces only its visible coordinate HUD while field input owns the scene', function () {
    $configs = new ReflectionClass(ConfigStore::class)->getStaticProperties();
    try {
        $config = new class extends ProjectConfig { protected function load(): array { return []; } };
        ConfigStore::put(ProjectConfig::class,$config);
        ConfigStore::put(\Ichiloto\Engine\Util\Config\PlaySettings::class,new \Ichiloto\Engine\Util\Config\PlaySettings());
        $old = $config->get('ui.hud.location');
        $config->set('ui.hud.location',true);
        $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
        $manager = $this->getStubBuilder(MapManager::class)->disableOriginalConstructor()
            ->onlyMethods(['getPresentationWorld','getWorldObjects'])->getStub();
        $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('ground',0,false,'fixture',"....\n....")]),'host-map');
        $manager->method('getPresentationWorld')->willReturn($world);
        $manager->method('getWorldObjects')->willReturn([]);
        new ReflectionProperty(MapManager::class,'overview')->setValue($manager,new MapOverview([['.','.','.','.'],['.','.','.','.']]));
        $player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
        new ReflectionProperty($player,'position')->setValue($player,new Vector2(1,1));
        new ReflectionProperty($player,'heading')->setValue($player,MovementHeading::EAST);
        $party = new Party();
        $party->location = new PartyLocation('Host map','Region');
        $hud = new LocationHUDWindow(new Vector2(1,1),MovementHeading::EAST);
        $hud->activate();
        $ui = $this->getStubBuilder(UIManager::class)->disableOriginalConstructor()->onlyMethods(['getActivePresentations'])->getStub();
        $ui->method('getActivePresentations')->willReturn([]);
        foreach (['mapManager'=>$manager,'player'=>$player,'party'=>$party,'gameState'=>new GameState(),
            'locationHUDWindow'=>$hud,'uiManager'=>$ui] as $name=>$value) {
            new ReflectionProperty($scene,$name)->setValue($scene,$value);
        }
        // Bind the isolated host directly: this test supplies no Game runtime singleton.
        $context = new ScenePresentationContext(new RendererGridConfig(90,30,15,24),fn()=>true,assetRoot:'/tmp');
        new ReflectionProperty($scene,'presentationContext')->setValue($scene,$context);
        $field = new FieldState(new SceneStateContext($scene));
        foreach (['state'=>$field,'fieldState'=>$field] as $name=>$value) { new ReflectionProperty($scene,$name)->setValue($scene,$value); }
        expect($scene->getMapAtlasCanvas(MapAtlasSize::COMPACT)?->getReferencedWorldIds())->toBe(['host-map']);
        expect($field->getPresentationOverlay(1350,720))->not->toBeNull()
            ->and($scene->getExcludedOverlayLayers())->toContain('ui:'.spl_object_id($hud));
        $hud->deactivate();
        expect($field->getPresentationOverlay(1350,720))->toBeNull()
            ->and($scene->getExcludedOverlayLayers())->toContain('ui:'.spl_object_id($hud));
        $hud->activate();
        $config->set('ui.hud.map_size','off');
        expect($field->getPresentationOverlay(1350,720))->toBeNull()
            ->and($scene->getExcludedOverlayLayers())->toContain('ui:'.spl_object_id($hud));
        $config->set('ui.hud.map_size','compact');
        $map = new LocalMapState(new SceneStateContext($scene));
        new ReflectionProperty($scene,'state')->setValue($scene,$map);
        $textPreparation = $this->getStubBuilder(\Ichiloto\Engine\Rendering\Text\TextPreparationProviderInterface::class)->getStub();
        new ReflectionProperty($scene,'presentationContext')->setValue($scene,
            new ScenePresentationContext($context->grid,fn()=>true,fieldActive:false,assetRoot:'/tmp',textPreparation:$textPreparation));
        expect($scene->getTextPreparationProvider())->toBe($textPreparation);
        expect($map->getPresentationCanvas())->not->toBeNull()->and($scene->getPresentationWorld())->toBe($world)
            ->and($field->getPresentationOverlay(1350,720))->toBeNull();
        new ReflectionProperty($scene,'presentationContext')->setValue($scene,
            new ScenePresentationContext($context->grid,fn()=>false,graphical:false,assetRoot:'/tmp'));
        expect($map->getPresentationCanvas())->toBeNull()->and($scene->getPresentationWorld())->toBeNull()
            ->and($scene->getExcludedOverlayLayers())->toBe([]);
        $config->set('ui.hud.location',$old);
    } finally {
        foreach ($configs as $name=>$value) { new ReflectionProperty(ConfigStore::class,$name)->setValue(null,$value); }
    }
});
