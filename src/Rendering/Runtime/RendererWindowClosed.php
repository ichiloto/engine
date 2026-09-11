<?php

namespace Ichiloto\Engine\Rendering\Runtime;

/** Unwinds nested engine waits to Game's normal quit path, not its crash handler. */
final class RendererWindowClosed extends \RuntimeException
{
}
