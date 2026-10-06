<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Trip;
use App\Services\CombinedInvoiceService;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * "Download all invoices (PDF)" for one trip: a combined invoice for every
 * customer who has orders in it, one customer per A5 page, alphabetical.
 *
 * Up to config('invoices.pdf_customers_per_file') customers (60 by default) come back as a single PDF. A bigger trip
 * comes back as ONE zip of several PDFs (still one click). That is deliberate:
 * dompdf's time and memory grow faster than linearly with page count, so one
 * enormous PDF risks blowing a shared host's memory/time limit, whereas parts of
 * this size stay small and quick (see InvoicePdfRenderer for the measurements).
 */
class TripInvoicePdfController extends Controller
{
    /** Fallbacks if config/invoices.php is missing. See that file for the measurements behind them. */
    public const DEFAULT_CUSTOMERS_PER_FILE = 60;
    public const DEFAULT_MAX_CUSTOMERS      = 600;

    public function __invoke(Request $request, Trip $trip, CombinedInvoiceService $invoices, InvoicePdfRenderer $pdf)
    {
        if (! $pdf->available()) {
            return back()->with('error',
                'The PDF library is not installed on this server yet. Run "composer require dompdf/dompdf" in the project folder, then try again.');
        }

        // Generous limits for a big trip; many hosts honour these, none are harmed by asking.
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        // Staff who can only see their own orders get invoices for just those.
        $perFile      = max(1, (int) config('invoices.pdf_customers_per_file', self::DEFAULT_CUSTOMERS_PER_FILE));
        $maxCustomers = max($perFile, (int) config('invoices.pdf_max_customers', self::DEFAULT_MAX_CUSTOMERS));

        $all = $invoices->forTrip($trip, Auth::user()->isOwnDataOnly() ? Auth::id() : null);

        if ($all->isEmpty()) {
            return back()->with('error', "There are no orders in \"{$trip->name}\" to make invoices from.");
        }

        if ($all->count() > $maxCustomers) {
            return back()->with('error',
                "\"{$trip->name}\" has {$all->count()} customers — more than the {$maxCustomers}"
                . ' that can be built in one download without risking a timeout. '
                . 'Use the individual combined invoices for now, or ask for the background-generation version.');
        }

        $slug  = Str::slug($trip->name) ?: 'trip-' . $trip->id;
        $parts = $all->chunk($perFile)->values();

        ActivityLog::record(
            'invoice.trip_pdf_downloaded',
            "Downloaded invoices PDF for \"{$trip->name}\" — {$all->count()} customer(s)"
                . ($parts->count() > 1 ? " in {$parts->count()} files" : ''),
            'trip',
            $trip->id
        );

        // One PDF.
        if ($parts->count() === 1) {
            return response($pdf->render($parts->first()->all(), $trip->name), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="invoices_' . $slug . '.pdf"',
            ]);
        }

        // Several PDFs in one zip. Each part is rendered and written to disk before
        // the next starts, so memory never holds more than one part at a time.
        $zipPath = tempnam(sys_get_temp_dir(), 'invzip_');
        $zip     = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $tmpFiles = [];
        $total    = $parts->count();
        foreach ($parts as $i => $part) {
            $tmp = tempnam(sys_get_temp_dir(), 'invpdf_');
            file_put_contents($tmp, $pdf->render($part->values()->all(), $trip->name));
            $tmpFiles[] = $tmp;

            // e.g. invoices_china-oct-2026_part-02-of-05_adi-to-dewi.pdf — the
            // customer range in the name makes the right file easy to find.
            $first = Str::limit(Str::slug($part->first()['customer']['name']), 18, '');
            $last  = Str::limit(Str::slug($part->last()['customer']['name']), 18, '');
            $zip->addFile($tmp, sprintf('invoices_%s_part-%02d-of-%02d_%s-to-%s.pdf', $slug, $i + 1, $total, $first ?: 'a', $last ?: 'z'));
        }

        $zip->close();
        foreach ($tmpFiles as $tmp) {
            @unlink($tmp);
        }

        return response()->download($zipPath, "invoices_{$slug}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
