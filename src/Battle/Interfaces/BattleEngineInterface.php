<?php

namespace Ichiloto\Engine\Battle\Interfaces;

use Ichiloto\Engine\Scenes\Battle\BattleConfig;

/**
 * Represents the battle engine interface.
 *
 * @package Ichiloto\Engine\Battle\Interfaces
 */
interface BattleEngineInterface
{
  /**
   * Configures the battle engine.
   *
   * @param BattleConfig $config The battle configuration.
   */
  public function configure(BattleConfig $config): void;

  /**
   * Starts the battle engine.
   */
  public function start(): void;

  /**
   * Runs the battle engine.
   */
  public function run(BattleEngineContextInterface $context): void;

  /**
   * Stops the battle engine.
   */
  public function stop(): void;
}