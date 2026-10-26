<?php

namespace Ichiloto\Engine\Scenes\Game;

/** The exact restored field owner exposes existing project state and excursion services. */
final readonly class SavedGameResumeContext
{
    public function __construct(public GameScene $scene) {}
}
