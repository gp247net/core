<?php

namespace GP247\Core\AdminShell\Support;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Middleware\LogOperation;
use GP247\Core\Models\AdminLog;
use Livewire\ComponentHook;
use Livewire\Drawer\Utils;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * Writes every Livewire action performed on an admin screen to the operation
 * log (`admin_log`) — automatically, for core and plugin components alike.
 *
 * WHY a Livewire hook and not the route middleware: `LogOperation` sits on the
 * admin route group, but every Livewire action (save, delete, approve, pay…)
 * travels through the shared `POST livewire/update` endpoint, so after the
 * admin shell moved to Livewire the log only kept page views. A component hook
 * runs on the framework's own `call` lifecycle for every component, so nothing
 * depends on a developer or a plugin remembering to log.
 *
 * What is written: the admin who acted, the screen they were on (Referer path,
 * else the component name), `LIVEWIRE` as the method, and as input the
 * component name, the action, its parameters and the component's public state
 * with secret-looking keys removed and objects reduced to a short description
 * (an uploaded file is its original name and size). Reads that are not operations (`$refresh`,
 * pagination) are skipped through `gp247-config.admin.admin_log_livewire_except`.
 * The row is written AFTER the action completed, so a denied or failed action
 * leaves no trace of something that did not happen.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-admin-shell-livewire-operation-log
 * @aidlc-adr admin-shell-rbac_rbac-livewire-authorization
 */
class LivewireOperationLog extends ComponentHook
{
    /** @var string Method column value; `admin_log.method` is varchar(10). */
    public const METHOD = 'LIVEWIRE';

    /** @var string Actions that are reads or framework plumbing, never operations. */
    public const DEFAULT_EXCEPT = '$refresh,$commit,__dispatch,gotoPage,previousPage,nextPage,setPage,resetPage';

    /** @var int `admin_log.input` is TEXT (64 KiB); leave room for escaping. */
    public const INPUT_MAX = 60000;

    /**
     * @var int Deepest array level written; deeper branches become `{_depth_limit: true}`.
     *          Admin state rarely goes past form.items.0.field (4).
     */
    public const SCRUB_MAX_DEPTH = 10;

    /**
     * Properties the browser changed in this same request (`wire:model` values
     * flushed with the click), keyed by full path — the fields the action touched.
     *
     * @var array<string, mixed>
     */
    protected array $changed = [];

    /**
     * Livewire `update` hook: remember what the admin edited before the action.
     *
     * @param string $propertyName
     * @param string $fullPath
     * @param mixed $newValue
     * @return null
     */
    public function update($propertyName, $fullPath, $newValue)
    {
        $this->changed[(string) $fullPath] = $newValue;

        return null;
    }

    /**
     * Livewire `call` hook: decide before the action runs, write after it ran.
     *
     * @param string $method
     * @param array<int, mixed> $params
     * @param mixed $returnEarly
     * @param mixed $metadata
     * @param mixed $componentContext
     * @return callable|null
     */
    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        if (!$this->shouldLog((string) $method)) {
            return null;
        }

