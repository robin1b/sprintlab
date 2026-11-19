<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk();
    }

    public function test_guests_cannot_see_the_profile_page(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'New Name', 'email' => 'New@Example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_email_is_optional(): void
    {
        $user = User::factory()->create(['email' => 'x@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Name', 'email' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->email);
    }

    public function test_keeping_the_same_email_is_allowed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Name', 'email' => $user->email])
            ->assertSessionHasNoErrors();
    }

    public function test_email_must_be_unique(): void
    {
        $other = User::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Name', 'email' => $other->email])
            ->assertSessionHasErrors('email');
    }

    public function test_name_is_required(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('profile.update'), ['name' => '', 'email' => null])
            ->assertSessionHasErrors('name');
    }

    public function test_user_can_delete_their_account_without_a_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'))
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }
}
