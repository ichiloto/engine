<?php

namespace Ichiloto\Engine\Exceptions;

/** A serialized persistent character state cannot be reconstructed. */
class PersistentStateRestoreException extends UnresolvedSaveReferenceException
{
}