        return function () use ($method, $params): void {
            $this->record((string) $method, is_array($params) ? $params : []);
        };
    }

    /**
     * Log when the operation log is on, an admin is signed in, the action is
     * not a read, and the component belongs to an admin screen.
     *
     * @param string $method
     * @return bool
     */
    protected function shouldLog(string $method): bool
    {
        if (!config('gp247-config.admin.admin_log')) {
            return false;
        }
        if (in_array($method, $this->except(), true)) {
            return false;
        }
        try {
            if (admin()->user() === null) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return $this->component instanceof GP247AdminComponent || $this->originIsAdminScreen();
    }

    /**
     * @return array<int, string>
     */
    protected function except(): array
    {
        $raw = (string) config('gp247-config.admin.admin_log_livewire_except', self::DEFAULT_EXCEPT);

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($m) => $m !== ''));
    }

    /**
     * A plugin component that does not extend the admin base class still counts
     * when the action was fired from an admin screen.
     *
     * @return bool
     */
    protected function originIsAdminScreen(): bool
    {
        $path = $this->originPath();
        $prefix = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';

        return $path !== null && str_starts_with($path, $prefix);
    }

    /**
     * The admin screen the action came from: the Referer path Livewire sends with
     * every update request. Null outside a browser request.
     *
     * @return string|null
     */
    protected function originPath(): ?string
    {
        try {
            $referer = (string) request()->headers->get('referer', '');
        } catch (\Throwable $e) {
            return null;
        }
        if ($referer === '') {
            return null;
        }
        $path = trim((string) parse_url($referer, PHP_URL_PATH), '/');

        return $path === '' ? null : $path;
    }

    /**
     * Write the row. Never lets a logging failure break the action itself.
     *
     * @param string $method
     * @param array<int, mixed> $params
     * @return void
     */
    protected function record(string $method, array $params): void
    {
        try {
            $except = LogOperation::exceptKeys();
            $input = [
                'component' => $this->component->getName(),
                'class' => get_class($this->component),
                'method' => $method,
                'params' => $this->scrub($params, $except),
                'changed' => $this->scrub($this->changed, $except),
                'data' => $this->scrub($this->publicData(), $except),
            ];
            $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false || strlen($json) > self::INPUT_MAX) {
                // WHY: keep the row (who did what, with which parameters) and drop
                // only the bulky state — a lost row is worse than a trimmed one.
                $input['data'] = ['_truncated' => true];
                $json = (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }

            $log = [
                'user_id' => admin()->user()->id,
                'path' => substr($this->originPath() ?? 'livewire/'.$this->component->getName(), 0, 255),
                'method' => self::METHOD,
                'ip' => gp247_get_real_ip_client(),
                'user_agent' => substr((string) request()->header('User-Agent', ''), 0, 255),
                'input' => $json,
            ];
            $log = gp247_text_plain(data: $log, stripTags: true);
            AdminLog::create($log);
        } catch (\Throwable $exception) {
            gp247_report($exception->getMessage());
        }
    }

    /**
     * The component's public properties — what the admin saw and edited.
     *
     * @return array<string, mixed>
     */
    protected function publicData(): array
    {
        try {
            $data = Utils::getPublicPropertiesDefinedOnSubclass($this->component);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Remove any key that names a secret (password, token, client_secret…) at
     * every depth — the same words LogOperation drops from request input — and
     * reduce objects to a short description (see describe()).
     *
     * WHY the depth limit: the log must never be able to break the action it
     * records. A memory exhaustion is fatal and escapes record()'s try/catch, so
     * recursion has a hard ceiling whatever the state looks like.
     *
     * @param array<mixed> $data
     * @param array<int, string> $except
     * @param int $depth Nesting level of $data (0 = top level).
     * @return array<mixed>
     */
    protected function scrub(array $data, array $except, int $depth = 0): array
    {
        if ($depth >= self::SCRUB_MAX_DEPTH) {
            return ['_depth_limit' => true];
        }
        $needles = array_values(array_filter(array_map('strtolower', $except), fn ($n) => $n !== ''));
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key, $needles)) {
                continue;
            }
            if (is_object($value)) {
                $value = $this->describe($value);
            }
            $out[$key] = is_array($value) ? $this->scrub($value, $except, $depth + 1) : $value;
        }

        return $out;
    }

    /**
     * @param string $key
     * @param array<int, string> $needles Lower-cased, non-empty secret words.
     * @return bool
     */
    protected function isSecretKey(string $key, array $needles): bool
    {
        $lower = strtolower($key);
        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A loggable stand-in for an object value.
     *
     * WHY not `(array) $object`: the cast dumps private and protected internals
     * (a TemporaryUploadedFile drags in its filesystem adapter and config, with
     * reference cycles) — unbounded in size, possibly sensitive under keys that
     * do not look secret, and the cause of the out-of-memory crash on upload
     * screens. Only what the object chooses to expose is kept.
     *
     * @param object $value
     * @return mixed Array (scrubbed further by the caller) or scalar.
     */
    protected function describe(object $value)
    {
        if ($value instanceof SymfonyUploadedFile) {
            return ['_upload' => $this->uploadName($value), 'size' => $this->uploadSize($value)];
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \stdClass) {
            return (array) $value;
        }
        if (method_exists($value, 'toArray')) {
            try {
                $array = $value->toArray();
                if (is_array($array)) {
                    return $array;
                }
            } catch (\Throwable $e) {
                // WHY: fall through to the class name — a failing toArray() must not drop the row.
            }
        }

        return ['_object' => get_class($value)];
    }

    /**
     * @param SymfonyUploadedFile $file
     * @return string The name the browser sent, cut to a sane length.
     */
    protected function uploadName(SymfonyUploadedFile $file): string
    {
        try {
            $name = (string) $file->getClientOriginalName();
        } catch (\Throwable $e) {
            return '';
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * @param SymfonyUploadedFile $file
     * @return int|null Bytes, or null when the temporary file cannot be read.
     */
    protected function uploadSize(SymfonyUploadedFile $file): ?int
    {
        try {
            $size = $file->getSize();
        } catch (\Throwable $e) {
            return null;
        }

        return is_int($size) ? $size : null;
    }
}
