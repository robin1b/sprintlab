<?php

namespace App\Services\Evaluation;

use App\Enums\AssessmentStatus;
use App\Enums\Colleague;
use App\Enums\ReviewStatus;
use App\Models\Message;
use App\Models\ReviewAssessment;
use App\Models\Sprint;
use App\Models\Ticket;

/**
 * The text both evaluation agents get to read about a sprint.
 *
 * It never holds the planted problems of a review ticket or the payload of a
 * scenario event: those are the secret of the scenario.
 */
class SprintMaterial
{
    // Only the newest messages, each cut short, keep the prompt small.
    private const MAX_MESSAGES = 40;

    private const MAX_MESSAGE_CHARACTERS = 500;

    public function forSprint(Sprint $sprint): string
    {
        $user = $sprint->user;
        $firstName = self::firstName($user->name);

        $tickets = $sprint->tickets()
            ->whereNotNull('released_at')
            ->with('pullRequest.reviews')
            ->orderBy('day')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $sections = [
            "Scenario: {$sprint->scenario->title} (level: {$sprint->scenario->level})",
            "Tickets\n".$this->ticketLines($tickets),
            "Your reviews of the developer's pull requests\n".$this->reviewLines($tickets),
            "Feedback you gave on the reviews {$firstName} wrote\n".$this->feedbackLines($tickets),
            "Messages {$firstName} sent in the team chat, oldest first\n".$this->messageLines($sprint),
        ];

        return implode("\n\n", $sections);
    }

    /**
     * The material plus the questions and the answers, for the writer.
     *
     * @param  list<string>  $questions
     * @param  list<string>  $answers
     */
    public function forAnswers(Sprint $sprint, array $questions, array $answers): string
    {
        $lines = [];

        foreach ($questions as $index => $question) {
            $answer = $answers[$index] ?? '';
            $lines[] = sprintf("Question %d: %s\nAnswer %d: %s", $index + 1, $question, $index + 1, trim($answer) !== '' ? $answer : '(no answer)');
        }

        return $this->forSprint($sprint)."\n\nThe evaluation conversation (use the question number as index)\n".implode("\n\n", $lines);
    }

    public static function firstName(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0]);

        return $first !== '' ? $first : 'there';
    }

    /**
     * @param  iterable<Ticket>  $tickets
     */
    private function ticketLines(iterable $tickets): string
    {
        $lines = [];

        foreach ($tickets as $ticket) {
            $lines[] = sprintf('- %s | %s | %s | final status: %s', $ticket->key, $ticket->type->value, $ticket->title, $ticket->status->label());
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    /**
     * @param  iterable<Ticket>  $tickets
     */
    private function reviewLines(iterable $tickets): string
    {
        $lines = [];

        foreach ($tickets as $ticket) {
            $pullRequest = $ticket->pullRequest;

            if ($pullRequest === null) {
                continue;
            }

            $reviews = $pullRequest->reviews
                ->where('colleague', Colleague::Sarah)
                ->where('status', ReviewStatus::Posted)
                ->sortBy('id')
                ->values();

            if ($reviews->isEmpty()) {
                continue;
            }

            $lines[] = "{$ticket->key} ({$pullRequest->title}):";

            foreach ($reviews as $number => $review) {
                $verdict = $review->verdict === null ? 'no verdict' : $review->verdict->value;
                $summary = trim((string) $review->summary);
                $lines[] = sprintf('  %d. %s: %s', $number + 1, $verdict, $summary !== '' ? $summary : '(no summary)');
            }
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    /**
     * @param  iterable<Ticket>  $tickets
     */
    private function feedbackLines(iterable $tickets): string
    {
        $ids = [];

        foreach ($tickets as $ticket) {
            $ids[$ticket->id] = $ticket;
        }

        $assessments = ReviewAssessment::query()
            ->whereIn('ticket_id', array_keys($ids))
            ->where('status', AssessmentStatus::Done)
            ->orderBy('id')
            ->get();

        $lines = [];

        foreach ($assessments as $assessment) {
            $feedback = trim((string) $assessment->feedback);

            if ($feedback !== '') {
                $lines[] = "- {$ids[$assessment->ticket_id]->key}: {$feedback}";
            }
        }

        return $lines === [] ? '(none)' : implode("\n", $lines);
    }

    private function messageLines(Sprint $sprint): string
    {
        $messages = Message::query()
            ->where('sprint_id', $sprint->id)
            ->where('from_user', true)
            ->orderByDesc('id')
            ->limit(self::MAX_MESSAGES)
            ->get()
            ->reverse();

        $lines = [];

        foreach ($messages as $message) {
            $text = mb_substr(trim($message->body), 0, self::MAX_MESSAGE_CHARACTERS);
            $lines[] = "- to {$message->colleague->displayName()}: {$text}";
        }

        return $lines === [] ? '(no messages)' : implode("\n", $lines);
    }
}
