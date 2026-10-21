<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleContactEffects;
use Ichiloto\Engine\Battle\Presentation\BattlerContact;
use Ichiloto\Engine\Battle\Presentation\BattlerGroundContact;
use Ichiloto\Engine\Battle\Presentation\BattlerGrounding;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;
use Ichiloto\Engine\Battle\Presentation\BattlerShadowStyle;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Battle\Presentation\BattleScale;
use Ichiloto\Engine\Battle\Presentation\BattlerScale;
use Ichiloto\Engine\Battle\Presentation\BattleShadowLight;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use function Tests\Support\Rendering\writeTestPng;

/**
 * Where battlers meet the surface: shadows under their feet in the theme's
 * style and the arena's light, bodies rising or sinking by their elevation,
 * ripples and burrows where a sunk body breaks the surface. Synthetic values only.
 */

function contactLayout(?BattlerShadowStyle $shadow = null, float $airElevation = 0.0,
  BattleContactEffects $effects = new BattleContactEffects()): BattleCanvasLayout
{
  return new BattleCanvasLayout(1350, 720, shadow: $shadow ?? new BattlerShadowStyle(PresentationColor::rgb(10, 10, 20),
    opacity: .5, softness: .5, width: 1.0, standing: .5, flatness: .25, hoverFade: 1.0), airElevation: $airElevation,
    contactEffects: $effects);
}

function contactArena(?BattleShadowLight $light = null): BattleArenaDefinition
{
  return new BattleArenaDefinition('Field', new CanvasImage('field', 'field.png', new CanvasRectangle(0, 0, 1350, 720)),
    shadowLight: $light);
}

/** @return array{images: list, composites: list} */
function contactAt(BattlerContact $contact, float $elevation = 0, bool $horizontal = false, ?BattleCanvasLayout $layout = null,
  ?BattleArenaDefinition $arena = null, ?BattlerGrounding $grounding = null, float $opacity = 1.0, string $root = ''): array
{
  return BattlerGroundContact::compose('contact', 500, 560, 200, $horizontal, 300, $elevation, $contact, $layout ?? contactLayout(),
    $arena ?? contactArena(), $grounding, $root, opacity: $opacity);
}

it('lies a soft shadow under the feet, sized by the footprint, in the theme\'s colour and strength', function () {
  $standing = contactAt(BattlerContact::SHADOW)['composites'][0];
  $lying = contactAt(BattlerContact::SHADOW, horizontal: true)['composites'][0];
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
  $mine = contactAt(BattlerContact::SHADOW, arena: contactArena(BattleShadowLight::fromArray(['strength' => 1.6, 'color' => '#201008'], 'arena')))['composites'][0];
  $fading = contactAt(BattlerContact::SHADOW, opacity: .4)['composites'][0];
  expect($mine->opacity)->toEqualWithDelta(.8, .000001)
    ->and($mine->operations[0]->data['brush']['stops'][0]['color'])->toBe(['kind' => 'rgb', 'r' => 32, 'g' => 16, 'b' => 8])
    ->and($fading->opacity)->toEqualWithDelta(.2, .000001)
    ->and(BattleShadowLight::fromArray(['strength' => 1.6, 'color' => '#201008'], 'arena')->toArray())->toBe(['strength' => 1.6, 'color' => '#201008'])
    ->and(fn() => BattleShadowLight::fromArray(['color' => 'brown'], 'arenas.a.shadow'))->toThrow(InvalidArgumentException::class, 'arenas.a.shadow.color')
    ->and(fn() => BattleShadowLight::fromArray(['strength' => 9], 'arenas.a.shadow'))->toThrow(InvalidArgumentException::class, 'within 0..4');
});

it('draws nothing where the theme draws no shadow or the battler meets the surface with nothing', function () {
  expect(contactAt(BattlerContact::SHADOW, layout: new BattleCanvasLayout(1350, 720)))->toBe(['images' => [], 'composites' => []])
    ->and(contactAt(BattlerContact::NONE))->toBe(['images' => [], 'composites' => []]);
});

