<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'statement' => $this->faker->sentence().' ?',
            'type' => 'single',
            'explanation' => $this->faker->sentence(),
            'position' => 0,
        ];
    }
}
