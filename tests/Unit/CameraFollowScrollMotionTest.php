<?php

use Ichiloto\Engine\Animations\Field\FieldSpriteBounds;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;

final class FollowScrollMotionMapManagerProbe extends MapManager
{
  public function __construct(GameScene $scene, int $width, int $height)
  {
    $this->gameScene = $scene;
    $this->mapWidth = $width;
    $this->mapHeight = $height;
  }
}

beforeEach(function () {
  [$width, $height, $columns, $rows] = [60, 40, 10, 8];
  $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  $this->camera = new Camera(makeCameraTestScene(), $columns, $rows,
    worldSpace: array_fill(0, $height, str_repeat('.', $width)));
  new ReflectionProperty(GameScene::class, 'camera')->setValue($scene, $this->camera);
  $this->manager = new FollowScrollMotionMapManagerProbe($scene, $width, $height);
  $this->player = new ReflectionClass(Player::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(Player::class, 'position')->setValue($this->player, new Vector2(20, 20));
});

it('never keeps a retained camera motion id once a following camera scrolls to a new origin', function () {
  $owner = new stdClass();
  $this->camera->frameBounds($owner, new FieldSpriteBounds(30, 24, 34, 28), new FieldSpriteBounds(0, 0, 10, 6), .2);
  $this->camera->attach($this->player);
  $this->camera->clearFraming($owner, .2);
  $released = $this->camera->presentationMotion;
  $releasedOrigin = $this->camera->getWorldOrigin();
  expect($released)->not->toBeNull()->and($this->camera->followsPlayer)->toBeTrue();

  $direction = new Vector2(1, 0);
  $scrolled = false;
  for ($step = 0; $step < 10 && !$scrolled; $step++) {
    $this->player->position->add($direction);
    $scrolled = $this->manager->scrollMap($this->player, $direction);
  }

  expect($scrolled)->toBeTrue()
    ->and($this->camera->getWorldOrigin())->not->toBe($releasedOrigin)
    ->and($this->camera->presentationMotion?->id)->not->toBe($released->id);
});

it('keeps the declared motion while a scroll is clamped at the map edge', function () {
  $owner = new stdClass();
  $this->camera->frameBounds($owner, new FieldSpriteBounds(30, 24, 34, 28), new FieldSpriteBounds(0, 0, 10, 6), .2);
  $this->camera->attach($this->player);
  $this->camera->clearFraming($owner, .2);
  $released = $this->camera->presentationMotion;
  $origin = $this->camera->getWorldOrigin();

  expect($this->camera->scrollTo($this->camera->position->x, $this->camera->position->y))->toBeFalse()
    ->and($this->camera->getWorldOrigin())->toBe($origin)
    ->and($this->camera->presentationMotion?->id)->toBe($released?->id);
});
