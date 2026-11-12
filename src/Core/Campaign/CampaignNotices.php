<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core\Campaign;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\UI\Modal\NoticeContent;

/** Campaign-owned notices use the same queue in the field and in non-blocking hosts. */
final class CampaignNotices
{
    /** @var \WeakMap<Party, \WeakReference<self>>|null */
    private static ?\WeakMap $owners = null;
    private ?\WeakReference $previousOwner;
    private bool $released = false;

    public function __construct(private readonly Game $game, private readonly Party $party)
    {
        self::$owners ??= new \WeakMap();
        $this->previousOwner = self::$owners[$party] ?? null;
        self::$owners[$party] = \WeakReference::create($this);
        // Members supplied by construction or hydration are already in this campaign.
        $party->clearPendingJoins();
    }

    private function __clone() {}

    public function queueNotice(string $title, string $text): void
    {
        if ($this->released) { return; }
        $this->game->getModalManager()->queueNotice(new NoticeContent($title, $text), $this);
    }

    public function queueRecruitmentNotices(): void
    {
        if ($this->released || (self::$owners[$this->party] ?? null)?->get() !== $this) { return; }
        while (($member = $this->party->getPendingJoin()) !== null) {
            $this->game->getModalManager()->queueNotice(new NoticeContent(
                '', MessageCatalog::getTerm('party.member_joined', $member->name),
                actorId: $member instanceof Character ? $member->actorId : null,
                sound: SystemSound::PARTY_JOIN,
            ), $this);
            $this->party->acknowledgeJoin($member);
        }
    }

    public function awaitRecruitmentNotices(): bool
    {
        if ($this->released) { return false; }
        $this->queueRecruitmentNotices();
        return $this->game->getModalManager()->hasPendingNotices($this);
    }

    public function release(): void
    {
        if ($this->released) { return; }
        $this->released = true;
        $this->game->getModalManager()->cancelNotices($this);
        if ((self::$owners[$this->party] ?? null)?->get() === $this) {
            $this->party->clearPendingJoins();
            $prior = $this->previousOwner?->get();
            while ($prior !== null && $prior->released) { $prior = $prior->previousOwner?->get(); }
            if ($prior === null) { unset(self::$owners[$this->party]); }
            else { self::$owners[$this->party] = \WeakReference::create($prior); }
        }
    }
}
