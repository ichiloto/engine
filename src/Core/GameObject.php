<?php

namespace Ichiloto\Engine\Core;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Interfaces\CanActivate;
use Ichiloto\Engine\Core\Interfaces\CanCompare;
use Ichiloto\Engine\Core\Interfaces\CanEquate;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanStart;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\Interfaces\SubjectInterface;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

abstract class GameObject implements CanActivate, SubjectInterface, CanUpdate, CanStart, CanRender, CanResume, CanCompare
{
  /**
   * @var ItemList<ObserverInterface> $observers The list of observers.
   */
  protected ItemList $observers;
  /**
   * @var string $hash The hash of the object.
   */
  protected string $hash = '';
  /**
   * @var bool $isActive Determines whether the object is active.
   */
  protected bool $isActive = false;

  /**
   * GameObject constructor.
   *
   * @param SceneInterface $scene The scene of the object.
   * @param string $name The name of the object.
   * @param Vector2 $position The position of the object.
   * @param Vector2 $size The size of the object.
   * @param string[] $sprite The sprite of the object.
   */
  public function __construct(
    protected SceneInterface $scene,
    protected string $name,
    protected Vector2 $position = new Vector2(),
    protected Vector2 $size = new Vector2(1, 1),
    protected array $sprite = ['x']
  )
  {
    $this->hash = uniqid(md5(__CLASS__) . 'Core' . md5($this->name));
    $this->observers = new ItemList(ObserverInterface::class);
  }

  /**
   * @inheritDoc
   */
  public function activate(): void
  {
    $this->isActive = true;
    $this->render();
  }

  /**
   * @inheritDoc
   */
  public function deactivate(): void
  {
    $this->isActive = false;
    $this->erase();
  }

  /**
   * Determines whether the object is active.
   *
   * @return bool Returns true if the object is active, otherwise false.
   */
  public function isActive(): bool
  {
    return $this->isActive;
  }

  /**
   * @inheritDoc
   */
  public function getHash(): string
  {
    return $this->hash;
  }

  /**
   * Determines whether this object and the given equatable are equal.
   *
   * @param CanEquate $equatable The other equatable.
   * @return bool Returns true if this object and the given equatable are equal, otherwise false.
   */
  public function equals(CanEquate $equatable): bool
  {
    return $this->getHash() === $equatable->getHash();
  }

  /**
   * Determines whether this object and the given equatable are NOT equal.
   *
   * @param CanEquate $equatable The other equatable.
   * @return bool Returns true if this object and the given equatable are NOT equal, otherwise false.
   */
  public function notEquals(CanEquate $equatable): bool
  {
    return !$this->equals($equatable);
  }

  /**
   * Returns the position of the object.
   *
   * @return Vector2 The position of the object.
   */
  public function getPosition(): Vector2
  {
    return $this->position;
  }

  /**
   * Sets the position of the object.
   *
   * @param Vector2 $position The position of the object.
   */
  public function setPosition(Vector2 $position): void
  {
    $this->position = $position;
  }

  /**
   * Returns the size of the object.
   *
   * @return Vector2 The size of the object.
   */
  public function getSize(): Vector2
  {
    return $this->size;
  }

  /**
   * Returns the name of the object.
   *
   * @return string The name of the object.
   */
  public function getName(): string
  {
    return $this->name;
  }

  /**
   * Returns the sprite of the object.
   *
   * @return string[] The sprite of the object.
   */
  public function getSprite(): array
  {
    return $this->sprite;
  }

  /**
   * Sets the sprite of the object.
   *
   * @param string[] $sprite The sprite of the object.
   * @return void
   */
  public function setSprite(array $sprite): void
  {
    $this->sprite = $sprite;
  }
}