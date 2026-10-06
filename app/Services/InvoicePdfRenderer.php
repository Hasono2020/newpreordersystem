<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Turns a batch of prepared invoices (CombinedInvoiceService::build()) into the
 * bytes of one A5 PDF, one customer per page, using dompdf.
 *
 * dompdf is NOT bundled — install it with `composer require dompdf/dompdf`.
 * available() lets callers say so politely instead of crashing with "class not
 * found" on a server where it hasn't been installed yet.
 *
 * Each call builds a fresh Dompdf and discards it. That matters: dompdf's time
 * and memory grow faster than linearly with page count (measured: 50 customers
 * ~4s / 130MB, 300 customers in ONE pdf ~60s / 670MB, versus 21s / 82MB as parts
 * of 25), so TripInvoicePdfController renders big trips as several smaller PDFs
 * rather than one huge one.
 */
class InvoicePdfRenderer
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * @param  array<int, array> $invoices  from CombinedInvoiceService::build()
     * @return string                       the PDF file's bytes
     */
    public function render(array $invoices, string $tripName): string
    {
        $html = view('orders.trip-invoices-pdf', [
            'invoices'     => $invoices,
            'tripName'     => $tripName,
            'storeName'    => Setting::get('store_name', config('app.name')),
            'storeTagline' => Setting::get('store_tagline', ''),
            'storePhone'   => Setting::get('store_phone', ''),
            'printedAt'    => now()->format('d M Y H:i'),
        ])->render();

        // Somewhere writable for dompdf's temp files and font cache — the vendor
        // folder may be read-only on shared hosting.
        $workDir = storage_path('app/dompdf');
        if (! is_dir($workDir)) {
            @mkdir($workDir, 0775, true);
        }

        $options = new \Dompdf\Options();
        // DejaVu Sans ships with dompdf and covers accents, dashes and symbols that
        // the built-in PDF fonts would print as "?". (It has no Chinese glyphs —
        // CJK text would need a CJK font installed.)
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);   // nothing here should fetch anything
        $options->set('tempDir', $workDir);
        $options->set('fontCache', $workDir);
        $options->set('chroot', base_path());

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A5', 'portrait');
        $dompdf->render();

        $bytes = $dompdf->output();
        unset($dompdf, $html);

        return $bytes;
    }
}
