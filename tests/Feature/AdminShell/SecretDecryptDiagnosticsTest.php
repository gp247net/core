<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Casts\Secret;
use GP247\Core\Models\AdminConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use GP247\Core\Tests\TestCase;

/**
 * A stored secret that no longer decrypts must be locatable without leaking it:
 * the report names the row (key/store or table/column/id) and the key id, never the
 * value; the same row is reported once per request and once per throttle window (but
 * still reported when the cache is broken); gp247:doctor lists the broken rows.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-secret-decrypt-diagnosable
 * @aidlc-adr compat-foundation_config-secret-at-rest
 */
class SecretDecryptDiagnosticsTest extends TestCase
{
    use DatabaseTransactions;

    private const GROUP = 'SecretDiagTestGroup';

    /**
     * A structurally valid v2 envelope written under a foreign key (undecryptable here).
     *
     * @param string $plain
     * @return array{0: string, 1: string} [envelope, kid]
     */
    private function foreignEnvelope(string $plain = 'TOP-SECRET-PLAIN'): array
    {
        $foreignRaw = random_bytes(32);
        $enc = new \Illuminate\Encryption\Encrypter($foreignRaw, (string) config('app.cipher', 'AES-256-CBC'));
        $kid = substr(hash('sha256', $foreignRaw), 0, 8);

        return ['enc:v2:' . $kid . ':' . $enc->encryptString($plain), $kid];
    }

    /**
     * Capture the secret-failure messages gp247_report sends (one per report, the
     * daily channel only, so a configured Slack channel does not double-count).
     *
     * @return \ArrayObject<int, string>
     */
    private function captureReports(): \ArrayObject
    {
        $messages = new \ArrayObject();
        $channel = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $channel->shouldReceive('emergency')->andReturnUsing(function ($msg) use ($messages) {
            $messages->append((string) $msg);
        });
        $silent = \Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing();
        Log::shouldReceive('channel')->andReturnUsing(fn ($name = null) => $name === 'daily' ? $channel : $silent);

        return $messages;
    }

    /**
     * Only the secret-decrypt reports among the captured messages.
     *
     * @param \ArrayObject<int, string> $messages
     * @return array<int, string>
     */
    private function secretReports(\ArrayObject $messages): array
    {
        return array_values(array_filter($messages->getArrayCopy(), fn ($m) => str_contains($m, '[gp247 secret]')));
    }

    /**
     * Start a new "request" for the per-request dedupe memo.
     *
     * @return void
     */
    private function newRequest(): void
    {
        $this->app->forgetInstance('gp247.secret-report-seen');
    }

    public function test_a_failed_decrypt_report_names_the_key_store_and_key_id_but_never_the_value(): void
    {
        [$raw, $kid] = $this->foreignEnvelope('TOP-SECRET-PLAIN');
        $reports = $this->captureReports();

        $this->assertSame('', gp247_secret_decrypt($raw, ['group' => 'Email', 'key' => 'smtp_password', 'store_id' => '1']));

        $found = $this->secretReports($reports);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('key=smtp_password', $found[0]);
        $this->assertStringContainsString('store_id=1', $found[0]);
        $this->assertStringContainsString('group=Email', $found[0]);
        $this->assertStringContainsString('kid=' . $kid, $found[0]);
        $this->assertStringNotContainsString('TOP-SECRET-PLAIN', $found[0]);
        $this->assertStringNotContainsString(substr($raw, strlen('enc:v2:' . $kid . ':'), 40), $found[0]);
    }

    public function test_the_same_broken_row_is_reported_once_however_often_it_is_read(): void
    {
        [$raw] = $this->foreignEnvelope();
        $reports = $this->captureReports();
        $ctx = ['key' => 'dup_secret', 'store_id' => '0'];

        gp247_secret_decrypt($raw, $ctx);
        gp247_secret_decrypt($raw, $ctx);
        gp247_secret_decrypt($raw, $ctx);
        $this->assertCount(1, $this->secretReports($reports), 'Repeated reads in one request must report once');

        // A later request inside the throttle window stays quiet (cache throttle).
        $this->newRequest();
        gp247_secret_decrypt($raw, $ctx);
        $this->assertCount(1, $this->secretReports($reports), 'A later request inside the TTL must not report again');
    }

    public function test_a_broken_cache_still_lets_the_report_through(): void
    {
        [$raw] = $this->foreignEnvelope();
        $reports = $this->captureReports();
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache down'));

        $this->assertSame('', gp247_secret_decrypt($raw, ['key' => 'cache_down_secret', 'store_id' => '0']));

        $this->assertCount(1, $this->secretReports($reports), 'Throttle must fail open when the cache errors');
    }

    public function test_a_secret_cast_column_reports_its_table_column_and_row_id(): void
    {
        [$raw] = $this->foreignEnvelope();
        $reports = $this->captureReports();

        $model = new class extends Model {
            protected $table = 'diag_vault';
            protected $casts = ['api_token' => Secret::class];
            public $timestamps = false;
        };
        $model->setRawAttributes(['id' => 42, 'api_token' => $raw], true);

        $this->assertSame('', $model->api_token);

        $found = $this->secretReports($reports);
        $this->assertCount(1, $found);
        $this->assertStringContainsString('table=diag_vault', $found[0]);
        $this->assertStringContainsString('column=api_token', $found[0]);
        $this->assertStringContainsString('id=42', $found[0]);
    }

    public function test_doctor_lists_the_group_key_and_store_of_each_undecryptable_row(): void
    {
        [$raw, $kid] = $this->foreignEnvelope();
        AdminConfig::insert([
            'group' => self::GROUP, 'code' => 'diag', 'key' => 'diag_broken_secret',
            'store_id' => GP247_STORE_ID_GLOBAL, 'value' => $raw, 'security' => 1, 'sort' => 0, 'detail' => 'x',
        ]);

        Artisan::call('gp247:doctor', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertIsArray($json);

        $checks = $json['data']['checks'] ?? $json['error']['data']['checks'] ?? [];
        $check = collect($checks)->firstWhere('name', 'secret_decryptable');
        $this->assertNotNull($check);
        $this->assertSame('warn', $check['status']);

        $label = self::GROUP . '/diag_broken_secret@' . GP247_STORE_ID_GLOBAL . ' kid=' . $kid;
        $this->assertContains($label, $check['items'] ?? []);
        $this->assertStringNotContainsString($raw, (string) json_encode($check));
    }
}
