<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Text;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Project-owned style intent, independent of label, locale and decoded font assets. */
final readonly class TextRole
{
  /** @var list<FontReference> */
  public array $fonts;

  /** @param array<array-key, mixed> $fonts */
  public function __construct(
    array $fonts,
    public float $fontSize,
    public PresentationColor $foreground,
    public ?CanvasGlyphEffects $glyphEffects = null,
  ) {
    // Reuse text validation and reference detachment; no placeholder locale enters a created spec.
    $this->fonts = new TextSpec('', $fonts, $fontSize, 'und', $foreground, $glyphEffects)->fonts;
  }

  public function createSpec(string $text, string $locale): TextSpec
  {
    return new TextSpec($text, $this->fonts, $this->fontSize, $locale, $this->foreground, $this->glyphEffects);
  }

  public static function fromArray(mixed $data): self
  {
    if (!is_array($data)) { throw new InvalidArgumentException('Text roles require plain descriptors.'); }
    self::assertFields($data, ['fonts', 'fontSize', 'foreground'], ['glyphEffects']);
    if (!is_array($data['fonts']) || !array_is_list($data['fonts'])
      || count($data['fonts']) < 1 || count($data['fonts']) > 16) {
      throw new InvalidArgumentException('Text role fonts require an ordered list of 1..16 descriptors.');
    }
    $fonts = [];
    foreach ($data['fonts'] as $font) {
      if (!is_array($font)) { throw new InvalidArgumentException('Text role fonts require plain descriptors.'); }
      self::assertFields($font, ['asset'], ['faceIndex']);
      if (!is_string($font['asset']) || (array_key_exists('faceIndex', $font) && !is_int($font['faceIndex']))) {
        throw new InvalidArgumentException('Text role fonts require string assets and optional integer faceIndex.');
      }
      $fonts[] = new FontReference($font['asset'], $font['faceIndex'] ?? 0);
    }
    if (!is_int($data['fontSize']) && !is_float($data['fontSize'])) {
      throw new InvalidArgumentException('Text role fontSize requires a number without coercion.');
    }
    $effects = $data['glyphEffects'] ?? null;
    if ($effects !== null && !$effects instanceof CanvasGlyphEffects) {
      throw new InvalidArgumentException('Text role glyphEffects must be null or CanvasGlyphEffects.');
    }
    return new self($fonts, (float)$data['fontSize'], self::readForeground($data['foreground']), $effects);
  }

  /** PHP authoring descriptor: effects remain immutable typed intent, not renderer wire data. */
  public function toArray(): array
  {
    return ['fonts' => array_map(static fn(FontReference $font): array => $font->toArray(), $this->fonts),
      'fontSize' => $this->fontSize, 'foreground' => $this->foreground->toArray(),
      ...($this->glyphEffects === null ? [] : ['glyphEffects' => $this->glyphEffects])];
  }

  private static function readForeground(mixed $data): PresentationColor
  {
    if (!is_array($data) || !is_string($data['kind'] ?? null)) {
      throw new InvalidArgumentException('Text role foreground requires a typed color descriptor.');
    }
    $fields = match ($data['kind']) {
      'rgb' => ['r', 'g', 'b'],
      'ansi16', 'ansi256' => ['index'],
      default => throw new InvalidArgumentException('Text role foreground supports rgb, ansi16 and ansi256 only.'),
    };
    self::assertFields($data, ['kind', ...$fields]);
    foreach ($fields as $field) {
      if (!is_int($data[$field])) {
        throw new InvalidArgumentException('Text role color components require integers without coercion.');
      }
    }
    return match ($data['kind']) {
      'rgb' => PresentationColor::rgb($data['r'], $data['g'], $data['b']),
      'ansi16' => PresentationColor::ansi16($data['index']),
      'ansi256' => PresentationColor::ansi256($data['index']),
    };
  }

  private static function assertFields(array $data, array $required, array $optional = []): void
  {
    if (array_diff($required, array_keys($data)) !== []
      || array_diff(array_keys($data), [...$required, ...$optional]) !== []) {
      throw new InvalidArgumentException('Text role descriptor contains missing or unsupported fields.');
    }
  }
}
