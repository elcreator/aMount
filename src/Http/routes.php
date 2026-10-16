<?php

use Elcreator\aMount\Http\Controllers\BootstrapController;
use Elcreator\aMount\Http\Controllers\FileController;
use Elcreator\aMount\Http\Controllers\LocalController;
use Elcreator\aMount\Http\Controllers\MountController;
use Elcreator\aMount\Http\Controllers\PageController;
use Elcreator\aMount\Http\Controllers\RuleController;
use Elcreator\aMount\Http\Controllers\RunController;
use Elcreator\aMount\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

/**
 * The module's routes.
 *
 * Included by `registerRoutingModule()` inside a group that already carries the
 * `modules/{id}` prefix and the `mgr` middleware, so nothing here names a prefix
 * or re-applies the manager guard.
 *
 *  - `mgr` includes `VerifyCsrfToken`, which fails closed once a manager session
 *    exists: every POST needs a `_token` field or an `X-CSRF-TOKEN` header.
 *  - The prefix is derived from the module name, so no URL here is stable enough
 *    to hardcode elsewhere. The page hands its base URL to the front end.
 *  - Mutations are POST only (also "delete"), as the CMS's own routes are.
 */

Route::get('/', [PageController::class, 'index'])->name('amount.index');
Route::get('/bootstrap', [BootstrapController::class, 'show'])->name('amount.bootstrap');

// Mounts: remote storage connections.
Route::post('/mounts', [MountController::class, 'store'])->name('amount.mounts.store');
// Registered before `/mounts/{uuid}`, which would otherwise take "test" for a uuid.
Route::post('/mounts/test', [MountController::class, 'testDraft'])->name('amount.mounts.test_draft');
Route::post('/mounts/{uuid}', [MountController::class, 'update'])->name('amount.mounts.update');
Route::post('/mounts/{uuid}/delete', [MountController::class, 'destroy'])->name('amount.mounts.delete');
Route::post('/mounts/{uuid}/test', [MountController::class, 'test'])->name('amount.mounts.test');
Route::get('/mounts/{uuid}/browse', [MountController::class, 'browse'])->name('amount.mounts.browse');
Route::post('/mounts/{uuid}/measure', [MountController::class, 'measure'])->name('amount.mounts.measure');

// The site's folders, filtered through the manager's own file-manager permissions.
Route::get('/local/folders', [LocalController::class, 'folders'])->name('amount.local.folders');
Route::get('/local/check', [LocalController::class, 'check'])->name('amount.local.check');

// Rules: which folder goes where.
Route::get('/rules', [RuleController::class, 'index'])->name('amount.rules.index');
Route::post('/rules', [RuleController::class, 'store'])->name('amount.rules.store');
Route::post('/rules/{uuid}', [RuleController::class, 'update'])->name('amount.rules.update');
Route::post('/rules/{uuid}/delete', [RuleController::class, 'destroy'])->name('amount.rules.delete');
Route::post('/rules/{uuid}/run', [RuleController::class, 'run'])->name('amount.rules.run');
Route::post('/rules/{uuid}/measure', [RuleController::class, 'measure'])->name('amount.rules.measure');

// What was offloaded, and the way back.
Route::get('/files', [FileController::class, 'index'])->name('amount.files.index');
Route::post('/files/recall', [FileController::class, 'recall'])->name('amount.files.recall');
Route::post('/files/{id}/delete', [FileController::class, 'destroy'])->whereNumber('id')->name('amount.files.delete');
Route::get('/files/{id}/download', [FileController::class, 'download'])->whereNumber('id')->name('amount.files.download');

// Activity.
Route::get('/runs', [RunController::class, 'index'])->name('amount.runs.index');
Route::get('/runs/{uuid}', [RunController::class, 'show'])->name('amount.runs.show');
Route::post('/runs/{uuid}/cancel', [RunController::class, 'cancel'])->name('amount.runs.cancel');

// Web-server integration (amount_manage only).
Route::get('/server', [ServerController::class, 'show'])->name('amount.server.show');
Route::post('/server/write', [ServerController::class, 'write'])->name('amount.server.write');
Route::post('/server/probe', [ServerController::class, 'probe'])->name('amount.server.probe');
