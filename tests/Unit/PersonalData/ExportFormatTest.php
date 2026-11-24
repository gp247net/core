<?php

namespace GP247\Core\Tests\Unit\PersonalData;

use GP247\Core\PersonalData\Domain\Export\ExportFormat;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rules of the data package: secrets never leave, spreadsheet cells are never formulas.
 */
#[Group('personal-data-registry')]
class ExportFormatTest extends TestCase
{
    public function test_secret_looking_columns_are_never_exported_even_when_listed(): void
    {
        $source = PersonalDataSource::table('pdt_customer')->link(SubjectLink::customer('id'))->deleteRows()
            ->export(['email', 'password', 'remember_token', 'google2fa_secret', 'api_key', 'first_name']);

        $this->assertSame(['email', 'first_name'], ExportFormat::columnsOf($source));
    }

    public function test_a_cell_that_starts_like_a_formula_is_written_as_text(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", ExportFormat::cell('=HYPERLINK("x")'));
        $this->assertSame("'@SUM(A1)", ExportFormat::cell('@SUM(A1)'));
        $this->assertSame("'+84 912", ExportFormat::cell('+84 912'));
        $this->assertSame('-12.50', ExportFormat::cell('-12.50'), 'a negative amount stays a number');
        $this->assertSame('', ExportFormat::cell(null));
    }

    public function test_csv_lines_quote_commas_quotes_and_line_breaks(): void
    {
        $this->assertSame("1,\"a, b\",\"say \"\"hi\"\"\",\"x\ny\"\r\n", ExportFormat::csvLine([1, 'a, b', 'say "hi"', "x\ny"]));
    }

    public function test_file_names_keep_only_safe_characters(): void
    {
        $this->assertSame('shop_order', ExportFormat::fileName('shop_order'));
        $this->assertSame('___etc_passwd', ExportFormat::fileName('../etc/passwd'));
    }
}
