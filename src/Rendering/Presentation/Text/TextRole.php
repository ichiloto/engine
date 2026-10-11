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
    if (is_array($effects)) { $effects = CanvasGlyphEffects::createFromArray($effects); }
    if ($effects !== null && !$effects instanceof CanvasGlyphEffects) {
      throw new InvalidArgumentException('Text role glyphEffects require null, a plain descriptor or CanvasGlyphEffects.');
    }
    return new self($fonts, (float)$data['fontSize'], PresentationColor::createFromArray($data['foreground']), $effects);
  }

  /** Plain authoring descriptor, including optional glyph effects. */
  public function toArray(): array
  {
    return ['fonts' => array_map(static fn(FontReference $font): array => $font->toArray(), $this->fonts),
      'fontSize' => $this->fontSize, 'foreground' => $this->foreground->toArray(),
      ...($this->glyphEffects === null ? [] : ['glyphEffects' => $this->glyphEffects->toArray()])];
  }

  private static function assertFields(array $data, array $required, array $optional = []): void
  {
    if (array_diff($required, array_keys($data)) !== []
      || array_diff(array_keys($data), [...$required, ...$optional]) !== []) {
      throw new InvalidArgumentException('Text role descriptor contains missing or unsupported fields.');
    }
  }
}
