<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\IO\Console\TerminalText;

/** Dialogue-wide playback state. A conversation shares one instance across its lines. */
final class DialoguePlayback
{
  private const float MINIMUM_READING_SECONDS = 1.0;
  private const float READING_CHARACTERS_PER_SECOND = 15.0;
  private ?AudioPlayback $voice = null;
  private ?float $textCompletedAt = null;
  private float $readingSeconds = self::MINIMUM_READING_SECONDS;
  private int $pageIndex = -1;
  private bool $holdAfterAutoToggle = false;

  public function __construct(
    private readonly ?AudioManager $audio = null,
    public bool $auto = false,
  ) {}

  public function beginLine(?string $voicePath = null, float $musicDuckFactor = 1.0): void
  {
    $this->finishLine();
    $this->pageIndex = -1;
    $this->holdAfterAutoToggle = false;
    $this->voice = $voicePath === null ? null : $this->audio?->playSpeech($voicePath, $musicDuckFactor);
  }

  public function beginPage(string $text): void
  {
    ++$this->pageIndex;
    $this->textCompletedAt = null;
    $this->readingSeconds = max(self::MINIMUM_READING_SECONDS,
      mb_strlen(TerminalText::stripAnsi($text)) / self::READING_CHARACTERS_PER_SECOND);
  }

  public function toggleAuto(): void
  {
    $this->auto = ! $this->auto;
    // Enabling Auto never dismisses already-visible text immediately.
    $this->textCompletedAt = null;
    $this->holdAfterAutoToggle = $this->auto;
  }

  public function canAdvance(float $now, bool $isTyping, bool $isLastPage): bool
  {
    $playing = $this->voice !== null && ($this->audio?->isSpeechPlaying($this->voice) ?? false);
    if ($isTyping || ! $this->auto) {
      $this->textCompletedAt = null;
      return false;
    }
    $this->textCompletedAt ??= $now;
    if ($this->holdAfterAutoToggle && $now - $this->textCompletedAt < self::MINIMUM_READING_SECONDS) {
      return false;
    }
    $voiced = $this->voice !== null && ! $this->voice->wasInterrupted && ($this->voice->exitCode ?? 0) === 0;
    if ($isLastPage && $voiced && $this->pageIndex === 0) {
      return ! $playing;
    }
    // Wrapped beats still provide time to read each page, even if voice has ended.
    return (! $isLastPage || ! $playing) && $now - $this->textCompletedAt >= $this->readingSeconds;
  }

  public function finishLine(): void
  {
    if ($this->voice !== null) {
      $this->audio?->stopSpeech($this->voice);
      $this->voice = null;
    }
    $this->textCompletedAt = null;
  }
}
