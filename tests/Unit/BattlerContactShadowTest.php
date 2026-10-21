<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlerContactShadow;
use Ichiloto\Engine\Battle\Presentation\BattlerGrounding;
use Ichiloto\Engine\Battle\Presentation\BattlerShadowSource;
use Ichiloto\Engine\Battle\Presentation\BattlerShadowStyle;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\BattleShadowLight;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

/**
 * Contact shadows lie under battlers' feet on the arena's ground in the
 * theme's style and the arena's light; hovering bodies rise over their feet.
 * Synthetic values only.
 */

function shadowLayout(?BattlerShadowStyle $shadow = null, float $airHover = 0.0): BattleCanvasLayout
{
  return new BattleCanvasLayout(1350, 720, shadow: $shadow ?? new BattlerShadowStyle(PresentationColor::rgb(10, 10, 20),
    opacity: .5, softness: .5, width: 1.0, standing: .5, flatness: .25, hoverFade: 1.0), airHover: $airHover);
}

function shadowArena(?BattleShadowLight $light = null): BattleArenaDefinition
{
  return new BattleArenaDefinition('Field', new CanvasImage('field', 'field.png', new CanvasRectangle(0, 0, 1350, 720)),
    shadowLight: $light);
}

it('lies a soft shadow under the feet, sized by the footprint, in the theme\'s colour and strength', function () {
  $standing = BattlerContactShadow::compose('shadow', 500, 560, 200, false, 0, shadowLayout(), shadowArena(), null);
  $lying = BattlerContactShadow::compose('shadow', 500, 560, 200, true, 0, shadowLayout(), shadowArena(), null);
  $brush = $standing->operations[0]->data['brush'];
  // A standing body's shadow spans half its height; a lying body's its whole length. Both are centred on the feet.
  expect([$standing->width, $standing->height])->toBe([100, 25])
    ->and($standing->destination->toArray())->toBe(['x' => 450.0, 'y' => 547.5, 'width' => 100.0, 'height' => 25.0])
    ->and($lying->width)->toBe(200)
    ->and($standing->opacity)->toBe(.5)->and($standing->layer)->toBeLessThan(100)
    ->and($brush['type'])->toBe('radial')
    ->and(array_column($brush['stops'], 'opacity'))->toBe([1.0, 1.0, 0.0])
    ->and(array_column($brush['stops'], 'offset'))->toBe([0.0, .5, 1.0])
    ->and($brush['stops'][0]['color'])->toBe(['kind' => 'rgb', 'r' => 10, 'g' => 10, 'b' => 20]);
});

it('darkens or tints the shadow by the arena\'s light, and fades it with the battler', function () {
  $mine = BattlerContactShadow::compose('shadow', 500, 560, 200, false, 0, shadowLayout(),
    shadowArena(BattleShadowLight::fromArray(['strength' => 1.6, 'color' => '#201008'], 'arena')), null);
  $fading = BattlerContactShadow::compose('shadow', 500, 560, 200, false, 0, shadowLayout(), shadowArena(), null, .4);
  expect($mine->opacity)->toEqualWithDelta(.8, .000001)
    ->and($mine->operations[0]->data['brush']['stops'][0]['color'])->toBe(['kind' => 'rgb', 'r' => 32, 'g' => 16, 'b' => 8])
    ->and($fading->opacity)->toEqualWithDelta(.2, .000001)
    ->and(BattleShadowLight::fromArray(['strength' => 1.6, 'color' => '#201008'], 'arena')->toArray())
    ->toBe(['strength' => 1.6, 'color' => '#201008'])
    ->and(fn() => BattleShadowLight::fromArray(['color' => 'brown'], 'arenas.a.shadow'))->toThrow(InvalidArgumentException::class, 'arenas.a.shadow.color')
    ->and(fn() => BattleShadowLight::fromArray(['strength' => 9], 'arenas.a.shadow'))->toThrow(InvalidArgumentException::class, 'within 0..4');
});

