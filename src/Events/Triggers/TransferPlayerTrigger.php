<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Exceptions\IchilotoException;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Field\Location;
use Override;

/**
 * The TransferPlayerTrigger class.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class TransferPlayerTrigger extends EventTrigger
{
  /**
   * @var string The destination map.
   */
  protected string $destinationMap = '';
  /**
   * @var Vector2 The spawn point.
   */
  protected Vector2 $spawnPoint;
  /**
   * @var string[] The spawn sprite.
   */
  protected array $spawnSprite;

  /**
   * @inheritDoc
   * @throws RequiredFieldException Thrown when a required field is missing.
   */
  public function configure(): void
  {
    $this->destinationMap = $this->data['destinationMap'] ?? throw new RequiredFieldException('destinationMap');
    $this->spawnPoint = new Vector2(
      $this->data['spawnPoint']['x'] ?? throw new RequiredFieldException('spawnPoint.x'),
      $this->data['spawnPoint']['y'] ?? throw new RequiredFieldException('spawnPoint.y')
    );
    $this->spawnSprite = $this->data['spawnSprite'] ?? throw new RequiredFieldException('spawnSprite');
  }


  /**
   * @inheritDoc
   * @throws IchilotoException Thrown when an error occurs.
   * @throws NotFoundException Thrown when the destination map is not found.
   */
  #[Override]
  public function enter(EventTriggerContextInterface $context): void
  {
    $destination = new Location($this->destinationMap, $this->spawnPoint, $this->spawnSprite);
    $context->scene->transferPlayer($destination);
  }
}