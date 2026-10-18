
# Mayor Sentiment Analytics — API

Laravel backend for **Mayor Sentiment Analytics (CivicPulse)**, a municipal social media intelligence platform designed to help government organizations understand public sentiment, monitor civic concerns, and analyze feedback from Facebook posts and comments.

The API manages application data, authentication, Facebook data synchronization, sentiment-analysis integration, and the endpoints consumed by the web dashboard.

## Overview

Mayor Sentiment Analytics provides a centralized backend for:

- Managing Facebook posts and associated comments.
- Synchronizing available Facebook data through the configured Meta integration.
- Retrieving sentiment-analysis results and aggregated statistics.
- Organizing feedback by language, sentiment, and civic topic.
- Serving structured API responses to the web application.
- Managing authentication, authorization, and application configuration.

> **Note:** Available features depend on the implemented API endpoints, Meta permissions, and the configured AI service.

## Technology Stack

- **Backend:** Laravel / PHP
- **Database:** PostgreSQL
- **Authentication:** Laravel Sanctum, where configured
- **External integration:** Meta Graph API
- **AI integration:** Mayor Sentiment Analytics AI service
- **Deployment:** Docker Compose and Traefik
- **CI/CD:** GitHub Actions

Check the project's `composer.json` and configuration files for the exact installed versions and enabled integrations.

## Architecture

```text
┌──────────────────────────┐
│  Next.js Web Dashboard   │
└────────────┬─────────────┘
             │ HTTPS / REST API
             ▼
┌──────────────────────────┐
│   Laravel API            │
│   Authentication         │
│   Business Logic         │
│   Facebook Integration   │
│   Data Management        │
└───────┬──────────┬───────┘
        │          │
        ▼          ▼
┌─────────────┐  ┌─────────────────┐
│ PostgreSQL  │  │ Sentiment AI API│
└─────────────┘  └─────────────────┘
        │
        ▼
┌──────────────────────────┐
│ Meta Graph API            │
│ Facebook Data Integration │
└──────────────────────────┘
```

The Laravel API is the application's backend and integration layer. The AI service performs sentiment analysis; the frontend consumes API responses rather than implementing the backend's business logic.

## Requirements

For local development, install:

- PHP version compatible with `composer.json`
- Composer
- PostgreSQL
- Node.js and npm only if this repository contains frontend assets that need building
- Git

For containerized deployment:

- Docker Engine
- Docker Compose
- A configured reverse proxy and public domain for production HTTPS

## Getting Started

### 1. Clone the repository

```bash
git clone https://github.com/awolkiyya/facebook-sentiment-api.git
cd facebook-sentiment-api
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Configure environment variables

Create a local environment file:

```bash
cp .env.example .env
```

Update `.env` with your local database credentials, application URL, authentication settings, and integration configuration.

Example PostgreSQL configuration:

```dotenv
APP_NAME="Mayor Sentiment Analytics API"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=adama_civic_intelligence
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
```

Use the actual environment variable names expected by the project. Configure Meta credentials and AI-service URLs according to the existing application's configuration.

**Security:** Never commit `.env`, production credentials, access tokens, private keys, or API secrets to Git.

### 4. Generate the application key

```bash
php artisan key:generate
```

### 5. Run database migrations

Ensure PostgreSQL is running and the configured database exists.

```bash
php artisan migrate
```

Run seeders only when they are appropriate for your environment:

```bash
php artisan db:seed
```

### 6. Start the development server

```bash
php artisan serve
```

By default, the local application is available at:

```text
http://127.0.0.1:8000
```

The API routes are defined in the Laravel route files, primarily `routes/api.php`. Confirm the actual API prefix and available endpoints in the project before relying on a particular URL.

To inspect registered routes:

```bash
php artisan route:list
```

## Environment Configuration

Configure these settings according to the existing project:

| Configuration | Purpose |
|---|---|
| `APP_ENV` | Application environment |
| `APP_DEBUG` | Detailed error output; must be `false` in production |
| `APP_URL` | Public API base URL |
| `DB_*` | PostgreSQL connection |
| `META_*` | Meta/Facebook integration credentials, if used |
| AI service variables | AI API URL, authentication, and timeouts, if configured |
| `SANCTUM_*` | Sanctum configuration, where applicable |
| `QUEUE_*` | Background job configuration, if used |
| `CACHE_*` | Application cache configuration |

The table describes configuration categories, not guaranteed variable names. Follow `.env.example`, `config/`, and the existing application code for the authoritative settings.

## API Development

Before adding or modifying endpoints:

1. Review the existing routes and controllers.
2. Keep business logic in the appropriate service classes.
3. Validate incoming requests using Form Requests where appropriate.
4. Apply the project's existing authentication and authorization policies.
5. Return consistent JSON responses and suitable HTTP status codes.
6. Add or update automated tests for the affected functionality.

Useful commands:

```bash
# List routes
php artisan route:list

