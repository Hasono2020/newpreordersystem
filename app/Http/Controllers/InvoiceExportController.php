<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateInvoiceExportPartJob;
use App\Models\ActivityLog;
use App\Models\InvoiceExport;
use App\Models\Order;
use App\Models\Trip;
use App\Services\CombinedInvoiceService;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * "Build every invoice for a trip" for trips too big to build while you wait. The work
 * runs on the queue (see GenerateInvoiceExportPartJob); this page starts it, shows its
 * progress, and hands over the finished ZIP.
 */
class InvoiceExportController extends Controller
{
    public function index(Request $request)
    {
        $ownerOnly = Auth::user()->isOwnDataOnly();

        $exports = InvoiceExport::with('trip', 'createdBy')
            ->when($ownerOnly, fn ($q) => $q->where('created_by', Auth::id()))
            ->latest()->latest('id')
            ->limit(30)
            ->get();

        // Arriving from "Download all invoices" on a trip that was too big for an instant download:
        // offer to build it in the background instead.
        $offer = null;
        if ($request->filled('trip') && $trip = Trip::find($request->query('trip'))) {
            $count = Order::where('trip_id', $trip->id)
                ->when($ownerOnly, fn ($q) => $q->where('created_by', Auth::id()))
                ->distinct()->count('customer_id');
            $offer = ['trip' => $trip, 'customers' => $count, 'perPart' => $this->perPart()];
        }

        $hasActive = $exports->contains(fn ($e) => ! $e->isFinished());

        return view('invoice-exports.index', compact('exports', 'offer', 'hasActive'));
    }

    public function store(Request $request, Trip $trip, CombinedInvoiceService $invoices, InvoicePdfRenderer $pdf)
    {
        if (! $pdf->available()) {
            return back()->with('error',
                'The PDF library is not installed on this server yet. Run "composer require dompdf/dompdf" in the project folder, then try again.');
        }
        if (! class_exists(ZipArchive::class)) {
            return back()->with('error', 'This server has no ZIP support (the PHP zip extension). Ask your hosting provider to enable it.');
        }

        // Pressing the button twice must not start two builds of the same trip.
        $running = InvoiceExport::where('trip_id', $trip->id)
            ->where('created_by', Auth::id())
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($running) {
            return redirect()->route('invoice-exports.index')
                ->with('warning', "\"{$trip->name}\" is already being built — its progress is below.");
        }

        $ownerId = Auth::user()->isOwnDataOnly() ? Auth::id() : null;
        $ids     = $invoices->customerIdsForTrip($trip, $ownerId);

        if ($ids === []) {
            return back()->with('error', "There are no orders in \"{$trip->name}\" to make invoices from.");
        }

        $perPart = $this->perPart();
        $parts   = (int) ceil(count($ids) / $perPart);

        $export = InvoiceExport::create([
            'trip_id'            => $trip->id,
            'created_by'         => Auth::id(),
            'owner_id'           => $ownerId,
            'status'             => 'queued',
            'customer_ids'       => $ids,
            'total_customers'    => count($ids),
            'customers_per_part' => $perPart,
            'total_parts'        => $parts,
        ]);

        ActivityLog::record(
            'invoice.export_started',
            "Started building invoices for \"{$trip->name}\" in the background — " . count($ids) . " customer(s) in {$parts} part(s)",
            'trip',
            $trip->id
        );

        GenerateInvoiceExportPartJob::dispatch($export->id, 0);

        return redirect()->route('invoice-exports.index')
            ->with('success', 'Started. You can leave this page — it keeps building in the background.');
    }

    public function download(InvoiceExport $invoiceExport)
    {
        $this->authorizeOwner($invoiceExport);

        abort_unless(
            $invoiceExport->status === 'done'
                && $invoiceExport->file_path
                && Storage::disk('local')->exists($invoiceExport->file_path),
            404,
            'This file is not available — it may have been removed.'
        );

        ActivityLog::record(
            'invoice.trip_pdf_downloaded',
            "Downloaded the background invoice build for \"{$invoiceExport->trip?->name}\" — {$invoiceExport->total_customers} customer(s)",
            'trip',
            $invoiceExport->trip_id
        );

        return Storage::disk('local')->download($invoiceExport->file_path, basename($invoiceExport->file_path));
    }

    /** Remove a build — and, if it's still running, stop it (the job checks before each part). */
    public function destroy(InvoiceExport $invoiceExport)
    {
        $this->authorizeOwner($invoiceExport);

        $invoiceExport->deleteFiles();
        $invoiceExport->delete();

        return redirect()->route('invoice-exports.index')->with('success', 'Removed.');
    }

    /** Staff who can only see their own data may only touch their own builds. */
    private function authorizeOwner(InvoiceExport $export): void
    {
        abort_if(Auth::user()->isOwnDataOnly() && $export->created_by !== Auth::id(), 403);
    }

    private function perPart(): int
    {
        return max(1, (int) config('invoices.background_customers_per_file', 50));
    }
}
