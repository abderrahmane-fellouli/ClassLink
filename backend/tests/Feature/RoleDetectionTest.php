<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Support\RoleDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-01 a T-04 — Detection de role (§17.5, RG-01/RG-02).
 *
 * Ces tests lisent le code metier de reference « tel quel ».
 */
class RoleDetectionTest extends TestCase
{
    use RefreshDatabase;

    // -- T-01 : domaine etranger refuse --------------------------------------

    /** @dataProvider foreignDomains */
    public function test_t01_foreign_domain_is_denied(string $email): void
    {
        $this->assertSame(Role::Denied->value, RoleDetector::fromEmail($email));
    }

    public static function foreignDomains(): array
    {
        return [
            'gmail' => ['x@gmail.com'],
            'sous-domaine piege' => ['prof@ofppt-edu.ma.evil.com'],
            'domaine voisin' => ['x@notofppt-edu.ma'],
            'domaine absent' => ['professeur'],
            'domaine different' => ['a.chraibi@umpp.ac.ma'],
            'adresse vide' => [''],
        ];
    }

    // -- T-02 : 13 chiffres -> etudiant ---------------------------------------

    /** @dataProvider students */
    public function test_t02_thirteen_digits_is_student(string $email): void
    {
        $this->assertSame(Role::Student->value, RoleDetector::fromEmail($email));
    }

    public static function students(): array
    {
        return [
            // Adresse de reference du cahier des charges.
            'reference' => ['2007031400094@ofppt-edu.ma'],
            'autre matricule' => ['2007031400095@ofppt-edu.ma'],
            'tout zeros' => ['0000000000000@ofppt-edu.ma'],
        ];
    }

    /** @dataProvider nonStudents */
    public function test_t02_other_digit_counts_are_not_student(string $email): void
    {
        $this->assertNotSame(Role::Student->value, RoleDetector::fromEmail($email));
    }

    public static function nonStudents(): array
    {
        return [
            '12 chiffres' => ['200703140094@ofppt-edu.ma'],
            '14 chiffres' => ['20070314000941@ofppt-edu.ma'],
            'chiffres et lettres' => ['200703140009a@ofppt-edu.ma'],
        ];
    }

    // -- T-03 : format enseignant -> enseignant -------------------------------

    /** @dataProvider teachers */
    public function test_t03_teacher_pattern_is_teacher(string $email): void
    {
        $this->assertSame(Role::Teacher->value, RoleDetector::fromEmail($email));
    }

    public static function teachers(): array
    {
        return [
            'reference' => ['zakariyae.chergui@ofppt-edu.ma'],
            'deux mots' => ['hamza.bouzid@ofppt-edu.ma'],
            'trois mots' => ['a.b.c@ofppt-edu.ma'],
            'apostrophe interne' => ["o'b.durand@ofppt-edu.ma"],
            'trait d-union interne' => ['jean-claude.martin@ofppt-edu.ma'],
        ];
    }

    /** @dataProvider nonTeachers */
    public function test_t03_other_patterns_are_not_teacher(string $email): void
    {
        $this->assertNotSame(Role::Teacher->value, RoleDetector::fromEmail($email));
    }

    public static function nonTeachers(): array
    {
        return [
            'un seul mot' => ['zakariyae@ofppt-edu.ma'],
            'commence par un point' => ['.zakariyae@ofppt-edu.ma'],
            'termine par un point' => ['zakariyae.@ofppt-edu.ma'],
            'double point' => ['a..b@ofppt-edu.ma'],
            'chiffres' => ['12345.67890@ofppt-edu.ma'],
            'majuscules normalisees' => ['12345.67890@OFPPT-EDU.MA'],
        ];
    }

    // -- T-04 : format inconnu dans le bon domaine -> en attente -------------

    /** @dataProvider pendings */
    public function test_t04_unknown_format_is_pending(string $email): void
    {
        $this->assertSame(Role::Pending->value, RoleDetector::fromEmail($email));
    }

    public static function pendings(): array
    {
        return [
            'reference du cahier des charges' => ['abc123@ofppt-edu.ma'],
            'un mot' => ['direction@ofppt-edu.ma'],
            'melange' => ['2024_ab@ofppt-edu.ma'],
        ];
    }

    // -- Normalisation --------------------------------------------------------

    public function test_email_is_trimmed_and_lowercased(): void
    {
        $this->assertSame(
            Role::Teacher->value,
            RoleDetector::fromEmail('  ZAKARIYAE.CHERGUI@OFPPT-EDU.MA  ')
        );
    }

    // -- RG-03 / §17.6 : un role attribue n'est pas ecrase par "pending" -------

    public function test_pending_never_overwrites_an_assigned_role(): void
    {
        $this->assertSame(
            Role::Teacher->value,
            RoleDetector::resolveFor('abc123@ofppt-edu.ma', false, Role::Teacher->value)
        );
    }

    public function test_locked_role_is_never_recomputed(): void
    {
        // Un super admin a promu cet enseignant en admin : la detection
        // rejouerait "teacher", le verrou doit l'empecher.
        $this->assertSame(
            Role::Admin->value,
            RoleDetector::resolveFor('zakariyae.chergui@ofppt-edu.ma', true, Role::Admin->value)
        );
    }

    public function test_unlocked_role_follows_detection(): void
    {
        $this->assertSame(
            Role::Student->value,
            RoleDetector::resolveFor('2007031400094@ofppt-edu.ma', false, Role::Teacher->value)
        );
    }
}
