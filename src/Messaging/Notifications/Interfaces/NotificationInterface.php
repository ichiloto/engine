<?php

namespace Ichiloto\Engine\Messaging\Notifications\Interfaces;

use Ichiloto\Engine\Core\Interfaces\CanRenderAt;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationSlideDirection;

/**
 * Interface NotificationInterface. Represents a notification.
 *
 * @package Ichiloto\Engine\Messaging\Notifications\Interfaces
 */
interface NotificationInterface extends CanUpdate, CanRenderAt, CanResume
{
  /**
   * Gets the notification channel.
   *
   * @return NotificationChannel Returns the notification channel.
   */
  public function getChannel(): NotificationChannel;

  /**
   * Sets the notification channel.
   *
   * @param NotificationChannel $channel The notification channel.
   * @return static Returns the notification.
   */
  public function setChannel(NotificationChannel $channel): static;

  /**
   * Gets the notification title.
   *
   * @return string Returns the notification title.
   */
  public function getContentTitle(): string;

  /**
   * Sets the notification title.
   *
   * @param string $contentTitle The notification title.
   * @return static Returns the notification.
   */
  public function setContentTitle(string $contentTitle): static;

  /**
   * Gets the notification text.
   *
   * @return string Returns the notification text.
   */
  public function getContentText(): string;

  /**
   * Sets the notification text.
   *
   * @param string $contentText The notification text.
   * @return static Returns the notification.
   */
  public function setContentText(string $contentText): static;

  /**
   * Gets the notification duration.
   *
   * @return float Returns the notification duration.
   */
  public function getDuration(): float;

  /**
   * Sets the notification duration.
   *
   * @param float $duration The notification duration.
   * @return static Returns the notification.
   */
  public function setDuration(float $duration): static;

  /**
   * Returns the notification animation duration in seconds.
   *
   * @return float The animation duration.
   */
  public function getAnimationDuration(): float;

  /**
   * Sets the entry slide direction.
   *
   * @param NotificationSlideDirection $direction The entry direction.
   * @return static Returns the notification.
   */
  public function setEnterDirection(NotificationSlideDirection $direction): static;

  /**
   * Sets the exit slide direction.
   *
   * @param NotificationSlideDirection $direction The exit direction.
   * @return static Returns the notification.
   */
  public function setExitDirection(NotificationSlideDirection $direction): static;

  /**
   * Opens the notification.
   *
   * @return static Returns the notification.
   */
  public function open(): static;

  /**
   * Closes the notification.
   *
   * @return static Returns the notification.
   */
  public function dismiss(): static;

  /**
   * Returns whether the notification has finished its full lifecycle.
   *
   * @return bool True when the notification is fully dismissed.
   */
  public function isFinished(): bool;
}
