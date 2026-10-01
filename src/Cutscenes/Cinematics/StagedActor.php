<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\FieldMetric;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheetAssetGuard;
use Ichiloto\Engine\Rendering\Sprites\CharacterStep;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\CharacterWalkAnimation;

/** A temporary field participant owned by one cinematic. */
final class StagedActor implements GraphicalSpriteProviderInterface
{
  public MovementHeading $facing {
    get => $this->subject?->subject->heading ?? $this->localFacing;
  }
  public Vector2 $position {
    get => $this->subject !== null ? clone $this->subject->subject->position : $this->localPosition;
  }
  public array $sprite {
    get {
      $sprite = $this->directionalSprites[$this->facing->name]
        ?? $this->directionalSprites[strtolower($this->facing->name)] ?? null;
      return is_array($sprite) && $sprite !== [] ? array_values(array_map('strval', $sprite)) : $this->baseSprite;
    }
  }
  public bool $isVisible {
    get => $this->visible && $this->ownsPresentation();
  }
  private MovementHeading $localFacing;
  private Vector2 $localPosition;
  private array $baseSprite;
  private bool $visible;
  private bool $released = false;
  private CharacterWalkAnimation $walkAnimation;
  private ?CharacterSheetAssetGuard $graphicalAssetGuard = null;

  /**
   * @param string[] $sprite
   * @param array<string, string[]> $directionalSprites
   */
  public function __construct(
    public readonly string $id,
    array $sprite,
    Vector2 $position,
    bool $isVisible = true,
    protected(set) bool $hasCollision = false,
    protected(set) array $directionalSprites = [],
    MovementHeading $facing = MovementHeading::SOUTH,
    public readonly ?string $assetReference = null,
    private readonly GraphicalSpriteDefinition|CharacterSheet|null $graphicalSprites = null,
    public readonly ?CinematicSubjectLease $subject = null,
    /** @var CinematicSubjectLease[] */
    public readonly array $suppressedSubjects = [],
    ?string $assetRoot = null,
  )
  {
    $this->localFacing = $facing;
    $this->localPosition = $position;
    $this->baseSprite = $sprite;
    $this->visible = $isVisible;
    $this->walkAnimation = new CharacterWalkAnimation();
    if ($graphicalSprites instanceof CharacterSheet) {
      $this->graphicalAssetGuard = new CharacterSheetAssetGuard($assetRoot ?? getcwd() . '/assets', 'Staged actor ' . $id);
    }
  }

  public function show(): void
  {
    $this->visible = true;
  }

  public function hide(): void
  {
    $this->visible = false;
    $this->walkAnimation->stop();
  }

  public function face(Vector2 $direction): void
  {
    $this->assertIndependentMotion();
    $this->walkAnimation->stop();
    $this->setFacing($direction);
  }

  /** @param float|null $stepSeconds How long the step takes to show; the field walking time when null. */
  public function move(Vector2 $direction, ?float $stepSeconds = null): void
  {
    $this->assertIndependentMotion();
    $this->setFacing($direction);
    $origin = clone $this->position;
    $this->position->x += $direction->x;
    $this->position->y += $direction->y;
    $this->beginGraphicalStep(new CharacterStep($origin, $this->position,
      $stepSeconds ?? new FieldMetric()->getWalkSeconds($direction)));
  }

  private function setFacing(Vector2 $direction): void
  {
    $this->localFacing = match (true) {
      $direction->y < 0 => MovementHeading::NORTH,
      $direction->y > 0 => MovementHeading::SOUTH,
      $direction->x < 0 => MovementHeading::WEST,
      $direction->x > 0 => MovementHeading::EAST,
      default => $this->facing,
    };
  }

  public function getGraphicalSpriteId(): string
  {
    return 'staged:' . $this->id;
  }

  public function getGraphicalSpriteDefinition(): ?GraphicalSpriteDefinition
  {
    return $this->isVisible ? $this->directionDefinition() : null;
  }

  public function getGraphicalSpriteWorldPosition(): Vector2
  {
    return clone $this->position;
  }

  public function getGraphicalSpriteMotion(): ?PresentationSpriteMotion
  {
    return $this->isVisible ? $this->walkAnimation->getMotion($this->position) : null;
  }

  /** Any visible art slides; only a character sheet also walks through its frames. */
  public function beginGraphicalStep(CharacterStep $step): void
  {
    if ($this->isVisible && $this->graphicalSprites !== null) {
      $this->walkAnimation->step($step);
    }
  }

  public function advanceGraphicalAnimation(float $seconds): void
  {
    $this->walkAnimation->advance($seconds);
  }

  private function directionDefinition(): ?GraphicalSpriteDefinition
  {
    if (!$this->graphicalSprites instanceof CharacterSheet) {
      return $this->graphicalSprites;
    }
    $frame = $this->graphicalAssetGuard?->getFrameSize($this->graphicalSprites);
    return $frame === null ? null
      : $this->graphicalSprites->getFrame($this->facing, $this->walkAnimation->getPattern(), $frame);
  }

  public function stopGraphicalAnimation(): void
  {
    $this->walkAnimation->stop();
  }

  /** Hidden leases still suppress ordinary art; ineligible subjects do not. */
  public function ownsPresentation(): bool
  {
    if ($this->released) {
      return false;
    }
    foreach ($this->suppressedSubjects as $lease) {
      if (!$lease->isEligible()) {
        return false;
      }
    }
    return $this->subject === null || $this->subject->isEligible();
  }

  public function releaseVisual(): void
  {
    $this->released = true;
    $this->walkAnimation->stop();
  }

  private function assertIndependentMotion(): void
  {
    if ($this->subject !== null) {
      throw new \RuntimeException('A bound staged visual cannot move independently; route its real subject.');
    }
  }
}
