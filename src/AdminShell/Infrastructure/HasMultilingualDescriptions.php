<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\Models\AdminLanguage;

/**
 * Reusable per-language descriptions for admin-shell screens whose model keeps
 * its translatable text (title/keyword/description/…) in a separate description
 * table, one row per language (the GP247 brownfield convention — e.g.
 * shop_category_description, shop_product_description).
 *
 * A consuming Livewire component (typically a ResourcePanel) declares the
 * translatable fields, the description model and its foreign key; this trait
 * supplies the `$desc[lang][field]` editing state plus load/init/persist helpers
 * (delete-then-recreate, matching the legacy controllers). Validation rules stay
 * with the consumer so per-field constraints (required / max length) remain
 * explicit. Reusable across packages (front/shop/plugin) — rule ui-tailadmin P3.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-002
 * @aidlc-adr ADR-005, ADR-007
 */
trait HasMultilingualDescriptions
{
    /** @var array<string, array<string, string>> Descriptions keyed by lang => field. */
    public array $desc = [];

    /**
     * Translatable field names stored in the description table (e.g. title,
     * keyword, description).
     *
     * @return array<int, string>
     */
    abstract protected function multilingualFields(): array;

    /**
     * @return class-string The description model (e.g. ShopCategoryDescription).
     */
    abstract protected function descriptionModelClass(): string;

    /**
     * @return string The description table foreign key (e.g. "category_id").
     */
    abstract protected function descriptionForeignKey(): string;

    /**
     * Active language codes the descriptions are edited in. Overridable (e.g. in
     * tests) to avoid a database dependency.
     *
     * @return array<int, string>
     */
    protected function descriptionLanguageCodes(): array
    {
        return array_keys(AdminLanguage::getListActive()->all());
    }

    /**
     * Reset the description state to empty values for every active language (used
     * in create mode).
     *
     * @return void
     */
    protected function initDescriptions(): void
    {
        $this->desc = [];
        foreach ($this->descriptionLanguageCodes() as $code) {
            foreach ($this->multilingualFields() as $field) {
                $this->desc[$code][$field] = '';
            }
        }
    }

    /**
     * Populate the description state from an existing record's descriptions
     * (used in edit mode). Missing languages/fields default to empty.
     *
     * @param iterable<mixed> $descriptions Rows each exposing `lang` + the fields.
     * @return void
     */
    protected function fillDescriptions(iterable $descriptions): void
    {
        $byLang = $this->descriptionsByLang($descriptions);

        $this->desc = [];
        foreach ($this->descriptionLanguageCodes() as $code) {
            foreach ($this->multilingualFields() as $field) {
                $this->desc[$code][$field] = (string) ($byLang[$code][$field] ?? '');
            }
        }
    }

    /**
     * Persist the descriptions for a record: delete existing rows then insert one
     * row per active language (delete-then-recreate, matching the legacy
     * controllers). Values are gp247_clean'd at the boundary.
     *
     * @param int|string $foreignId The owning record id.
     * @return void
     */
    protected function saveDescriptions($foreignId): void
    {
        $modelClass = $this->descriptionModelClass();
        $foreignKey = $this->descriptionForeignKey();

        $modelClass::where($foreignKey, $foreignId)->delete();

        $rows = [];
        foreach ($this->descriptionLanguageCodes() as $code) {
            $row = [$foreignKey => $foreignId, 'lang' => $code];
            foreach ($this->multilingualFields() as $field) {
                $row[$field] = gp247_clean((string) ($this->desc[$code][$field] ?? ''));
            }
            $rows[] = $row;
        }

        if ($rows !== []) {
            // WHY: query-builder insert (not Eloquent create) so the composite-key
            // description models persist reliably in one statement.
            $modelClass::insert($rows);
        }
    }

    /**
     * The first active language code (used e.g. to derive an alias from its
     * title), or null when none are active.
     *
     * @return string|null
     */
    protected function firstDescriptionLanguage(): ?string
    {
        $codes = $this->descriptionLanguageCodes();

        return $codes[0] ?? null;
    }

    /**
     * Normalise a descriptions iterable (Eloquent collection / models / arrays)
     * into [lang => [field => value]].
     *
     * @param iterable<mixed> $descriptions
     * @return array<string, array<string, mixed>>
     */
    private function descriptionsByLang(iterable $descriptions): array
    {
        $byLang = [];
        foreach ($descriptions as $row) {
            $lang = is_array($row) ? ($row['lang'] ?? null) : ($row->lang ?? null);
            if ($lang === null) {
                continue;
            }
            foreach ($this->multilingualFields() as $field) {
                $byLang[$lang][$field] = is_array($row) ? ($row[$field] ?? '') : ($row->{$field} ?? '');
            }
        }

        return $byLang;
    }
}
