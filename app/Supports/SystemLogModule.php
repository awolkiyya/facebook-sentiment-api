<?php

namespace App\Supports;

final class SystemLogModule
{
    private function __construct()
    {
    }

    // Authentication & Security
    public const AUTHENTICATION = 'AUTHENTICATION';
    public const SECURITY = 'SECURITY';

    // User & Access Management
    public const USERS = 'USERS';
    public const ROLES = 'ROLES';
    public const PERMISSIONS = 'PERMISSIONS';

    // Facebook
    public const FACEBOOK = 'FACEBOOK';
    public const FACEBOOK_PAGES = 'FACEBOOK_PAGES';
    public const FACEBOOK_POSTS = 'FACEBOOK_POSTS';
    public const FACEBOOK_COMMENTS = 'FACEBOOK_COMMENTS';
    public const FACEBOOK_REACTIONS = 'FACEBOOK_REACTIONS';
    public const FACEBOOK_INSIGHTS = 'FACEBOOK_INSIGHTS';

    // AI
    public const AI = 'AI';
    public const SENTIMENT = 'SENTIMENT';

    // Reports
    public const REPORTS = 'REPORTS';

    // API & System
    public const API = 'API';
    public const SYSTEM = 'SYSTEM';
}