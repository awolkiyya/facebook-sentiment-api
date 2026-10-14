<?php

namespace App\Supports;

enum ApiErrorCode: string
{
    /*
    |--------------------------------------------------------------------------
    | General
    |--------------------------------------------------------------------------
    */

    case ERROR = 'ERROR';


    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    case UNAUTHENTICATED = 'UNAUTHENTICATED';

    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    case TOKEN_EXPIRED = 'TOKEN_EXPIRED';

    case ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';


    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    case FORBIDDEN = 'FORBIDDEN';

    case INSUFFICIENT_PERMISSION = 'INSUFFICIENT_PERMISSION';


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    case VALIDATION_FAILED = 'VALIDATION_FAILED';


    /*
    |--------------------------------------------------------------------------
    | Resource
    |--------------------------------------------------------------------------
    */

    case RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    case FACEBOOK_PAGE_NOT_FOUND = 'FACEBOOK_PAGE_NOT_FOUND';

    case REPORT_NOT_FOUND = 'REPORT_NOT_FOUND';


    /*
    |--------------------------------------------------------------------------
    | Conflict
    |--------------------------------------------------------------------------
    */

    case CONFLICT = 'CONFLICT';

    case OPERATION_NOT_ALLOWED = 'OPERATION_NOT_ALLOWED';


    /*
    |--------------------------------------------------------------------------
    | Facebook Connection
    |--------------------------------------------------------------------------
    */

    case FACEBOOK_CONNECTION_REQUIRED = 'FACEBOOK_CONNECTION_REQUIRED';

    case FACEBOOK_CONNECTION_EXISTS = 'FACEBOOK_CONNECTION_EXISTS';

    case FACEBOOK_PAGE_ALREADY_CONNECTED = 'FACEBOOK_PAGE_ALREADY_CONNECTED';


    /*
    |--------------------------------------------------------------------------
    | Resource State
    |--------------------------------------------------------------------------
    */

    case RESOURCE_LOCKED = 'RESOURCE_LOCKED';


    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    case RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';


    /*
    |--------------------------------------------------------------------------
    | External Services
    |--------------------------------------------------------------------------
    */

    case EXTERNAL_SERVICE_ERROR = 'EXTERNAL_SERVICE_ERROR';


    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    case DATABASE_ERROR = 'DATABASE_ERROR';


    /*
    |--------------------------------------------------------------------------
    | Server
    |--------------------------------------------------------------------------
    */

    case INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';
}