it('rises a body by a positive elevation and sinks one by a negative, hiding what lies below the surface line', function () {
  $layout = contactLayout(airElevation: .5);
  $slot = new BattlerSlot(500, 560, 100, 100, 1.2);
  $scale = new BattleScale('Hero', 150, ['Hero' => new BattlerScale(1, 1)]);
  $art = new CanvasRectangle(450, 400, 100, 200);
  [$risen, $noClip] = BattlerGroundContact::place($art, BattlerGroundContact::getOffset(.5, $slot, $scale), 560, $layout);
  [$sunk, $clip] = BattlerGroundContact::place($art, BattlerGroundContact::getOffset(-.25, $slot, $scale), 560, $layout);
  expect(BattlerGroundContact::getElevation(null, EnemyHabitat::AIR, $layout))->toBe(.5)
    ->and(BattlerGroundContact::getElevation(null, EnemyHabitat::LAND, $layout))->toBe(0.0)
    ->and(BattlerGroundContact::getElevation(new BattlerGrounding(elevation: -.2), EnemyHabitat::WATER, $layout))->toBe(-.2)
    // A knocked-out flier lies on the ground; art painting its own shadow keeps the height it was painted at.
    ->and(BattlerGroundContact::getElevation(null, EnemyHabitat::AIR, $layout, knockedOut: true))->toBe(0.0)
    ->and(BattlerGroundContact::getElevation(new BattlerGrounding(bakedShadow: true), EnemyHabitat::AIR, $layout))->toBe(0.0)
    // In reference heights at the slot's perspective; without a reference, by the slot's own height.
    ->and(BattlerGroundContact::getOffset(.5, $slot, $scale))->toEqualWithDelta(90.0, .000001)
    ->and(BattlerGroundContact::getOffset(.5, $slot, null))->toEqualWithDelta(60.0, .000001)
    ->and([$risen->y, $noClip])->toBe([310.0, null])
    // Sunk 45 below its painted place, only the art above the surface line at 560 shows.
    ->and($sunk->y)->toEqualWithDelta(445.0, .000001)
    ->and($clip->toArray())->toBe(['x' => 450.0, 'y' => 445.0, 'width' => 100.0, 'height' => 115.0]);
  $grounded = contactAt(BattlerContact::SHADOW, horizontal: true, layout: $layout)['composites'][0];
  $hovering = contactAt(BattlerContact::SHADOW, .5, true, $layout)['composites'][0];
  expect($hovering->width)->toBeLessThan($grounded->width)->and($hovering->opacity)->toBeLessThan($grounded->opacity)
    ->and($hovering->destination->y + $hovering->destination->height / 2)->toEqualWithDelta(560.0, .5);
});

it('meets the surface by habitat and elevation unless the battler says, with nothing over a painted shadow', function () {
  expect(BattlerContact::getDefault(EnemyHabitat::WATER, -.2))->toBe(BattlerContact::RIPPLE)
    ->and(BattlerContact::getDefault(EnemyHabitat::LAND, -.2))->toBe(BattlerContact::BURROW)
    ->and(BattlerContact::getDefault(null, -.2))->toBe(BattlerContact::BURROW)
    ->and(BattlerContact::getDefault(EnemyHabitat::AIR, -.2))->toBe(BattlerContact::SHADOW)
    ->and(BattlerContact::getDefault(EnemyHabitat::WATER, 0))->toBe(BattlerContact::SHADOW)
    ->and(BattlerContact::getDefault(EnemyHabitat::AIR, .4))->toBe(BattlerContact::SHADOW)
    ->and(BattlerGroundContact::getContact(new BattlerGrounding(contact: BattlerContact::NONE), EnemyHabitat::WATER, -.2))->toBe(BattlerContact::NONE)
    ->and(BattlerGroundContact::getContact(new BattlerGrounding(bakedShadow: true), EnemyHabitat::LAND, 0))->toBe(BattlerContact::NONE);
});

