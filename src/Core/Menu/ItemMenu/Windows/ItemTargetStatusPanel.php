<?php

namespace Ichiloto\Engine\Core\Menu\ItemMenu\Windows;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Entities\Character as Target;
use Ichiloto\Engine\Scenes\Game\States\ItemMenuState;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

class ItemTargetStatusPanel extends Window
{
  /**
   * @var Target|null The target of the status panel.
   */
  protected ?Target $target = null;

  /**
   * Creates a new instance of the ItemTargetStatusPanel.
   *
   * @param ItemMenuState $state The state of the item menu.
   * @param Rect $area The area of the window.
   * @param BorderPackInterface $borderPack The border pack of the window.
   */
  public function __construct(
    protected ItemMenuState $state,
    Rect $area,
    BorderPackInterface $borderPack,
  )
  {
    parent::__construct(
      Vocabulary::getTerm('command.status', 'Status'),
      '',
      $area->position,
      $area->size->width,
      $area->size->height,
      $borderPack,
    );
  }

  /**
   * Sets the target of the status panel.
   *
   * @param Target|null $target The target.
   */
  public function setTarget(?Target $target): void
  {
    $this->target = $target;
    $this->updateContent();
  }

  /**
   * Updates the content of the status panel.
   *
   * @return void
   */
  public function updateContent(): void
  {
    if (!$this->target) {
      $this->setContent(['', '']);
      $this->render();
      return;
    }

    $hp = "{$this->target->stats->currentHp} / {$this->target->stats->totalHp}";
    $mp = "{$this->target->stats->currentMp} / {$this->target->stats->totalMp}";
    $content = [
      sprintf("%s %02d %16s %12s", Vocabulary::getTerm('stats.level', 'Lvl'), $this->target->level, Vocabulary::getTerm('stats.hp', 'HP'), $hp),
      sprintf("%23s %12s", Vocabulary::getTerm('stats.mp', 'MP'), $mp),
    ];
    $this->setContent($content);
    $this->render();
  }
}
