<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Sprites;

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Animations\Field\FieldPosePlayback;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\UI\Accessibility;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/** Shared field role admission and current-file projection, with an owned pose clock. */
final class FieldSpriteRole
{
    private ?FieldPosePlayback $playback = null;
    private CharacterSheetAssetGuard $sheetGuard;
    private ?string $lastFailure = null;
    private bool $released = false;

    public function __construct(public readonly GraphicalSpriteDefinition|CharacterSheet|FieldPoseAnimation $definition,
        private readonly string $assetRoot, private readonly string $context)
    {
        $this->sheetGuard = new CharacterSheetAssetGuard($assetRoot, $context);
        if ($definition instanceof FieldPoseAnimation) { $this->playback = new FieldPosePlayback($definition, $assetRoot, $context); }
    }

    public static function readDefinition(array $data): GraphicalSpriteDefinition|CharacterSheet|FieldPoseAnimation
    {
        $sprites = array_key_exists('sheet', $data) ? CharacterSheet::fromArray($data)
            : (array_key_exists('animation', $data) ? FieldPoseAnimation::fromArray($data) : GraphicalSpriteDefinition::fromArray($data));
        if ($sprites->layer < PresentationLayerPolicy::WORLD || $sprites->layer >= PresentationLayerPolicy::UI) {
            throw new InvalidArgumentException('Field world sprites require layers 0..999; UI layers are reserved.');
        }
        return $sprites;
    }

    public function getFrame(PresentationSpritePivot $pivot, MovementHeading $heading = MovementHeading::SOUTH): ?GraphicalSpriteDefinition
    {
        if ($this->released) { return null; }
        if ($this->definition instanceof FieldPoseAnimation) {
            $frame = $this->playback?->getFrame(Accessibility::prefersReducedMotion());
        } elseif ($this->definition instanceof CharacterSheet) {
            $size = $this->sheetGuard->getFrameSize($this->definition);
            $frame = $size === null ? null : $this->definition->getFrame($heading, 1, $size);
        } else {
            $frame = $this->definition;
        }
        if ($frame === null) { return null; }
        return $this->prepareFrame($frame, $pivot);
    }

    /** Optional stale crops reconcile only inside the same current asset, never to another art role. */
    private function prepareFrame(GraphicalSpriteDefinition $frame, PresentationSpritePivot $pivot): ?GraphicalSpriteDefinition
    {
        try {
            $size = PngAssetPreflight::inspect($this->assetRoot, $frame->asset);
            $crop = $frame->sourceRect;
            if ($crop !== null && ($crop->x + $crop->width > $size['width'] || $crop->y + $crop->height > $size['height'])) {
                if ($crop->x >= $size['width'] || $crop->y >= $size['height']) {
                    throw new RuntimeException('The current world-object crop lies outside its asset: ' . $frame->asset);
                }
                $crop = new SpriteSourceRect($crop->x, $crop->y, min($crop->width, $size['width'] - $crop->x),
                    min($crop->height, $size['height'] - $crop->y));
                $this->reportFailure('Reconciled stale world-object crop inside current asset: ' . $frame->asset);
            } else { $this->lastFailure = null; }
            return new GraphicalSpriteDefinition($frame->asset, $frame->width, $frame->height, $frame->anchor,
                $frame->layer, $crop, $frame->lift, $frame->quarterTurns, $pivot);
        } catch (RuntimeException $error) {
            $this->reportFailure($error->getMessage());
            return null;
        }
    }

    private function reportFailure(string $failure): void
    {
        if ($failure !== $this->lastFailure) {
            Debug::warn($this->context . ': ' . $failure);
            $this->lastFailure = $failure;
        }
    }

    public function advance(float $seconds): void { $this->playback?->advance($seconds, Accessibility::prefersReducedMotion()); }
    public function pause(): void { $this->playback?->pause(); }
    public function resume(): void { if (!$this->released) { $this->playback?->resume(); } }
    public function release(): void { $this->released = true; $this->playback?->release(); }
}
