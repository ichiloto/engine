<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageFit;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageTint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;
use RuntimeException;

/** Retained effect layers inspect the command lane, never dispatch combat cues. */
final class GraphicalBattleEffects
{
  /** @param array<int, CanvasRectangle> $bounds Combatant instance identities.
   * @param array<int, CanvasImage> $battlerImages Current visible poses, including crop, motion and opacity.
   * @param array<int, Vector2>|null $groundAnchors Current posed ground points; rectangle-only hosts use bottom centres.
   */
  public static function compose(BattleEffectPlayback $playback, BattleCanvasLayout $arena,
    array $bounds, string $assetRoot, bool $reducedMotion, array $battlerImages = [], bool $imageFlips = true,
    bool $compositing = true, ?array $groundAnchors = null): PresentationCanvas
  {
    $groundAnchors ??= array_map(static fn(CanvasRectangle $box): Vector2 =>
      new Vector2($box->x + $box->width / 2, $box->y + $box->height), $bounds);
    $hadConditionFailure = $playback instanceof BattlerConditionEffect && $playback->hasPresentationFailure;
    $images = $text = $composites = [];
    $tints = [];
    $summon = $playback instanceof BattleCommandPlayback ? $playback->plan->summon : null;
    if ($summon !== null && !$reducedMotion) {
      if (in_array($playback->phase, ['summon-in', 'summon-out'], true)) {
        $direction = $playback->phase === 'summon-in' ? 'in' : 'out';
        $transition = $summon->transitionCache[$direction] ?? [];
        if (in_array(strtolower($transition['type'] ?? ''), ['fadetoblack', 'fadefromblack'], true)) {
          $phase = $playback->plan->phases[$playback->phase];
          $progress = clamp(($playback->session->currentFrame - $phase['start']) / max(1, $phase['length'] - 1), 0, 1);
          array_push($text, ...self::getWashes('command-transition', new CanvasRectangle(0, 0, $arena->width, $arena->height),
            self::getColor($transition['color'] ?? 'black'), $direction === 'in' ? $progress : 1 - $progress));
        }
      } elseif ($playback->phase === 'summon-title') {
        $lines = [strval($summon->defaults['name'] ?? $summon->sourceId)];
        if ($summon->defaults['targetPresentation']['showCasterNameBanner'] ?? false) { $lines[] = $playback->actor->name; }
        $columns = min(intdiv($arena->width, $arena->uiGrid->cellWidth), max(1, ...array_map('mb_strwidth', $lines)));
        $text[] = new CanvasTextLayer('command-summon-title', 180,
          ($arena->width - $columns * $arena->uiGrid->cellWidth) / 2, $arena->height / 3,
          new RendererGridConfig($columns, count($lines), $arena->uiGrid->cellWidth, $arena->uiGrid->cellHeight),
          array_map(static fn(string $line, int $row) => new PresentationTextRun($row, 0,
            mb_strimwidth($line, 0, $columns, ''), PresentationColor::rgb(255, 224, 160)), $lines, array_keys($lines)));
      }
    }
    foreach ($playback->getActiveSegments($reducedMotion) as $segment) {
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true)) { continue; }
        $data = $command['payload'] ?? [];
        $anchor = $data['anchor'] ?? 'target';
        if ($anchor === 'stage') { continue; }
        $subjects = [];
        foreach ($anchor === 'caster' ? [$playback->actor] : $playback->targets as $subject) {
          $subjects[spl_object_id($subject)] = $subject;
        }
        if (in_array($anchor, ['screen', 'legacy-screen'], true)
          || ($segment['layer'] === 'flash' && ($data['scope'] ?? '') === 'screen')) { $subjects = [null]; }
        foreach ($subjects as $subject) {
          $box = $subject === null ? null : ($bounds[spl_object_id($subject)] ?? null);
          if ($subject !== null && $box === null) { continue; }
          $oriented = $command;
          $recipient = $anchor === 'caster'
            ? array_find($playback->targets, static fn($target): bool => $target !== $playback->actor) : $subject;
          $casterX = ($groundAnchors[spl_object_id($playback->actor)] ?? null)?->x;
          $recipientX = $recipient === null ? null : ($groundAnchors[spl_object_id($recipient)] ?? null)?->x;
          if ($casterX !== null && $recipientX !== null) {
            $oriented = BattleEffectDirection::orientCommand($command, $casterX, $recipientX);
          }
          $data = $oriented['payload'] ?? [];
          $id = 'command-effect-' . $oriented['trackId'] . '-' . ($subject === null ? 'screen' : spl_object_id($subject));
          $position = $oriented['position'] ?? ['x' => 0, 'y' => 0];
          $attachment = $data['attachment'] ?? $data['legacyPosition'] ?? 'center';
          $ground = $subject === null ? null : ($groundAnchors[spl_object_id($subject)] ?? null);
          try {
            if ($attachment === 'ground' && $ground === null) {
              throw new InvalidArgumentException('Battle effect ground attachment requires the subject ground point.');
            }
            $x = $box === null ? ($anchor === 'legacy-screen' ? 2 * $arena->uiGrid->cellWidth : $arena->width / 2)
              : ($attachment === 'ground' ? $ground->x : $box->x + $box->width / 2);
            $y = $box === null ? ($anchor === 'legacy-screen' ? 2 * $arena->uiGrid->cellHeight : $arena->height / 2)
              : match ($attachment) {
                'ground' => $ground->y,
                'head' => $box->y, 'feet' => $box->y + $box->height, default => $box->y + $box->height / 2,
              };
            $x += ($position['x'] ?? 0) * $arena->uiGrid->cellWidth;
            $y += ($position['y'] ?? 0) * $arena->uiGrid->cellHeight;
            switch ($segment['layer']) {
              case 'image':
                if (!$imageFlips && (($data['flipX'] ?? false) || ($data['flipY'] ?? false))) {
                  throw new InvalidArgumentException('Renderer cannot mirror this battle effect image.');
                }
                $asset = $oriented['assetId'] ?? '';
                $size = PngAssetPreflight::getAvailableSize($assetRoot, $asset);
                if ($size === null) { throw new InvalidArgumentException('Battle effect PNG is unavailable: ' . $asset); }
                $columns = max(1, (int)($data['columns'] ?? 1));
                $rows = max(1, (int)($data['rows'] ?? 1));
                if ($size['width'] % $columns !== 0 || $size['height'] % $rows !== 0) {
                  throw new InvalidArgumentException('Battle effect PNG does not divide into its sheet grid.');
                }
                $source = (int)($data['sourceFrame'] ?? 0);
                if ($source < 0 || $source >= $columns * $rows) {
                  throw new InvalidArgumentException('Battle effect source frame is outside the current sheet.');
                }
                $frameWidth = intdiv($size['width'], $columns);
                $frameHeight = intdiv($size['height'], $rows);
                $width = min($arena->width, ($data['cells']['width'] ?? null) === null
                  ? $frameWidth : $data['cells']['width'] * 48);
                $height = min($arena->height, ($data['cells']['height'] ?? null) === null
                  ? $frameHeight : $data['cells']['height'] * 48);
                $fitted = CanvasImageFit::parse($data['fit'] ?? 'stretch')->getSize($frameWidth, $frameHeight, $width, $height);
                $width = $fitted['width'];
                $height = $fitted['height'];
                $pivot = $data['pivot'] ?? ['x' => .5, 'y' => .5];
                if ($data['flipX'] ?? false) { $pivot['x'] = 1 - $pivot['x']; }
                if ($data['flipY'] ?? false) { $pivot['y'] = 1 - $pivot['y']; }
                $image = CanvasImage::createClipped($id, $asset, $x - $width * $pivot['x'], $y - $height * $pivot['y'],
                  $width, $height, $arena->width, $arena->height,
                  ($data['depth'] ?? 'front') === 'behind' ? 90 : 180,
                  new SpriteSourceRect(($source % $columns) * $frameWidth,
                    intdiv($source, $columns) * $frameHeight, $frameWidth, $frameHeight),
                  flipX: $data['flipX'] ?? false, flipY: $data['flipY'] ?? false);
                if ($image !== null) { $images[] = $image; }
                break;
              case 'glyph':
              case 'text':
                $content = trim(strval($oriented['content'] ?? ''), "\r\n");
                if (trim($content) === '') {
                  $assetId = trim(strval($command['assetId'] ?? ''));
                  $content = $assetId === '' ? '' : '[' . strtoupper($assetId) . ']';
                }
                $lines = explode("\n", $content);
                $columns = min(intdiv($arena->width, $arena->uiGrid->cellWidth),
                  max(1, ...array_map('mb_strwidth', $lines)));
                $rows = min(count($lines), intdiv($arena->height, $arena->uiGrid->cellHeight));
                $runs = [];
                foreach (array_slice($lines, 0, $rows) as $row => $line) {
                  $runs[] = new PresentationTextRun($row, 0, mb_strimwidth($line, 0, $columns, ''),
                    self::getColor($command['color'] ?? 'white'));
                }
                $text[] = new CanvasTextLayer($id, 180,
                  clamp($x, 0, $arena->width - $columns * $arena->uiGrid->cellWidth),
                  clamp($y, 0, $arena->height - $rows * $arena->uiGrid->cellHeight),
                  new RendererGridConfig($columns, $rows, $arena->uiGrid->cellWidth, $arena->uiGrid->cellHeight), $runs);
                break;
              case 'flash':
                if ($reducedMotion) { break; }
                $color = self::getColor($data['color'] ?? $command['color'] ?? 'white');
                if ($subject === null) {
                  array_push($text, ...self::getWashes($id, new CanvasRectangle(0, 0, $arena->width, $arena->height), $color, .22));
                } elseif (isset($battlerImages[spl_object_id($subject)])) {
                  $tints[spl_object_id($subject)][] = ['color' => $color, 'strength' => .22];
                }
                break;
            }
          } catch (InvalidArgumentException|RuntimeException $error) {
            $playback->recordPresentationFailure($error);
          }
        }
      }
    }
    if (!$reducedMotion && $playback instanceof BattleCommandPlayback) {
      $reacted = [];
      foreach ([$playback->actor, ...$playback->targets] as $subject) {
        $identity = spl_object_id($subject);
        if (isset($reacted[$identity])) { continue; }
        $reacted[$identity] = true;
        $role = $playback->getPoseRole($subject);
        $image = $battlerImages[$identity] ?? null;
        if ($image !== null && in_array($role, [BattlePoseRole::DAMAGE, BattlePoseRole::HEAL], true)) {
          $tints[$identity][] = ['color' => self::getColor($role === BattlePoseRole::DAMAGE ? 'red' : 'green'), 'strength' => .16];
        }
      }
    }
    $groups = $tintAreas = [];
    foreach ($tints as $identity => $layers) {
      if (!$compositing) { continue; }
      try {
        $tint = CanvasImageTint::composeLayers('command-tint-' . $identity, $battlerImages[$identity], $layers, $assetRoot);
        $tintAreas[] = $tint->clipRect ?? $tint->destination;
        // Surface opacity cannot be distributed through overlapping operations.
        // Keep faded subjects separate; ordinary subjects share a depth surface.
        if ($tint->opacity === 1.0) { $groups[$tint->layer][] = $tint; }
        else { $composites[] = $tint; }
      } catch (InvalidArgumentException|RuntimeException $error) {
        $playback->recordPresentationFailure($error);
      }
    }
    if (!$compositing && $tints !== []) {
      $playback->recordPresentationFailure(new InvalidArgumentException(
        'Renderer cannot composite battle tints; poses, effects and result feedback remain.'));
    }
    foreach ($groups as $layer => $group) {
      try { $composites[] = CanvasImageTint::combine('command-tints-layer-' . $layer, $group); }
      catch (InvalidArgumentException|RuntimeException $error) { $playback->recordPresentationFailure($error); }
    }
    try { new PresentationCanvas($arena->width, $arena->height, composites: $composites); }
    catch (InvalidArgumentException $error) {
      // Optional raster work never takes otherwise valid poses or effects with it.
      $playback->recordPresentationFailure($error);
      $composites = [];
    }
    if ($playback instanceof BattlerConditionEffect && !$hadConditionFailure && $playback->hasPresentationFailure) {
      return self::compose($playback, $arena, $bounds, $assetRoot, $reducedMotion, $battlerImages,
        $imageFlips, $compositing, $groundAnchors);
    }
    return new PresentationCanvas($arena->width, $arena->height, $images, textLayers: $text, composites: $composites,
      protectedAreas: [...$tintAreas,
        ...array_map(static fn(CanvasImage $image) => $image->clipRect ?? $image->destination, $images),
        ...array_map(static fn(CanvasTextLayer $layer) => $layer->clipRect ?? $layer->paintBounds, $text)]);
  }

  /** Only deliberate full-screen fades/flashes use rectangular washes. Battlers use their image alpha. */
  private static function getWashes(string $id, CanvasRectangle $bounds, PresentationColor $color, float $opacity): array
  {
    $layers = [];
    for ($y = 0; $y < (int)$bounds->height; $y += RendererGridConfig::MAX_CELL_SIZE) {
      for ($x = 0; $x < (int)$bounds->width; $x += RendererGridConfig::MAX_CELL_SIZE) {
        $layers[] = new CanvasTextLayer($id . ($x + $y === 0 ? '' : "-{$x}-{$y}"), 190,
          $bounds->x + $x, $bounds->y + $y,
          new RendererGridConfig(1, 1, min(RendererGridConfig::MAX_CELL_SIZE, (int)$bounds->width - $x),
            min(RendererGridConfig::MAX_CELL_SIZE, (int)$bounds->height - $y)),
          [new PresentationTextRun(0, 0, ' ', background: $color)], opacity: $opacity);
      }
    }
    return $layers;
  }

  private static function getColor(string $name): PresentationColor
  {
    return match (strtolower($name)) {
      'black' => PresentationColor::rgb(0, 0, 0),
      'red', 'light_red' => PresentationColor::rgb(255, 96, 96),
      'green', 'light_green' => PresentationColor::rgb(96, 255, 160),
      'blue', 'cyan', 'light_cyan' => PresentationColor::rgb(96, 192, 255),
      'yellow' => PresentationColor::rgb(255, 224, 96),
      default => PresentationColor::rgb(255, 255, 255),
    };
  }
}
