<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\AIClient;
use Illuminate\Console\Command;
use Throwable;

class TestAIClient extends Command
{
    protected $signature = 'ai:test';

    protected $description = 'Test Laravel to Python AI integration';

    public function handle(AIClient $aiClient): int
    {
        $this->info('Testing Laravel → Python AI service...');

        try {
            $response = $aiClient->analyzeBatch([
                [
                    'id' => 'test-comment-001',
                    'text' => 'Thank you for the excellent work.',
                ],
                [
                    'id' => 'test-comment-002',
                    'text' => 'We are disappointed with this project.',
                ],
                [
                    'id' => 'test-comment-003',
                    'text' => 'Galatoomaa hojii gaarii hojjettaniif.',
                ],
            ]);

            $this->info('AI service responded successfully.');

            $this->line(
                json_encode(
                    $response,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                )
            );

            return self::SUCCESS;

        } catch (Throwable $exception) {
            $this->error(
                'AI integration failed: ' . $exception->getMessage()
            );

            report($exception);

            return self::FAILURE;
        }
    }
}