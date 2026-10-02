<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageTint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;
use RuntimeException;

/** Retained effect layers inspect the command lane, never dispatch combat cues. */
final class GraphicalBattleEffects
{
  /** @param array<int, CanvasRectangle> $bounds Combatant instance identities.
   * @param array<int, CanvasImage> $battlerImages Current visible poses, including crop, motion and opacity.
   */
  public static function compose(BattleCommandPlayback $playback, BattleCanvasLayout $arena,
    array $bounds, string $assetRoot, bool $reducedMotion, array $battlerImages = []): PresentationCanvas
  {
    $images = $text = $composites = [];
    $tints = [];
    $summon = $playback->plan->summon;
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
        $subjects = [];
        foreach ($anchor === 'caster' ? [$playback->actor] : $playback->targets as $subject) {
          $subjects[spl_object_id($subject)] = $subject;
        }
        if (in_array($anchor, ['screen', 'legacy-screen'], true)
          || ($segment['layer'] === 'flash' && ($data['scope'] ?? '') === 'screen')) { $subjects = [null]; }
        foreach ($subjects as $subject) {
          $box = $subject === null ? null : ($bounds[spl_object_id($subject)] ?? null);
          if ($subject !== null && $box === null) { continue; }
          $id = 'command-effect-' . $command['trackId'] . '-' . ($subject === null ? 'screen' : spl_object_id($subject));
          $position = $command['position'] ?? ['x' => 0, 'y' => 0];
          $x = $box === null ? ($anchor === 'legacy-screen' ? 2 * $arena->uiGrid->cellWidth : $arena->width / 2)
            : $box->x + $box->width / 2;
          $y = $box === null ? ($anchor === 'legacy-screen' ? 2 * $arena->uiGrid->cellHeight : $arena->height / 2)
            : match ($data['legacyPosition'] ?? 'center') {
              'head' => $box->y, 'feet' => $box->y + $box->height, default => $box->y + $box->height / 2,
            };
          $x += ($position['x'] ?? 0) * $arena->uiGrid->cellWidth;
          $y += ($position['y'] ?? 0) * $arena->uiGrid->cellHeight;
          try {
            switch ($segment['layer']) {
              case 'image':
                $asset = $command['assetId'] ?? '';
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
                $destination = new CanvasRectangle(clamp($x - $width / 2, 0, $arena->width - $width),
                  clamp($y - $height / 2, 0, $arena->height - $height), $width, $height);
                $images[] = new CanvasImage($id, $asset, $destination,
                  ($data['depth'] ?? 'front') === 'behind' ? 90 : 180,
                  $columns * $rows === 1 ? null : new SpriteSourceRect(($source % $columns) * $frameWidth,
                    intdiv($source, $columns) * $frameHeight, $frameWidth, $frameHeight));
                break;
              case 'glyph':
              case 'text':
                $content = trim(strval($command['content'] ?? ''), "\r\n");
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
    if (!$reducedMotion) {
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
    foreach ($tints as $identity => $layers) {
      try {
        $composites[] = CanvasImageTint::composeLayers('command-tint-' . $identity, $battlerImages[$identity], $layers, $assetRoot);
      } catch (InvalidArgumentException|RuntimeException $error) {
        $playback->recordPresentationFailure($error);
      }
    }
    return new PresentationCanvas($arena->width, $arena->height, $images, textLayers: $text, composites: $composites);
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
