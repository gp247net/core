<?php

namespace GP247\Core\Support\PreChange;

/**
 * Combined answer of every registrant: the change runs only when all of them proceed.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-pre-change-hook
 * @aidlc-adr system-cli_pre-change-registry
 */
final class PreChangeOutcome
{
    /**
     * @param list<array{key: string, proceed: bool, reason: ?string, notice: ?string, pending_job: ?string, error: ?string}> $entries
     */
    public function __construct(private readonly array $entries = [])
    {
    }

    /**
     * @return bool True when no registrant asked to stop.
     */
    public function proceed(): bool
    {
        foreach ($this->entries as $entry) {
            if (!$entry['proceed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string> Reasons of the registrants that asked to stop.
     */
    public function reasons(): array
    {
        return $this->column('reason', fn (array $e) => !$e['proceed']);
    }

    /**
     * @return list<string> Reminders of the registrants that let the change run.
     */
    public function notices(): array
    {
        return $this->column('notice', fn (array $e) => $e['proceed']);
    }

    /**
     * @return list<array{key: string, proceed: bool, reason: ?string, notice: ?string, pending_job: ?string, error: ?string}>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * @param string   $field
     * @param callable $filter
     * @return list<string>
     */
    private function column(string $field, callable $filter): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            if ($filter($entry) && ($entry[$field] ?? '') !== '') {
                $out[] = $entry[$field];
            }
        }

        return $out;
    }
}
