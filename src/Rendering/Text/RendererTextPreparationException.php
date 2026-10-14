<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Text;

use RuntimeException;

/** A correlated resource refusal, not a failed gameplay or retained-frame update. */
final class RendererTextPreparationException extends RuntimeException {}
