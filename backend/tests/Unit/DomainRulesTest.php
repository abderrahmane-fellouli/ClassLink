<?php

namespace Tests\Unit;

use App\Enums\QuestionType;
use App\Models\Membership;
use App\Models\Classroom;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\NotificationService;
use App\Exceptions\BusinessRuleException;
use App\Services\QuizGradingService;
use App\Support\RoleDetector;
use Tests\TestCase;

class DomainRulesTest extends TestCase
{
    public function test_role_detection_and_locked_roles(): void
    {
        $this->assertSame('student', RoleDetector::fromEmail('2007031400094@ofppt-edu.ma'));
        $this->assertSame('teacher', RoleDetector::fromEmail('nom.prenom@ofppt-edu.ma'));
        $this->assertSame('pending', RoleDetector::fromEmail('abc123@ofppt-edu.ma'));
        $this->assertSame('denied', RoleDetector::fromEmail('x@gmail.com'));
        $this->assertSame('admin', RoleDetector::resolveFor('nom.prenom@ofppt-edu.ma', true, 'admin'));
    }

    public function test_membership_cooldown_boundary_without_database(): void
    {
        $this->freezeTime();
        $membership = new Membership(['status' => 'rejected', 'decided_at' => now()->subHours(23)]);
        $this->assertTrue($membership->isInRejectionCooldown());
        $membership->decided_at = now()->subHours(24);
        $this->assertFalse($membership->isInRejectionCooldown());
        $membership->status = 'accepted';
        $this->assertFalse($membership->isInRejectionCooldown());
    }

    public function test_grading_is_exact_set_match_for_each_question_type(): void
    {
        $service = new QuizGradingService;
        foreach (QuestionType::cases() as $type) {
            $this->assertTrue($service->evaluate($type, [1], [1]));
            $this->assertFalse($service->evaluate($type, [], [1]));
            $this->assertFalse($service->evaluate($type, [1, 2], [1]));
        }
        $this->assertTrue($service->evaluate(QuestionType::Multiple, [2, 1, 1], [1, 2]));
        $this->assertFalse($service->evaluate(QuestionType::Multiple, [1], [1, 2]));
    }

    public function test_membership_service_rejects_decisions_on_non_pending_states_without_io(): void
    {
        $teacher = new User(['role' => 'teacher']);
        $teacher->id = 1;
        $classroom = new Classroom(['teacher_id' => 1, 'status' => 'active']);
        $service = new MembershipService(new NotificationService);
        foreach (['accepted', 'rejected', 'removed'] as $status) {
            $membership = (new Membership(['status' => $status]))->setRelation('classroom', $classroom);
            try {
                $service->accept($teacher, $membership);
                $this->fail('Only pending requests may be accepted.');
            } catch (BusinessRuleException $exception) {
                $this->assertSame(409, $exception->status());
            }
        }
    }
}
