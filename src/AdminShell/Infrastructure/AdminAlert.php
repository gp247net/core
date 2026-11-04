<?php

namespace GP247\Core\AdminShell\Infrastructure;

use InvalidArgumentException;

/**
 * Result of one site-health check shown in the "System alerts" dashboard block
 * and printed by `gp247:doctor`.
 *
 * `message` is the translated sentence for the admin UI; `detail` (and `items`)
 * is the untranslated technical line doctor prints. Neither may carry a secret
 * value, a password or an absolute server path.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-alert-check-registry, US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 */
final readonly class AdminAlert
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';
    public const OK = 'ok';

    private const WEIGHTS = [self::CRITICAL => 0, self::WARNING => 1, self::INFO => 2, self::OK => 3];

    /**
     * @param string             $key      Stable check key (also the doctor row name).
     * @param string             $level    One of critical|warning|info|ok.
     * @param string             $message  Translated sentence for the admin UI.
     * @param string             $detail   Untranslated technical line for doctor.
     * @param string             $fixUrl   Admin screen that fixes the condition ('' = none).
     * @param string             $fixLabel Label of the fix button.
     * @param string             $docUrl   Help page explaining the condition.
     * @param array<int, string> $items    Itemised findings (doctor --json).
     * @throws InvalidArgumentException When the level is unknown.
     */
    public function __construct(
        public string $key,
        public string $level,
        public string $message,
        public string $detail = '',
        public string $fixUrl = '',
        public string $fixLabel = '',
        public string $docUrl = '',
        public array $items = [],
    ) {
        if (!isset(self::WEIGHTS[$level])) {
            throw new InvalidArgumentException('Unknown alert level: ' . $level);
        }
    }

    /**
     * @param string             $key
     * @param string             $message
     * @param string             $detail
     * @param string             $fixUrl
     * @param string             $fixLabel
     * @param string             $docUrl
     * @param array<int, string> $items
     * @return self
     */
    public static function critical(string $key, string $message, string $detail = '', string $fixUrl = '', string $fixLabel = '', string $docUrl = '', array $items = []): self
    {
        return new self($key, self::CRITICAL, $message, $detail, $fixUrl, $fixLabel, $docUrl, $items);
    }

    /**
     * @param string             $key
     * @param string             $message
     * @param string             $detail
     * @param string             $fixUrl
     * @param string             $fixLabel
     * @param string             $docUrl
     * @param array<int, string> $items
     * @return self
     */
    public static function warning(string $key, string $message, string $detail = '', string $fixUrl = '', string $fixLabel = '', string $docUrl = '', array $items = []): self
    {
        return new self($key, self::WARNING, $message, $detail, $fixUrl, $fixLabel, $docUrl, $items);
    }

    /**
     * @param string $key
     * @param string $message
     * @param string $detail
     * @param string $fixUrl
     * @param string $fixLabel
     * @param string $docUrl
     * @return self
     */
    public static function info(string $key, string $message, string $detail = '', string $fixUrl = '', string $fixLabel = '', string $docUrl = ''): self
    {
        return new self($key, self::INFO, $message, $detail, $fixUrl, $fixLabel, $docUrl);
    }

    /**
     * @param string $key
     * @param string $message
     * @param string $detail
     * @return self
     */
    public static function ok(string $key, string $message, string $detail = ''): self
    {
        return new self($key, self::OK, $message, $detail);
    }

    /**
     * Whether the condition is satisfied (only `ok` counts as passed).
     *
     * @return bool
     */
    public function passed(): bool
    {
        return $this->level === self::OK;
    }

    /**
     * Sort weight: critical (0) → warning → info → ok (3).
     *
     * @return int
     */
    public function weight(): int
    {
        return self::WEIGHTS[$this->level];
    }

    /**
     * Plain array for the cache.
     *
     * @return array{key: string, level: string, message: string, detail: string, fixUrl: string, fixLabel: string, docUrl: string, items: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'level' => $this->level,
            'message' => $this->message,
            'detail' => $this->detail,
            'fixUrl' => $this->fixUrl,
            'fixLabel' => $this->fixLabel,
            'docUrl' => $this->docUrl,
            'items' => $this->items,
        ];
    }

    /**
     * Rebuild an alert from toArray() output.
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws InvalidArgumentException When key/level/message are missing or invalid.
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['key'], $data['level'], $data['message'])) {
            throw new InvalidArgumentException('Malformed alert payload');
        }

        return new self(
            (string) $data['key'],
            (string) $data['level'],
            (string) $data['message'],
            (string) ($data['detail'] ?? ''),
            (string) ($data['fixUrl'] ?? ''),
            (string) ($data['fixLabel'] ?? ''),
            (string) ($data['docUrl'] ?? ''),
            array_values(array_map('strval', (array) ($data['items'] ?? []))),
        );
    }
}
