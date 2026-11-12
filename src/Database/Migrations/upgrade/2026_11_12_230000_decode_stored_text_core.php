<?php

use GP247\Core\Support\StoredTextDecoder;
use Illuminate\Database\Migrations\Migration;

/**
 * Running sites: give back the plain text of core tables that gp247_clean() HTML-encoded on
 * save before core 3.12 ("Cron &amp; Queue" ⇒ "Cron & Queue"), once, so text is stored as
 * typed (US-CMP-text-stored-as-typed, decision D2). Column list approved 2026-11-12 (rule
 * data-safety §C, modification_plan_20261112T061240): only plain-text columns; never the
 * languages table (D1), rich content, secrets, passwords, JSON, codes or logs.
 *
 * CHANGES DATA — back up the database before gp247:update. Idempotent through the
 * `text_storage_decoded_core` flag; rows are read in chunks and updated by primary key.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-text-stored-as-typed
 * @aidlc-adr compat-foundation_text-stored-as-typed
 */
return new class extends Migration
{
    /**
     * Table (without prefix) => plain-text columns to decode.
     *
     * @return array<string, array<int, string>>
     */
    public static function columns(): array
    {
        return [
            'admin_user' => ['name', 'username'],
            'admin_role' => ['name'],
            'admin_permission' => ['name'],
            'admin_menu' => ['title'],
            'admin_store' => ['phone', 'long_phone', 'time_active', 'address', 'office', 'warehouse'],
            'admin_store_description' => ['name', 'description', 'keyword', 'maintain_note'],
            'admin_custom_field' => ['name'],
            'admin_custom_field_detail' => ['text'],
            'admin_language' => ['name'],
            'admin_country' => ['name'],
            'api_connection' => ['description'],
            'admin_config' => ['value'],
        ];
    }

    /**
     * Decode once.
     *
     * @return void
     */
    public function up()
    {
        StoredTextDecoder::run(
            'text_storage_decoded_core',
            self::columns(),
            [
                // WHY: secret values are encrypted (security = 1) and never went through gp247_clean.
                'admin_config' => fn ($q) => $q->where(fn ($s) => $s->where('security', 0)->orWhereNull('security')),
            ],
            // WHY: a config value that decodes to a JSON list/object was stored by code, not typed;
            // leave it as it is.
            fn (string $table, string $column, string $decoded) => $table === 'admin_config'
                && is_array(json_decode($decoded, true))
        );
    }

    /**
     * Irreversible: encoding the text again would bring back "&amp;" everywhere. Restore the
     * backup taken before gp247:update to go back.
     *
     * @return void
     */
    public function down()
    {
        // Irreversible on purpose — see method doc.
    }
};
