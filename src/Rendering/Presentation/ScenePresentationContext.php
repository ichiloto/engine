<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Closure;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use InvalidArgumentException;

/** Host-selected presentation only; scene providers and existing sessions still own projection and simulation. */
final readonly class ScenePresentationContext
{
    /**
     * @param Closure(string): bool $supportsCapability Use the same capability reader for the frame composer/presenter.
     * @param Closure(): list<LayeredPresentationInterface>|null $collectPresentations Active owners, top first, as UIManager reports them.
     * @param Closure(): float|null $readTime The host playhead in seconds, not a second animation clock.
     */
    public function __construct(
        public RendererGridConfig $grid,
        private Closure $supportsCapability,
        public bool $graphical = true,
        public bool $fieldActive = true,
        private ?Closure $collectPresentations = null,
        private ?Closure $readTime = null,
        public ?string $assetRoot = null,
    ) {}

    public function supports(string $capability): bool
    {
        return $this->graphical && ($this->supportsCapability)($capability);
    }

    /** An isolated host never borrows the runtime singleton's dialogue/modal registry. */
    public function getActivePresentations(): array
    {
        $owners = $this->collectPresentations === null ? [] : ($this->collectPresentations)();
        if (!is_array($owners) || !array_is_list($owners)
            || array_any($owners, static fn($owner) => !$owner instanceof LayeredPresentationInterface)) {
            throw new InvalidArgumentException('Scene presentation owners must be an ordered list of layered presentations.');
        }
        return $owners;
    }

    public function getPresentationTime(): float
    {
        $seconds = $this->readTime === null ? Time::getTime() : ($this->readTime)();
        if ((!is_int($seconds) && !is_float($seconds)) || !is_finite((float)$seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Scene presentation time must be finite non-negative seconds.');
        }
        return (float)$seconds;
    }
}
