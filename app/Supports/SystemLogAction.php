<?php

namespace App\Supports;

final class SystemLogAction
{
    private function __construct()
    {
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD
    |--------------------------------------------------------------------------
    */

    public const CREATE = 'CREATE';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';
    public const VIEW = 'VIEW';

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public const LOGIN = 'LOGIN';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    public const LOGOUT = 'LOGOUT';
    public const PASSWORD_CHANGED = 'PASSWORD_CHANGED';
    public const PASSWORD_RESET = 'PASSWORD_RESET';

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    public const PERMISSION_DENIED = 'PERMISSION_DENIED';
    public const SECURITY_EVENT = 'SECURITY_EVENT';

    /*
    |--------------------------------------------------------------------------
    | Connections / Synchronization
    |--------------------------------------------------------------------------
    */

    public const CONNECT = 'CONNECT';
    public const DISCONNECT = 'DISCONNECT';

    public const SYNC = 'SYNC';
    public const SYNC_STARTED = 'SYNC_STARTED';
    public const SYNC_COMPLETED = 'SYNC_COMPLETED';
    public const SYNC_FAILED = 'SYNC_FAILED';

    public const WEBHOOK_RECEIVED = 'WEBHOOK_RECEIVED';
    public const RATE_LIMIT_WARNING = 'RATE_LIMIT_WARNING';

    /*
    |--------------------------------------------------------------------------
    | Facebook
    |--------------------------------------------------------------------------
    */

    public const POST_CREATED = 'POST_CREATED';
    public const POST_UPDATED = 'POST_UPDATED';

    public const COMMENT_CREATED = 'COMMENT_CREATED';
    public const COMMENT_UPDATED = 'COMMENT_UPDATED';

    public const REACTION_SYNCED = 'REACTION_SYNCED';
    public const INSIGHTS_SYNCED = 'INSIGHTS_SYNCED';

    /*
    |--------------------------------------------------------------------------
    | AI / Analysis
    |--------------------------------------------------------------------------
    */

    public const ANALYZE = 'ANALYZE';
    public const ANALYSIS_STARTED = 'ANALYSIS_STARTED';
    public const ANALYSIS_COMPLETED = 'ANALYSIS_COMPLETED';
    public const ANALYSIS_FAILED = 'ANALYSIS_FAILED';

    public const SENTIMENT_ANALYZED = 'SENTIMENT_ANALYZED';
    public const BATCH_ANALYSIS = 'BATCH_ANALYSIS';

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    public const EXPORT = 'EXPORT';
    public const DOWNLOAD = 'DOWNLOAD';
    public const REPORT_GENERATED = 'REPORT_GENERATED';

    /*
    |--------------------------------------------------------------------------
    | System
    |--------------------------------------------------------------------------
    */

    public const SYSTEM_EVENT = 'SYSTEM_EVENT';
    public const STATUS_CHANGED = 'STATUS_CHANGED';
    public const CACHE_CLEARED = 'CACHE_CLEARED';
    public const ERROR = 'ERROR';
}