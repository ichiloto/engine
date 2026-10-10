<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * The kinds of value a registered script command field holds.
 *
 * Authoring tools choose their controls from the kind, and the shared
 * validation in ScriptCommandField checks authored values against it.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
enum ScriptCommandFieldKind: string
{
  /** A line of text. */
  case TEXT = 'text';
  /** A whole number. */
  case INTEGER = 'integer';
  /** Any number. */
  case NUMBER = 'number';
  /** True or false. */
  case BOOLEAN = 'boolean';
  /** One of the field's declared options. */
  case OPTION = 'option';
  /** The name of another project resource, chosen rather than typed. */
  case REFERENCE = 'reference';
  /** A map cell, as whole-number `x` and `y`. */
  case POSITION = 'position';
  /** A list of entries, each holding the field's own fields. */
  case LIST = 'list';
}
