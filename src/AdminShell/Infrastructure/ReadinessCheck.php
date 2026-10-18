<?php

namespace GP247\Core\AdminShell\Infrastructure;

/**
 * One condition a plugin needs before it can work, shown in the "Check before use" box of
 * its settings screen: what is (or is not) in place, why it matters, and where to fix it.
 * Built by ConfigForm::readiness() of a plugin; only local settings, never a network call.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-config-form-readiness
 * @aidlc-adr admin-shell_config-form-readiness-seam
 */
final readonly class ReadinessCheck
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /**
     * @param string $key      Stable id of the condition (used in data-testid).
     * @param string $level    ok | warning | error.
     * @param string $message  Translated sentence shown to the admin.
     * @param string $fixUrl   Where to fix it ('' = no link).
     * @param string $fixLabel Text of the link (the path to the setting).
     */
    public function __construct(
        public string $key,
        public string $level,
        public string $message,
        public string $fixUrl = '',
        public string $fixLabel = '',
    ) {
    }

    /**
     * @param string $key     Condition id.
     * @param string $message Sentence.
     * @return self A condition that is in place.
     */
    public static function ok(string $key, string $message): self
    {
        return new self($key, self::OK, $message);
    }

    /**
     * @param string $key      Condition id.
     * @param string $message  Sentence.
     * @param string $fixUrl   Where to fix it.
     * @param string $fixLabel Link text.
     * @return self A missing condition the plugin can partly work without.
     */
    public static function warning(string $key, string $message, string $fixUrl = '', string $fixLabel = ''): self
    {
        return new self($key, self::WARNING, $message, $fixUrl, $fixLabel);
    }

    /**
     * @param string $key      Condition id.
     * @param string $message  Sentence.
     * @param string $fixUrl   Where to fix it.
     * @param string $fixLabel Link text.
     * @return self A missing condition that stops the plugin from working.
     */
    public static function error(string $key, string $message, string $fixUrl = '', string $fixLabel = ''): self
    {
        return new self($key, self::ERROR, $message, $fixUrl, $fixLabel);
    }

    /**
     * @param bool   $missing   The condition is not met.
     * @param string $key       Condition id.
     * @param string $message   Sentence when missing.
     * @param string $level     Level when missing (error | warning).
     * @param string $fixUrl    Where to fix it.
     * @param string $fixLabel  Link text.
     * @param string $okMessage Sentence when met ('' = reuse $message).
     * @return self
     */
    public static function failIf(bool $missing, string $key, string $message, string $level = self::ERROR, string $fixUrl = '', string $fixLabel = '', string $okMessage = ''): self
    {
        if (!$missing) {
            return self::ok($key, $okMessage !== '' ? $okMessage : $message);
        }

        return new self($key, $level === self::WARNING ? self::WARNING : self::ERROR, $message, $fixUrl, $fixLabel);
    }

    /**
     * @return bool The condition is in place.
     */
    public function passed(): bool
    {
        return $this->level === self::OK;
    }

    /**
     * @return int Sort weight: errors first, then warnings, then passed checks.
     */
    public function weight(): int
    {
        return match ($this->level) {
            self::ERROR => 0,
            self::WARNING => 1,
            default => 2,
        };
    }
}
