<?php
// LfmWorkingStore captures ?working_store=<store> (sent by media-input on popup open) into
// the session so LFM's later upload/list AJAX — which drop the query param — resolve the
// same per-store folder (ADR admin-shell_lfm-working-dir).
//
// LfmWriteRequestGuard: actions that change files run only for a same-origin AJAX request — the library
// sends them as GET, which CSRF does not cover (ADR admin-shell_lfm-write-request-guard).
$lfmWrite = [\GP247\Core\Middleware\LfmWriteRequestGuard::class];

Route::group([
    'prefix' => 'uploads',
    'namespace' => '\\UniSharp\\LaravelFilemanager\\Controllers\\',
    'middleware' => [\GP247\Core\Middleware\LfmWorkingStore::class],
], function () use ($lfmWrite) {

    // display main layout
    Route::get('/', [
        'uses' => 'LfmController@show',
        'as' => 'unisharp.lfm.show',
    ]);

    // display integration error messages
    Route::get('/errors', [
        'uses' => 'LfmController@getErrors',
        'as' => 'unisharp.lfm.getErrors',
    ]);

    // upload
    Route::post('/upload', [
        'uses' => 'UploadController@upload',
        'as' => 'unisharp.lfm.upload',
    ]);

    // list images & files
    Route::get('/jsonitems', [
        'uses' => 'ItemsController@getItems',
        'as' => 'unisharp.lfm.getItems',
    ]);

    Route::get('/move', [
        'uses' => 'ItemsController@move',
        'as' => 'unisharp.lfm.move',
    ]);

    Route::get('/domove', [
        'uses' => 'ItemsController@domove',
        'as' => 'unisharp.lfm.domove',
        'middleware' => $lfmWrite,
    ]);

    // folders
    Route::get('/newfolder', [
        'uses' => 'FolderController@getAddfolder',
        'as' => 'unisharp.lfm.getAddfolder',
        'middleware' => $lfmWrite,
    ]);

    // list folders
    Route::get('/folders', [
        'uses' => 'FolderController@getFolders',
        'as' => 'unisharp.lfm.getFolders',
    ]);

    // crop
    Route::get('/crop', [
        'uses' => 'CropController@getCrop',
        'as' => 'unisharp.lfm.getCrop',
    ]);
    Route::get('/cropimage', [
        'uses' => 'CropController@getCropimage',
        'as' => 'unisharp.lfm.getCropimage',
        'middleware' => $lfmWrite,
    ]);
    Route::get('/cropnewimage', [
        'uses' => 'CropController@getNewCropimage',
        'as' => 'unisharp.lfm.getCropnewimage',
        'middleware' => $lfmWrite,
    ]);

    // rename
    Route::get('/rename', [
        'uses' => 'RenameController@getRename',
        'as' => 'unisharp.lfm.getRename',
        'middleware' => $lfmWrite,
    ]);

    // scale/resize
    Route::get('/resize', [
        'uses' => 'ResizeController@getResize',
        'as' => 'unisharp.lfm.getResize',
    ]);
    Route::get('/doresize', [
        'uses' => 'ResizeController@performResize',
        'as' => 'unisharp.lfm.performResize',
        'middleware' => $lfmWrite,
    ]);

    // download
    Route::get('/download', [
        'uses' => 'DownloadController@getDownload',
        'as' => 'unisharp.lfm.getDownload',
    ]);

    // delete
    Route::get('/delete', [
        'uses' => 'DeleteController@getDelete',
        'as' => 'unisharp.lfm.getDelete',
        'middleware' => $lfmWrite,
    ]);
});
