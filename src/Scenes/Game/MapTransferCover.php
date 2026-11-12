<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Game;

use Closure;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Rendering\ScreenTransitionSession;

/** Keeps the configured graphical cover across destination preparation and presentation. */
final class MapTransferCover
{
    private ScreenTransitionSession $session;
    private ?Closure $onRevealed = null;

    public function __construct(private readonly ScreenTransition $transition)
    {
        $this->session = $transition->session(graphicalOnly: true);
    }

    public function advanceCover(float $seconds): bool
    {
        if ($this->session->isComplete) { return true; }

        $this->session->update($seconds);
        // Let the renderer submit the fully covered origin before installation
        // suppresses new frames while the replacement world is prepared.
        return false;
    }

    public function advanceReveal(float $seconds): bool
    {
        return $this->session->update($seconds);
    }

    public function getCanvas(int $width, int $height): ?PresentationCanvas
    {
        return $this->session->getPresentationCanvas($width, $height);
    }

    public function beginReveal(Closure $onRevealed): void
    {
        $this->session = $this->transition->session('in', graphicalOnly: true);
        $this->onRevealed = $onRevealed;
    }

    public function getRevealCompletion(): ?Closure
    {
        return $this->onRevealed;
    }
}
