<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeBrush;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use InvalidArgumentException;
use RuntimeException;

/** Project-owned appearance; it cannot replace the engine's safe handoff cover. */
final readonly class ScreenTransitionTreatment
{
  public string $id;
  public int $width;
  public int $height;
  public array $timings;
  public array $phases;
  public CanvasCompositeBrush $coverBrush;

  public function __construct(array $data)
  {
    CanvasCompositeValues::validateKeys($data, ['id', 'width', 'height', 'timings', 'coverBrush', 'phases']);
    if (!is_string($data['id']) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $data['id']) !== 1) {
      throw new InvalidArgumentException('A transition treatment requires a stable resource id.');
    }
    $this->id = $data['id'];
    foreach (['width', 'height'] as $axis) {
      if (!is_int($data[$axis]) || $data[$axis] < 1 || $data[$axis] > 4096) {
        throw new InvalidArgumentException('Transition reference dimensions must be integers in 1..4096.');
      }
    }
    $this->width = $data['width'];
    $this->height = $data['height'];
    CanvasCompositeValues::validateKeys($data['timings'], ['gather', 'cover', 'hold', 'reveal']);
    $timings = [];
    foreach ($data['timings'] as $phase => $duration) {
      $timings[$phase] = CanvasCompositeValues::getNumber($duration, 0, 10000) / 1000;
    }
    if (array_sum($timings) > 10) {
      throw new InvalidArgumentException('Transition animation is limited to ten seconds; readiness is independent.');
    }
    $this->timings = $timings;
    $this->coverBrush = new CanvasCompositeBrush($data['coverBrush']);
    foreach ($this->coverBrush->data['stops'] ?? [] as $stop) {
      if ($stop['opacity'] !== 1.0) {
        throw new InvalidArgumentException('The safe handoff brush must be fully opaque.');
      }
    }
    CanvasCompositeValues::validateKeys($data['phases'], ['gather', 'cover', 'reveal']);
    $phases = [];
    foreach ($data['phases'] as $phase => $tracks) {
      $phases[$phase] = [];
      foreach (CanvasCompositeValues::getList($tracks, 0, 32) as $track) {
        CanvasCompositeValues::validateKeys($track, ['operation'], ['tweens', 'easing']);
        $easing = $track['easing'] ?? 'linear';
        if (!in_array($easing, ['linear', 'smoothstep'], true)) {
          throw new InvalidArgumentException('Transition easing must be linear or smoothstep.');
        }
        $operation = new CanvasCompositeOperation($track['operation']);
        $tweens = [];
        $paths = [];
        foreach (CanvasCompositeValues::getList($track['tweens'] ?? [], 0, 64) as $tween) {
          CanvasCompositeValues::validateKeys($tween, ['path', 'to']);
          $path = CanvasCompositeValues::getList($tween['path'], 1, 8);
          $value = $operation->data;
          foreach ($path as $part) {
            if ((!is_string($part) && !is_int($part)) || !is_array($value) || !array_key_exists($part, $value)
              || in_array($part, ['r', 'g', 'b', 'index', 'columns', 'rows'], true)) {
              throw new InvalidArgumentException('Transition tweens require existing numeric geometry or opacity paths.');
            }
            $value = $value[$part];
          }
          $key = json_encode($path, JSON_THROW_ON_ERROR);
          if (isset($paths[$key])) { throw new InvalidArgumentException('Transition tween paths must be unique.'); }
          $paths[$key] = true;
          $tweens[] = ['path' => $path, 'from' => CanvasCompositeValues::getNumber($value, -16384, 16384),
            'to' => CanvasCompositeValues::getNumber($tween['to'], -16384, 16384)];
        }
        $phases[$phase][] = ['operation' => $operation->data, 'tweens' => $tweens, 'easing' => $easing];
      }
    }
    $this->phases = $phases;
    // Validate both endpoints and an intermediate frame through the shared canvas budgets.
    foreach (ScreenTransitionPhase::cases() as $phase) {
      foreach ([0.0, 0.5, 1.0] as $progress) { $this->compose($phase, $progress, $this->width, $this->height); }
    }
  }

  public function compose(ScreenTransitionPhase $phase, float $progress, int $width, int $height): PresentationCanvas
  {
    $progress = CanvasCompositeValues::getNumber($progress, 0, 1);
    if (in_array($phase, [ScreenTransitionPhase::COMPLETE, ScreenTransitionPhase::CANCELLED], true)) {
      return new PresentationCanvas($width, $height);
    }
    $operations = [];
    if ($phase === ScreenTransitionPhase::HOLD) {
      $operations[] = new CanvasCompositeOperation(['type' => 'fill',
        'destination' => ['x' => 0, 'y' => 0, 'width' => $this->width, 'height' => $this->height],
        'brush' => $this->coverBrush->data]);
      $phase = ScreenTransitionPhase::COVER;
      $progress = 1;
    }
    foreach ($this->phases[$phase->value] as $track) {
      $data = $track['operation'];
      $u = $track['easing'] === 'smoothstep' ? $progress * $progress * (3 - 2 * $progress) : $progress;
      foreach ($track['tweens'] as $tween) {
        $value = &$data;
        foreach ($tween['path'] as $part) { $value = &$value[$part]; }
        $value = $tween['from'] + ($tween['to'] - $tween['from']) * $u;
        unset($value);
      }
      $operations[] = new CanvasCompositeOperation($data);
    }
    if ($operations === []) { return new PresentationCanvas($width, $height); }
    return new PresentationCanvas($width, $height, composites: [new CanvasComposite('screen-transition',
      $this->width, $this->height, new CanvasRectangle(0, 0, $width, $height), $operations,
      \Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy::TRANSITION)]);
  }

  /** Uses the same replaceable PNG/path/resource boundary as other canvas artwork. */
  public function validateAssets(string $assetRoot): void
  {
    $composites = [];
    foreach ([ScreenTransitionPhase::GATHER, ScreenTransitionPhase::COVER, ScreenTransitionPhase::REVEAL] as $phase) {
      array_push($composites, ...$this->compose($phase, 0, $this->width, $this->height)->composites);
    }
    try { CanvasImagePreflight::inspect([], $assetRoot, $composites); }
    catch (RuntimeException $error) {
      throw new RuntimeException('Transition ' . $this->id . ': ' . $error->getMessage(), previous: $error);
    }
  }
}
