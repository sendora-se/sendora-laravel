<?php

declare(strict_types=1);

namespace Sendora\Laravel\Mail;

/**
 * A queued job keeps its id when the queue retries it, so the n-th mail of
 * each attempt goes under the same key, and the API answers the repeat with
 * the mail that it accepted before.
 *
 * @internal
 */
final class JobKeys
{
    private ?string $job = null;
    private int $mails = 0;

    /** @param null|string $job the job's id, or null for a payload without one */
    public function started(?string $job): void
    {
        $this->job = $job;
        $this->mails = 0;
    }

    // A job run inside another, as dispatchSync() runs one, ends the keys of both.
    public function ended(): void
    {
        $this->job = null;
    }

    /** The key of the running job's next mail, or null outside a job. */
    public function next(): ?string
    {
        if ($this->job === null) {
            return null;
        }
        ++$this->mails;

        return "job-{$this->job}-{$this->mails}";
    }
}
