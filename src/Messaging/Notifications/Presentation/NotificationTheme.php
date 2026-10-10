<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Replaceable notification styling inside the shared menu theme, not a second UI catalogue. */
final readonly class NotificationTheme
{
  public array $colors;

  public function __construct(
    public int $width = 444,
    public int $maxWidth = 520,
    public int $margin = 24,
    public int $padding = 24,
    public int $iconSize = 32,
    public int $iconGap = 16,
    public int $textGap = 6,
    public float $maxHeightRatio = 0.4,
    array $colors = [],
  ) {
    if ($width < 240 || $maxWidth < $width || $maxWidth > 1024 || $margin < 8 || $margin > 64
      || $padding < 8 || $padding > 64 || $iconSize < 16 || $iconSize > 64
      || $iconGap < 0 || $iconGap > 32 || $textGap < 0 || $textGap > 32
      || !is_finite($maxHeightRatio) || $maxHeightRatio < 0.2 || $maxHeightRatio > 0.8) {
      throw new InvalidArgumentException('Notification metrics must fit finite readable bounds.');
    }
    $palette = [];
    foreach ($colors as $role => $rgb) {
      if (!is_string($role) || preg_match('/^[a-z][a-z0-9.-]*$/D', $role) !== 1
        || !is_array($rgb) || !array_is_list($rgb) || count($rgb) !== 3
        || !array_all($rgb, static fn($value) => is_int($value))) {
        throw new InvalidArgumentException('Notification colors require semantic roles and RGB integer triples.');
      }
      $palette[$role] = PresentationColor::rgb(...$rgb);
    }
    $this->colors = $palette;
  }
}
