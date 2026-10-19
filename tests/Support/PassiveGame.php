<?php

namespace Tests\Support;

use Ichiloto\Engine\Core\Game;

/** A dependency fixture that owns neither Game bootstrap nor terminal cleanup. */
class PassiveGame extends Game
{
    public function __construct()
    {
    }

    public function __destruct()
    {
    }
}
