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
     * One submitted review of a pull request.
     *
     * @return array{id: int, state: string, body: string, user_login: string}
     */
    public function review(string $repository, int $number, int $reviewId): array
    {
        $review = $this->request()
            ->get("{$this->apiUrl}/repos/{$repository}/pulls/{$number}/reviews/{$reviewId}")
            ->throw();

        return [
            'id' => (int) $review->json('id'),
            'state' => (string) $review->json('state'),
            // GitHub sends null for a review without text.
            'body' => (string) $review->json('body', ''),
            'user_login' => (string) $review->json('user.login', ''),
        ];
    }

    /**
     * The inline comments of one review. One page of 100 is enough for a training pull request.
     *
     * @return list<array{path: string, line: int|null, body: string}>
     */
    public function reviewComments(string $repository, int $number, int $reviewId): array
    {
        $batch = $this->request()
            ->get("{$this->apiUrl}/repos/{$repository}/pulls/{$number}/reviews/{$reviewId}/comments", ['per_page' => 100])
            ->throw()
            ->json();

        $comments = [];

        foreach (is_array($batch) ? $batch : [] as $comment) {
            $line = $comment['line'] ?? $comment['original_line'] ?? null;

            $comments[] = [
                'path' => (string) ($comment['path'] ?? ''),
                'line' => $line === null ? null : (int) $line,
                'body' => (string) ($comment['body'] ?? ''),
            ];
        }

        return $comments;
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

    /**
     * @return array{default_branch: string}
     */
    public function repository(string $repository): array
    {
        $response = $this->request()
            ->get("{$this->apiUrl}/repos/{$repository}")
            ->throw();

        return ['default_branch' => (string) $response->json('default_branch')];
    }

    /**
     * The commit a branch points at, or null when the branch does not exist.
     */
    public function branchSha(string $repository, string $branch): ?string
    {
        $response = $this->request()
            ->get("{$this->apiUrl}/repos/{$repository}/git/ref/heads/".$this->encodePath($branch));

        if ($response->status() === 404) {
            return null;
        }

        return (string) $response->throw()->json('object.sha');
    }

    public function createBranch(string $repository, string $branch, string $sha): void
    {
        $this->request()
            ->post("{$this->apiUrl}/repos/{$repository}/git/refs", [
                'ref' => "refs/heads/{$branch}",
                'sha' => $sha,
            ])
            ->throw();
    }

    /**
     * The blob sha of a file on a branch, or null when the file is not there.
     * GitHub needs it to update an existing file.
     */
    public function fileSha(string $repository, string $path, string $branch): ?string
    {
        $response = $this->request()
            ->get("{$this->apiUrl}/repos/{$repository}/contents/".$this->encodePath($path), ['ref' => $branch]);

        if ($response->status() === 404) {
            return null;
        }

        return (string) $response->throw()->json('sha');
    }

    /**
     * Create or update a file with a commit on a branch. Pass the current sha to update.
     */
    public function putFile(string $repository, string $path, string $content, string $message, string $branch, ?string $sha = null): void
    {
        $this->request()
            ->put("{$this->apiUrl}/repos/{$repository}/contents/".$this->encodePath($path), array_filter([
                'message' => $message,
                'content' => base64_encode($content),
                'branch' => $branch,
                'sha' => $sha,
            ], fn (?string $value): bool => $value !== null))
            ->throw();
    }

    /**
     * @return array{number: int, head_sha: string, author_login: string}
     */
    public function createPullRequest(string $repository, string $title, string $body, string $head, string $base): array
    {
        $response = $this->request()
            ->post("{$this->apiUrl}/repos/{$repository}/pulls", [
                'title' => $title,
                'body' => $body,
                'head' => $head,
                'base' => $base,
            ])
            ->throw();

        return $this->pullRequestSummary($response->json());
    }

    /**
     * The open pull request from a branch of this repository, if there is one.
     *
     * @return array{number: int, head_sha: string, author_login: string}|null
     */
    public function openPullRequest(string $repository, string $head): ?array
    {
        $owner = explode('/', $repository)[0];

        $batch = $this->request()
            ->get("{$this->apiUrl}/repos/{$repository}/pulls", [
                'head' => "{$owner}:{$head}",
                'state' => 'open',
            ])
            ->throw()
            ->json();

        return is_array($batch) && isset($batch[0]) && is_array($batch[0])
            ? $this->pullRequestSummary($batch[0])
            : null;
    }

    /**
     * @param  array<array-key, mixed>  $pullRequest
     * @return array{number: int, head_sha: string, author_login: string}
     */
    private function pullRequestSummary(array $pullRequest): array
    {
        return [
            'number' => (int) ($pullRequest['number'] ?? 0),
            'head_sha' => (string) data_get($pullRequest, 'head.sha', ''),
            'author_login' => (string) data_get($pullRequest, 'user.login', ''),
        ];
    }

    /**
     * Encode a path or branch for a URL, but keep its slashes.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token)
            ->withHeaders(self::HEADERS)
            ->timeout(20);
    }
}
