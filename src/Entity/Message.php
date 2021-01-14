<?php
namespace App\Entity;

class Message {
    protected int $messageNumber = 0;
    protected string $messageId = '';
    protected string $subject = '';
    protected string $mailDate = '';

    protected string $flagUnseen = '';
    protected string $flagFlagged = '';
    protected string $flagAnswered = '';
    protected string $flagDeleted = '';
    protected string $flagDraft = '';

    // @todo Hashed message, maybe not good (Unseen, Flagged, Answered, Deleted, Draft)
    protected string $hash = '';

    public function getMessageNumber(): int {
        return $this->messageNumber;
    }

    public function setMessageNumber(int $messageNumber): self {
        $this->messageNumber = $messageNumber;
        return $this;
    }

    public function getMessageId(): string {
        return $this->messageId;
    }

    public function setMessageId(string $messageId): self {
        $this->messageId = $messageId;
        return $this;
    }

    public function getSubject(): string {
        return $this->subject;
    }

    public function setSubject(string $subject): self {
        $this->subject = $subject;
        return $this;
    }

    public function getMailDate(): string {
        return $this->mailDate;
    }

    public function setMailDate(string $mailDate): self {
        $this->mailDate = $mailDate;
        return $this;
    }

    public function getFlagUnseen(): string {
        return $this->flagUnseen;
    }

    public function setFlagUnseen(string $flagUnseen): self {
        $this->flagUnseen = $flagUnseen;
        return $this;
    }

    public function getFlagFlagged(): string {
        return $this->flagFlagged;
    }

    public function setFlagFlagged(string $flagFlagged): self {
        $this->flagFlagged = $flagFlagged;
        return $this;
    }

    public function getFlagAnswered(): string {
        return $this->flagAnswered;
    }

    public function setFlagAnswered(string $flagAnswered): self {
        $this->flagAnswered = $flagAnswered;
        return $this;
    }

    public function getFlagDeleted(): string {
        return $this->flagDeleted;
    }

    public function setFlagDeleted(string $flagDeleted): self {
        $this->flagDeleted = $flagDeleted;
        return $this;
    }

    public function getFlagDraft(): string {
        return $this->flagDraft;
    }

    public function setFlagDraft(string $flagDraft): self {
        $this->flagDraft = $flagDraft;
        return $this;
    }

    public function getHash(): string {
        return $this->hash;
    }

    public function updateHash(): self {
        $this->hash = md5(serialize([
            'Unseen' => $this->flagUnseen,
            'Flagged' => $this->flagFlagged,
            'Answered' => $this->flagAnswered,
            'Deleted' => $this->flagDeleted,
            'Draft' => $this->flagDraft,
        ]));
        return $this;
    }

    public function getMailOptions(): string {
        $mailOptions = [];
        foreach (['Unseen', 'Flagged', 'Answered', 'Deleted', 'Draft'] as $flag) {
            $flagName = 'flag' . $flag;
            if ($flag === 'Unseen' && empty($this->$flagName)) {
                $mailOptions[] = '\\Seen';
            } else if ($flag !== 'Unseen' && !empty($this->$flagName)) {
                $mailOptions[] = '\\' . $flag;
            }
        }
        return implode(' ', $mailOptions);
    }
}
