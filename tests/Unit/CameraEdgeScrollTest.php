<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;

final class EdgeScrollMapManagerProbe extends MapManager
{
    public function __construct(GameScene $scene, int $width, int $height)
    {
        $this->gameScene = $scene;
        $this->mapWidth = $width;
        $this->mapHeight = $height;
    }
}

/**
 * Walks a player one step at a time and returns the camera origin after each step, as the field scrolls it.
 *
 * @return list<array{x: int, y: int}>
 */
function walkEdgeScrollPlayer(MapManager $manager, Player $player, Vector2 $direction, int $steps): array
{
    $origins = [];
    for ($step = 0; $step < $steps; $step++) {
        $player->position->add($direction);
        $manager->scrollMap($player, $direction);
        $origins[] = test()->camera->getWorldOrigin();
    }
    return $origins;
}

beforeEach(function () {
    // A map larger than the view on both axes, so every edge is reached by scrolling.
    [$this->mapWidth, $this->mapHeight, $columns, $rows] = [21, 15, 8, 5];
    $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    $this->camera = new Camera(makeCameraTestScene(), $columns, $rows);
    $this->camera->worldSpace = array_fill(0, $this->mapHeight, str_repeat('.', $this->mapWidth));
    new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $this->camera);
    $this->manager = new EdgeScrollMapManagerProbe($scene, $this->mapWidth, $this->mapHeight);
    $this->player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Player::class, 'position')->setValue($this->player, new Vector2(10, 7));
    $this->camera->resetPosition($this->player);
});

it('scrolls each step to the same origin a placed player would have, through the map edge rows and columns', function (
    Vector2 $direction, int $steps
) {
    $origins = walkEdgeScrollPlayer($this->manager, $this->player, $direction, $steps);
    $placed = $this->camera->positionForFocus($this->player->position);

    expect(end($origins))->toBe(['x' => (int)$placed->x, 'y' => (int)$placed->y]);
    // The edge the player walked toward is on screen.
    $origin = end($origins);
    match (true) {
        $direction->x < 0 => expect($origin['x'])->toBe(0),
        $direction->x > 0 => expect($origin['x'])->toBe($this->mapWidth - $this->camera->screen->getWidth()),
        $direction->y < 0 => expect($origin['y'])->toBe(0),
        default => expect($origin['y'])->toBe($this->mapHeight - $this->camera->screen->getHeight()),
    };
})->with([
    'west' => [new Vector2(-1, 0), 10],
    'east' => [new Vector2(1, 0), 10],
    'north' => [new Vector2(0, -1), 7],
    'south' => [new Vector2(0, 1), 7],
]);

it('keeps following a player walking back from an edge and reports no scroll while the camera holds at it', function () {
    walkEdgeScrollPlayer($this->manager, $this->player, new Vector2(-1, 0), 10);
    $held = $this->camera->getWorldOrigin();
    $this->player->position->add(new Vector2(1, 0));
    expect($this->manager->scrollMap($this->player, new Vector2(1, 0)))->toBeFalse()
        ->and($this->camera->getWorldOrigin())->toBe($held);

    walkEdgeScrollPlayer($this->manager, $this->player, new Vector2(1, 0), 4);
    $placed = $this->camera->positionForFocus($this->player->position);
    expect($this->camera->getWorldOrigin())->toBe(['x' => (int)$placed->x, 'y' => (int)$placed->y]);
});

it('reports no camera movement when a northward walk is already at the top edge', function () {
    walkEdgeScrollPlayer($this->manager, $this->player, new Vector2(0, -1), 6);
    expect($this->camera->getWorldOrigin()['y'])->toBe(0);
    $this->player->position->add(new Vector2(0, -1));
    expect($this->manager->scrollMap($this->player, new Vector2(0, -1)))->toBeFalse()
        ->and($this->camera->getWorldOrigin()['y'])->toBe(0);
});
