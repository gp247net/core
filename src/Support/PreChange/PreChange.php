<?php

namespace GP247\Core\Support\PreChange;

/**
 * A risky change the core is about to make, announced to "before change" registrants
 * (e.g. the backup feature, which snapshots the affected data first).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-pre-change-hook
 * @aidlc-adr system-cli_pre-change-registry
 */
final class PreChange
{
    /** Core and package data update (`gp247:update`). */
    public const SYSTEM_UPDATE = 'system.update';

    /** Marketplace update of one extension. */
    public const EXTENSION_UPDATE = 'extension.update';

    /** Switching the template of a store. */
    public const TEMPLATE_SWITCH = 'template.switch';

    /**
     * @param string               $operation One of the constants above (registrants may meet others later).
     * @param array<string, mixed> $scope     What the change touches (e.g. ['type' => 'Plugins', 'key' => 'Foo']).
     * @param string|null          $from      Version / value before the change, when known.
     * @param string|null          $to        Version / value after the change, when known.
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $scope = [],
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {
    }

    /**
     * @return array{operation: string, scope: array<string, mixed>, from: ?string, to: ?string}
     */
    public function toArray(): array
    {
        return ['operation' => $this->operation, 'scope' => $this->scope, 'from' => $this->from, 'to' => $this->to];
    }
}
