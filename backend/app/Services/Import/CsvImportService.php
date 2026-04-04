<?php

namespace App\Services\Import;

use App\Models\Account;
use App\Models\FinanceImport;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CsvImportService
{
    public function __construct(private readonly CsvImportDetector $detector) {}

    /**
     * @return array{
     *     detected_type: string,
     *     file_hash: string,
     *     line_count: int,
     *     delimiter: string,
     *     header_row_index: int|null,
     *     headers: list<string>,
     *     sample_rows: list<array<string, string>>
     * }
     */
    public function preview(string $content): array
    {
        $detectedType = $this->detector->detectFromContent($content);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $delimiter = $this->detectDelimiter($lines);
        $headerRowIndex = $this->findHeaderRowIndex($lines, $delimiter, $detectedType);
        $headers = $headerRowIndex === null ? [] : $this->parseRow($lines[$headerRowIndex], $delimiter);

        return [
            'detected_type' => $detectedType,
            'file_hash' => hash('sha256', $content),
            'line_count' => count($lines),
            'delimiter' => $delimiter,
            'header_row_index' => $headerRowIndex,
            'headers' => $headers,
            'sample_rows' => $headerRowIndex === null ? [] : $this->extractSampleRows($lines, $headerRowIndex, $headers, $delimiter),
        ];
    }

    public function import(User $user, string $fileName, string $content): FinanceImport
    {
        $preview = $this->preview($content);

        if ($preview['detected_type'] === 'unknown' || $preview['header_row_index'] === null || $preview['headers'] === []) {
            throw ValidationException::withMessages([
                'file' => 'Das CSV-Format konnte nicht erkannt oder nicht verarbeitet werden.',
            ]);
        }

        return DB::transaction(function () use ($user, $fileName, $content, $preview): FinanceImport {
            $account = $this->resolveAccount($user, $content, $preview['detected_type'], $preview['delimiter']);

            $import = FinanceImport::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'source_type' => $preview['detected_type'],
                'file_name' => $fileName,
                'file_hash' => $preview['file_hash'],
                'status' => 'processing',
                'started_at' => now(),
            ]);

            [$importedRows, $skippedRows, $errorRows] = $this->persistTransactions($account, $import, $content, $preview);

            $import->forceFill([
                'status' => $errorRows > 0 && $importedRows === 0 ? 'failed' : 'completed',
                'imported_rows' => $importedRows,
                'skipped_rows' => $skippedRows,
                'error_rows' => $errorRows,
                'finished_at' => now(),
                'notes' => sprintf('CSV import %s: %d importiert, %d übersprungen, %d Fehler.', $preview['detected_type'], $importedRows, $skippedRows, $errorRows),
            ])->save();

            return $import->fresh(['account']);
        });
    }

    /**
     * @param  array{detected_type: string, delimiter: string, header_row_index: int|null, headers: list<string>}  $preview
     * @return array{0: int, 1: int, 2: int}
     */
    private function persistTransactions(Account $account, FinanceImport $import, string $content, array $preview): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $headerRowIndex = $preview['header_row_index'];
        $headers = $preview['headers'];
        $delimiter = $preview['delimiter'];
        $sourceType = $preview['detected_type'];

        if ($headerRowIndex === null) {
            return [0, 0, 0];
        }

        $importedRows = 0;
        $skippedRows = 0;
        $errorRows = 0;

        foreach (array_slice($lines, $headerRowIndex + 1) as $line) {
            $row = $this->parseRow($line, $delimiter);

            if ($row === [] || $this->rowIsEmpty($row)) {
                continue;
            }

            $paddedRow = array_pad($row, count($headers), '');
            $payload = array_combine($headers, array_slice($paddedRow, 0, count($headers)));

            if (! is_array($payload)) {
                $errorRows++;

                continue;
            }

            try {
                $normalized = $this->normalizeRow($payload, $sourceType);

                if ($normalized === null) {
                    $skippedRows++;

                    continue;
                }

                $alreadyImported = Transaction::query()
                    ->where('account_id', $account->id)
                    ->where('transaction_hash', $normalized['transaction_hash'])
                    ->exists();

                if ($alreadyImported) {
                    $skippedRows++;

                    continue;
                }

                Transaction::query()->create([
                    ...$normalized,
                    'account_id' => $account->id,
                    'finance_import_id' => $import->id,
                ]);

                $importedRows++;
            } catch (\Throwable) {
                $errorRows++;
            }
        }

        return [$importedRows, $skippedRows, $errorRows];
    }

    private function resolveAccount(User $user, string $content, string $sourceType, string $delimiter): Account
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $contextRow = [];

        foreach (array_slice($lines, 0, 3) as $line) {
            $row = $this->parseRow($line, $delimiter);

            if ($row !== []) {
                $contextRow = $row;
                break;
            }
        }

        return match ($sourceType) {
            'dkb_giro' => $this->resolveDkbGiroAccount($user, $contextRow),
            'dkb_visa' => $this->resolveDkbVisaAccount($user, $contextRow),
            'paypal' => $this->resolvePayPalAccount($user),
            default => throw ValidationException::withMessages([
                'file' => 'Das CSV-Format wird derzeit noch nicht als echter Import unterstützt.',
            ]),
        };
    }

    /**
     * @param  list<string>  $contextRow
     */
    private function resolveDkbGiroAccount(User $user, array $contextRow): Account
    {
        $name = $contextRow[0] ?? 'DKB Girokonto';
        $ibanMasked = $this->maskIban($contextRow[1] ?? null);

        return Account::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'account_type' => 'checking_account',
                'name' => $name,
            ],
            [
                'institution' => 'DKB',
                'iban_masked' => $ibanMasked,
                'currency' => 'EUR',
                'metadata' => ['source_type' => 'dkb_giro'],
            ],
        );
    }

    /**
     * @param  list<string>  $contextRow
     */
    private function resolveDkbVisaAccount(User $user, array $contextRow): Account
    {
        $cardLabel = trim(implode(' ', array_filter([
            $contextRow[1] ?? 'DKB Visa',
            $contextRow[2] ?? null,
        ])));

        return Account::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'account_type' => 'credit_card',
                'name' => $cardLabel !== '' ? $cardLabel : 'DKB Visa',
            ],
            [
                'institution' => 'DKB',
                'currency' => 'EUR',
                'metadata' => ['source_type' => 'dkb_visa'],
            ],
        );
    }

    private function resolvePayPalAccount(User $user): Account
    {
        return Account::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'account_type' => 'paypal_account',
                'name' => 'PayPal',
            ],
            [
                'institution' => 'PayPal',
                'currency' => 'EUR',
                'metadata' => ['source_type' => 'paypal'],
            ],
        );
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row, string $sourceType): ?array
    {
        return match ($sourceType) {
            'dkb_giro' => $this->normalizeDkbGiroRow($row),
            'dkb_visa' => $this->normalizeDkbVisaRow($row),
            'paypal' => $this->normalizePayPalRow($row),
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeDkbGiroRow(array $row): ?array
    {
        $amount = $this->parseAmount($row['Betrag (€)'] ?? null);
        $bookingDate = $this->parseGermanDate($row['Buchungsdatum'] ?? null);

        if ($amount === null || $bookingDate === null) {
            return null;
        }

        $valueDate = $this->parseGermanDate($row['Wertstellung'] ?? null);
        $counterparty = $this->firstFilled(
            $row['Zahlungsempfänger*in'] ?? null,
            $row['Zahlungspflichtige*r'] ?? null,
        );
        $description = $this->joinTextParts([
            $row['Verwendungszweck'] ?? null,
            $row['Umsatztyp'] ?? null,
        ]);
        $externalId = $this->firstFilled(
            $row['Kundenreferenz'] ?? null,
            $row['Mandatsreferenz'] ?? null,
        );

        return $this->buildTransactionPayload(
            sourceType: 'dkb_giro',
            row: $row,
            bookingDate: $bookingDate,
            valueDate: $valueDate,
            postedAt: null,
            amount: $amount,
            currency: 'EUR',
            counterpartyName: $counterparty,
            description: $description,
            externalId: $externalId,
            sourceReference: $row['IBAN'] ?? null,
            metadata: [
                'status' => $row['Status'] ?? null,
                'umsatztyp' => $row['Umsatztyp'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeDkbVisaRow(array $row): ?array
    {
        $amount = $this->parseAmount($row['Betrag (€)'] ?? null);
        $bookingDate = $this->parseGermanDate($row['Belegdatum'] ?? null);

        if ($amount === null || $bookingDate === null) {
            return null;
        }

        $valueDate = $this->parseGermanDate($row['Wertstellung'] ?? null);
        $description = $this->joinTextParts([
            $row['Beschreibung'] ?? null,
            $row['Umsatztyp'] ?? null,
        ]);

        return $this->buildTransactionPayload(
            sourceType: 'dkb_visa',
            row: $row,
            bookingDate: $bookingDate,
            valueDate: $valueDate,
            postedAt: null,
            amount: $amount,
            currency: 'EUR',
            counterpartyName: $row['Beschreibung'] ?? null,
            description: $description,
            externalId: $this->firstFilled($row['Beschreibung'] ?? null, $row['Umsatztyp'] ?? null),
            sourceReference: $row['Status'] ?? null,
            metadata: [
                'status' => $row['Status'] ?? null,
                'umsatztyp' => $row['Umsatztyp'] ?? null,
                'foreign_amount' => $row['Fremdwährungsbetrag'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function normalizePayPalRow(array $row): ?array
    {
        $amount = $this->parseAmount($row['Netto'] ?? ($row['Brutto'] ?? null));
        $bookingDate = $this->parseGermanDate($row['Datum'] ?? null);

        if ($amount === null || $bookingDate === null) {
            return null;
        }

        $postedAt = $this->parseGermanDateTime($row['Datum'] ?? null, $row['Uhrzeit'] ?? null);
        $currency = strtoupper(trim((string) ($row['Währung'] ?? 'EUR')));
        $counterparty = $this->firstFilled(
            $row['Name'] ?? null,
            $row['Absender E-Mail-Adresse'] ?? null,
            $row['Beschreibung'] ?? null,
        );
        $description = $this->joinTextParts([
            $row['Beschreibung'] ?? null,
            $row['Rechnungsnummer'] ?? null,
        ]);

        return $this->buildTransactionPayload(
            sourceType: 'paypal',
            row: $row,
            bookingDate: $bookingDate,
            valueDate: $bookingDate,
            postedAt: $postedAt,
            amount: $amount,
            currency: $currency !== '' ? $currency : 'EUR',
            counterpartyName: $counterparty,
            description: $description,
            externalId: $row['Transaktionscode'] ?? null,
            sourceReference: $row['Zugehöriger Transaktionscode'] ?? null,
            metadata: [
                'fee' => $row['Entgelt'] ?? null,
                'gross' => $row['Brutto'] ?? null,
                'balance' => $row['Guthaben'] ?? null,
                'timezone' => $row['Zeitzone'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildTransactionPayload(
        string $sourceType,
        array $row,
        string $bookingDate,
        ?string $valueDate,
        ?string $postedAt,
        string $amount,
        string $currency,
        ?string $counterpartyName,
        ?string $description,
        ?string $externalId,
        ?string $sourceReference,
        array $metadata,
    ): array {
        $direction = (float) $amount < 0 ? 'debit' : 'credit';
        $transactionHash = $this->buildTransactionHash(
            $sourceType,
            $bookingDate,
            $valueDate,
            $amount,
            $currency,
            $counterpartyName,
            $description,
            $externalId,
            $sourceReference,
        );

        return [
            'booking_date' => $bookingDate,
            'value_date' => $valueDate,
            'posted_at' => $postedAt,
            'amount' => $amount,
            'currency' => $currency,
            'direction' => $direction,
            'counterparty_name' => $counterpartyName,
            'description' => $description,
            'external_id' => $externalId,
            'transaction_hash' => $transactionHash,
            'source_system' => $sourceType,
            'source_reference' => $sourceReference,
            'is_transfer' => false,
            'is_hidden_from_cashflow' => false,
            'metadata' => array_filter($metadata, static fn(mixed $value): bool => $value !== null && $value !== ''),
            'raw_payload' => $row,
        ];
    }

    private function buildTransactionHash(
        string $sourceType,
        string $bookingDate,
        ?string $valueDate,
        string $amount,
        string $currency,
        ?string $counterpartyName,
        ?string $description,
        ?string $externalId,
        ?string $sourceReference,
    ): string {
        return hash('sha256', implode('|', [
            $sourceType,
            $bookingDate,
            $valueDate ?? '',
            $amount,
            $currency,
            mb_strtolower((string) $counterpartyName),
            mb_strtolower((string) $description),
            (string) $externalId,
            (string) $sourceReference,
        ]));
    }

    /**
     * @param  list<string>  $lines
     */
    private function detectDelimiter(array $lines): string
    {
        $sample = implode("\n", array_slice($lines, 0, 5));
        $semicolonCount = substr_count($sample, ';');
        $commaCount = substr_count($sample, ',');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    /**
     * @param  list<string>  $lines
     */
    private function findHeaderRowIndex(array $lines, string $delimiter, string $detectedType): ?int
    {
        $markers = match ($detectedType) {
            'dkb_giro' => ['Buchungsdatum', 'Wertstellung', 'Betrag (€)'],
            'dkb_visa' => ['Belegdatum', 'Beschreibung', 'Betrag (€)'],
            'paypal' => ['Datum', 'Beschreibung', 'Transaktionscode'],
            default => [],
        };

        foreach ($lines as $index => $line) {
            $row = $this->parseRow($line, $delimiter);

            if ($row === []) {
                continue;
            }

            if ($markers !== [] && count(array_intersect($markers, $row)) === count($markers)) {
                return $index;
            }
        }

        $bestIndex = null;
        $bestWidth = 0;

        foreach ($lines as $index => $line) {
            $row = $this->parseRow($line, $delimiter);
            $width = count(array_filter($row, fn(string $value): bool => $value !== ''));

            if ($width > $bestWidth) {
                $bestWidth = $width;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $headers
     * @return list<array<string, string>>
     */
    private function extractSampleRows(array $lines, int $headerRowIndex, array $headers, string $delimiter): array
    {
        $sampleRows = [];

        foreach (array_slice($lines, $headerRowIndex + 1) as $line) {
            $row = $this->parseRow($line, $delimiter);

            if ($row === [] || $this->rowIsEmpty($row)) {
                continue;
            }

            $paddedRow = array_pad($row, count($headers), '');
            $sampleRows[] = array_combine($headers, array_slice($paddedRow, 0, count($headers)));

            if (count($sampleRows) >= 5) {
                break;
            }
        }

        return $sampleRows;
    }

    /**
     * @return list<string>
     */
    private function parseRow(string $line, string $delimiter): array
    {
        $line = trim($line);

        if ($line === '' || $line === '""' || $line === "''") {
            return [];
        }

        $values = str_getcsv($line, $delimiter, '"', '\\');

        return array_map(
            static fn(?string $value): string => trim((string) $value),
            $values ?: [],
        );
    }

    private function parseAmount(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(["\u{00A0}", '€', ' '], '', $value);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function parseGermanDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['d.m.y', 'd.m.Y', 'Y-m-d'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $value)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function parseGermanDateTime(?string $date, ?string $time): ?string
    {
        $date = trim((string) $date);
        $time = trim((string) $time);

        if ($date === '' || $time === '') {
            return null;
        }

        foreach (['d.m.y H:i:s', 'd.m.Y H:i:s'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, sprintf('%s %s', $date, $time))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function joinTextParts(array $parts): ?string
    {
        $filtered = array_values(array_filter(array_map(
            static fn(?string $value): string => trim((string) $value),
            $parts,
        )));

        return $filtered === [] ? null : implode(' • ', $filtered);
    }

    private function maskIban(?string $iban): ?string
    {
        $iban = preg_replace('/\s+/', '', (string) $iban) ?? '';

        if ($iban === '') {
            return null;
        }

        if (strlen($iban) <= 8) {
            return $iban;
        }

        return substr($iban, 0, 4) . '••••' . substr($iban, -4);
    }

    /**
     * @param  list<string>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }
}
