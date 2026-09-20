<?php

namespace GP247\Core\AdminShell\Support;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Middleware\LogOperation;
use GP247\Core\Models\AdminLog;
use Livewire\ComponentHook;
use Livewire\Drawer\Utils;

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
 * with secret-looking keys removed. Reads that are not operations (`$refresh`,
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
            $log = gp247_clean(data: $log, hight: true);
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
     * every depth — the same words LogOperation drops from request input.
     *
     * @param array<mixed> $data
     * @param array<int, string> $except
     * @return array<mixed>
     */
    protected function scrub(array $data, array $except): array
    {
        $needles = array_map('strtolower', $except);
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $lower = strtolower($key);
                $hit = false;
                foreach ($needles as $needle) {
                    if ($needle !== '' && str_contains($lower, $needle)) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    continue;
                }
            }
            if (is_object($value)) {
                $value = method_exists($value, 'toArray') ? $value->toArray() : (array) $value;
            }
            $out[$key] = is_array($value) ? $this->scrub($value, $except) : $value;
        }

        return $out;
    }
}
