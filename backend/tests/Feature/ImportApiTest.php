<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_preview_a_csv_import(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $content = <<<'CSV'
"Girokonto";"DE86120300000016478885"
""
"Buchungsdatum";"Wertstellung";"Status";"Zahlungspflichtige*r";"Zahlungsempfänger*in";"Verwendungszweck";"Umsatztyp";"IBAN";"Betrag (€)";"Gläubiger-ID";"Mandatsreferenz";"Kundenreferenz"
"02.04.26";"02.04.26";"Gebucht";"ISSUER";"REWE";"Girokartenumsatz";"Ausgang";"DE27700202700015820743";"-31,04";"";"";"56006389643387010426134430"
CSV;

        $file = UploadedFile::fake()->createWithContent('giro.csv', $content);

        $response = $this->postJson('/api/imports/detect', [
            'file' => $file,
        ]);

        $response->assertOk()->assertJsonStructure([
            'detected_type',
            'file_name',
            'file_hash',
            'line_count',
            'delimiter',
            'headers',
            'sample_rows',
        ]);

        $response->assertJsonPath('detected_type', 'dkb_giro');
        $response->assertJsonPath('headers.0', 'Buchungsdatum');
        $response->assertJsonPath('sample_rows.0.Zahlungsempfänger*in', 'REWE');
    }

    public function test_guest_cannot_preview_a_csv_import(): void
    {
        $file = UploadedFile::fake()->createWithContent('giro.csv', '"Buchungsdatum";"Betrag (€)"');

        $response = $this->postJson('/api/imports/detect', [
            'file' => $file,
        ]);

        $response->assertUnauthorized();
    }
}
