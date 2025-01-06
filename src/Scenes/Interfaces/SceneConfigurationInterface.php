<?php

namespace Ichiloto\Engine\Scenes\Interfaces;

use JsonSerializable;
use Serializable;
use Stringable;

/**
 * Represents the scene configuration interface.
 *
 * @package Ichiloto\Engine\Scenes\Interfaces
 */
interface SceneConfigurationInterface extends Stringable, Serializable, JsonSerializable
{
}