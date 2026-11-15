<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use InvalidArgumentException;

/** Source and ownership contract for one captured ground span, shared with authoring tools. */
final class EffectGroundSpan
{
  public const string FIELD = 'groundSpan';
  public const string TIP_FIELD = 'tip';
  public const string ORIENTATION_FIELD = 'orientation';
  public const string CAPTURE_FIELD = 'targetCaptureFrame';
  public const array SOURCE_FIELDS = [self::TIP_FIELD, self::ORIENTATION_FIELD];
  public const array CONFLICTING_TRACK_FIELDS = ['cells', 'span', 'flight', 'detachFrame', 'contactRail', 'attachmentPoint', 'facing', 'toward'];
  public const array REQUIRED_TRACK_VALUES = ['anchor' => 'target-group', 'attachment' => 'ground',
    'anchorLifetime' => EffectAnchorLifetime::TARGET_CAPTURE->value, 'fit' => 'contain'];

  private function __construct(public readonly PresentationSpritePivot $tip, private readonly ?EffectGroundSpanOrientation $authoredOrientation) {}

  public EffectGroundSpanOrientation $orientation { get => $this->authoredOrientation ?? EffectGroundSpanOrientation::RIGID; }

  public static function fromArray(mixed $source): self
  {
    if (!is_array($source) || array_diff(array_keys($source), self::SOURCE_FIELDS) !== [] || !is_array($source[self::TIP_FIELD] ?? null)) {
      throw new InvalidArgumentException('groundSpan requires a normalized source tip and an optional orientation.');
    }
    $orientation = null;
    if (array_key_exists(self::ORIENTATION_FIELD, $source)) {
      $orientation = is_string($source[self::ORIENTATION_FIELD]) ? EffectGroundSpanOrientation::tryFrom($source[self::ORIENTATION_FIELD]) : null;
      if ($orientation === null) { throw new InvalidArgumentException('groundSpan.orientation must be rigid or upright.'); }
    }
    return new self(PresentationSpritePivot::fromArray($source[self::TIP_FIELD]), $orientation);
  }

  public static function fromTrack(array $track, bool $forBattle, string $playback, array $timeline, string $path): self
  {
    $fail = static fn(string $message): never => throw new InvalidArgumentException($path . '.groundSpan: ' . $message);
    $conflicts = array_values(array_intersect(self::CONFLICTING_TRACK_FIELDS, array_keys($track)));
    if ($conflicts !== []) { $fail('conflicting track fields: ' . implode(', ', $conflicts) . '. The captured endpoints own placement.'); }
    if (!$forBattle) { $fail('requires a battle image track.'); }
    if ($playback !== 'once') { $fail('playback must be once.'); }
    foreach (self::REQUIRED_TRACK_VALUES as $field => $value) {
      if (($track[$field] ?? null) !== $value) { $fail($field . ' must be ' . $value . '.'); }
    }
    if (!array_key_exists(self::CAPTURE_FIELD, $timeline)) { $fail('requires targetCaptureFrame.'); }
    if (!is_array($track['pivot'] ?? null)) { $fail('pivot must declare the measured source rear.'); }
    $rear = PresentationSpritePivot::fromArray($track['pivot']);
    $span = self::fromArray($track[self::FIELD] ?? null);
    if ($span->tip->x === $rear->x && $span->tip->y === $rear->y) { $fail('source tip must differ from its rear pivot.'); }
    return $span;
  }

  public function toArray(): array
  {
    return [self::TIP_FIELD => $this->tip->toArray(), ...($this->authoredOrientation === null ? []
      : [self::ORIENTATION_FIELD => $this->authoredOrientation->value])];
  }

  public static function exportContract(): array
  {
    return ['field' => self::FIELD, 'sourceFields' => self::SOURCE_FIELDS, 'tipField' => self::TIP_FIELD,
      'orientationField' => self::ORIENTATION_FIELD, 'orientations' => array_column(EffectGroundSpanOrientation::cases(), 'value'),
      'defaultOrientation' => EffectGroundSpanOrientation::RIGID->value,
      'requiredTrackValues' => self::REQUIRED_TRACK_VALUES, 'conflictingTrackFields' => self::CONFLICTING_TRACK_FIELDS,
      'pivotField' => 'pivot', 'captureField' => self::CAPTURE_FIELD, 'playback' => 'once',
      'keyframePosition' => 'zero offset; captured endpoints own projection', 'bindingScale' => 1,
      'upright' => 'Choose the rigid horizontal source reflection with the source-cell top pointing upward; diagnose if neither rigid orientation can do so. Authored flipY remains explicit.',
      'placementInspection' => 'BattleCommandPreview::getGroundSpanDiagnostics checks the selected troop/targets at the real authored capture without painting or applying outcomes.'];
  }
}
