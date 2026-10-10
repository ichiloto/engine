<?php

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Rendering\Sprites\ScreenSpaceSpriteProviderInterface;

/** A field effect pinned to the screen, outside camera-follow transforms. */
readonly class FieldScreenSprite extends FieldEffectSprite implements ScreenSpaceSpriteProviderInterface {}
