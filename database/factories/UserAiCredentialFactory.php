<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAiCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAiCredential>
 */
class UserAiCredentialFactory extends Factory
{
    protected $model = UserAiCredential::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => UserAiCredential::PROVIDER_ANTHROPIC,
            'api_key' => 'test-key-'.fake()->uuid(),
            'model' => null,
            'enabled' => true,
        ];
    }
}
