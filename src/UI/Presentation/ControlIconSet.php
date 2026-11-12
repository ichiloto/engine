<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/**
 * How one input device family's controls look, as a theme supplies it under `controls.<family>`.
 *
 * A control shows its family's whole-glyph image when the set has one (a controller's A button),
 * otherwise a keycap: the family's key frame with the control's name centred on it. The keycap
 * frame is optional artwork that stretches to the name's width; without it the Engine draws a rounded,
 * bevelled key in these colours.
 * Which family shows comes from the active display profile's controls, never from this set.
 */
final readonly class ControlIconSet
{
  public const array COLOR_ROLES = ['face', 'edge', 'highlight', 'lip', 'label'];
  private const array DEFAULT_COLORS = ['face' => 'selected', 'edge' => 'edge', 'highlight' => 'edge', 'lip' => 'panel', 'label' => 'text'];

  /** @var array<string, string> Keycap colour parts to theme colour roles. */
  public array $colors;

  /**
   * @param array<string, string> $glyphs Control identities (ENTER, face-south) to PNG paths.
   * @param array<string, string> $colors Keycap parts to theme colour roles: the face, its rounded edge, a one-pixel
   *   highlight along the top, the darker lip along the bottom, and the name.
   * @param int $lip Canvas pixels of lip along the bottom of an Engine-drawn key.
   */
  public function __construct(public ?MenuRowArtwork $keycap = null, public array $glyphs = [], array $colors = [],
    public int $lip = 2)
  {
    foreach ($glyphs as $control => $asset) {
      if (!is_string($control) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,47}$/D', $control) !== 1 || !is_string($asset)) {
        throw new InvalidArgumentException('Control glyphs require control identities and PNG paths.');
      }
      SpriteValidation::validateAssetPath($asset);
    }
    if (array_diff(array_keys($colors), self::COLOR_ROLES) !== [] || !array_all($colors, static fn($role) => is_string($role))) {
      throw new InvalidArgumentException('Keycap colours name the face, edge, highlight, lip and label parts with theme colour roles.');
    }
    if ($lip < 0 || $lip > 8) { throw new InvalidArgumentException('A keycap lip must be 0..8 pixels.'); }
    $this->colors = array_replace(self::DEFAULT_COLORS, $colors);
  }

  /** @param array<string, mixed> $data A theme's `controls.<family>` entry. */
  public static function createFromArray(array $data): self
  {
    if (array_diff(array_keys($data), ['keycap', 'glyphs', 'colors', 'lip']) !== []) {
      throw new InvalidArgumentException('Unsupported control set field.');
    }
    $keycap = $data['keycap'] ?? null;
    if ($keycap !== null) {
      if (!is_array($keycap) || array_diff(array_keys($keycap), ['asset', 'cuts', 'density']) !== []) {
        throw new InvalidArgumentException('A keycap frame is an asset with optional cuts and density.');
      }
      $cuts = $keycap['cuts'] ?? [0, 0, 0, 0];
      if (!is_array($cuts) || !array_is_list($cuts) || count($cuts) !== 4 || !array_all($cuts, static fn($value) => is_int($value))) {
        throw new InvalidArgumentException('Keycap cuts must be four integers: left, top, right, bottom.');
      }
      $keycap = new MenuRowArtwork($keycap['asset'] ?? '', ...[...$cuts, $keycap['density'] ?? 1]);
    }
    foreach (['glyphs', 'colors'] as $key) {
      if (!is_array($data[$key] ?? [])) { throw new InvalidArgumentException("Control set {$key} must be an array."); }
    }
    $lip = $data['lip'] ?? 2;
    if (!is_int($lip)) { throw new InvalidArgumentException('A keycap lip must be 0..8 pixels.'); }
    return new self($keycap, $data['glyphs'] ?? [], $data['colors'] ?? [], $lip);
  }
}
