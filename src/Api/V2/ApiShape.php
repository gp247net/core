<?php

namespace GP247\Core\Api\V2;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Declared shape of one v2 resource for `fields[<type>]=a,b` (sparse fieldsets) and
 * `include=a,b` (related records in the same call). An endpoint declares which fields
 * exist — and which relation each one reads, so unselected heavy relations are not
 * loaded — and which relations may be included for its caller. Anything else in the
 * request is a 422; nothing outside the declaration can be asked for.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-sparse-fieldsets, US-CMP-api-include-relations
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
final class ApiShape
{
    /** @var array<string, string[]> field => base names of the relations it reads */
    public readonly array $fields;

    /**
     * @param string $type     Key of fields[<type>] for this resource, e.g. "product".
     * @param array  $fields   Field names; a string key maps a field to the relations it reads
     *                         (['name' => ['descriptions'], 'sku']).
     * @param array<string, array{with?: array, render: callable, type?: string, fields?: string[]}> $includes
     *                         Include name => eager loads, renderer fn(Model, Request): mixed, and the
     *                         type + field names of the included records (for fields[<type>]).
     */
    public function __construct(public readonly string $type, array $fields, public readonly array $includes = [])
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                $normalized[$value] = [];
            } else {
                $normalized[$key] = (array) $value;
            }
        }
        $this->fields = $normalized;
    }

    /**
     * Read and validate `fields` and `include` of the request against this declaration.
     *
     * @param Request $request
     * @return ApiShapeSelection
     * @throws ValidationException When a type, field or include is not declared, or too many includes.
     */
    public function resolve(Request $request): ApiShapeSelection
    {
        $includes = $this->requestedIncludes($request);
        $fields = $this->requestedFields($request);

        return new ApiShapeSelection($this, $fields[$this->type] ?? null, $includes, $fields);
    }

    /**
     * @param Request $request
     * @return string[]
     * @throws ValidationException
     */
    private function requestedIncludes(Request $request): array
    {
        $raw = $request->query('include');
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_string($raw)) {
            throw ValidationException::withMessages(['include' => ['include is a comma-separated list, e.g. include=brand,categories.']]);
        }
        $names = self::names($raw);
        $allowed = array_keys($this->includes);
        $unknown = array_diff($names, $allowed);
        if ($unknown) {
            throw ValidationException::withMessages(['include' => ['Unknown include: ' . implode(', ', $unknown) . '. Allowed: ' . ($allowed ? implode(', ', $allowed) : 'none on this endpoint') . '.']]);
        }
        $max = max(0, (int) config('gp247-config.api.query.max_include', 3));
        if (count($names) > $max) {
            throw ValidationException::withMessages(['include' => ['At most ' . $max . ' includes per request.']]);
        }

        return $names;
    }

    /**
     * @param Request $request
     * @return array<string, string[]> type => chosen field names
     * @throws ValidationException
     */
    private function requestedFields(Request $request): array
    {
        $raw = $request->query('fields');
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw ValidationException::withMessages(['fields' => ['fields takes one list per type: fields[' . $this->type . ']=a,b.']]);
        }
        $allowed = [$this->type => array_merge(array_keys($this->fields), array_keys($this->includes))];
        foreach ($this->includes as $definition) {
            if (isset($definition['type'])) {
                $allowed[$definition['type']] = $definition['fields'] ?? [];
            }
        }

        $chosen = [];
        foreach ($raw as $type => $value) {
            if (!isset($allowed[$type])) {
                throw ValidationException::withMessages(['fields' => ['Unknown fields type: ' . $type . '. Allowed: ' . implode(', ', array_keys($allowed)) . '.']]);
            }
            if (!is_string($value)) {
                throw ValidationException::withMessages(['fields' => ['fields[' . $type . '] is a comma-separated list of field names.']]);
            }
            $names = self::names($value);
            $unknown = array_diff($names, $allowed[$type]);
            if ($unknown) {
                throw ValidationException::withMessages(['fields' => ['Unknown field of ' . $type . ': ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $allowed[$type]) . '.']]);
            }
            if ($names) {
                $chosen[$type] = $names;
            }
        }

        return $chosen;
    }

    /**
     * @param string $csv
     * @return string[] Trimmed, non-empty, unique names in request order.
     */
    private static function names(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), fn ($name) => $name !== '')));
    }
}
