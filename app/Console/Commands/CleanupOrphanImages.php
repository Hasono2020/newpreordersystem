<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes image files in storage/app/public/products that are no longer
 * referenced by any row in the products table (orphans left behind by
 * old bulk deletes / trip cascade deletes before the fix).
 *
 * Usage:
 *   php artisan images:cleanup --dry-run   # list orphans, delete nothing
 *   php artisan images:cleanup             # actually delete orphans
 */
class CleanupOrphanImages extends Command
{
    protected $signature = 'images:cleanup {--dry-run : List orphaned files without deleting them}';

    protected $description = 'Delete product image files in storage that are not referenced by any product';

    public function handle(): int
    {
        $disk = Storage::disk('public');

        // All files physically on disk under products/
        $files = collect($disk->files('products'));

        if ($files->isEmpty()) {
            $this->info('No files found in storage/app/public/products — nothing to do.');
            return self::SUCCESS;
        }

        // All image paths still referenced in the database
        $referenced = Product::whereNotNull('image')->pluck('image')->flip();

        $orphans = $files->reject(fn ($path) => $referenced->has($path))->values();

        $this->info(sprintf(
            'Files on disk: %d | Referenced in DB: %d | Orphans: %d',
            $files->count(),
            $referenced->count(),
            $orphans->count()
        ));

        if ($orphans->isEmpty()) {
            $this->info('No orphaned images. Storage is clean.');
            return self::SUCCESS;
        }

        $totalBytes = $orphans->sum(fn ($path) => $disk->size($path));
        $totalMb    = round($totalBytes / 1048576, 2);

        if ($this->option('dry-run')) {
            $this->warn("DRY RUN — the following {$orphans->count()} file(s) ({$totalMb} MB) would be deleted:");
            foreach ($orphans as $path) {
                $this->line('  ' . $path . ' (' . round($disk->size($path) / 1024) . ' KB)');
            }
            $this->warn('Run without --dry-run to delete them.');
            return self::SUCCESS;
        }

        $disk->delete($orphans->all());

        $this->info("Deleted {$orphans->count()} orphaned image(s), freed ~{$totalMb} MB.");
        return self::SUCCESS;
    }
}