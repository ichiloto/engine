<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Audio\SpeechSequence;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Settings\SettingsCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Exception;

/** Dialogue-wide playback state. A conversation shares one instance across its lines. */
final class DialoguePlayback
{
  public const string CONFIG_AUTO = 'ui.dialogue.auto';
  public bool $auto = false {
    get => ConfigStore::has(ProjectConfig::class)
      ? boolval(ConfigStore::get(ProjectConfig::class)->get(self::CONFIG_AUTO, false)) : $this->auto;
    set {
      $this->auto = $value;
      if (ConfigStore::has(ProjectConfig::class)) {
        try {
          $settings = new SettingsCatalog();
          $settings->write('dialogue_auto', $value);
          $settings->persist();
        }
        catch (Exception $exception) { Debug::warn('Dialogue Auto changed for this session only: ' . $exception->getMessage()); }
      }
    }
  }
  private const float MINIMUM_READING_SECONDS = 1.0;
  private const float READING_CHARACTERS_PER_SECOND = 15.0;
  private ?AudioPlayback $voice = null;
  private ?SpeechSequence $sequence = null;
  private ?float $textCompletedAt = null;
  private float $readingSeconds = self::MINIMUM_READING_SECONDS;
  private int $pageIndex = -1;
  private bool $holdAfterAutoToggle = false;

  public function __construct(
    private readonly ?AudioManager $audio = null,
    ?bool $auto = null,
  ) {
    if ($auto !== null) { $this->auto = $auto; }
  }

  public function beginLine(?string $voicePath = null, float $musicDuckFactor = 1.0): void
  {
    $this->finishLine();
    $this->pageIndex = -1;
    $this->holdAfterAutoToggle = false;
    if ($voicePath === null && $this->sequence !== null) {
      $this->audio?->releaseSpeechDucking($this->sequence);
    }
    $this->voice = $voicePath === null ? null : $this->audio?->playSpeech($voicePath, $musicDuckFactor);
  }

  public function beginConversation(): void
  {
    $this->finishConversation();
    $this->sequence = $this->audio?->beginSpeechSequence();
  }

  public function finishConversation(): void
  {
    $this->finishLine();
    if ($this->sequence !== null) {
      $this->audio?->endSpeechSequence($this->sequence);
      $this->sequence = null;
    }
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
