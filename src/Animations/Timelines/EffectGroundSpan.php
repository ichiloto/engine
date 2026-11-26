<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use InvalidArgumentException;

/** Source and ownership contract for captured ground spans, shared with authoring tools. */
final class EffectGroundSpan
{
  public const string FIELD = 'groundSpan';
  public const string TIP_FIELD = 'tip';
  public const string ORIENTATION_FIELD = 'orientation';
  public const string CASTER_POINT_FIELD = 'casterPoint';
  public const string REVEAL_FIELD = 'reveal';
  public const string CAPTURE_FIELD = 'targetCaptureFrame';
  public const array SOURCE_FIELDS = [self::TIP_FIELD, self::ORIENTATION_FIELD, self::CASTER_POINT_FIELD, self::REVEAL_FIELD];
  public const array CONFLICTING_TRACK_FIELDS = ['cells', 'span', 'flight', 'detachFrame', 'contactRail', 'attachmentPoint', 'facing', 'toward'];
  public const array ANCHORS = ['target-group', 'target'];
  public const array REQUIRED_TRACK_VALUES = ['attachment' => 'ground',
    'anchorLifetime' => EffectAnchorLifetime::TARGET_CAPTURE->value, 'fit' => 'contain'];

  private function __construct(public readonly PresentationSpritePivot $tip,
    private readonly ?EffectGroundSpanOrientation $authoredOrientation, public readonly ?string $casterPoint,
    public readonly ?EffectGroundSpanReveal $reveal) {}

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
    $casterPoint = null;
    if (array_key_exists(self::CASTER_POINT_FIELD, $source)) {
      $casterPoint = $source[self::CASTER_POINT_FIELD];
      if (!is_string($casterPoint)) { throw new InvalidArgumentException('groundSpan.casterPoint must name a caster pose attachment.'); }
      EffectTimelineLibrary::assertId($casterPoint);
    }
    $reveal = array_key_exists(self::REVEAL_FIELD, $source) ? EffectGroundSpanReveal::fromArray($source[self::REVEAL_FIELD]) : null;
    return new self(PresentationSpritePivot::fromArray($source[self::TIP_FIELD]), $orientation, $casterPoint, $reveal);
  }

  public static function fromTrack(array $track, bool $forBattle, string $playback, array $timeline, string $path): self
  {
    $fail = static fn(string $message): never => throw new InvalidArgumentException($path . '.groundSpan: ' . $message);
    $conflicts = array_values(array_intersect(self::CONFLICTING_TRACK_FIELDS, array_keys($track)));
    if ($conflicts !== []) { $fail('conflicting track fields: ' . implode(', ', $conflicts) . '. The captured endpoints own placement.'); }
    if (!$forBattle) { $fail('requires a battle image track.'); }
    if ($playback !== 'once') { $fail('playback must be once.'); }
    if (!in_array($track['anchor'] ?? null, self::ANCHORS, true)) { $fail('anchor must be target-group or target.'); }
    foreach (self::REQUIRED_TRACK_VALUES as $field => $value) {
      if (($track[$field] ?? null) !== $value) { $fail($field . ' must be ' . $value . '.'); }
    }
    if (!array_key_exists(self::CAPTURE_FIELD, $timeline)) { $fail('requires targetCaptureFrame.'); }
    if (!is_array($track['pivot'] ?? null)) { $fail('pivot must declare the measured source rear.'); }
    $rear = PresentationSpritePivot::fromArray($track['pivot']);
    $span = self::fromArray($track[self::FIELD] ?? null);
    if ($span->tip->x === $rear->x && $span->tip->y === $rear->y) { $fail('source tip must differ from its rear pivot.'); }
    if ($span->reveal !== null) {
      $contacts = $timeline['targetContactSequence'] ?? [];
      $first = $contacts['firstFrame'] ?? null;
      $contactTracks = $contacts['tracks'] ?? null;
      if (($track['anchor'] ?? null) !== 'target' || ($track['presentation'] ?? null) !== 'graphical'
        || !is_int($first) || $span->reveal->startFrame >= $first || !is_array($contactTracks)
        || in_array($track['id'], $contactTracks, true)) {
        $fail('reveal requires a graphical recipient span, a start before first contact, and separate contact tracks.');
      }
    }
    return $span;
  }

  public function toArray(): array
  {
    return [self::TIP_FIELD => $this->tip->toArray(), ...($this->authoredOrientation === null ? []
      : [self::ORIENTATION_FIELD => $this->authoredOrientation->value]),
      ...($this->casterPoint === null ? [] : [self::CASTER_POINT_FIELD => $this->casterPoint]),
      ...($this->reveal === null ? [] : [self::REVEAL_FIELD => $this->reveal->toArray()])];
  }

  public static function exportContract(): array
  {
    return ['field' => self::FIELD, 'sourceFields' => self::SOURCE_FIELDS, 'tipField' => self::TIP_FIELD,
      'casterPointField' => self::CASTER_POINT_FIELD, 'revealField' => self::REVEAL_FIELD,
      'orientationField' => self::ORIENTATION_FIELD, 'orientations' => array_column(EffectGroundSpanOrientation::cases(), 'value'),
      'defaultOrientation' => EffectGroundSpanOrientation::RIGID->value,
      'anchors' => self::ANCHORS, 'requiredTrackValues' => ['anchor' => 'target-group', ...self::REQUIRED_TRACK_VALUES],
      'conflictingTrackFields' => self::CONFLICTING_TRACK_FIELDS,
      'pivotField' => 'pivot', 'captureField' => self::CAPTURE_FIELD, 'playback' => 'once',
      'keyframePosition' => 'zero offset; captured endpoints own projection', 'bindingScale' => 1,
      'upright' => 'Choose the rigid horizontal source reflection with the source-cell top pointing upward; diagnose if neither rigid orientation can do so. Authored flipY remains explicit.',
      'placementInspection' => 'BattleCommandPreview::getGroundSpanDiagnostics checks the selected troop/targets at the real authored capture without painting or applying outcomes.'];
  }
}
