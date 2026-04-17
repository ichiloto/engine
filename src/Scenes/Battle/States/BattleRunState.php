<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\BattleEngineContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedBattleConfig;
use Ichiloto\Engine\Battle\Interfaces\BattleEngineContextInterface;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\Scenes\SceneStateContext;
use RuntimeException;

/**
 * Represents the battle run state.
 *
 * @package Ichiloto\Engine\Scenes\Battle\States
 */
class BattleRunState extends BattleSceneState
{
  /**
   * @var BattleEngineContextInterface|null The battle engine context.
   */
  protected ?BattleEngineContextInterface $battleEngineContext = null;

  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    $characters = $this->scene->party->battlers->toArray();
    $names = array_map(fn(CharacterInterface $character) => $character->name, $characters);
    $settings = is_array($this->scene->config?->settings ?? null) ? $this->scene->config->settings : [];
    $activeTimeSettings = is_array($settings['activeTime'] ?? null) ? $settings['activeTime'] : [];

    $this->ui->render();
    $this->ui->characterNameWindow->setNames($names);
    $this->ui->characterStatusWindow->setCharacters($characters);
    $this->ui->characterStatusWindow->clearAtbPercentages();

    $this->battleEngineContext = new BattleEngineContext(
      $this->scene->getGame(),
      $this->scene->party,
      $this->scene->troop,
      $this->ui
    );

    if ($this->engine instanceof ActiveTimeBattleEngine) {
      $this->engine->configure(new ActiveTimeBattleConfig(
        $this->scene->party,
        $this->scene->troop,
        $this->ui,
        $this->scene->events,
        strval($activeTimeSettings['mode'] ?? 'wait'),
        max(1.0, floatval($activeTimeSettings['baseFillRate'] ?? 35)),
        max(0.0, floatval($activeTimeSettings['speedFactorPercent'] ?? 100)) / 100,
        max(0.0, floatval($activeTimeSettings['openingVariance'] ?? 24)),
        max(0.0, floatval($activeTimeSettings['openingSpeedFactorPercent'] ?? 250)) / 100,
        min(100, max(0, intval($activeTimeSettings['surpriseAttackChancePercent'] ?? 8))),
        min(100, max(0, intval($activeTimeSettings['backAttackChancePercent'] ?? 6))),
        $settings,
      ));
    } else {
      $this->engine->configure(new TurnBasedBattleConfig(
        $this->scene->party,
        $this->scene->troop,
        $this->ui,
        $this->scene->events,
        $settings,
      ));
    }

    $this->engine->start();
  }

  /**
   * @inheritDoc
   * @throws \Exception
   */
  public function execute(?SceneStateContext $context = null): void
  {
    $this->handleActions();
    $this->engine->run($this->battleEngineContext ?? throw new RuntimeException('Battle engine context is not set.'));

    if ($this->scene->getGame()->sceneManager->currentScene !== $this->scene) {
      return;
    }

    $this->ui->update();
  }

  /**
   * @return void
   * @throws \Exception
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown('quit')) {
      $this->scene->getGame()->quit();
    }

    if (Input::isButtonDown('pause')) {
      $this->setState($this->scene->pauseState);
    }
  }
}