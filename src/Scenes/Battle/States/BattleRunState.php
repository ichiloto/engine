<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

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
    $this->ui->render();
    $this->ui->characterNameWindow->setNames($names);
    $this->ui->characterStatusWindow->setCharacters($characters);

    $this->battleEngineContext = new BattleEngineContext(
      $this->scene->getGame(),
      $this->scene->party,
      $this->scene->troop,
      $this->ui
    );
    $this->engine->configure(new TurnBasedBattleConfig(
      $this->scene->party,
      $this->scene->troop,
      $this->ui,
      $this->scene->events,
    ));
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
    $this->ui->update();
  }

  /**
   * @return void
   * @throws \Exception
   */
  protected function handleActions(): void
  {
    if (Input::isButtonDown("quit")) {
      $this->scene->getGame()->quit();
    }

    if (Input::isButtonDown("pause")) {
      $this->setState($this->scene->pauseState);
    }
  }
}