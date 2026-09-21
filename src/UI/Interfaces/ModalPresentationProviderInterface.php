<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Interfaces;

use Ichiloto\Engine\UI\Modal\ModalPresentation;

interface ModalPresentationProviderInterface
{
  /** Null means this modal requires its existing terminal presentation. */
  public function getModalPresentation(): ?ModalPresentation;
}
