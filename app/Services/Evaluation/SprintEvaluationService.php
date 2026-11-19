<?php

namespace App\Services\Evaluation;

use App\Ai\Agents\EvaluationInterviewer;
use App\Ai\Agents\EvaluationWriter;
use App\Enums\AiFeature;
use App\Enums\Colleague;
use App\Enums\EvaluationStatus;
use App\Enums\Level;
use App\Jobs\WriteSprintEvaluation;
use App\Models\Message;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\User;
use App\Services\Ai\Budget;
use App\Services\Ai\UsageRecorder;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * The evaluation after a sprint: scores, three questions the user answers in
 * writing, Sarah's text and the promotion to the next level.
 */
class SprintEvaluationService
{
    private const QUESTIONS = 3;

    public function __construct(
        private readonly ScoreCalculator $calculator,
        private readonly SprintMaterial $material,
        private readonly Budget $budget,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * Calculate the scores and let Sarah prepare her three questions.
     * Safe to run twice: a prepared evaluation is returned as it is.
     */
    public function prepare(Sprint $sprint): ?SprintEvaluation
    {
        if (! $sprint->isCompleted()) {
            return null;
        }

        $user = $sprint->user;

        $evaluation = SprintEvaluation::firstOrCreate(
            ['sprint_id' => $sprint->id],
            ['status' => EvaluationStatus::Preparing, 'level' => $user->level],
        );

        $retryable = $evaluation->status === EvaluationStatus::Preparing
            || ($evaluation->status === EvaluationStatus::Failed && $evaluation->answers === null);

        if (! $retryable) {
            return $evaluation;
        }

        try {
            $scores = array_replace(
                array_fill_keys(ScoreCalculator::AREAS, null),
                $this->calculator->fromSprint($sprint),
            );

            $evaluation->update(['status' => EvaluationStatus::Preparing, 'scores' => $scores, 'error' => null]);

            if (! $this->aiAllowed()) {
                $this->finish($evaluation);

                return $evaluation;
            }

            $firstName = SprintMaterial::firstName($user->name);

            $response = EvaluationInterviewer::make(level: $user->level->value, firstName: $firstName)->prompt(
                $this->material->forSprint($sprint),
                provider: Lab::Anthropic,
                model: (string) config('sprintlab.ai.review_model'),
            );

            $this->usage->record(
                AiFeature::Evaluation,
                $response->meta->model,
                $response->usage->inputTokens,
                $response->usage->outputTokens,
                $user,
                $sprint,
            );

            $questions = $this->cleanQuestions((array) ($response['questions'] ?? []));
            $scores['communication'] = $this->calculator->ratingToScore((int) ($response['communication'] ?? 1));
            $evidence = trim((string) ($response['communication_evidence'] ?? ''));

            $evaluation->update(['scores' => $scores, 'communication_evidence' => $evidence]);

            // Without three usable questions there is nothing to ask: continue as without AI.
            if (count($questions) < self::QUESTIONS) {
                $this->finish($evaluation);

                return $evaluation;
            }

            // The status change and Sarah's message belong together.
            DB::transaction(function () use ($evaluation, $questions, $firstName): void {
                $evaluation->update(['questions' => $questions, 'status' => EvaluationStatus::AwaitingAnswers]);

                $this->sarahSays($evaluation, "Your sprint is done, {$firstName}. I have three questions for your evaluation. You will find them on your evaluation page.");
            });
        } catch (Throwable $exception) {
            $this->fail($evaluation, $exception);

            throw $exception;
        }

        return $evaluation;
    }

    /**
     * @param  array<array-key, mixed>  $answers
     */
    public function submitAnswers(SprintEvaluation $evaluation, array $answers): void
    {
        if ($evaluation->status !== EvaluationStatus::AwaitingAnswers) {
            throw new DomainException('This evaluation is not waiting for answers.');
        }

        $questions = $evaluation->questions ?? [];

        if (count($answers) !== count($questions)) {
            throw new InvalidArgumentException('Give exactly one answer per question.');
        }

        $clean = [];
        $max = (int) config('sprintlab.evaluation.answer_max_characters');

        foreach ($answers as $answer) {
            if (! is_string($answer)) {
                throw new InvalidArgumentException('Every answer must be text.');
            }

            $answer = trim($answer);

            if (mb_strlen($answer) > $max) {
                throw new InvalidArgumentException("An answer can have at most {$max} characters.");
            }

            $clean[] = $answer;
        }

        // Only one of two quick double submits can win this update.
        $changed = SprintEvaluation::query()
            ->whereKey($evaluation->id)
            ->where('status', EvaluationStatus::AwaitingAnswers)
            ->update(['answers' => json_encode($clean), 'status' => EvaluationStatus::Writing->value]);

        if ($changed === 0) {
            throw new DomainException('This evaluation is not waiting for answers.');
        }

        $evaluation->refresh();

        WriteSprintEvaluation::dispatch($evaluation->id);
    }

    /**
     * Rate the answers and let Sarah write the evaluation.
     */
    public function write(SprintEvaluation $evaluation): void
    {
        $allowed = in_array($evaluation->status, [EvaluationStatus::Writing, EvaluationStatus::Failed], true);

        if (! $allowed || $evaluation->answers === null || $evaluation->questions === null) {
            throw new DomainException('This evaluation has no answers to write about.');
        }

        try {
            $evaluation->update(['status' => EvaluationStatus::Writing, 'error' => null]);

            if (! $this->aiAllowed()) {
                $this->finish($evaluation);

                return;
            }

            $sprint = $evaluation->sprint;
            $user = $sprint->user;

            $response = EvaluationWriter::make(level: $evaluation->level->value, firstName: SprintMaterial::firstName($user->name))->prompt(
                $this->material->forAnswers($sprint, $evaluation->questions, $evaluation->answers),
                provider: Lab::Anthropic,
                model: (string) config('sprintlab.ai.review_model'),
            );

            $this->usage->record(
                AiFeature::Evaluation,
                $response->meta->model,
                $response->usage->inputTokens,
                $response->usage->outputTokens,
                $user,
                $sprint,
            );

            $notes = $this->cleanNotes((array) ($response['answers'] ?? []), count($evaluation->questions));
            $scores = $evaluation->scores ?? [];
            $scores['understanding'] = (int) round(array_sum(array_map(
                fn (array $note): int => $this->calculator->ratingToScore($note['understanding']),
                $notes,
            )) / max(1, count($notes)));

            $evaluation->update([
                'answer_notes' => $notes,
                'scores' => $scores,
                'strengths' => trim((string) ($response['strengths'] ?? '')),
                'growth' => trim((string) ($response['growth'] ?? '')),
                'advice' => trim((string) ($response['advice'] ?? '')),
            ]);

            $this->finish($evaluation);
        } catch (Throwable $exception) {
            $this->fail($evaluation, $exception);

            throw $exception;
        }
    }

    /**
     * Where the user stands on the way to the next level.
     *
     * @return array{level: string, passed: int, needed: int, next: ?string}
     */
    public function progressFor(User $user): array
    {
        return [
            'level' => $user->level->label(),
            'passed' => $this->passedAtCurrentLevel($user),
            'needed' => (int) config('sprintlab.evaluation.sprints_to_promote'),
            'next' => $user->level->next()?->label(),
        ];
    }

    /**
     * Compute the overall score, promote when earned and tell the user, all
     * in one transaction so a retry can never send the message twice.
     */
    private function finish(SprintEvaluation $evaluation): void
    {
        DB::transaction(function () use ($evaluation): void {
            $overall = $this->calculator->overall($evaluation->scores ?? []);
            $passed = $overall !== null && $overall >= (int) config('sprintlab.evaluation.pass_score');

            $evaluation->update([
                'overall' => $overall,
                'passed' => $passed,
                'status' => EvaluationStatus::Completed,
                'completed_at' => Carbon::now(),
                'error' => null,
            ]);

            $promotedTo = $passed ? $this->promote($evaluation->sprint->user) : null;

            if ($promotedTo !== null) {
                $evaluation->update(['promoted_to' => $promotedTo->value]);
            }

            $text = 'Your evaluation is ready.';

            if ($promotedTo !== null) {
                $text .= " You have been promoted to {$promotedTo->label()}. Well done.";
            }

            $this->sarahSays($evaluation, $text);
        });
    }

    /**
     * Moves the user up when enough sprints passed at the current level.
     */
    private function promote(User $user): ?Level
    {
        $next = $user->level->next();

        if ($next === null || $this->passedAtCurrentLevel($user) < (int) config('sprintlab.evaluation.sprints_to_promote')) {
            return null;
        }

        $user->level = $next;
        $user->save();

        return $next;
    }

    private function passedAtCurrentLevel(User $user): int
    {
        return SprintEvaluation::query()
            ->where('status', EvaluationStatus::Completed)
            ->where('passed', true)
            ->where('level', $user->level)
            ->whereHas('sprint', fn ($sprints) => $sprints->where('user_id', $user->id))
            ->count();
    }

    private function aiAllowed(): bool
    {
        return (bool) config('sprintlab.ai.enabled') && $this->budget->allows();
    }

    private function fail(SprintEvaluation $evaluation, Throwable $exception): void
    {
        $evaluation->update([
            'status' => EvaluationStatus::Failed,
            'error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }

    private function sarahSays(SprintEvaluation $evaluation, string $body): void
    {
        Message::create([
            'sprint_id' => $evaluation->sprint_id,
            'colleague' => Colleague::Sarah,
            'from_user' => false,
            'body' => $body,
        ]);
    }

    /**
     * Exactly three non-empty questions, or fewer when the model gave fewer.
     *
     * @param  array<array-key, mixed>  $questions
     * @return list<string>
     */
    private function cleanQuestions(array $questions): array
    {
        $clean = [];

        foreach ($questions as $question) {
            if (is_string($question) && trim($question) !== '') {
                $clean[] = trim($question);
            }
        }

        return array_slice($clean, 0, self::QUESTIONS);
    }

    /**
     * One note per question, in order. A missing answer counts as rating 1.
     *
     * @param  array<array-key, mixed>  $answer
     * @return list<array{understanding: int, note: string}>
     */
    private function cleanNotes(array $answer, int $count): array
    {
        $indexes = array_map(fn ($item): int => (int) data_get($item, 'index', -1), array_values($answer));

        // The questions are numbered from 1 in the prompt; forgive a model that counts from 0.
        $offset = in_array(0, $indexes, true) && ! in_array($count, $indexes, true) ? 1 : 0;

        $byIndex = [];

        foreach (array_values($answer) as $position => $item) {
            // The first answer for an index wins.
            $byIndex[$indexes[$position] + $offset] ??= $item;
        }

        $notes = [];

        for ($number = 1; $number <= $count; $number++) {
            $item = $byIndex[$number] ?? null;

            $notes[] = [
                'understanding' => $item === null ? 1 : max(1, min(5, (int) data_get($item, 'understanding', 1))),
                'note' => $item === null ? '' : trim((string) data_get($item, 'note', '')),
            ];
        }

        return $notes;
    }
}
