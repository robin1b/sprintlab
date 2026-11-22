<?php

namespace Tests\Feature\GitHub;

use App\Jobs\AssessUserReview;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\GitHubFixtures;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReviewOnOtherRepositoryTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase, ReviewFixtures;

    public function test_a_review_on_another_repository_is_not_assessed(): void
    {
        Bus::fake([AssessUserReview::class]);
        $this->createReviewTicket();

        $user = User::sole();
        $practice = Repository::sole();
        $this->githubWebhook('pull_request_review', $this->reviewPayload(), 'delivery-1');
        Bus::assertDispatchedTimes(AssessUserReview::class, 1);

        // The user now practises in another repository: reviews on this one are not ours.
        $other = Repository::create(['github_id' => 7003, 'github_installation_id' => $practice->github_installation_id, 'full_name' => 'robin/elsewhere', 'private' => false]);
        $user->update(['practice_repository_id' => $other->id]);

        $this->githubWebhook('pull_request_review', $this->reviewPayload(), 'delivery-2')->assertStatus(202);

        Bus::assertDispatchedTimes(AssessUserReview::class, 1);
    }
}