it('rings a sunk body with the theme\'s ripple or burrow art, or a plain placeholder until art exists', function () {
  $ripple = contactAt(BattlerContact::RIPPLE, -.2)['composites'][0];
  $burrow = contactAt(BattlerContact::BURROW, -.2)['composites'][0];
  // Ringing the drawn body (300 wide) at the surface line, in front of it.
  expect($ripple->width)->toBe(270)->and($ripple->layer)->toBeGreaterThan(100)
    ->and($ripple->destination->y + $ripple->destination->height / 2)->toEqualWithDelta(560.0, .5)
    ->and($ripple->operations[0]->data['brush']['stops'][0]['color'])->toBe(['kind' => 'rgb', 'r' => 222, 'g' => 236, 'b' => 244])
    ->and($burrow->operations[0]->data['brush']['stops'][1]['color'])->toBe(['kind' => 'rgb', 'r' => 84, 'g' => 62, 'b' => 42]);

  $root = sys_get_temp_dir() . '/ichiloto-contact-' . bin2hex(random_bytes(4));
  writeTestPng($root . '/Effects/Ripple.png', 400, 50);
  $layout = contactLayout(effects: new BattleContactEffects(ripple: new BattlerPose('Effects/Ripple.png', columns: 4, frames: [0, 1, 2, 3],
    fps: 8, pivotY: .5)));
  $art = contactAt(BattlerContact::RIPPLE, -.2, layout: $layout, root: $root);
  // One 100 by 50 frame stretched to 270 wide, its pivot on the feet.
  expect($art['composites'])->toBe([])
    ->and($art['images'][0]->asset)->toBe('Effects/Ripple.png')
    ->and($art['images'][0]->destination->toArray())->toBe(['x' => 365.0, 'y' => 492.5, 'width' => 270.0, 'height' => 135.0])
    ->and($art['images'][0]->sourceRect->width)->toBe(100);
  exec('rm -rf ' . escapeshellarg($root));
});

it('reads a battler\'s grounding and an arena\'s shadow light as data, refusing what it cannot read by its path', function () {
  expect(BattlerGrounding::fromArray(['elevation' => -.3, 'contact' => 'ripple', 'contactWidth' => 1.2], 'enemies.Ness.grounding')->toArray())
    ->toBe(['elevation' => -.3, 'contact' => 'ripple', 'contactWidth' => 1.2])
    ->and(BattlerGrounding::fromArray(['bakedShadow' => true], 'g')->toArray())->toBe(['bakedShadow' => true])
    ->and(fn() => BattlerGrounding::fromArray(['contact' => 'splash'], 'enemies.Ness.grounding'))
    ->toThrow(InvalidArgumentException::class, 'enemies.Ness.grounding.contact: expected one of shadow, ripple, burrow, none')
    ->and(fn() => BattlerGrounding::fromArray(['elevation' => 9], 'enemies.Ness.grounding'))
    ->toThrow(InvalidArgumentException::class, 'within -4..4')
    // Art painting its own shadow stands where it was painted, with that shadow.
    ->and(fn() => BattlerGrounding::fromArray(['bakedShadow' => true, 'elevation' => .3], 'enemies.Bat.grounding'))
    ->toThrow(InvalidArgumentException::class, 'stands at the height it was painted')
    ->and(fn() => BattlerGrounding::fromArray(['bakedShadow' => true, 'contact' => 'ripple'], 'enemies.Bat.grounding'))
    ->toThrow(InvalidArgumentException::class, 'stands at the height it was painted')
    ->and(BattleArenaBindings::getFromArray(['arenas' => ['arena.mine' => ['name' => 'Mine', 'background' => ['image' => 'mine.png'],
      'shadow' => ['strength' => 1.4]]]])->getDefinitions(1350, 720)['arena.mine']->shadowLight->strength)->toBe(1.4)
    ->and(fn() => BattleArenaBindings::getFromArray(['arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png'],
      'shadow' => ['tint' => 1]]]]))->toThrow(InvalidArgumentException::class, 'arenas.a.shadow: expected strength and color only');
});
