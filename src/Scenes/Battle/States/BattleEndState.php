<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneStateContext;

class BattleEndState extends BattleSceneState
{
  /**
   * @inheritDoc
   */
  public function enter(): void
  {
    // Only states flagged persistent (classic poison) follow the party out
    // of battle; everything else — including buff/debuff stages — clears
    // here.
    foreach ($this->scene->party?->battlers?->toArray() ?? [] as $battler) {
      if (method_exists($battler, 'clearBattleStates')) {
        $battler->clearBattleStates();
      }

      if (method_exists($battler, 'resetStatStages')) {
        $battler->resetStatStages();
      }
    }

    $this->scene->resultWindow?->erase();
    $this->scene->ui?->erase();
    $this->engine->stop();
    $this->scene->stop();
    Console::clear();

    if ($this->scene->shouldLoadGameOver) {
      $gameScene = $this->scene->getGame()->sceneManager->findScene(GameScene::class);

      if ($gameScene instanceof GameScene) {
        $gameScene->failEventAfterBattle(
          'Scripted battle ended in defeat under the default game-over policy.'
        );
      }

      $this->scene->getGame()->sceneManager->loadGameOverScene();
      return;
    }

    $this->scene->getGame()->sceneManager->returnFromBattleScene();
  }

  public function execute(?SceneStateContext $context = null): void
  {
    // Do nothing. Transition happens when the state is entered.
  }
}
