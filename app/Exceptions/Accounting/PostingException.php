<?php

namespace App\Exceptions\Accounting;

use App\Support\Money;
use DomainException;

/** Raised when a journal entry cannot be posted or reversed. The message is safe to show to the user. */
class PostingException extends DomainException
{
    public static function tooFewLines(): self
    {
        return new self('A journal entry needs at least two lines.');
    }

    public static function invalidLine(int $lineNo, string $why): self
    {
        return new self("Line {$lineNo}: {$why}");
    }

    public static function unbalanced(int $debitCents, int $creditCents): self
    {
        return new self(sprintf('Entry is not balanced: debits %s, credits %s.', Money::fromCents($debitCents), Money::fromCents($creditCents)));
    }

    public static function zeroAmount(): self
    {
        return new self('A journal entry cannot have a zero total.');
    }

    public static function noOpenFiscalYear(string $date): self
    {
        return new self("There is no open fiscal year for {$date}. Add or reopen one in Settings.");
    }

    public static function alreadyCancelled(string $entryNo): self
    {
        return new self("Journal entry {$entryNo} is already cancelled.");
    }

    public static function cannotReverseReversal(string $entryNo): self
    {
        return new self("{$entryNo} is itself a reversal and cannot be reversed again.");
    }
}
