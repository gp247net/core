<?php

namespace GP247\Core\PersonalData\Application;

use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the query that selects the rows of one declared source belonging to one subject.
 * Every identifier comes from a validated declaration; every value is bound.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-subject-lookup
 * @aidlc-adr personal-data-registry_source-declaration
 */
final class SourceQuery
{
    private const MAX_PARENT_DEPTH = 4;

    /**
     * @return ConnectionInterface
     */
    public static function db(): ConnectionInterface
    {
        return DB::connection(GP247_DB_CONNECTION);
    }

    /**
     * @param string $table Logical name.
     * @return bool
     */
    public static function exists(string $table): bool
    {
        return self::db()->getSchemaBuilder()->hasTable(GP247_DB_PREFIX . $table);
    }

    /**
     * @param PersonalDataSource $source
     * @return bool Whether the source's table exists on this site.
     */
    public static function sourceExists(PersonalDataSource $source): bool
    {
        return self::db()->getSchemaBuilder()->hasTable($source->physicalTable());
    }

    /**
     * @param PersonalDataSource $source
     * @param DataSubject        $subject
     * @return Builder Rows of the source that belong to the subject.
     */
    public static function forSubject(PersonalDataSource $source, DataSubject $subject): Builder
    {
        return self::build($source, $subject, 0);
    }

    /**
     * @param PersonalDataSource $source
     * @param DataSubject        $subject
     * @param int                $depth
     * @return Builder
     */
    private static function build(PersonalDataSource $source, DataSubject $subject, int $depth): Builder
    {
        $query = self::db()->table($source->physicalTable());
        foreach ($source->equals() as $column => $value) {
            $query->where($column, $value);
        }
        if ($source->filter() !== null) {
            ($source->filter())($query);
        }
        $matched = false;
        $query->where(function (Builder $w) use ($source, $subject, $depth, &$matched) {
            foreach ($source->links() as $link) {
                $matched = self::applyLink($w, $link, $subject, $depth) || $matched;
            }
        });
        if (!$matched) {
            // WHY: no link applies to this subject (a guest has no customer id) — select nothing.
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * @param Builder     $w
     * @param SubjectLink $link
     * @param DataSubject $subject
     * @param int         $depth
     * @return bool Whether a condition was added.
     */
    private static function applyLink(Builder $w, SubjectLink $link, DataSubject $subject, int $depth): bool
    {
        switch ($link->kind) {
            case SubjectLink::CUSTOMER:
                if ($subject->customerId === null) {
                    return false;
                }
                $w->orWhere($link->column, $subject->customerId);

                return true;
            case SubjectLink::EMAIL:
                if ($subject->emails === []) {
                    return false;
                }
                $w->orWhere(function (Builder $q) use ($link, $subject) {
                    $q->whereIn($link->column, $subject->emails);
                    if ($link->guestColumn !== null) {
                        $q->where(fn (Builder $g) => $g->whereNull($link->guestColumn)->orWhereIn($link->guestColumn, ['', '0']));
                    }
                });

                return true;
            case SubjectLink::MORPH:
                if ($subject->customerId === null) {
                    return false;
                }
                $w->orWhere(fn (Builder $q) => $q->where($link->typeColumn, $link->typeValue)->where($link->column, $subject->customerId));

                return true;
            case SubjectLink::PARENT:
                return self::applyParent($w, $link, $subject, $depth);
        }

        return false;
    }

    /**
     * @param Builder     $w
     * @param SubjectLink $link
     * @param DataSubject $subject
     * @param int         $depth
     * @return bool
     */
    private static function applyParent(Builder $w, SubjectLink $link, DataSubject $subject, int $depth): bool
    {
        $parent = PersonalDataRegistry::source((string) $link->parentTable);
        if ($parent === null || $depth >= self::MAX_PARENT_DEPTH) {
            return false;
        }
        $parentQuery = self::build($parent, $subject, $depth + 1)->select($parent->key);
        $w->orWhere(function (Builder $q) use ($link, $parentQuery) {
            if ($link->typeColumn !== null) {
                $q->where($link->typeColumn, $link->typeValue);
            }
            $q->whereIn($link->column, $parentQuery);
        });

        return true;
    }
}
