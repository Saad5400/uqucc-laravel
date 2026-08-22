<?php

namespace Database\Factories\StudentGroup;

use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\StudentGroup;
use App\Models\StudentGroup\SupervisorSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentGroup\GroupSupervisor>
 */
class GroupSupervisorFactory extends Factory
{
    protected $model = GroupSupervisor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_group_id' => StudentGroupFactory::new(),
            'name' => fake()->firstName(),
            'telegram_username' => fake()->unique()->userName(),
            'whatsapp_number' => null,
            'section' => SupervisorSection::Men,
            'is_available' => true,
        ];
    }

    /** Reachable on WhatsApp only, the way half the college's lists publish. */
    public function whatsappOnly(string $number = '0501234567'): static
    {
        return $this->state(fn (array $attributes) => [
            'telegram_username' => null,
            'whatsapp_number' => $number,
        ]);
    }

    /** Reachable both ways. */
    public function withWhatsapp(string $number = '0501234567'): static
    {
        return $this->state(fn (array $attributes) => ['whatsapp_number' => $number]);
    }

    public function women(): static
    {
        return $this->state(fn (array $attributes) => ['section' => SupervisorSection::Women]);
    }

    /** On a mixed roster that advertises no section split. */
    public function bothSections(): static
    {
        return $this->state(fn (array $attributes) => ['section' => SupervisorSection::Both]);
    }

    /** A supervisor currently out of the rotation. */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => ['is_available' => false]);
    }

    /** Attach the supervisor to an existing group. */
    public function forGroup(StudentGroup $group): static
    {
        return $this->state(fn (array $attributes) => ['student_group_id' => $group->id]);
    }
}
