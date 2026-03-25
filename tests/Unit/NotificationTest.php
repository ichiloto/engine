<?php

use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationSlideDirection;
use Ichiloto\Engine\Messaging\Notifications\Notification;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

beforeEach(function () {
  if (ConfigStore::doesntHave(PlaySettings::class)) {
    ConfigStore::put(PlaySettings::class, new PlaySettings([
      'width' => DEFAULT_SCREEN_WIDTH,
      'height' => DEFAULT_SCREEN_HEIGHT,
      'screen' => [
        'width' => DEFAULT_SCREEN_WIDTH,
        'height' => DEFAULT_SCREEN_HEIGHT,
      ],
    ]));
  }
});

it('slides notifications using the configured entry and exit directions', function () {
  $notification = makeNotificationForTest(
    new Vector2(40, 0),
    NotificationSlideDirection::LEFT,
    NotificationSlideDirection::UP,
    0.2
  );

  Time::setElapsedTime(0.0);
  $notification->open();

  expect(getNotificationRenderPosition($notification)->x)->toBe((float)-Notification::WIDTH)
    ->and(getNotificationRenderPosition($notification)->y)->toBe(0.0);

  Time::setElapsedTime(0.2);
  $notification->update();

  expect(getNotificationRenderPosition($notification)->x)->toBe(40.0)
    ->and(getNotificationRenderPosition($notification)->y)->toBe(0.0)
    ->and($notification->isFinished())->toBeFalse();

  $notification->dismiss();
  Time::setElapsedTime(0.3);
  $notification->update();

  expect(getNotificationRenderPosition($notification)->y)->toBeLessThan(0);

  Time::setElapsedTime(0.4);
  $notification->update();

  expect($notification->isFinished())->toBeTrue();
});

it('starts fully off-screen when sliding in from the right', function () {
  $notification = makeNotificationForTest(
    new Vector2(40, 0),
    NotificationSlideDirection::RIGHT,
    NotificationSlideDirection::RIGHT,
    0.2
  );

  Time::setElapsedTime(0.0);
  $notification->open();

  expect(getNotificationRenderPosition($notification)->x)->toBe((float)get_screen_width())
    ->and(getNotificationRenderPosition($notification)->y)->toBe(0.0);
});

/**
 * Creates a notification instance suitable for lifecycle unit tests.
 *
 * @param Vector2 $anchorPosition The visible resting position.
 * @param NotificationSlideDirection $enterDirection The entry direction.
 * @param NotificationSlideDirection $exitDirection The exit direction.
 * @param float $animationDuration The animation duration.
 * @return Notification The configured notification.
 */
function makeNotificationForTest(
  Vector2 $anchorPosition,
  NotificationSlideDirection $enterDirection,
  NotificationSlideDirection $exitDirection,
  float $animationDuration
): Notification
{
  $notification = (new ReflectionClass(Notification::class))->newInstanceWithoutConstructor();
  $eventManager = (new ReflectionClass(EventManager::class))->newInstanceWithoutConstructor();
  $window = new Window('', '', $anchorPosition, Notification::WIDTH, Notification::HEIGHT);

  setNotificationProperty($notification, 'eventManager', $eventManager);
  setNotificationProperty($notification, 'window', $window);
  setNotificationProperty($notification, 'position', $anchorPosition);
  setNotificationProperty($notification, 'renderPosition', clone $anchorPosition);
  setNotificationProperty($notification, 'contentTitle', 'Title');
  setNotificationProperty($notification, 'contentText', 'Text');
  setNotificationProperty($notification, 'enterDirection', $enterDirection);
  setNotificationProperty($notification, 'exitDirection', $exitDirection);
  setNotificationProperty($notification, 'animationDuration', $animationDuration);

  return $notification;
}

/**
 * Returns the notification's current animated render position.
 *
 * @param Notification $notification The notification under test.
 * @return Vector2 The current render position.
 */
function getNotificationRenderPosition(Notification $notification): Vector2
{
  return getNotificationProperty($notification, 'renderPosition');
}

/**
 * Writes a protected notification property for test setup.
 *
 * @param Notification $notification The notification under test.
 * @param string $propertyName The property name to write.
 * @param mixed $value The value to assign.
 * @return void
 */
function setNotificationProperty(Notification $notification, string $propertyName, mixed $value): void
{
  $property = new ReflectionProperty(Notification::class, $propertyName);
  $property->setValue($notification, $value);
}

/**
 * Reads a protected notification property.
 *
 * @param Notification $notification The notification under test.
 * @param string $propertyName The property name to read.
 * @return mixed The property value.
 */
function getNotificationProperty(Notification $notification, string $propertyName): mixed
{
  $property = new ReflectionProperty(Notification::class, $propertyName);

  return $property->getValue($notification);
}
