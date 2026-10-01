<?php

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Rendering\Sprites\ScreenSpaceSpriteProviderInterface;

/** Pinned field navigation image, intentionally excluded from viewport follow. */
final readonly class FieldEdgeSprite extends FieldEffectSprite implements ScreenSpaceSpriteProviderInterface {}