it('draws no shadow where the theme draws none, the artwork paints its own or the battler opts out', function (?BattlerShadowStyle $style, ?BattlerGrounding $grounding) {
  $layout = $style === null ? new BattleCanvasLayout(1350, 720) : shadowLayout($style);
  expect(BattlerContactShadow::compose('shadow', 500, 560, 200, false, 0, $layout, shadowArena(), $grounding))->toBeNull();
})->with([
  'no theme shadow' => [null, null],
  'baked into the artwork' => [new BattlerShadowStyle(PresentationColor::rgb(0, 0, 0)), new BattlerGrounding(shadow: BattlerShadowSource::ARTWORK)],
  'opted out' => [new BattlerShadowStyle(PresentationColor::rgb(0, 0, 0)), new BattlerGrounding(shadow: BattlerShadowSource::NONE)],
]);

it('lifts a hovering body over its feet and shrinks and fades its shadow with the height', function () {
  $layout = shadowLayout(airHover: .5);
  $slot = new BattlerSlot(500, 560, 100, 100, 1.2);
  $flier = BattlerContactShadow::getHover(null, EnemyHabitat::AIR, $layout);
  $grounded = BattlerContactShadow::compose('shadow', 500, 560, 200, true, 0, $layout, shadowArena(), null);
  $hovering = BattlerContactShadow::compose('shadow', 500, 560, 200, true, $flier, $layout, shadowArena(), null);
  expect($flier)->toBe(.5)
    ->and(BattlerContactShadow::getHover(null, EnemyHabitat::LAND, $layout))->toBe(0.0)
    ->and(BattlerContactShadow::getHover(new BattlerGrounding(hover: .2), EnemyHabitat::AIR, $layout))->toBe(.2)
    // A knocked-out flier lies on the ground.
    ->and(BattlerContactShadow::getHover(null, EnemyHabitat::AIR, $layout, knockedOut: true))->toBe(0.0)
    // In reference heights, at the slot's perspective; without a reference, by the slot's own height.
    ->and(BattlerContactShadow::getLift(.5, $slot, new \Ichiloto\Engine\Battle\Presentation\BattleScale('Hero', 150,
      ['Hero' => new \Ichiloto\Engine\Battle\Presentation\BattlerScale(1, 1)])))->toEqualWithDelta(90.0, .000001)
    ->and(BattlerContactShadow::getLift(.5, $slot, null))->toEqualWithDelta(60.0, .000001)
    ->and($hovering->width)->toBeLessThan($grounded->width)->and($hovering->opacity)->toBeLessThan($grounded->opacity)
    // The shadow stays on the ground at the feet however high the body rises.
    ->and($hovering->destination->y + $hovering->destination->height / 2)->toEqualWithDelta(560.0, .5);
});

it('reads a battler\'s grounding and an arena\'s shadow light as data, refusing what it cannot read by its path', function () {
  expect(BattlerGrounding::fromArray(['hover' => .4, 'shadow' => 'artwork', 'shadowWidth' => 1.2], 'enemies.Bat.grounding')->toArray())
    ->toBe(['hover' => .4, 'shadow' => 'artwork', 'shadowWidth' => 1.2])
    ->and(BattlerGrounding::fromArray([], 'g')->shadow)->toBe(BattlerShadowSource::ENGINE)
    ->and(fn() => BattlerGrounding::fromArray(['shadow' => 'painted'], 'enemies.Bat.grounding'))
    ->toThrow(InvalidArgumentException::class, 'enemies.Bat.grounding.shadow: expected one of engine, artwork, none')
    ->and(fn() => BattlerGrounding::fromArray(['hover' => 9], 'enemies.Bat.grounding'))
    ->toThrow(InvalidArgumentException::class, 'enemies.Bat.grounding: A battler hovers between 0 and 4')
    ->and(BattleArenaBindings::getFromArray(['arenas' => ['arena.mine' => ['name' => 'Mine', 'background' => ['image' => 'mine.png'],
      'shadow' => ['strength' => 1.4]]]])->getDefinitions(1350, 720)['arena.mine']->shadowLight->strength)->toBe(1.4)
    ->and(fn() => BattleArenaBindings::getFromArray(['arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png'],
      'shadow' => ['tint' => 1]]]]))->toThrow(InvalidArgumentException::class, 'arenas.a.shadow: expected strength and color only');
});
