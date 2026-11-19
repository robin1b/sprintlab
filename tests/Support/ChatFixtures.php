<?php

namespace Tests\Support;

use App\Ai\Agents\ColleagueChat;
use App\Enums\Colleague;
use App\Enums\SprintStatus;
use App\Models\Message;
use App\Models\Sprint;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;

/**
 * Helpers for the chat tests. Needs SprintFixtures next to it.
 */
trait ChatFixtures
{
    /**
     * Fakes the agent. Without arguments it answers with one fixed text.
     *
     * @param  list<mixed>|null  $responses
     */
    protected function fakeColleague(?array $responses = null): void
    {
        ColleagueChat::fake($responses ?? ['Have a look at the approve method first.']);
    }

    protected function fakeColleagueWithUsage(string $text, int $input, int $output): void
    {
        ColleagueChat::fake([
            new TextResponse($text, new TextUsage($input, $output), new Meta('anthropic', 'claude-haiku-5-5')),
        ]);
    }

    protected function userMessage(Sprint $sprint, string $body = 'Where do I start?', Colleague $colleague = Colleague::Emily): Message
    {
        return Message::factory()->fromUser()->create([
            'sprint_id' => $sprint->id,
            'colleague' => $colleague,
            'body' => $body,
        ]);
    }

    protected function setSprintStatus(Sprint $sprint, SprintStatus $status): Sprint
    {
        $sprint->update([
            'status' => $status,
            'paused_at' => $status === SprintStatus::Paused ? now() : null,
            'completed_at' => $status === SprintStatus::Completed ? now() : null,
        ]);

        return $sprint;
    }
}
