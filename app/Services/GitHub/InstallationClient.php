<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The GitHub API calls Sprintlab makes inside one installation
 * (the repositories of one user).
 */
class InstallationClient
{
    /** @var array<string, string> */
    public const HEADERS = [
        'Accept' => 'application/vnd.github+json',
        'X-GitHub-Api-Version' => '2022-11-28',
        'User-Agent' => 'Sprintlab',
    ];

    // 3 pages of 100 files is plenty for a training pull request.
    private const MAX_FILE_PAGES = 3;

    public function __construct(
        private readonly string $token,
        private readonly string $apiUrl,
    ) {}

    /**
     * The files a pull request changes, with their diff ("patch").
     *
     * @return list<array{filename: string, status: string, additions: int, deletions: int, patch: string|null}>
     */
    public function pullRequestFiles(string $repository, int $number): array
    {
        $files = [];

        for ($page = 1; $page <= self::MAX_FILE_PAGES; $page++) {
            $batch = $this->request()
                ->get("{$this->apiUrl}/repos/{$repository}/pulls/{$number}/files", [
                    'per_page' => 100,
                    'page' => $page,
                ])
                ->throw()
                ->json();

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $file) {
                $files[] = [
                    'filename' => (string) $file['filename'],
                    'status' => (string) $file['status'],
                    'additions' => (int) ($file['additions'] ?? 0),
                    'deletions' => (int) ($file['deletions'] ?? 0),
                    // Binary and very large files come without a patch.
                    'patch' => isset($file['patch']) ? (string) $file['patch'] : null,
                ];
            }

            if (count($batch) < 100) {
                break;
            }
        }

        return $files;
    }

    /**
     * Submit a review on a pull request and return GitHub's id for it.
     *
     * @param  'APPROVE'|'REQUEST_CHANGES'|'COMMENT'  $event
     * @param  list<array{path: string, line: int, body: string}>  $comments
     */
    public function createReview(string $repository, int $number, string $commitSha, string $event, string $body, array $comments = []): int
    {
        $response = $this->request()
            ->post("{$this->apiUrl}/repos/{$repository}/pulls/{$number}/reviews", [
                'commit_id' => $commitSha,
                'event' => $event,
                'body' => $body,
                'comments' => array_map(fn (array $comment): array => [
                    'path' => $comment['path'],
                    'line' => $comment['line'],
                    'side' => 'RIGHT',
                    'body' => $comment['body'],
                ], $comments),
            ])
            ->throw();

        return (int) $response->json('id');
    }

    /**
     * A plain comment in the conversation of a pull request.
     */
    public function createComment(string $repository, int $number, string $body): void
    {
        $this->request()
            ->post("{$this->apiUrl}/repos/{$repository}/issues/{$number}/comments", ['body' => $body])
            ->throw();
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token)
            ->withHeaders(self::HEADERS)
            ->timeout(20);
    }
}
