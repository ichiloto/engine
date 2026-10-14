<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use InvalidArgumentException;

/** Authored presentation space, never a map, combatant or global field camera. */
final readonly class CinematicStage
{
  public const array EASINGS = ['hold', 'linear', 'smoothstep'];

  private function __construct(public array $data, public int $restFrame, public int $lengthFrames) {}

  public static function fromArray(array $data, int $lengthFrames, int $restFrame = 0): self
  {
    self::validateKeys($data, ['canvas', 'startFrame', 'restoreFrame', 'camera', 'covers', 'subjects', 'background'], 'stage');
    $canvas = $data['canvas'] ?? null;
    if (!is_array($canvas) || count($canvas) !== 2 || array_diff(array_keys($canvas), ['width', 'height']) !== []
      || array_any($canvas, static fn($value): bool => !is_int($value) || $value < 1 || $value > 4096)) {
      throw new InvalidArgumentException('stage.canvas needs width/height integers in 1..4096.');
    }
    foreach (['startFrame', 'restoreFrame'] as $key) {
      if (!is_int($data[$key] ?? null) || $data[$key] < 0 || $data[$key] >= $lengthFrames) {
        throw new InvalidArgumentException('stage.' . $key . ' must be a frame of its authored sequence.');
      }
    }
    if ($data['startFrame'] >= $data['restoreFrame'] || $restFrame < $data['startFrame'] || $restFrame >= $data['restoreFrame']) {
      throw new InvalidArgumentException('stage.startFrame/restoreFrame require an active interval containing the sequence restFrame.');
    }
    $background = self::validateColor(array_key_exists('background', $data) ? $data['background'] : 'black', 'stage.background');
    $subjects = array_key_exists('subjects', $data) ? $data['subjects'] : [];
    if (!is_array($subjects) || !array_is_list($subjects) || count($subjects) > 32) {
      throw new InvalidArgumentException('stage.subjects must be a list of at most 32 identities.');
    }
    $identities = [];
    foreach ($subjects as $index => &$subject) {
      $path = 'stage.subjects[' . $index . ']';
      if (!is_array($subject)) { throw new InvalidArgumentException($path . ' must be a descriptor.'); }
      self::validateId($subject['id'] ?? null, $path . '.id');
      $path = 'stage.subjects[' . $subject['id'] . ']';
      self::validateKeys($subject, ['id', 'position', 'size', 'pivot', 'attachments'], $path);
      if (isset($identities[$subject['id']])) { throw new InvalidArgumentException($path . '.id must be unique.'); }
      $identities[$subject['id']] = true;
      $subject['position'] = self::validatePoint($subject['position'] ?? null, path: $path . '.position');
      $subject['size'] = self::validateSize($subject['size'] ?? null, $path . '.size');
      $subject['pivot'] = self::validatePoint(array_key_exists('pivot', $subject) ? $subject['pivot'] : ['x' => .5, 'y' => 1], true, $path . '.pivot');
      $attachments = array_key_exists('attachments', $subject) ? $subject['attachments'] : [];
      if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) > 32) {
        throw new InvalidArgumentException($path . '.attachments must be a bounded list of named normalized points.');
      }
      $attachmentIds = [];
      foreach ($attachments as $pointIndex => &$point) {
        $pointPath = $path . '.attachments[' . $pointIndex . ']';
        if (!is_array($point)) { throw new InvalidArgumentException($pointPath . ' must be a descriptor.'); }
        self::validateId($point['id'] ?? null, $pointPath . '.id');
        $pointPath = $path . '.attachments[' . $point['id'] . ']';
        if (isset($attachmentIds[$point['id']])) { throw new InvalidArgumentException($pointPath . '.id must be unique.'); }
        $attachmentIds[$point['id']] = true;
        $id = $point['id'];
        unset($point['id']);
        $point = ['id' => $id, ...self::validatePoint($point, true, $pointPath)];
      }
      unset($point);
      $subject['attachments'] = $attachments;
    }
    unset($subject);
    $camera = self::validateKeysOnClock($data['camera'] ?? null, $lengthFrames, 'camera');
    if ($camera === [] || $camera[0]['frame'] !== 0) {
      throw new InvalidArgumentException('stage.camera needs its initial key at frame zero.');
    }
    foreach ($camera as &$key) {
      $path = 'stage.camera[' . $key['id'] . ']';
      self::validateKeys($key, ['id', 'frame', 'focus', 'zoom', 'easing'], $path);
      $key['focus'] = self::validatePoint($key['focus'] ?? null, path: $path . '.focus');
      $key['zoom'] = self::validateNumber($key['zoom'] ?? null, .125, 4, $path . '.zoom');
      $key['easing'] = self::validateEasing(array_key_exists('easing', $key) ? $key['easing'] : 'linear', $path . '.easing');
    }
    unset($key);
    $covers = self::validateKeysOnClock(array_key_exists('covers', $data) ? $data['covers'] : [], $lengthFrames, 'covers');
    foreach ($covers as &$key) {
      $path = 'stage.covers[' . $key['id'] . ']';
      self::validateKeys($key, ['id', 'frame', 'color', 'opacity', 'easing'], $path);
      $key['color'] = self::validateColor($key['color'] ?? null, $path . '.color');
      $key['opacity'] = self::validateNumber($key['opacity'] ?? null, 0, 1, $path . '.opacity');
      $key['easing'] = self::validateEasing(array_key_exists('easing', $key) ? $key['easing'] : 'linear', $path . '.easing');
    }
    unset($key);
    if ($covers !== [] && ($covers[0]['frame'] !== 0 || end($covers)['frame'] !== $lengthFrames - 1
      || end($covers)['opacity'] !== 0.0)) {
      throw new InvalidArgumentException('stage.covers needs frame-zero and transparent final-frame keys.');
    }
    return new self([...$data, 'background' => $background, 'subjects' => $subjects, 'camera' => $camera, 'covers' => $covers], $restFrame, $lengthFrames);
  }

  public function getFrame(int $frame, bool $reducedMotion = false): CinematicStageFrame
  {
    $active = $frame >= $this->data['startFrame'] && $frame < $this->data['restoreFrame'];
    $contentFrame = $reducedMotion && $active ? $this->restFrame : $frame;
    [$from, $to, $progress] = self::getInterval($this->data['camera'], $contentFrame);
    $camera = ['focus' => ['x' => self::interpolate($from['focus']['x'], $to['focus']['x'], $progress),
      'y' => self::interpolate($from['focus']['y'], $to['focus']['y'], $progress)],
      'zoom' => self::interpolate($from['zoom'], $to['zoom'], $progress)];
    $cover = ['color' => 'black', 'opacity' => 0.0];
    if (!$reducedMotion && $this->data['covers'] !== []) {
      [$from, $to, $progress] = self::getInterval($this->data['covers'], $frame);
      $cover = ['color' => $from['color'], 'opacity' => self::interpolate($from['opacity'], $to['opacity'], $progress)];
    }
    $drawsContent = $frame >= $this->data['startFrame'] && $frame < $this->lengthFrames && (!$reducedMotion || $active);
    return new CinematicStageFrame($this, $active, $contentFrame, $camera, $cover, $drawsContent);
  }

  public function getSubjectPoint(string $id, ?string $attachment = null): array
  {
    $subject = array_find($this->data['subjects'], static fn(array $subject): bool => $subject['id'] === $id);
    $point = $subject === null || $attachment === null ? null
      : array_find($subject['attachments'], static fn(array $point): bool => $point['id'] === $attachment);
    if ($subject === null || ($attachment !== null && $point === null)) {
      throw new InvalidArgumentException('Cinematic stage placement references an unknown subject or attachment.');
    }
    $offset = $point === null ? ['x' => 0, 'y' => 0]
      : ['x' => ($point['x'] - $subject['pivot']['x']) * $subject['size']['width'],
        'y' => ($point['y'] - $subject['pivot']['y']) * $subject['size']['height']];
    return ['x' => $subject['position']['x'] + $offset['x'], 'y' => $subject['position']['y'] + $offset['y']];
  }

  public static function validatePlacement(mixed $placement, self $stage, string $path = 'placement'): array
  {
    if (!is_array($placement)) { throw new InvalidArgumentException($path . ' must be a descriptor.'); }
    self::validateKeys($placement, ['position', 'size', 'subject', 'attachment'], $path);
    $placement['position'] = self::validatePoint(array_key_exists('position', $placement) ? $placement['position'] : ['x' => 0, 'y' => 0], path: $path . '.position');
    if (array_key_exists('subject', $placement)) {
      self::validateId($placement['subject'], $path . '.subject');
      if (array_key_exists('attachment', $placement)) { self::validateId($placement['attachment'], $path . '.attachment'); }
      try { $stage->getSubjectPoint($placement['subject'], $placement['attachment'] ?? null); }
      catch (InvalidArgumentException $error) { throw new InvalidArgumentException($path . ': ' . $error->getMessage(), previous: $error); }
    } elseif (array_key_exists('attachment', $placement)) {
      throw new InvalidArgumentException($path . '.attachment requires a subject.');
    }
    $subject = isset($placement['subject']) ? array_find($stage->data['subjects'],
      static fn(array $subject): bool => $subject['id'] === $placement['subject']) : null;
    $placement['size'] = self::validateSize(array_key_exists('size', $placement) ? $placement['size'] : ($subject['size'] ?? null), $path . '.size');
    return $placement;
  }

  public static function validatePoint(mixed $point, bool $normalized = false, string $path = 'point'): array
  {
    if (!is_array($point) || array_diff(array_keys($point), ['x', 'y']) !== []) {
      throw new InvalidArgumentException($path . ' requires x/y coordinates.');
    }
    return ['x' => self::validateNumber($point['x'] ?? null, $normalized ? 0 : -32768, $normalized ? 1 : 32768, $path . '.x'),
      'y' => self::validateNumber($point['y'] ?? null, $normalized ? 0 : -32768, $normalized ? 1 : 32768, $path . '.y')];
  }

  public static function validateNumber(mixed $value, float $min, float $max, string $path = 'value'): float
  {
    if ((!is_int($value) && !is_float($value)) || !is_finite($value) || $value < $min || $value > $max) {
      throw new InvalidArgumentException($path . ' must be a finite number in ' . $min . '..' . $max . '.');
    }
    return (float)$value;
  }

  private static function validateKeys(array $data, array $allowed, string $path): void
  {
    if (array_diff(array_keys($data), $allowed) !== []) {
      throw new InvalidArgumentException($path . ' contains unsupported fields: ' . implode(', ', array_diff(array_keys($data), $allowed)) . '.');
    }
  }

  private static function validateSize(mixed $size, string $path): array
  {
    if (!is_array($size) || array_diff(array_keys($size), ['width', 'height']) !== []) {
      throw new InvalidArgumentException($path . ' requires width/height in stage units.');
    }
    return ['width' => self::validateNumber($size['width'] ?? null, 1, 4096, $path . '.width'),
      'height' => self::validateNumber($size['height'] ?? null, 1, 4096, $path . '.height')];
  }

  private static function validateColor(mixed $color, string $path): string
  {
    if (!is_string($color) || (!in_array($color, ['black', 'white'], true) && preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1)) {
      throw new InvalidArgumentException($path . ' requires black, white or a #RRGGBB string.');
    }
    return $color;
  }

  private static function validateEasing(mixed $easing, string $path): string
  {
    if (!in_array($easing, self::EASINGS, true)) {
      throw new InvalidArgumentException($path . ' must be one of: ' . implode(', ', self::EASINGS) . '.');
    }
    return $easing;
  }

  private static function validateKeysOnClock(mixed $keys, int $length, string $lane): array
  {
    if (!is_array($keys) || !array_is_list($keys) || count($keys) > 10000) {
      throw new InvalidArgumentException('stage.' . $lane . ' must be a bounded list.');
    }
    $previous = -1;
    $ids = [];
    foreach ($keys as $index => $key) {
      $path = 'stage.' . $lane . '[' . $index . ']';
      if (!is_array($key)) { throw new InvalidArgumentException($path . ' must be a descriptor.'); }
      self::validateId($key['id'] ?? null, $path . '.id');
      $path = 'stage.' . $lane . '[' . $key['id'] . ']';
      if (!is_int($key['frame'] ?? null) || $key['frame'] <= $previous || $key['frame'] >= $length) {
        throw new InvalidArgumentException($path . '.frame must be an ordered unique frame of its sequence.');
      }
      $previous = $key['frame'];
      if (isset($ids[$key['id']])) { throw new InvalidArgumentException($path . '.id must be unique.'); }
      $ids[$key['id']] = true;
    }
    return $keys;
  }

  private static function validateId(mixed $id, string $path): void
  {
    if (!is_string($id)) { throw new InvalidArgumentException($path . ' requires a stable ID.'); }
    try { EffectTimelineLibrary::assertId($id); }
    catch (InvalidArgumentException $error) { throw new InvalidArgumentException($path . ': ' . $error->getMessage(), previous: $error); }
  }

  /** The outgoing key owns interpolation; holds jump only at the next key. */
  private static function getInterval(array $keys, int $frame): array
  {
    $from = $keys[0];
    foreach (array_slice($keys, 1) as $to) {
      if ($frame < $to['frame']) {
        $progress = clamp(($frame - $from['frame']) / ($to['frame'] - $from['frame']), 0, 1);
        $progress = match ($from['easing']) { 'hold' => 0.0,
          'smoothstep' => $progress * $progress * (3 - 2 * $progress), default => $progress };
        return [$from, $to, $progress];
      }
      $from = $to;
    }
    return [$from, $from, 0.0];
  }

  private static function interpolate(float $from, float $to, float $progress): float
  {
    return $from + ($to - $from) * $progress;
  }
}
