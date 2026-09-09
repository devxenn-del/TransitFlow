<?php

namespace Tests\Feature\Fleet;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

class FareMatrixExcelTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $company;

    private Franchise $franchise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->franchise = Franchise::factory()->for($this->company)->withStops(['A', 'B', 'C'])->create();
        Sanctum::actingAs(User::factory()->companyAdmin($this->company)->create());

        // Seed one fare so the template has something to round-trip.
        $this->putJson("/api/company/franchises/{$this->franchise->id}/fare-matrix/cell", ['origin' => 'A', 'destination' => 'B', 'amount' => 12]);
    }

    public function test_template_downloads_an_xlsx_shaped_like_the_grid(): void
    {
        $response = $this->get("/api/company/franchises/{$this->franchise->id}/fare-matrix/template");
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));

        $tmp = tempnam(sys_get_temp_dir(), 'ftpl').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();

        $this->assertSame('Origin \\ Destination', $sheet->getCell('A1')->getValue());
        $this->assertSame('B', $sheet->getCell('C1')->getValue());        // destination header
        $this->assertSame('A', $sheet->getCell('A2')->getValue());        // origin label
        $this->assertEqualsWithDelta(12.0, (float) $sheet->getCell('C2')->getValue(), 0.001); // A→B fare
        @unlink($tmp);
    }

    public function test_import_updates_fares_from_a_matrix_file(): void
    {
        $file = $this->makeMatrix([
            ['Origin \\ Destination', 'A', 'B', 'C'],
            ['A', '—', 20, 35],
            ['B', 15, '—', 18],
            ['C', '', '', '—'],
        ]);

        $this->postJson("/api/company/franchises/{$this->franchise->id}/fare-matrix/import", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('updated', 4)
            ->assertJsonPath('skipped', 2); // the two blank C-row cells

        $this->getJson("/api/company/franchises/{$this->franchise->id}/fare-matrix")
            ->assertJsonPath('cells.A.B.amount', 20)
            ->assertJsonPath('cells.B.A.amount', 15);
    }

    public function test_import_reports_unknown_stops_and_bad_values(): void
    {
        $file = $this->makeMatrix([
            ['Origin \\ Destination', 'A', 'B', 'ZZZ'],
            ['A', '—', 'abc', 10],   // "abc" bad, ZZZ column unknown -> only the bad value counts as skipped
            ['NOWHERE', 5, 5, 5],    // unknown origin row
        ]);

        $res = $this->postJson("/api/company/franchises/{$this->franchise->id}/fare-matrix/import", ['file' => $file])
            ->assertOk();

        $errors = $res->json('errors');
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'NOWHERE')));
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'abc')));
    }

    public function test_blank_cells_are_left_alone_unless_clear_blanks_is_set(): void
    {
        // A→B is 12 from setUp. A blank A→B cell...
        $file = $this->makeMatrix([
            ['Origin \\ Destination', 'A', 'B', 'C'],
            ['A', '—', '', ''],
        ]);

        $this->postJson("/api/company/franchises/{$this->franchise->id}/fare-matrix/import", ['file' => $file])->assertOk();
        $this->getJson("/api/company/franchises/{$this->franchise->id}/fare-matrix")
            ->assertJsonPath('cells.A.B.amount', 12); // untouched

        $file2 = $this->makeMatrix([
            ['Origin \\ Destination', 'A', 'B', 'C'],
            ['A', '—', '', ''],
        ]);
        $this->postJson("/api/company/franchises/{$this->franchise->id}/fare-matrix/import", ['file' => $file2, 'clear_blanks' => 1])
            ->assertOk()
            ->assertJsonPath('cleared', 2);

        $this->assertDatabaseMissing('fare_matrix', ['route_id' => $this->franchise->routes()->where('origin', 'A')->where('destination', 'B')->value('id')]);
    }

    public function test_office_can_download_template_but_not_import(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->company)->create()); // office

        $this->get("/api/company/franchises/{$this->franchise->id}/fare-matrix/template")->assertOk();
        $this->postJson("/api/company/franchises/{$this->franchise->id}/fare-matrix/import", [
            'file' => $this->makeMatrix([['Origin \\ Destination', 'A'], ['A', '—']]),
        ])->assertForbidden();
    }

    /**
     * @param  list<list<mixed>>  $grid
     */
    private function makeMatrix(array $grid): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($grid as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'fimp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'matrix.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
