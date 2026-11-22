<?php

namespace App\Services\GitHub;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Talks to GitHub as the Sprintlab GitHub App.
 *
 * The app proves who it is with a short-lived JWT signed with its private
 * key. With that JWT it asks for an "installation token": a token that only
 * works for the repositories one user installed the app on.
 */
class GitHubApp
{
    public function isConfigured(): bool
    {
        return filled(config('github.app_id')) && filled(config('github.private_key'));
    }

    /**
     * A JWT that identifies the app itself. GitHub accepts it for 10 minutes at most.
     */
    public function jwt(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The GitHub App is not configured: set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY.');
        }

        $now = Carbon::now()->getTimestamp();

        return JWT::encode([
            'iat' => $now - 60, // one minute back, in case the clocks differ a little
            'exp' => $now + 540,
            'iss' => (string) config('github.app_id'),
        ], $this->privateKey(), 'RS256');
    }

    /**
     * A token for one installation, cached until shortly before it expires.
     */
    public function installationToken(int $installationId): string
    {
        $cacheKey = "github.installation-token.{$installationId}";

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::withToken($this->jwt())
            ->withHeaders(InstallationClient::HEADERS)
            ->timeout(15)
            ->post($this->apiUrl()."/app/installations/{$installationId}/access_tokens")
            ->throw();

        $token = (string) $response->json('token');
        $expiresAt = Carbon::parse((string) $response->json('expires_at'));

        Cache::put($cacheKey, $token, $expiresAt->subMinutes(5));

        return $token;
    }

    public function installation(int $installationId): InstallationClient
    {
        return new InstallationClient($this->installationToken($installationId), $this->apiUrl());
    }

    /**
     * Remove the app from an account on GitHub's side. Needs the app JWT, not
     * an installation token. True when the installation is gone (a 404 means
     * it already was), false when the app is not configured; other failures throw.
     */
    public function deleteInstallation(int $installationId): bool
    {
        if (! $this->isConfigured()) {
            Log::info("GitHub App is not configured: installation {$installationId} was not removed on GitHub.");

            return false;
        }

        Cache::forget("github.installation-token.{$installationId}");

        $response = Http::withToken($this->jwt())
            ->withHeaders(InstallationClient::HEADERS)
            ->timeout(15)
            ->delete($this->apiUrl()."/app/installations/{$installationId}");

        if ($response->status() === 404) {
            return true;
        }

        $response->throw();

        return true;
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('github.api_url'), '/');
    }

    private function privateKey(): string
    {
        $key = trim((string) config('github.private_key'));

        // Stored base64-encoded on one line: decode it first.
        if (! str_contains($key, 'BEGIN')) {
            $decoded = base64_decode($key, true);

            if ($decoded !== false) {
                $key = $decoded;
            }
        }

        // Stored as PEM text with literal "\n": turn those into real line breaks.
        return str_replace('\n', "\n", $key);
    }
}
