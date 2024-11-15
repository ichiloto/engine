<?php

namespace Ichiloto\Engine\Core\Interfaces;

/**
 * CanResume is an interface implemented by all classes that can resume.
 *
 * @package Ichiloto\Engine\Core\Interfaces
 */
interface CanResume
{
  /**
   * Resumes the object.
   */
  public function resume(): void;

  /**
   * Suspends the object.
   */
  public function suspend(): void;
}