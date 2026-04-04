<?php

namespace App\Services\Import;

class CsvImportDetector
{
    public function detectFromContent(string $content): string
    {
        $normalized = mb_strtolower($content);

        if ($this->containsAll($normalized, [
            'buchungsdatum',
            'wertstellung',
            'zahlungspflichtige*r',
            'zahlungsempfänger*in',
            'betrag (€)',
        ])) {
            return 'dkb_giro';
        }

        if ($this->containsAll($normalized, [
            'visa kreditkarte',
            'belegdatum',
            'beschreibung',
            'fremdwährungsbetrag',
        ])) {
            return 'dkb_visa';
        }

        if ($this->containsAll($normalized, [
            'transaktionscode',
            'zugehöriger transaktionscode',
            'beschreibung',
            'brutto',
            'netto',
        ])) {
            return 'paypal';
        }

        return 'unknown';
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }
}
