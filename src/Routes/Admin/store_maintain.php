<?php

// store_maintain removed (modification 20260630): merged into store_info (WebsiteInfo).
// Keep a permanent redirect so any bookmarks / old links land on store_info.
Route::get('store_maintain', fn() => redirect(gp247_route_admin('admin_store.index'), 301))
    ->name('admin_store_maintain.index');
