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

    // Past this many customers one request would run too long to be reliable.
    'pdf_max_customers' => (int) env('INVOICE_PDF_MAX_CUSTOMERS', 600),
];
