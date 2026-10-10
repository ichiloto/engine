<?php

use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleConditions;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\UI\Presentation\MenuIconRegistry;

function allocateConditionProofColor(GdImage $image, PresentationColor $color): int
{
  $rgb = $color->toArray();
  if ($rgb['kind'] !== 'rgb') { throw new LogicException('This badge proof only admits RGB theme colors.'); }
  return imagecolorallocate($image, $rgb['r'], $rgb['g'], $rgb['b']);
}

/** Rasterize the actual badge canvas, not a second implementation of badge geometry. */
function paintConditionProofCanvas(GdImage $image, PresentationCanvas $canvas, string $root, string $font): void
{
  $nodes = [...$canvas->textLayers, ...$canvas->images];
  usort($nodes, static fn($a, $b) => $a->layer <=> $b->layer);
  foreach ($nodes as $node) {
    if ($node instanceof CanvasImage) {
      if ($node->flipX || $node->flipY || $node->opacity !== 1.0 || $node->brightness !== 1.0) {
        throw new LogicException('Unsupported image treatment in the bounded badge raster proof.');
      }
      $source = imagecreatefrompng($root . '/' . $node->asset);
      $rect = $node->sourceRect ?? new SpriteSourceRect(0, 0, imagesx($source), imagesy($source));
      $box = $node->destination;
      imagecopyresampled($image, $source, (int)round($box->x), (int)round($box->y), $rect->x, $rect->y,
        (int)round($box->width), (int)round($box->height), $rect->width, $rect->height);
      unset($source);
      continue;
    }
    if ($node->opacity !== 1.0 || $node->glyphEffects !== null) {
      throw new LogicException('Unsupported text treatment in the bounded badge raster proof.');
    }
    foreach ($node->runs as $run) {
      $x = (int)round($node->x + $run->column * $node->grid->cellWidth);
      $y = (int)round($node->y + $run->row * $node->grid->cellHeight);
      $characters = mb_str_split($run->text);
      if ($run->background !== null) {
        imagefilledrectangle($image, $x, $y, $x + count($characters) * $node->grid->cellWidth - 1,
          $y + $node->grid->cellHeight - 1, allocateConditionProofColor($image, $run->background));
      }
      foreach ($characters as $column => $character) {
        if ($character === ' ') { continue; }
        $color = allocateConditionProofColor($image, $run->foreground);
        $points = $node->grid->cellHeight * .6;
        $ink = imagettfbbox($points, 0, $font, $character);
        $left = $x + $column * $node->grid->cellWidth + ($node->grid->cellWidth - ($ink[2] - $ink[0])) / 2 - $ink[0];
        $baseline = $y + ($node->grid->cellHeight - ($ink[1] - $ink[7])) / 2 - $ink[7];
        imagettftext($image, $points, 0, (int)round($left), (int)round($baseline), $color, $font, $character);
      }
    }
  }
}

