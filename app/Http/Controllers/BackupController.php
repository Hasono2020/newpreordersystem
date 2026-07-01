<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class BackupController extends Controller
{
    private function backupDir(): string
    {
        return storage_path('app/backups');
    }

    /**
     * Validate a requested filename is a real backup in our dir and not a
     * path-traversal attempt. Returns the safe absolute path or null.
     */
    private function safePath(string $filename): ?string
    {
        // Only allow our exact naming pattern — blocks ../, slashes, etc.
        if (!preg_match('/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.sql$/', $filename)) {
            return null;
        }
        $path = $this->backupDir() . DIRECTORY_SEPARATOR . $filename;
        return File::exists($path) ? $path : null;
    }

    public function index()
    {
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Only admins can access database backups.');
        }

        $backups = [];
        if (File::isDirectory($this->backupDir())) {
            foreach (File::files($this->backupDir()) as $file) {
                if (str_starts_with($file->getFilename(), 'backup-') && $file->getExtension() === 'sql') {
                    // Derive the date from the filename (backup-YYYY-MM-DD-HHmmss.sql) so the
                    // displayed time matches the filename and the app timezone. Fall back to
                    // the file mtime (converted to app timezone) if the name doesn't parse.
                    $created = null;
                    if (preg_match('/^backup-(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})\.sql$/', $file->getFilename(), $m)) {
                        $created = \Carbon\Carbon::create(
                            $m[1], $m[2], $m[3], $m[4], $m[5], $m[6],
                            config('app.timezone')   // ← must be explicit; PHP default may be UTC
                        );
                    } else {
                        $created = \Carbon\Carbon::createFromTimestamp($file->getMTime())
                            ->setTimezone(config('app.timezone'));
                    }
                    $backups[] = [
                        'filename' => $file->getFilename(),
                        'size_mb'  => round($file->getSize() / 1048576, 2),
                        'created'  => $created,
                    ];
                }
            }
        }
        // Newest first
        usort($backups, fn($a, $b) => $b['created'] <=> $a['created']);

        return view('backups.index', compact('backups'));
    }

    public function download(string $filename)
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }
        $path = $this->safePath($filename);
        if (!$path) {
            abort(404, 'Backup not found.');
        }
        return response()->download($path);
    }

    public function run()
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }
        $exitCode = Artisan::call('backup:database --keep=7');
        if ($exitCode === 0) {
            return back()->with('success', 'Backup created successfully.');
        }
        return back()->with('error', 'Backup failed. Check the logs for details.');
    }

    public function destroy(string $filename)
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }
        $path = $this->safePath($filename);
        if (!$path) {
            abort(404, 'Backup not found.');
        }
        File::delete($path);
        return back()->with('success', 'Backup deleted.');
    }
}