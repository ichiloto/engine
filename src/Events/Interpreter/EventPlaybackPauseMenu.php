<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

use Closure;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Throwable;

/** Cooperative shared modal flow; the interpreter owns playback and outcomes. */
class EventPlaybackPauseMenu
{
    protected ?SelectModal $modal = null;
    /** @var list<PlaybackPauseAction> */
    private array $actions = [];
    private bool $choosingExit = false;
    private ?int $sessionId = null;
    private int $width = 0;
    private int $height = 0;
    public bool $isOpen { get => $this->modal !== null; }

    /** @param Closure(): void $toTitle @param Closure(): void $quit @param Closure(): void $redraw */
    public function __construct(
        private readonly Game $game,
        private readonly EventInterpreter $interpreter,
        private readonly Closure $toTitle,
        private readonly Closure $quit,
        private readonly Closure $redraw,
    ) {}

    public function open(): bool
    {
        if ($this->isOpen || !$this->interpreter->pausePlayback()) { return false; }
        $this->sessionId = $this->interpreter->activeSession()?->id;
        try { $this->showMenu(); }
        catch (Throwable $error) { $this->close(); throw $error; }
        return true;
    }

    private function showMenu(?int $selection = null): void
    {
        $this->hideModal();
        $this->actions = $this->choosingExit
            ? [PlaybackPauseAction::TITLE, PlaybackPauseAction::QUIT, PlaybackPauseAction::CANCEL]
            : [...($this->interpreter->canSkipPlayback() ? [PlaybackPauseAction::SKIP] : []),
                PlaybackPauseAction::EXIT, PlaybackPauseAction::CANCEL];
        $this->width = get_screen_width();
        $this->height = get_screen_height();
        $width = max(1, min(32, $this->width));
        $height = min($this->height, count($this->actions) + 5);
        $this->modal = $this->createModal($this->choosingExit ? 'Exit to title or quit the game?' : '',
            array_column($this->actions, 'value'), $this->choosingExit ? 'Exit' : 'Paused',
            $selection ?? count($this->actions) - 1,
            new Rect(max(0, intdiv($this->width - $width, 2)), max(0, intdiv($this->height - $height, 2)), $width, $height));
        InputManager::consumeCurrentInput();
        $this->modal->show();
    }

    /** @param list<string> $options */
    protected function createModal(string $message, array $options, string $title, int $default, Rect $rect): SelectModal
    {
        return new SelectModal($this->game, $message, $options, $title, $default, $rect);
    }

    public function update(): void
    {
        if (!$this->isOpen) { return; }
        if ($this->game->hasStopped() || !$this->ownsSession() || !$this->interpreter->isPlaybackPaused) {
            $this->close();
            return;
        }
        if ($this->width !== get_screen_width() || $this->height !== get_screen_height()) {
            $this->showMenu($this->modal->getActiveIndex());
        }
        $this->modal->update();
        if ($this->modal->isShowing()) { return; }
        $action = $this->actions[$this->modal->getValue()] ?? PlaybackPauseAction::CANCEL;
        if ($action === PlaybackPauseAction::EXIT) {
            $this->choosingExit = true;
            $this->showMenu();
            return;
        }
        if ($action === PlaybackPauseAction::CANCEL && $this->choosingExit) {
            $this->choosingExit = false;
            $this->showMenu();
            return;
        }
        $this->hideModal();
        InputManager::consumeCurrentInput();
        try {
            if ($action === PlaybackPauseAction::SKIP) {
                $this->interpreter->skipActivePlayback();
            } elseif ($action === PlaybackPauseAction::TITLE || $action === PlaybackPauseAction::QUIT) {
                // Normal scene shutdown also cancels its unfinished event; do it
                // before the title/quit continuation can run another scene.
                $this->interpreter->failActiveSession('Playback interrupted by player exit.');
                ($action === PlaybackPauseAction::TITLE ? $this->toTitle : $this->quit)();
            }
        } finally {
            $this->close();
            ($this->redraw)();
        }
    }

    public function render(): void
    {
        if ($this->modal?->isShowing()) { $this->modal->render(); }
    }

    /** Releases this menu only; a later session keeps its own playback ownership. */
    public function close(): void
    {
        $this->hideModal();
        InputManager::consumeCurrentInput();
        if ($this->ownsSession()) { $this->interpreter->resumePlayback(); }
        $this->sessionId = null;
    }

    private function hideModal(): void
    {
        $modal = $this->modal;
        $this->modal = null;
        if ($modal?->isShowing()) { $modal->hide(); }
    }

    private function ownsSession(): bool
    {
        return $this->sessionId !== null && $this->interpreter->activeSession()?->id === $this->sessionId;
    }
}
