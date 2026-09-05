<?php
// list ID admin guard
define('GP247_GUARD_ADMIN', ['AU-AAAAA']); // admin
// list ID language guard
define('GP247_GUARD_LANGUAGE', ['1', '2']); // vi, en
// list ID ROLES guard
define('GP247_GUARD_ROLES', ['1', '2']); // admin, only view

/**
 * Admin define
 */
define('GP247_ADMIN_MIDDLEWARE', ['web', 'admin']);
define('GP247_API_MIDDLEWARE', ['api', 'api.extend']);
define('GP247_API_CORE_PREFIX', 'api/core');
define('GP247_API_FRONT_PREFIX', 'api/front');
define('GP247_DB_CONNECTION', config('gp247-config.env.GP247_DB_CONNECTION'));
//Prefix url admin
define('GP247_ADMIN_PREFIX', config('gp247-config.env.GP247_ADMIN_PREFIX'));
//Prefix database
define('GP247_DB_PREFIX', config('gp247-config.env.GP247_DB_PREFIX'));
//GP247 active
define('GP247_ACTIVE', config('gp247-config.env.GP247_ACTIVE'));
// Root ID store
// WHY string, not int: store_id columns are uuid/char(36) (ids like "STO-xxx").
// Binding an int store filter sends PDO::PARAM_INT, forcing MySQL to coerce the whole
// char column to DOUBLE and throwing "1292 Truncated incorrect DOUBLE value" as soon as
// a non-numeric store id is in scope. String constants keep every derived binding a
// string comparison (ADR compat-foundation_store-id-string-identity, RISK-TECH-store-id-numeric-coercion).
define('GP247_STORE_ID_ROOT', '1'); // ID of root store
define('GP247_STORE_ID_GLOBAL', '0'); // Global, for all stores
define('GP247_SYSTEM', 'SYSTEM');