it('rasterizes actual 32px badges at one to one scale beside synthetic 150px battlers', function () {
  $font = '/System/Library/Fonts/Monaco.ttf';
  if (!extension_loaded('gd') || !function_exists('imagettftext') || !is_file($font)) {
    $this->markTestSkipped('Headless visual proof requires GD FreeType and the local monospace proof font.');
  }
  $root = createTestDirectory('badge-visual-proof');
  $surface = imagecreatetruecolor(1350, 840);
  $background = imagecolorallocate($surface, 16, 24, 32);
  $muted = imagecolorallocate($surface, 158, 177, 187);
  $white = imagecolorallocate($surface, 242, 246, 247);
  imagefill($surface, 0, 0, $background);
  imagettftext($surface, 16, 0, 34, 36, $white, $font, '32px badges / 150px synthetic battlers / 1:1 logical pixels');
  imagettftext($surface, 11, 0, 34, 65, $muted, $font, 'Procedural semantic icons: seven stats, poison and stun. No artwork required.');
  imagettftext($surface, 11, 0, 34, 424, $muted, $font, 'Bound synthetic 32px artwork: full 32px contain viewport, signed footer beneath.');
  $keys = ['attack' => 'A', 'defence' => 'D', 'magicAttack' => 'M', 'magicDefence' => 'W',
    'speed' => 'S', 'grace' => 'G', 'evasion' => 'E'];
  $icons = [];
  foreach ([...array_map(static fn($key) => 'status.stat.' . $key . '.positive', array_keys($keys)),
    ...array_map(static fn($key) => 'status.stat.' . $key . '.negative', array_keys($keys)),
    'status.state.poison', 'status.state.stun'] as $key) {
    $asset = $key . '.png';
    $art = imagecreatetruecolor(32, 32);
    imagefill($art, 0, 0, imagecolorallocate($art, 40, 64, 78));
    $light = imagecolorallocate($art, 232, 241, 244);
    imagerectangle($art, 1, 1, 30, 30, $light);
    $parts = explode('.', $key);
    $letter = $keys[$parts[2]] ?? ($parts[2] === 'poison' ? 'P' : 'T');
    imagettftext($art, 14, 0, 4, 24, $light, $font, $letter);
    if (isset($parts[3])) {
      $positive = $parts[3] === 'positive';
      $arrow = $positive ? [26, 17, 22, 22, 30, 22] : [26, 28, 22, 23, 30, 23];
      imagefilledpolygon($art, $arrow, $light);
      imagefilledrectangle($art, 25, 21, 27, 25, $light);
    }
    imagepng($art, $root . '/' . $asset);
    unset($art);
    $icons[$key] = $asset;
  }
  $colors = array_fill_keys(['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'],
    PresentationColor::rgb(241, 246, 248));
  $colors['ink'] = PresentationColor::rgb(12, 21, 28);
  $colors['healing'] = PresentationColor::rgb(110, 234, 176);
  $colors['damage'] = PresentationColor::rgb(255, 151, 131);
  $skin = new BattleUiSkin(array_fill_keys(['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued'],
    new CanvasNineSlice('unused.png', new SpriteSourceRect(0, 0, 1, 1))), $colors, icons: new MenuIconRegistry($root, $icons));
  foreach ([240, 600] as $row => $y) {
    $battlers = $bounds = [];
    foreach ([1, -1] as $column => $sign) {
      $actor = new Character('Synthetic', 1, new Stats(currentHp: 100));
      foreach (array_keys($keys) as $index => $stat) {
        $actor->setStatStage($stat, $sign * ($index % 2 === 0 ? 1 : -1) * ($index % 4 + 1));
      }
      $actor->addState(new State('poison', 'Poison'));
      $actor->addState(new State('stun', 'Stun'));
      $box = new CanvasRectangle(335 + 400 * $column, $y, 75, 150);
      $battlers[spl_object_id($actor)] = $actor;
      $bounds[spl_object_id($actor)] = $box;
      $fill = imagecolorallocate($surface, 58, 80, 91);
      imagefilledellipse($surface, (int)$box->x + 37, $y + 18, 32, 36, $fill);
      imagefilledrectangle($surface, (int)$box->x + 15, $y + 36, (int)$box->x + 59, $y + 120, $fill);
      imagefilledrectangle($surface, (int)$box->x + 15, $y + 120, (int)$box->x + 29, $y + 149, $fill);
      imagefilledrectangle($surface, (int)$box->x + 45, $y + 120, (int)$box->x + 59, $y + 149, $fill);
      imagettftext($surface, 10, 0, (int)$box->x + 85, $y + 70, $muted, $font, '150px');
      imagettftext($surface, 10, 0, (int)$box->x - 30, $y - 153, $white, $font, $sign > 0 ? 'Mixed stages' : 'Opposite stages');
    }
    $canvas = GraphicalBattleConditions::compose(new BattleCanvasLayout(1350, 840, skin: $row === 0 ? null : $skin,
      feedbackArea: $row === 0 ? null : new CanvasRectangle(0, 80, 1350, 452)),
      $battlers, $bounds, $root);
    expect($canvas->getOverlayProtection())->toHaveCount(18);
    $stack = array_slice($canvas->getOverlayProtection(), 0, 9);
    expect(array_unique(array_column($stack, 'x')))->toHaveCount(3)
      ->and(array_unique(array_column($stack, 'y')))->toHaveCount(3);
    foreach ($canvas->getOverlayProtection() as $box) {
      expect($box->width)->toBe(32.0)->and($box->height)->toBe(44.0);
    }
    foreach ($canvas->images as $icon) {
      expect($icon->destination->width)->toBe(32.0)->and($icon->destination->height)->toBe(32.0);
    }
    paintConditionProofCanvas($surface, $canvas, $root, $font);
  }
  imagettftext($surface, 10, 0, 1030, 128, $muted, $font, 'Stats: sword / shield');
  imagettftext($surface, 10, 0, 1030, 150, $muted, $font, 'wand / ward / boot');
  imagettftext($surface, 10, 0, 1030, 172, $muted, $font, 'sparkle / eye');
  imagettftext($surface, 10, 0, 1030, 216, $muted, $font, 'States: PSN / STN');
  imagettftext($surface, 10, 0, 1030, 508, $muted, $font, 'Test art, not approved art');
  imagettftext($surface, 10, 0, 34, 825, $muted, $font, 'Headless GD canvas proof. Native font/filtering acceptance remains separate; no native process launched.');
  $path = $root . '/badges-32px.png';
  expect(imagepng($surface, $path))->toBeTrue()->and(getimagesize($path)[0])->toBe(1350);
  $output = getenv('ICHILOTO_BADGE_PROOF_DIRECTORY');
  if ($output !== false && $output !== '') {
    if (!is_dir($output)) { mkdir($output, 0700, true); }
    expect(imagepng($surface, $output . '/badges-32px.png'))->toBeTrue();
  }
  unset($surface);
});
