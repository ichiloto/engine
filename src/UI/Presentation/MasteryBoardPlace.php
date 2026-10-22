<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;

/**
 * Where one place of the drawn Mastery board stands, with everything drawn
 * for it: the disc and its focus rings, the rim badge with its learned or
 * locked mark beside it, and the name under them. The board's framing and
 * the drawing share these measures, so what the camera keeps in view is
 * exactly what is drawn.
 */
final readonly class MasteryBoardPlace
{
  /** How far the outer focus ring reaches past the disc, stroke included. */
  public const int FOCUS_REACH = 14;
  public const int BADGE_HEIGHT = 17;
  /** The radius of the round backing behind a learned or locked mark. */
  public const int MARK_RADIUS = 8;
  /** The gap between a name and the badge above it, at a family's top, or under a bare disc. */
  public const int LABEL_GAP_BADGE = 12;
  public const int LABEL_GAP_FAMILY_TOP = 15;
  public const int LABEL_GAP_BARE = 6;

  /**
   * @param float $x The disc's centre.
   * @param float $y The disc's centre.
   * @param float|null $badgeWidth The rim badge's width, when the place has a badge.
   * @param float|null $markX The centre of the learned or locked mark, when the place shows one.
   * @param float $labelY The top of the name.
   * @param list<int> $labelCell The name's text cell: a family's top names its family in the tab size.
   */
  public function __construct(
    public MasteryMenuNode $node,
    public float $x,
    public float $y,
    public float $radius,
    public bool $focused,
    public ?float $badgeWidth,
    public ?float $markX,
    public float $labelY,
    public array $labelCell,
    public float $left,
    public float $top,
    public float $right,
    public float $bottom,
  ) {}

  /** Measures a place whose disc is centred on a point. */
  public static function measure(MasteryMenuNode $node, float $x, float $y, bool $focused, MasteryTheme $theme): self
  {
    $radius = $theme->nodeDiameter / 2;
    $reach = $focused ? $radius + self::FOCUS_REACH : $radius;
    $badgeWidth = $node->badge === null ? null : TerminalText::displayWidth($node->badge) * $theme->smallCell[0] + 12;
    $badgeRight = $badgeWidth === null ? $x + $radius * 0.45 : $x + $badgeWidth / 2;
    // The learned or locked mark sits just past the badge, so a wide badge never covers it.
    $markX = in_array($node->state, [MasteryNodeState::LEARNED, MasteryNodeState::LOCKED], true)
      ? max($x + $radius * 0.62, $badgeRight + 10) : null;
    $cell = $node->isFamilyTop() ? $theme->tabCell : $theme->bodyCell;
    $labelY = $y + $radius + self::getLabelGap($node);
    $labelHalf = TerminalText::displayWidth($node->label) * $cell[0] / 2;
    $left = min($x - $reach, $x - $labelHalf, $badgeWidth === null ? $x : $x - $badgeWidth / 2);
    $right = max($x + $reach, $x + $labelHalf, $badgeRight, $markX === null ? $x : $markX + self::MARK_RADIUS);
    $bottom = max($y + $reach, $labelY + $cell[1], $y + $radius - 1 + self::BADGE_HEIGHT / 2);

    return new self($node, $x, $y, $radius, $focused, $badgeWidth, $markX, $labelY, $cell, $left, $y - $reach, $right, $bottom);
  }

  /**
   * How far any place may reach above and below its disc's centre: the focus rings above, and below the
   * tallest name a theme's places can carry (a family's top, in the tab size), so whole rows can be framed
   * before knowing which places they hold.
   *
   * @return array{float, float} Above and below.
   */
  public static function getRowReach(MasteryTheme $theme): array
  {
    $radius = $theme->nodeDiameter / 2;
    $name = max(self::LABEL_GAP_BADGE, self::LABEL_GAP_FAMILY_TOP, self::LABEL_GAP_BARE) + max($theme->tabCell[1], $theme->bodyCell[1]);

    return [$radius + self::FOCUS_REACH, max($radius + self::FOCUS_REACH, $radius + $name, $radius - 1 + self::BADGE_HEIGHT / 2)];
  }

  /** Whether the place, with its rings, badge, mark and name, lies wholly within an area. */
  public function isWithin(float $left, float $top, float $right, float $bottom): bool
  {
    return $this->left >= $left && $this->right <= $right && $this->top >= $top && $this->bottom <= $bottom;
  }

  /** The same place moved by an offset. */
  public function moveBy(float $dx, float $dy): self
  {
    return new self($this->node, $this->x + $dx, $this->y + $dy, $this->radius, $this->focused, $this->badgeWidth,
      $this->markX === null ? null : $this->markX + $dx, $this->labelY + $dy, $this->labelCell, $this->left + $dx, $this->top + $dy,
      $this->right + $dx, $this->bottom + $dy);
  }

  /** A name sits under the badge, or clear of the focus rings at a family's or sub-family's top, which it names. */
  private static function getLabelGap(MasteryMenuNode $node): int
  {
    return match (true) {
      $node->badge !== null => self::LABEL_GAP_BADGE,
      $node->isFamilyTop() => self::LABEL_GAP_FAMILY_TOP,
      default => self::LABEL_GAP_BARE,
    };
  }
}
