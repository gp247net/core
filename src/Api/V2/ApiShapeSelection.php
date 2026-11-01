<?php

namespace GP247\Core\Api\V2;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What one request chose from an ApiShape: the fields of the resource (null = all),
 * the includes, and the fields of each included type. Gives the query its eager loads,
 * lets a resource skip computing fields nobody asked for, and shapes the final row.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-sparse-fieldsets, US-CMP-api-include-relations
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
final class ApiShapeSelection
{
    /**
     * @param ApiShape                  $shape
     * @param string[]|null             $fields     Chosen fields of the resource; null = every field.
     * @param string[]                  $includes   Chosen includes.
     * @param array<string, string[]>   $typeFields Chosen fields per type (resource and included types).
     */
    public function __construct(
        private readonly ApiShape $shape,
        private readonly ?array $fields,
        private readonly array $includes,
        private readonly array $typeFields
    ) {
    }

    /**
     * Whether the answer carries $field (the id always does).
     *
     * @param string $field
     * @return bool
     */
    public function keeps(string $field): bool
    {
        return $this->fields === null || $field === 'id' || in_array($field, $this->fields, true);
    }

    /**
     * Eager loads for the query: the endpoint's defaults minus relations only unchosen fields
     * read, plus what the includes need.
     *
     * @param array $defaults Eager loads the endpoint uses today (names, "name:cols", name => closure).
     * @return array
     */
    public function with(array $defaults): array
    {
        $with = [];
        $needed = null;
        if ($this->fields !== null) {
            $needed = [];
            foreach ($this->fields as $field) {
                foreach ($this->shape->fields[$field] ?? [] as $relation) {
                    $needed[$relation] = true;
                }
            }
        }
        foreach ($defaults as $key => $value) {
            $relation = is_string($key) ? $key : (string) $value;
            if ($needed !== null && !isset($needed[preg_split('/[.:]/', $relation)[0]])) {
                continue;
            }
            is_string($key) ? $with[$key] = $value : $with[] = $value;
        }
        foreach ($this->includes as $name) {
            foreach ($this->shape->includes[$name]['with'] ?? [] as $key => $value) {
                is_string($key) ? $with[$key] = $value : $with[] = $value;
            }
        }

        return $with;
    }

    /**
     * Add the chosen includes to one resource row, then keep only the chosen fields.
     *
     * @param array                                $row     The resource of one record.
     * @param \Illuminate\Database\Eloquent\Model  $model   The record (relations loaded by with()).
     * @param Request                              $request
     * @return array
     */
    public function apply(array $row, $model, Request $request): array
    {
        foreach ($this->includes as $name) {
            $definition = $this->shape->includes[$name];
            $value = ($definition['render'])($model, $request);
            $type = $definition['type'] ?? null;
            if ($type !== null && isset($this->typeFields[$type])) {
                $value = self::cut($value, $this->typeFields[$type]);
            }
            $row[$name] = $value instanceof Collection ? $value->all() : $value;
        }
        if ($this->fields === null) {
            return $row;
        }

        return array_intersect_key($row, array_flip(array_merge(['id'], $this->fields)));
    }

    /**
     * Build a resource row whose costly values are closures: a closure runs only when its field
     * is kept, so an unchosen field never touches a relation that was not loaded.
     *
     * @param self|null $selection Null = every field (the shape of a caller that sent no fields).
     * @param array     $values    Field => value or Closure returning the value.
     * @return array
     */
    public static function build(?self $selection, array $values): array
    {
        $row = [];
        foreach ($values as $field => $value) {
            if ($selection !== null && !$selection->keeps($field)) {
                continue;
            }
            $row[$field] = $value instanceof Closure ? $value() : $value;
        }

        return $row;
    }

    /**
     * Keep the chosen fields (and id) of an included record or list of records.
     *
     * @param mixed    $value
     * @param string[] $fields
     * @return mixed
     */
    private static function cut($value, array $fields)
    {
        $keep = array_flip(array_merge(['id'], $fields));
        if ($value instanceof Collection) {
            $value = $value->all();
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? array_intersect_key($item, $keep) : $item, $value);
        }

        return array_intersect_key($value, $keep);
    }
}
