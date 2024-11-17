<?php

namespace Ichiloto\Engine\Scenes\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;

interface SceneInterface extends CanStart, CanResume, CanUpdate, CanRender
{

}