# Run tests
php artisan test

# Clear cached configuration
php artisan config:clear

# Clear application caches during development
php artisan optimize:clear
```

## Docker Deployment

The production deployment uses a shared Docker platform under:

```text
/opt/asi-platform/
├── apps/
│   ├── facebook-sentiment-api/
│   └── facebook-sentiment-web/
├── backups/
├── deploy/
│   └── traefik/
└── secrets/
```

The Laravel API should run as its own application service. Traefik should handle external HTTP/HTTPS routing, while the API connects to PostgreSQL and the AI service through the appropriate configured network.

### Production deployment principles

- Keep production environment files and credentials outside the Git repository.
- Do not publish the Laravel container's internal port directly to the public internet unless explicitly required.
- Route external API traffic through the shared Traefik proxy.
- Configure HTTPS and DNS before enabling public production routing.
- Use persistent storage only where the application requires it.
- Run migrations deliberately as part of the release process.
- Configure queue workers and scheduled tasks if the application uses them.
- Keep `APP_DEBUG=false` in production.
- Do not expose database ports publicly.

The exact Docker Compose service names, Dockerfile, network names, health checks, and deployment commands must match the files committed to this repository and the shared platform configuration.

## CI/CD

GitHub Actions can automate deployment when changes are pushed to the production branch.

A deployment workflow should:

1. Connect to the deployment server using securely stored GitHub Actions secrets.
2. Update the intended application repository.
3. Validate the Docker Compose configuration.
4. Build the new API image.
5. Apply database migrations through a controlled release step.
6. Replace the API container and verify application health.
7. Report deployment failures without exposing secrets.

Keep SSH private keys, server credentials, production configuration, and third-party tokens in the appropriate secure secret store. Do not place credentials directly in workflow files.

## Project Structure

A typical Laravel application may include:

```text
facebook-sentiment-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Middleware/
│   │   └── Requests/
│   ├── Models/
│   └── Services/
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── routes/
├── tests/
├── .env.example
├── artisan
├── composer.json
├── composer.lock
├── Dockerfile
├── compose.yaml
└── README.md
```

This is an illustrative structure. Retain the repository's actual directories and filenames rather than creating unnecessary files just to match the example.

## Troubleshooting

### Database connection errors

- Verify PostgreSQL is running.
- Confirm the database exists.
- Check the configured credentials and hostname.
- Remember that `127.0.0.1` inside a container refers to that container, not the PostgreSQL host or another container.

### Configuration changes are not applied

```bash
php artisan optimize:clear
```

For production, rebuild or restart the relevant service when required by the deployment configuration.

### API returns an unexpected response

```bash
php artisan route:list
php artisan test
```

Check the application logs and verify authentication, authorization, request validation, and upstream AI-service availability.

## Security

- Never commit `.env` files or production secrets.
- Disable debug mode in production.
- Validate and authorize all incoming requests.
- Protect Meta tokens and AI-service credentials.
- Restrict access to internal services.
- Keep dependencies updated and run tests before deployment.
- Avoid logging access tokens, passwords, or sensitive user information.

## Contributing

1. Create a feature branch.
2. Implement the change using the existing application conventions.
3. Add or update tests.
4. Run the test suite.
5. Submit a pull request for review.

## License

Specify the license applicable to this project. Do not assume that the Laravel framework's MIT license automatically determines the license of this application.
