<?php

namespace Ichiloto\Engine\Scenes\Game;

/** Project policy decides whether restored progress requires an activity before field play. */
interface SavedGameResumeHandlerInterface
{
    /** Return normally when ready; throw if a required activity cannot be restored. */
    public function resumeSavedGame(SavedGameResumeContext $context): void;
}
