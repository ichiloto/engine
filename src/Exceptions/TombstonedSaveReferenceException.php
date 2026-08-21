<?php

namespace Ichiloto\Engine\Exceptions;

/** A saved identity was deliberately removed without a content migration. */
class TombstonedSaveReferenceException extends SaveCompatibilityException
{
}
