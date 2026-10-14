<?php

namespace App\Services\Facebook;

use App\Services\Http\HttpClientService;
use RuntimeException;

class FacebookOAuthService
{
    /**
     * Meta Graph API version.
     */
    protected string $graphVersion;

    /**
     * Meta application ID.
     */
    protected string $appId;

    /**
     * Meta application secret.
     */
    protected string $appSecret;

    /**
     * OAuth redirect URI.
     */
    protected string $redirectUri;

    /**
     * Central HTTP client.
     */
    public function __construct(
        private readonly HttpClientService $httpClient,
    ) {
        $this->graphVersion = config(
            'services.facebook.graph_version',
            'v26.0'
        );

        $this->appId = config(
            'services.facebook.client_id'
        );

        $this->appSecret = config(
            'services.facebook.client_secret'
        );

        /*
         * IMPORTANT:
         *
         * This must exactly match the redirect URI configured
         * inside Meta.
         */
        $this->redirectUri = config(
            'services.facebook.redirect_uri'
        );

        $this->validateConfiguration();
    }

    /**
     * Generate Meta OAuth authorization URL.
     */
    public function getAuthorizationUrl(
        string $state
    ): string {
        /*
         * Keep this list to the minimum permissions actually
         * required by the application.
         */
        $permissions = [
            'pages_show_list',
        ];

        $parameters = [
            'client_id' => $this->appId,

            /*
             * Must exactly match the URI configured in Meta.
             */
            'redirect_uri' => $this->redirectUri,

            /*
             * OAuth CSRF protection.
             */
            'state' => $state,

            /*
             * Authorization Code flow.
             */
            'response_type' => 'code',

            /*
             * Requested permissions.
             */
            'scope' => implode(',', $permissions),
        ];

        return sprintf(
            'https://www.facebook.com/%s/dialog/oauth?%s',
            $this->graphVersion,
            http_build_query(
                $parameters,
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        );
    }

    /**
     * Exchange authorization code for User Access Token.
     *
     * IMPORTANT:
     *
     * The authorization code must never be logged or exposed
     * to the frontend.
     */
    public function exchangeCodeForUserToken(
        string $code
    ): array {
        if (trim($code) === '') {
            throw new RuntimeException(
                'Facebook authorization code is empty.'
            );
        }

        $response = $this->httpClient->get(
            $this->graphUrl('/oauth/access_token'),
            [
                'client_id' => $this->appId,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->redirectUri,
                'code' => $code,
            ],
        );

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException(
                'Invalid response received from Facebook token endpoint.'
            );
        }

        if (
            empty($data['access_token'])
            || !is_string($data['access_token'])
        ) {
            throw new RuntimeException(
                'Facebook User Access Token was not returned.'
            );
        }

        return $data;
    }

    /**
     * Retrieve the authenticated Facebook user.
     */
    public function getUser(
        string $accessToken
    ): array {
        $this->validateAccessToken(
            $accessToken,
            'Facebook User Access Token'
        );

        $response = $this->httpClient->get(
            $this->graphUrl('/me'),
            [
                'access_token' => $accessToken,
                'fields' => 'id,name',
            ],
        );

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException(
                'Invalid Facebook user response.'
            );
        }

        if (
            empty($data['id'])
            || !is_string($data['id'])
        ) {
            throw new RuntimeException(
                'Facebook user ID was not returned.'
            );
        }

