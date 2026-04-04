<?php

namespace App\Services\Import;

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
