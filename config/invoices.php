<?php

/*
 * Whole-trip invoice PDF ("Download all invoices (PDF)").
 *
 * dompdf's time and memory grow faster than linearly with page count, so a trip
 * is split into several smaller PDFs (delivered together as one zip) instead of
 * one enormous one. Measured on a sample invoice, one customer per page:
 *
 *      one PDF of 300 customers   ~60s   ~670MB
 *      parts of 50                ~25s   ~134MB   (6 files)
 *      parts of 25                ~21s    ~82MB   (12 files)
 *
 * The defaults suit typical shared hosting (a 256–512MB memory limit). If your
 * server has plenty of headroom, raise pdf_customers_per_file for fewer, larger
 * files; if you see memory or timeout errors, lower it.
 */
return [
    // Up to this many customers come back as a single PDF; more become a zip of PDFs.
    'pdf_customers_per_file' => (int) env('INVOICE_PDF_CUSTOMERS_PER_FILE', 60),

    // Past this many customers one request would run too long to be reliable —
    // the trip is offered as a background build instead (see below).
    'pdf_max_customers' => (int) env('INVOICE_PDF_MAX_CUSTOMERS', 600),

    // ── Background build (trips too big to build while you wait) ──
    // Customers per PDF part. Each part is one short queue job. Measured per invoice (dompdf's
    // layout time grows faster than linearly with page count, so smaller parts are cheaper):
    //
    //      parts of  10   80 ms     50MB
    //      parts of  25   61 ms     82MB   <- fastest, and light on memory
    //      parts of  50   77 ms    140MB
    //      parts of 100  108 ms    252MB
    //
    // Leave it at 25 unless you have a reason; if a job runs out of memory, lower it.
    'background_customers_per_file' => (int) env('INVOICE_EXPORT_CUSTOMERS_PER_FILE', 25),

    // Finished builds are deleted after this many days (they can be hundreds of MB).
    'export_keep_days' => (int) env('INVOICE_EXPORT_KEEP_DAYS', 3),
];
