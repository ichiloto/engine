<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Measured composition demand exceeds the owning session's bounded resource contract. */
final class CanvasCompositeBudgetExceeded extends InvalidArgumentException {}
