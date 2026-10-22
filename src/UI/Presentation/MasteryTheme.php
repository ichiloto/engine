<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/**
 * A game's treatment of the Mastery board beside the shared palette, icons
 * and portraits: each discipline's accent colour, the board's spacing and the
 * text sizes of its corner block, tabs and nodes. The Engine composes the
 * screen; a theme supplies no drawing. A discipline without a colour takes
 * the palette's accent.
 */
final readonly class MasteryTheme
{
  public const array KEYS = ['regionColors', 'nodeDiameter', 'laneWidth', 'stageSpacing', 'portraitWidth', 'portraitHeight',
    'titleCell', 'nameCell', 'numberCell', 'tabCell', 'bodyCell', 'smallCell'];

  /** @var array<string, PresentationColor> Each discipline's accent, by region id. */
  public array $regionColors;

  /**
   * @param array<string, list<int>> $regionColors RGB triples by region id.
   * @param int $nodeDiameter A node's disc, in pixels.
   * @param int $laneWidth The width of one board lane, in pixels.
   * @param int $stageSpacing The height of one board stage, in pixels.
   * @param int $portraitWidth The corner block's portrait width.
   * @param int $portraitHeight The corner block's portrait height.
   * @param list<int> $titleCell Text cell width and height of the screen title.
   * @param list<int> $nameCell Text cell of the character's name and the focused skill's name.
   * @param list<int> $numberCell Text cell of the points total.
   * @param list<int> $tabCell Text cell of discipline and family tabs.
   * @param list<int> $bodyCell Text cell of node names, counts and the footer.
   * @param list<int> $smallCell Text cell of badges, the role and subtitles.
   */
  public function __construct(
    array $regionColors = [],
    public int $nodeDiameter = 68,
    public int $laneWidth = 200,
    public int $stageSpacing = 150,
    public int $portraitWidth = 270,
    public int $portraitHeight = 330,
    public array $titleCell = [16, 34],
    public array $nameCell = [14, 30],
    public array $numberCell = [18, 38],
    public array $tabCell = [10, 22],
    public array $bodyCell = [8, 18],
    public array $smallCell = [7, 14],
  )
  {
    $colors = [];
    foreach ($regionColors as $region => $rgb) {
      if (! is_string($region) || $region === '' || ! is_array($rgb) || ! array_is_list($rgb) || count($rgb) !== 3
        || ! array_all($rgb, static fn(mixed $value): bool => is_int($value))) {
        throw new InvalidArgumentException('Mastery region colours map region ids to RGB integer triples.');
      }
      $colors[$region] = PresentationColor::rgb(...$rgb);
    }
    $this->regionColors = $colors;
    if ($nodeDiameter < 24 || $nodeDiameter > 160 || $laneWidth < $nodeDiameter + 16 || $laneWidth > 480
      || $stageSpacing < $nodeDiameter + 32 || $stageSpacing > 400 || $portraitWidth < 64 || $portraitWidth > 480
      || $portraitHeight < 64 || $portraitHeight > 600) {
      throw new InvalidArgumentException('Mastery nodes need 24..160px discs, lanes wider and stages taller than a disc with its badge and name, and a 64..480px portrait.');
    }
    foreach ([$titleCell, $nameCell, $numberCell, $tabCell, $bodyCell, $smallCell] as $cell) {
      if (! is_array($cell) || ! array_is_list($cell) || count($cell) !== 2 || ! is_int($cell[0]) || ! is_int($cell[1])
        || $cell[0] < 4 || $cell[0] > 32 || $cell[1] < 8 || $cell[1] > 64) {
        throw new InvalidArgumentException('Mastery text cells are [width, height] with width 4..32 and height 8..64.');
      }
    }
  }

  /** @param array<string, mixed> $data The theme's `mastery` section. */
  public static function fromArray(array $data): self
  {
    if (array_diff(array_keys($data), self::KEYS) !== []) {
      throw new InvalidArgumentException('Unsupported Mastery theme field.');
    }
    foreach ($data as $key => $value) {
      if (in_array($key, ['regionColors', 'titleCell', 'nameCell', 'numberCell', 'tabCell', 'bodyCell', 'smallCell'], true)
        ? ! is_array($value) : ! is_int($value)) {
        throw new InvalidArgumentException("Mastery theme field {$key} has the wrong type.");
      }
    }

    return new self(...$data);
  }
}
