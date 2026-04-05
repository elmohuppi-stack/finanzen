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

    /**
     * @param  array{detected_type: string, delimiter: string, header_row_index: int|null, headers: list<string>}|null  $preview
     * @return array{account: array{id: int|null, name: string|null, account_type: string|null}, recognized_rows: int, imported_rows: int, skipped_rows: int, duplicate_rows: int, unreadable_rows: int, error_rows: int, note: string}
     */
    public function inspectImport(User $user, string $content, ?array $preview = null): array
    {
        $preview ??= $this->preview($content);

        if ($preview['detected_type'] === 'unknown' || $preview['header_row_index'] === null || $preview['headers'] === []) {
            return [
                'account' => [
                    'id' => null,
                    'name' => null,
                    'account_type' => null,
                ],
                'recognized_rows' => 0,
                'imported_rows' => 0,
                'skipped_rows' => 0,
                'duplicate_rows' => 0,
                'unreadable_rows' => 0,
                'error_rows' => 0,
                'note' => 'Das CSV-Format konnte nicht sicher erkannt werden.',
            ];
        }

        $account = $this->resolveAccount(
            $user,
            $content,
            $preview['detected_type'],
            $preview['delimiter'],
            false,
        );
        $stats = $this->collectImportStats($account, null, $content, $preview);

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'account_type' => $account->account_type,
            ],
            'recognized_rows' => $stats['recognized_rows'],
            'imported_rows' => $stats['imported_rows'],
            'skipped_rows' => $stats['skipped_rows'],
            'duplicate_rows' => $stats['duplicate_rows'],
            'unreadable_rows' => $stats['unreadable_rows'],
            'error_rows' => $stats['error_rows'],
            'note' => $this->buildImportNote($stats, true),
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
            $accountSnapshot = $this->extractAccountSnapshot(
                $content,
                $preview['delimiter'],
                $preview['header_row_index'],
            );

            $this->applyAccountSnapshot($account, $accountSnapshot);

            $import = FinanceImport::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'source_type' => $preview['detected_type'],
                'file_name' => $fileName,
                'file_hash' => $preview['file_hash'],
                'status' => 'processing',
                'started_at' => now(),
            ]);

            $stats = $this->persistTransactions($account, $import, $content, $preview);

            $import->forceFill([
                'status' => $stats['error_rows'] > 0 && $stats['imported_rows'] === 0 ? 'failed' : 'completed',
                'imported_rows' => $stats['imported_rows'],
                'skipped_rows' => $stats['skipped_rows'],
                'error_rows' => $stats['error_rows'],
                'finished_at' => now(),
                'notes' => $this->buildImportNote($stats),
            ])->save();

            return $import->fresh(['account']);
        });
    }

    /**
     * @param  array{detected_type: string, delimiter: string, header_row_index: int|null, headers: list<string>}  $preview
     * @return array{recognized_rows: int, imported_rows: int, skipped_rows: int, duplicate_rows: int, unreadable_rows: int, error_rows: int}
     */
    private function persistTransactions(Account $account, FinanceImport $import, string $content, array $preview): array
    {
        return $this->collectImportStats($account, $import, $content, $preview);
    }

    /**
     * @param  array{detected_type: string, delimiter: string, header_row_index: int|null, headers: list<string>}  $preview
     * @return array{recognized_rows: int, imported_rows: int, skipped_rows: int, duplicate_rows: int, unreadable_rows: int, error_rows: int}
     */
    private function collectImportStats(Account $account, ?FinanceImport $import, string $content, array $preview): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $headerRowIndex = $preview['header_row_index'];
        $headers = $preview['headers'];
        $delimiter = $preview['delimiter'];
        $sourceType = $preview['detected_type'];

        if ($headerRowIndex === null) {
            return [
                'recognized_rows' => 0,
                'imported_rows' => 0,
                'skipped_rows' => 0,
                'duplicate_rows' => 0,
                'unreadable_rows' => 0,
                'error_rows' => 0,
            ];
        }

        $recognizedRows = 0;
        $importedRows = 0;
        $duplicateRows = 0;
        $unreadableRows = 0;
        $errorRows = 0;

        foreach (array_slice($lines, $headerRowIndex + 1) as $line) {
            $row = $this->parseRow($line, $delimiter);

            if ($row === [] || $this->rowIsEmpty($row)) {
                continue;
            }

            $paddedRow = array_pad($row, count($headers), '');
            $payload = array_combine($headers, array_slice($paddedRow, 0, count($headers)));

            if (! is_array($payload)) {
                $unreadableRows++;
                continue;
            }

            try {
                $normalized = $this->normalizeRow($payload, $sourceType);

                if ($normalized === null) {
                    $unreadableRows++;
                    continue;
                }

                $recognizedRows++;

                $alreadyImported = $account->exists
                    && Transaction::query()
                    ->where('account_id', $account->id)
                    ->where('transaction_hash', $normalized['transaction_hash'])
                    ->exists();

                if ($alreadyImported) {
                    $duplicateRows++;
                    continue;
                }

                if ($import !== null) {
                    Transaction::query()->create([
                        ...$normalized,
                        'account_id' => $account->id,
                        'finance_import_id' => $import->id,
                    ]);
                }

                $importedRows++;
            } catch (\Throwable) {
                $errorRows++;
            }
        }

        return [
            'recognized_rows' => $recognizedRows,
            'imported_rows' => $importedRows,
            'skipped_rows' => $duplicateRows + $unreadableRows,
            'duplicate_rows' => $duplicateRows,
            'unreadable_rows' => $unreadableRows,
            'error_rows' => $errorRows,
        ];
    }

    private function resolveAccount(
        User $user,
        string $content,
        string $sourceType,
        string $delimiter,
        bool $createIfMissing = true,
    ): Account {
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
            'dkb_giro' => $this->resolveDkbGiroAccount($user, $contextRow, $createIfMissing),
            'dkb_visa' => $this->resolveDkbVisaAccount($user, $contextRow, $createIfMissing),
            'paypal' => $this->resolvePayPalAccount($user, $createIfMissing),
            default => throw ValidationException::withMessages([
                'file' => 'Das CSV-Format wird derzeit noch nicht als echter Import unterstützt.',
            ]),
        };
    }

    /**
     * @param  list<string>  $contextRow
     */
    private function resolveDkbGiroAccount(User $user, array $contextRow, bool $createIfMissing = true): Account
    {
        $name = $contextRow[0] ?? 'DKB Girokonto';
        $ibanMasked = $this->maskIban($contextRow[1] ?? null);

        $attributes = [
            'user_id' => $user->id,
            'account_type' => 'checking_account',
            'name' => $name,
        ];
        $defaults = [
            'institution' => 'DKB',
            'iban_masked' => $ibanMasked,
            'currency' => 'EUR',
            'metadata' => ['source_type' => 'dkb_giro'],
        ];

        return $createIfMissing
            ? Account::query()->firstOrCreate($attributes, $defaults)
            : Account::query()->firstOrNew($attributes, $defaults);
    }

    /**
     * @param  list<string>  $contextRow
     */
    private function resolveDkbVisaAccount(User $user, array $contextRow, bool $createIfMissing = true): Account
    {
        $cardLabel = trim(implode(' ', array_filter([
            $contextRow[1] ?? 'DKB Visa',
            $contextRow[2] ?? null,
        ])));

        $attributes = [
            'user_id' => $user->id,
            'account_type' => 'credit_card',
            'name' => $cardLabel !== '' ? $cardLabel : 'DKB Visa',
        ];
        $defaults = [
            'institution' => 'DKB',
            'currency' => 'EUR',
            'metadata' => ['source_type' => 'dkb_visa'],
        ];

        return $createIfMissing
            ? Account::query()->firstOrCreate($attributes, $defaults)
            : Account::query()->firstOrNew($attributes, $defaults);
    }

    private function resolvePayPalAccount(User $user, bool $createIfMissing = true): Account
    {
        $attributes = [
            'user_id' => $user->id,
            'account_type' => 'paypal_account',
            'name' => 'PayPal',
        ];
        $defaults = [
            'institution' => 'PayPal',
            'currency' => 'EUR',
            'metadata' => ['source_type' => 'paypal'],
        ];

        return $createIfMissing
            ? Account::query()->firstOrCreate($attributes, $defaults)
            : Account::query()->firstOrNew($attributes, $defaults);
    }

    /**
     * @return array{current_balance?: string, balance_as_of?: string, statement_period_from?: string, statement_period_to?: string}
     */
    private function extractAccountSnapshot(string $content, string $delimiter, ?int $headerRowIndex): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $snapshotLines = $headerRowIndex === null
            ? array_slice($lines, 0, 12)
            : array_slice($lines, 0, $headerRowIndex);

        $snapshot = [];

        foreach ($snapshotLines as $line) {
            $row = $this->parseRow($line, $delimiter);

            if ($row === []) {
                continue;
            }

            $label = trim((string) ($row[0] ?? ''));
            $value = trim((string) ($row[1] ?? ''));
            $combined = trim(implode(' ', array_filter($row, static fn(string $cell): bool => $cell !== '')));
            $searchText = $label !== '' ? $label : $combined;

            if (
                (! isset($snapshot['statement_period_from']) || ! isset($snapshot['statement_period_to']))
                && str_contains(mb_strtolower($searchText), 'zeitraum')
                && preg_match('/(\d{2}\.\d{2}\.\d{2,4})\s*-\s*(\d{2}\.\d{2}\.\d{2,4})/', $value !== '' ? $value : $combined, $matches) === 1
            ) {
                $periodFrom = $this->parseGermanDate($matches[1]);
                $periodTo = $this->parseGermanDate($matches[2]);

                if ($periodFrom !== null) {
                    $snapshot['statement_period_from'] = $periodFrom;
                }

                if ($periodTo !== null) {
                    $snapshot['statement_period_to'] = $periodTo;
                }
            }

            if (! isset($snapshot['current_balance']) && preg_match('/(?:kontostand|saldo)/iu', $searchText) === 1) {
                $balanceAsOf = null;

                if (preg_match('/(\d{2}\.\d{2}\.\d{2,4})/', $searchText, $dateMatch) === 1) {
                    $balanceAsOf = $this->parseGermanDate($dateMatch[1]);
                }

                $amount = $this->parseAmount($value !== '' ? $value : $combined);

                if ($amount !== null) {
                    $snapshot['current_balance'] = $amount;
                }

                if ($balanceAsOf !== null) {
                    $snapshot['balance_as_of'] = $balanceAsOf;
                }
            }
        }

        return $snapshot;
    }

    /**
     * @param  array{current_balance?: string, balance_as_of?: string, statement_period_from?: string, statement_period_to?: string}  $snapshot
     */
    private function applyAccountSnapshot(Account $account, array $snapshot): void
    {
        if ($snapshot === []) {
            return;
        }

        $metadata = is_array($account->metadata) ? $account->metadata : [];
        $incomingBalance = $snapshot['current_balance'] ?? null;
        $incomingDate = $snapshot['balance_as_of'] ?? null;
        $existingDate = data_get($metadata, 'balance_as_of');
        $shouldReplaceSnapshot = $incomingBalance !== null
            && ($existingDate === null || ($incomingDate !== null && $incomingDate >= $existingDate));

        if ($shouldReplaceSnapshot) {
            $account->current_balance = $incomingBalance;
            $account->metadata = array_merge($metadata, $snapshot);
            $account->save();

            return;
        }

        foreach ($snapshot as $key => $value) {
            if (! array_key_exists($key, $metadata)) {
                $metadata[$key] = $value;
            }
        }

        $account->metadata = $metadata;
        $account->save();
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
        $counterparty = (float) $amount >= 0
            ? $this->firstFilled(
                $row['Zahlungspflichtige*r'] ?? null,
                $row['Zahlungsempfänger*in'] ?? null,
            )
            : $this->firstFilled(
                $row['Zahlungsempfänger*in'] ?? null,
                $row['Zahlungspflichtige*r'] ?? null,
            );
        $description = $this->joinTextParts([
            $row['Verwendungszweck'] ?? null,
            $row['Umsatztyp'] ?? null,
        ]);
        $cashWithdrawalAmount = $this->extractCashWithdrawalAmount(
            $row['Verwendungszweck'] ?? null,
            $description,
        );
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
                'cash_withdrawal_amount' => $cashWithdrawalAmount,
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
            $row['Absender E-Mail-Adresse'] ?? ($row['Von E-Mail-Adresse'] ?? null),
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
                'fee' => $row['Entgelt'] ?? ($row['Gebühr'] ?? null),
                'gross' => $row['Brutto'] ?? null,
                'balance' => $row['Guthaben'] ?? ($row['Saldo'] ?? null),
                'timezone' => $row['Zeitzone'] ?? null,
            ],
        );
    }

    private function extractCashWithdrawalAmount(?string ...$values): ?string
    {
        $text = trim(implode(' ', array_filter(array_map(
            static fn(?string $value): string => trim((string) $value),
            $values,
        ))));

        if ($text === '') {
            return null;
        }

        if (preg_match('/bargeldausz(?:ahlung)?\.?\s*([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2}|[0-9]+,[0-9]{2}|[0-9]+)/iu', $text, $matches) !== 1) {
            return null;
        }

        return $this->parseAmount($matches[1] ?? null);
    }

    /**
     * @param  array{recognized_rows: int, imported_rows: int, skipped_rows: int, duplicate_rows: int, unreadable_rows: int, error_rows: int}  $stats
     */
    private function buildImportNote(array $stats, bool $isPreview = false): string
    {
        if ($stats['imported_rows'] === 0) {
            if ($stats['duplicate_rows'] > 0 && $stats['unreadable_rows'] === 0 && $stats['error_rows'] === 0) {
                return $isPreview
                    ? 'Beim Import würden keine neuen Umsätze übernommen, weil alle erkannten Buchungen bereits vorhanden sind.'
                    : 'Es wurden keine neuen Umsätze importiert, weil alle erkannten Buchungen bereits vorhanden sind.';
            }

            if ($stats['duplicate_rows'] === 0 && $stats['unreadable_rows'] > 0 && $stats['error_rows'] === 0) {
                return $isPreview
                    ? 'Beim Import würden keine neuen Umsätze übernommen, weil die Buchungszeilen nicht sauber gelesen werden konnten.'
                    : 'Es wurden keine neuen Umsätze importiert, weil die Buchungszeilen nicht sauber gelesen werden konnten.';
            }

            if ($stats['duplicate_rows'] > 0 && $stats['unreadable_rows'] > 0) {
                return $isPreview
                    ? sprintf('Beim Import würden keine neuen Umsätze übernommen: %d Zeilen sind bereits bekannt, %d konnten nicht gelesen werden.', $stats['duplicate_rows'], $stats['unreadable_rows'])
                    : sprintf('Es wurden keine neuen Umsätze importiert: %d Zeilen sind bereits bekannt, %d konnten nicht gelesen werden.', $stats['duplicate_rows'], $stats['unreadable_rows']);
            }

            if ($stats['error_rows'] > 0) {
                return $isPreview
                    ? 'Beim Import würden keine neuen Umsätze übernommen, weil beim Einlesen Fehler auftreten.'
                    : 'Es wurden keine neuen Umsätze importiert, weil beim Einlesen Fehler aufgetreten sind.';
            }

            return $isPreview
                ? 'Beim Import würden keine neuen Umsätze übernommen.'
                : 'Es wurden keine neuen Umsätze importiert.';
        }

        $parts = [sprintf('%d neu', $stats['imported_rows'])];

        if ($stats['duplicate_rows'] > 0) {
            $parts[] = sprintf('%d bereits bekannt', $stats['duplicate_rows']);
        }

        if ($stats['unreadable_rows'] > 0) {
            $parts[] = sprintf('%d nicht lesbar', $stats['unreadable_rows']);
        }

        if ($stats['error_rows'] > 0) {
            $parts[] = sprintf('%d Fehler', $stats['error_rows']);
        }

        return ($isPreview ? 'Voraussichtliches Ergebnis: ' : 'Import abgeschlossen: ') . implode(', ', $parts) . '.';
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
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        $line = trim($line);

        if ($line === '' || $line === '""' || $line === "''") {
            return [];
        }

        $values = str_getcsv($line, $delimiter, '"', '\\');

        return array_map(
            static fn(?string $value): string => trim(ltrim((string) $value, "\u{FEFF}")),
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
