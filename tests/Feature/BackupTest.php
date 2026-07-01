<?php

use Illuminate\Support\Facades\File;

// ── Access control ───────────────────────────────────────────────────

test('admin can view the backups page', function () {
    $this->actingAs($this->adminUser())->get('/backups')->assertStatus(200);
});

test('staff cannot view the backups page', function () {
    $this->actingAs($this->staffUser())->get('/backups')->assertStatus(403);
});

test('finance cannot view the backups page', function () {
    $this->actingAs($this->financeUser())->get('/backups')->assertStatus(403);
});

test('staff cannot trigger a backup run', function () {
    $this->actingAs($this->staffUser())->post('/backups/run')->assertStatus(403);
});

test('staff cannot download a backup', function () {
    $this->actingAs($this->staffUser())
        ->get('/backups/download/backup-2026-06-29-030000.sql')
        ->assertStatus(403);
});

// ── Path-traversal protection (security-critical) ────────────────────
// A backup file contains the ENTIRE database, so the download endpoint must
// reject anything that isn't an exact, real backup filename. These tests prove
// an attacker (or accident) can't use ../ tricks to read other files.

test('download rejects a non-backup filename', function () {
    $this->actingAs($this->adminUser())
        ->get('/backups/download/.env')
        ->assertStatus(404);
});

test('download rejects a path-traversal attempt', function () {
    // URL-encoded ../../.env — must not resolve to anything outside the backups dir
    $this->actingAs($this->adminUser())
        ->get('/backups/download/' . urlencode('../../.env'))
        ->assertStatus(404);
});

test('download rejects a well-formed name that does not exist', function () {
    $this->actingAs($this->adminUser())
        ->get('/backups/download/backup-2099-01-01-000000.sql')
        ->assertStatus(404);
});

test('download serves a real backup file to an admin', function () {
    // Create a fake backup file in the expected location
    $dir = storage_path('app/backups');
    if (!File::isDirectory($dir)) File::makeDirectory($dir, 0755, true);
    $name = 'backup-2026-06-29-030000.sql';
    File::put($dir . '/' . $name, "-- test sql dump\n");

    $response = $this->actingAs($this->adminUser())
        ->get('/backups/download/' . $name);

    $response->assertStatus(200);
    // Served as a file download (attachment), not rendered inline
    expect($response->headers->get('content-disposition'))->toContain('attachment');

    // cleanup
    File::delete($dir . '/' . $name);
});

// ── Delete protection ────────────────────────────────────────────────

test('staff cannot delete a backup', function () {
    $this->actingAs($this->staffUser())
        ->delete('/backups/backup-2026-06-29-030000.sql')
        ->assertStatus(403);
});

test('admin deleting a bad filename is rejected', function () {
    $this->actingAs($this->adminUser())
        ->delete('/backups/' . urlencode('../../.env'))
        ->assertStatus(404);
});