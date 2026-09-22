<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Shared theme: palette, authored metrics and optional state artwork. No per-game painting code. */
final readonly class MenuRowSkin
{
  public const array COLORS = ['text', 'selected', 'accent', 'focus', 'disabled', 'edge'];
  public const array ARTWORK = ['normal', 'selected', 'disabled', 'focus',
    'command.normal', 'command.selected', 'command.disabled', 'command.focus'];

  /** @var array<string, PresentationColor> */
  public array $colors;
  /** @var array<string, MenuRowArtwork> */
  public array $artwork;

  /** Command roles override the shared role; omitted artwork uses the neutral primitive treatment.
   * @param array<string, PresentationColor> $colors
   * @param array<string, MenuRowArtwork> $artwork
   */
  public function __construct(array $colors, public MenuRowMetrics $metrics = new MenuRowMetrics(),
    array $artwork = [], public ?string $assetRoot = null)
  {
    if (count($colors) !== count(self::COLORS)) {
      throw new InvalidArgumentException('Menu row skin requires exactly its supported color roles.');
    }
    $copy = [];
    foreach (self::COLORS as $role) {
      if (!($colors[$role] ?? null) instanceof PresentationColor) {
        throw new InvalidArgumentException("Menu row skin requires a typed {$role} color.");
      }
      $copy[$role] = $colors[$role];
    }
    $this->colors = $copy;
    $copy = [];
    foreach ($artwork as $role => $art) {
      if (!in_array($role, self::ARTWORK, true) || !$art instanceof MenuRowArtwork || $assetRoot === null) {
        throw new InvalidArgumentException('Menu state artwork requires a supported role, typed artwork and asset root.');
      }
      $copy[$role] = $art;
    }
    $this->artwork = $copy;
  }

  public function treatment(MenuRowKind $kind, string $role): ?MenuRowArtwork
  {
    return ($kind->isAction() ? ($this->artwork['command.' . $role] ?? null) : null)
      ?? $this->artwork[$role] ?? null;
  }
}
