<?php

namespace Tests\Unit;

use App\Services\Import\CsvImportDetector;
use App\Services\Import\CsvImportService;
use PHPUnit\Framework\TestCase;

class CsvImportServiceTest extends TestCase
{
    public function test_it_detects_dkb_giro_csv_from_headers(): void
    {
        $content = <<<'CSV'
"Girokonto";"DE02120300000000202051"
""
"Buchungsdatum";"Wertstellung";"Status";"Zahlungspflichtige*r";"Zahlungsempfänger*in";"Verwendungszweck";"Umsatztyp";"IBAN";"Betrag (€)";"Gläubiger-ID";"Mandatsreferenz";"Kundenreferenz"
CSV;

        $detector = new CsvImportDetector();

        $this->assertSame('dkb_giro', $detector->detectFromContent($content));
    }

    public function test_it_detects_visa_csv_from_headers(): void
    {
        $content = <<<'CSV'
"Karte";"Visa Kreditkarte";"4000 •••• •••• 0000"
""
"Belegdatum";"Wertstellung";"Status";"Beschreibung";"Umsatztyp";"Betrag (€)";"Fremdwährungsbetrag"
CSV;

        $detector = new CsvImportDetector();

        $this->assertSame('dkb_visa', $detector->detectFromContent($content));
    }

    public function test_it_detects_paypal_csv_from_headers(): void
    {
        $content = <<<'CSV'
"Datum","Uhrzeit","Zeitzone","Beschreibung","Währung","Brutto","Entgelt","Netto","Guthaben","Transaktionscode","Absender E-Mail-Adresse","Name","Name der Bank","Bankkonto","Versand- und Bearbeitungsgebühr","Umsatzsteuer","Rechnungsnummer","Zugehöriger Transaktionscode"
"21.01.2026","19:23:08","Europe/Berlin","PayPal Express-Zahlung","EUR","-11,99","0,00","-11,99","-11,99","6W3730272P751531C","robloxpaypal@roblox.com","ROBLOX Corporation","","","0,00","0,00","ad47a3c4-9ae3-41ad-bdcf-101520e5d35f",""
CSV;

        $detector = new CsvImportDetector();

        $this->assertSame('paypal', $detector->detectFromContent($content));
    }

    public function test_it_builds_a_preview_with_headers_and_sample_rows(): void
    {
        $content = <<<'CSV'
"Girokonto";"DE02120300000000202051"
""
"Buchungsdatum";"Wertstellung";"Status";"Zahlungspflichtige*r";"Zahlungsempfänger*in";"Verwendungszweck";"Umsatztyp";"IBAN";"Betrag (€)";"Gläubiger-ID";"Mandatsreferenz";"Kundenreferenz"
"02.04.26";"02.04.26";"Gebucht";"ISSUER";"REWE";"Girokartenumsatz";"Ausgang";"DE02100500000054540402";"-31,04";"";"";"00000000000000000000000000"
CSV;

        $service = new CsvImportService(new CsvImportDetector());
        $preview = $service->preview($content);

        $this->assertSame('dkb_giro', $preview['detected_type']);
        $this->assertSame(';', $preview['delimiter']);
        $this->assertSame('Buchungsdatum', $preview['headers'][0]);
        $this->assertSame('REWE', $preview['sample_rows'][0]['Zahlungsempfänger*in']);
        $this->assertSame('-31,04', $preview['sample_rows'][0]['Betrag (€)']);
    }
}
