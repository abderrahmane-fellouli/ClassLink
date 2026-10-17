<?php

namespace App\Workspace;

enum Preset: string
{
    case Ofppt = 'ofppt';
    case LanguageTeacher = 'language_teacher';
    case PrivateTutor = 'private_tutor';
    case TrainingCenter = 'training_center';
    case UniversityTeacher = 'university_teacher';
    case GeneralSchool = 'general_school';
    case ClubAssociation = 'club_association';
    case Custom = 'custom';
}