        return $data;
    }

    /**
     * Retrieve all Pages accessible through the authorized
     * Facebook User Access Token.
     *
     * IMPORTANT:
     *
     * Page Access Tokens remain server-side.
     *
     * They are intentionally retained in the returned internal
     * data because the persistence layer needs them to store the
     * encrypted Page token.
     *
     * NEVER return this array directly from a controller response.
     */
    public function getUserPages(
        string $accessToken
    ): array {
        $this->validateAccessToken(
            $accessToken,
            'Facebook User Access Token'
        );

        $response = $this->httpClient->get(
            $this->graphUrl('/me/accounts'),
            [
                'access_token' => $accessToken,

                /*
                 * Only request fields required by the application.
                 */
                'fields' => implode(',', [
                    'id',
                    'name',
                    'access_token',
                    'category',
                    'category_list',
                    'tasks',
                ]),

                /*
                 * Request a bounded page size.
                 */
                'limit' => 100,
            ],
        );

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException(
                'Invalid Facebook Pages response.'
            );
        }

        if (
            isset($data['data'])
            && !is_array($data['data'])
        ) {
            throw new RuntimeException(
                'Invalid Facebook Pages data response.'
            );
        }

        if (
            !isset($data['data'])
            || !is_array($data['data'])
        ) {
            $data['data'] = [];
        }

        /*
         * Validate Page records.
         */
        foreach ($data['data'] as $page) {
            $this->validatePageRecord($page);
        }

        return $data;
    }

    /**
     * Retrieve Page metadata.
     *
     * This method uses the Page Access Token and retrieves
     * information that belongs to the Page itself.
     *
     * Current metadata:
     *
     * - followers_count
     */
    public function getPageMetadata(
        string $pageId,
        string $pageAccessToken
    ): array {
        $this->validatePageId($pageId);

        $this->validateAccessToken(
            $pageAccessToken,
            'Facebook Page Access Token'
        );

        $response = $this->httpClient->get(
            $this->graphUrl("/{$pageId}"),
            [
                'access_token' => $pageAccessToken,
                'fields' => 'followers_count',
            ],
        );

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException(
                'Invalid Facebook Page metadata response.'
            );
        }

        if (
            isset($data['followers_count'])
            && !is_numeric($data['followers_count'])
        ) {
            throw new RuntimeException(
                'Invalid Facebook Page followers count.'
            );
        }

        return [
            'followers_count' => isset($data['followers_count'])
                ? (int) $data['followers_count']
                : null,
        ];
    }

    /**
     * Retrieve Page metadata using multiple fields.
     *
     * This method is useful when the application needs to
     * synchronize Page information after the initial OAuth flow.
     */
    public function getPage(
        string $pageId,
        string $pageAccessToken,
        array $fields = [
            'id',
            'name',
            'category',
            'category_list',
            'followers_count',
        ],
    ): array {
        $this->validatePageId($pageId);

        $this->validateAccessToken(
            $pageAccessToken,
            'Facebook Page Access Token'
        );

        if ($fields === []) {
            throw new RuntimeException(
                'At least one Facebook Page field must be requested.'
            );
        }

        $fields = array_values(
            array_unique(
                array_filter(
                    $fields,
                    static fn ($field): bool =>
                        is_string($field)
                        && trim($field) !== ''
                )
            )
        );

        if ($fields === []) {
            throw new RuntimeException(
                'Invalid Facebook Page fields.'
            );
        }

        $response = $this->httpClient->get(
            $this->graphUrl("/{$pageId}"),
            [
                'access_token' => $pageAccessToken,
                'fields' => implode(',', $fields),
            ],
        );

        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException(
                'Invalid Facebook Page response.'
            );
        }

        return $data;
    }

    /**
     * Validate Facebook service configuration.
     */
    private function validateConfiguration(): void
    {
        if (
            !is_string($this->graphVersion)
            || $this->graphVersion === ''
        ) {
            throw new RuntimeException(
                'Facebook Graph API version is not configured.'
            );
        }

        if (
            !preg_match(
                '/^v\d+\.\d+$/',
                $this->graphVersion
            )
        ) {
            throw new RuntimeException(
                'Invalid Facebook Graph API version.'
            );
        }

        if (
            !is_string($this->appId)
            || $this->appId === ''
        ) {
            throw new RuntimeException(
                'Facebook App ID is not configured.'
            );
        }

        if (
            !is_string($this->appSecret)
            || $this->appSecret === ''
        ) {
            throw new RuntimeException(
                'Facebook App Secret is not configured.'
            );
        }

        if (
            !is_string($this->redirectUri)
            || $this->redirectUri === ''
        ) {
            throw new RuntimeException(
                'Facebook OAuth redirect URI is not configured.'
            );
        }
    }

    /**
     * Validate an access token before making Graph API requests.
     */
    private function validateAccessToken(
        string $accessToken,
        string $name
    ): void {
        if (trim($accessToken) === '') {
            throw new RuntimeException(
                "{$name} is empty."
            );
        }
    }

    /**
     * Validate a Facebook Page ID.
     */
    private function validatePageId(
        string $pageId
    ): void {
        if (trim($pageId) === '') {
            throw new RuntimeException(
                'Facebook Page ID is empty.'
            );
        }
    }

    /**
     * Validate an individual Page record returned by Meta.
     *
     * @param mixed $page
     */
    private function validatePageRecord(
        mixed $page
    ): void {
        if (!is_array($page)) {
            throw new RuntimeException(
                'Invalid Facebook Page record.'
            );
        }

        if (
            empty($page['id'])
            || !is_string($page['id'])
        ) {
            throw new RuntimeException(
                'Facebook Page ID was not returned.'
            );
        }

        if (
            empty($page['name'])
            || !is_string($page['name'])
        ) {
            throw new RuntimeException(
                'Facebook Page name was not returned.'
            );
        }

        if (
            empty($page['access_token'])
            || !is_string($page['access_token'])
        ) {
            throw new RuntimeException(
                'Facebook Page Access Token was not returned.'
            );
        }

        /*
         * category is optional because Meta may not return it
         * for every Page/account configuration.
         */
        if (
            isset($page['category'])
            && !is_string($page['category'])
        ) {
            throw new RuntimeException(
                'Invalid Facebook Page category.'
            );
        }

        /*
         * category_list is optional.
         */
        if (
            isset($page['category_list'])
            && !is_array($page['category_list'])
        ) {
            throw new RuntimeException(
                'Invalid Facebook Page category list.'
            );
        }

        /*
         * tasks is optional.
         */
        if (
            isset($page['tasks'])
            && !is_array($page['tasks'])
        ) {
            throw new RuntimeException(
                'Invalid Facebook Page tasks response.'
            );
        }
    }

    /**
     * Build a Meta Graph API URL.
     */
    private function graphUrl(
        string $path
    ): string {
        return sprintf(
            'https://graph.facebook.com/%s/%s',
            $this->graphVersion,
            ltrim($path, '/')
        );
    }
}