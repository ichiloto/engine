<?php

use Assegai\Collections\Stack;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\PagedAlertModal;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

class PagedAlertGameProbe extends Game
{
    public function __construct() {}
    public function __destruct() {}
}

class PagedAlertProbe extends PagedAlertModal
{
    public function getPages(): array { return $this->pages; }
    public function advancePage(): void { $this->submit(); }
}

class AlertDeliveryProbe extends ModalManager
{
    public array $delivered = [];
    public bool $fail = false;

    public function __construct(Game $game)
    {
        $this->game = $game;
        $this->modals = new Stack(ModalInterface::class);
    }

    protected function showPendingAlert(string $message, string $title): void
    {
        if ($this->fail) { throw new RuntimeException('Interrupted delivery'); }
        $this->delivered[] = [$message, $title];
    }

    protected function showPendingNotice(\Ichiloto\Engine\UI\Modal\NoticeContent $notice): void
    {
        if ($this->fail) { throw new RuntimeException('Interrupted delivery'); }
        $this->delivered[] = $notice;
    }

    public function releaseActiveModal(): void { $this->modals->pop(); }
}

beforeEach(function () {
    $this->saved = [];
    foreach ([Console::class, ConfigStore::class, EventManager::class] as $class) {
        $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    Console::setTerminalOutputEnabled(false);
    Console::syncDimensions(80, 24);
    ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
    new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
    $this->game = new PagedAlertGameProbe();
});

afterEach(function () {
    foreach ($this->saved as $class => $properties) {
        foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
    }
});

it('preserves every explicit line and reward count across acknowledged alert pages', function () {
    $lines = array_map(fn($i) => 'Reward ' . $i . ': 123,456 G x12', range(1, 100));
    $modal = new PagedAlertProbe($this->game, implode("\n", $lines), 'Rewards');
    expect(explode("\n", implode("\n", $modal->getPages())))->toBe($lines);
    foreach ($modal->getPages() as $index => $page) {
        expect(count(explode("\n", $page)))->toBeLessThanOrEqual(6)
            ->and($modal->getModalPresentation()->message)->toBe($page)
            ->and($modal->getModalPresentation()->choices)->toBe([$index < count($modal->getPages()) - 1 ? 'Next' : 'OK']);
        if ($index < count($modal->getPages()) - 1) { $modal->advancePage(); }
    }
});

it('keeps long headings and blank paragraphs as paged information', function () {
    $title = str_repeat('Long named assignment ', 4);
    $modal = new PagedAlertProbe($this->game, "First\r\n\r\nLast café", $title);
    $text = implode("\n", $modal->getPages());
    expect($modal->getModalPresentation()->title)->toBe('Information')
        ->and(str_replace("\n", ' ', $text))->toContain(trim($title))
        ->and($text)->toContain("First\n\nLast café");
});

it('defers alert input until an active modal ends and retains FIFO delivery', function () {
    $manager = new AlertDeliveryProbe($this->game);
    $modal = $this->createMock(ModalInterface::class);
    $modal->expects($this->once())->method('show');
    $modal->expects($this->never())->method('update');
    $manager->open($modal);
    $manager->queueAlert('First body', 'First');
    $manager->queueAlert('Second body', 'Second');
    $manager->processPendingAlerts();
    expect($manager->delivered)->toBe([])->and($manager->currentModal)->toBe($modal);
    $manager->releaseActiveModal();
    $manager->processPendingAlerts();
    expect($manager->delivered)->toBe([['First body', 'First']]);
    $manager->processPendingAlerts();
    $manager->processPendingAlerts();
    expect($manager->delivered)->toBe([['First body', 'First'], ['Second body', 'Second']]);
});

it('preserves a queued alert after delivery is interrupted', function () {
    $manager = new AlertDeliveryProbe($this->game);
    $manager->queueAlert('Full body', 'Title');
    $manager->fail = true;
    expect(fn() => $manager->processPendingAlerts())->toThrow(RuntimeException::class, 'Interrupted delivery');
    $manager->fail = false;
    $manager->processPendingAlerts();
    expect($manager->delivered)->toBe([['Full body', 'Title']]);
});

it('preserves notice and alert producer order and retries an interrupted notice without losing it', function () {
    $manager = new AlertDeliveryProbe($this->game);
    $notice = new \Ichiloto\Engine\UI\Modal\NoticeContent('Member joined', 'A companion.', actorId: 'actor.synthetic');
    $manager->queueAlert('First body', 'First');
    $manager->queueNotice($notice);
    $manager->queueAlert('Last body', 'Last');
    $manager->processPendingAlerts();
    $manager->fail = true;
    expect(fn() => $manager->processPendingAlerts())->toThrow(RuntimeException::class, 'Interrupted delivery');
    $manager->fail = false;
    $manager->processPendingAlerts(); $manager->processPendingAlerts(); $manager->processPendingAlerts();
    expect($manager->delivered)->toBe([['First body', 'First'], $notice, ['Last body', 'Last']]);
});

it('uses ordinary confirmation and text fields for a notice in every presentation', function () {
    $notice = new \Ichiloto\Engine\UI\Modal\NoticeContent('New power', 'An unlocked technique.', iconRole: 'power.synthetic');
    $modal = new \Ichiloto\Engine\UI\Modal\NoticeModal($this->game, $notice);
    expect($modal->title)->toBe($notice->title)->and($modal->message)->toBe($notice->message)
      ->and($modal->getModalPresentation()->notice)->toBe($notice)
      ->and($modal->getModalPresentation()->singleConfirmation)->toBeTrue()
      ->and($modal->getModalPresentation()->choices)->toBe(['OK']);
});

it('refuses ambiguous or empty notice content', function (array $args) {
    expect(fn() => new \Ichiloto\Engine\UI\Modal\NoticeContent(...$args))->toThrow(InvalidArgumentException::class);
})->with([[['', '']], [['Title', 'Text', '']], [['Title', 'Text', ' actor']], [['Title', 'Text', null, '']],
    [['Title', 'Text', 'actor', 'power']]]);

it('retains long notice content across the existing bounded acknowledgement pages', function () {
    $lines = array_map(static fn(int $i): string => 'Acquisition detail ' . $i, range(1, 24));
    $modal = new \Ichiloto\Engine\UI\Modal\NoticeModal($this->game,
      new \Ichiloto\Engine\UI\Modal\NoticeContent('New power', implode("\n", $lines)));
    $pages = new ReflectionProperty(PagedAlertModal::class, 'pages')->getValue($modal);
    expect(explode("\n", implode("\n", $pages)))->toBe($lines)
      ->and($modal->getModalPresentation()->choices)->toBe(['Next']);
});
