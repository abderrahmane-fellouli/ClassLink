/* ─── Types ──────────────────────────────────────────────────────────────
   Miroir exact des resources Laravel (app/Http/Resources) et des réponses
   JSON de routes/api.php. Toute divergence doit être corrigée ici, jamais
   dans un écran.
   ────────────────────────────────────────────────────────────────────── */

export type Locale = 'fr' | 'en'
export type Role = 'student' | 'teacher' | 'admin' | 'pending' | 'denied'
export type MembershipStatus = 'pending' | 'accepted' | 'rejected'
export type ClassStatus = 'active' | 'archived'
export type QuizStatus = 'draft' | 'published'
export type AiTarget = 'quiz' | 'flashcard'
export type AiJobStatus = 'queued' | 'running' | 'succeeded' | 'failed'

/* UserResource */
export interface ApiUser {
  id: number
  display_name: string
  role: Role
  locale: Locale
  is_active: boolean
  last_login_at: string | null
  initials: string
  email?: string | null
}

/* ClassroomResource */
export interface ApiClassroom {
  id: number
  name: string
  subject: string
  group_label: string
  school_year: string
  status: ClassStatus
  archived_at: string | null
  is_read_only: boolean
  join_code?: string | null
  join_enabled?: boolean | null
  teacher?: { id: number; display_name: string } | null
  membership?: { status: MembershipStatus } | null
  members_count?: number
  created_at: string | null
}

/* MemberResource — aucun email pour un étudiant (RG-18) */
export interface ApiMember {
  membership_id: number
  status: MembershipStatus
  requested_at: string | null
  decided_at: string | null
  user: {
    id: number
    display_name: string
    initials: string
    role: Role
    locale: Locale
    email?: string | null
  } | null
}

/* MembershipResource */
export interface ApiMembership {
  id: number
  classroom_id: number
  status: MembershipStatus
  requested_at: string | null
  decided_at: string | null
  classroom?: {
    id: number
    name: string
    subject: string
    group_label: string
    teacher_name?: string | null
  } | null
}

/* MaterialResource */
export interface ApiMaterial {
  id: number
  classroom_id: number
  title: string
  chapter: string | null
  type: 'file' | 'link'
  url: string | null
  has_file: boolean
  download_endpoint: string | null
  file_name: string | null
  mime_type: string | null
  file_size: number | null
  uploaded_by?: { id: number; display_name: string } | null
  created_at: string | null
}

/* AnnouncementResource */
export interface ApiAnnouncement {
  id: number
  classroom_id: number
  title: string
  body: string
  pinned: boolean
  author?: { id: number; display_name: string } | null
  created_at: string | null
}

/* QuestionResource (vue enseignant) */
export interface ApiQuestion {
  id: number
  statement: string
  type: string
  explanation: string | null
  position: number
  options?: { id: number; label: string; is_correct: boolean }[]
}

/* AttemptSummaryResource */
export interface ApiAttemptSummary {
  id: number
  attempt_no: number
  score: number
  max_score: number
  percentage: number
  expired: boolean
  started_at: string | null
  submitted_at: string | null
  student?: { id: number; display_name: string } | null
}

/* QuizResource */
export interface ApiQuiz {
  id: number
  classroom_id: number
  title: string
  status: QuizStatus
  source: 'manual' | 'ai'
  reviewed: boolean
  can_publish: boolean
  time_limit_min: number | null
  max_attempts: number | null
  shuffle: boolean
  show_answers: boolean
  published_at: string | null
  questions_count: number
  created_at: string | null
  classroom?: { id: number; name: string; subject: string } | null
  questions?: ApiQuestion[]
  attempts?: ApiAttemptSummary[]
}

/* StudentQuizResource — jamais is_correct ni explanation (RG-13) */
export interface ApiStudentQuiz {
  id: number
  classroom_id: number
  title: string
  status: QuizStatus
  time_limit_min: number | null
  max_attempts: number | null
  shuffle: boolean
  show_answers: boolean
  published_at: string | null
  classroom?: { id: number; name: string } | null
  attempts_used?: number
}

/* POST /quizzes/{id}/attempts */
export interface ApiAttemptStart {
  attempt_id: number
  attempt_no: number
  started_at: string
  time_limit_min: number | null
  remaining_seconds: number
  questions: {
    id: number
    statement: string
    type: string
    position: number
    options: { id: number; label: string }[]
  }[]
}

/* AttemptResultResource */
export interface ApiAttemptResult {
  id: number
  quiz_id: number
  quiz_title: string
  attempt_no: number
  score: number
  max_score: number
  percentage: number
  started_at: string | null
  submitted_at: string | null
  expired: boolean
  show_answers: boolean
  answers: {
    question_id: number
    statement: string
    type: string
    is_correct: boolean
    awarded_score: number
    selected_option_ids: number[]
    explanation: string | null
    options: { id: number; label: string; is_correct: boolean | null }[]
  }[]
}

/* QuizResultsResource */
export interface ApiQuizResults {
  quiz: { id: number; title: string; classroom_id: number; questions_count: number }
  summary: {
    students: number
    participants: number
    participation_rate: number
    average_percentage: number
    highest_percentage: number
    lowest_percentage: number
    attempts_count: number
  }
  attempts: ApiAttemptSummary[]
  most_missed: { question_id: number; statement: string; misses: number; attempts: number; miss_rate: number }[]
}

/* AssignmentResource */
export interface ApiAssignment {
  id: number
  classroom_id: number
  title: string
  instructions: string | null
  due_at: string | null
  is_overdue: boolean
  submissions_count?: number
  /** Rendu de l'etudiant connecte : present uniquement pour lui. */
  my_submission?: ApiSubmission | null
  author?: { id: number; display_name: string } | null
  created_at: string | null
}

/* SubmissionResource */
export interface ApiSubmission {
  id: number
  assignment_id: number
  file_name: string | null
  mime_type: string | null
  file_size: number | null
  download_endpoint: string | null
  is_late: boolean
  grade: number | null
  feedback: string | null
  submitted_at: string | null
  student?: { id: number; display_name: string; email?: string | null } | null
}

/* FlashcardDeckResource */
export interface ApiFlashcardDeck {
  id: number
  classroom_id: number
  title: string
  source: 'manual' | 'ai'
  status: 'draft' | 'published'
  reviewed: boolean
  cards_count: number
  /** `known` : null = jamais révisée, true = « su », false = « à revoir ». */
  cards?: { id: number; front: string; back: string; known: boolean | null }[]
  created_at: string | null
}

/* NotificationResource */
export interface ApiNotification {
  id: number
  type: string
  payload: Record<string, unknown> | null
  read_at: string | null
  is_read: boolean
  created_at: string | null
}

/* PartnerProfileResource */
export interface ApiPartnerProfile {
  user_id: number
  opt_in: boolean
  skills: string[]
  availability: string[]
}

/* Candidate (GET /classes/{id}/partners) — jamais d'email (F-PAR-04) */
export interface ApiPartnerCandidate {
  user_id: number
  display_name: string | null
  skills: string[] | null
  availability: string[] | null
  shared_skills: string[]
  compatibility: number
}

/* PartnerRequestResource */
export interface ApiPartnerRequest {
  id: number
  status: 'pending' | 'accepted' | 'rejected'
  classroom_id: number
  classroom_name: string | null
  direction: 'incoming' | 'outgoing'
  counterpart: { id: number; display_name: string } | null
  created_at: string | null
}

/* AiJobResource */
export interface ApiAiJob {
  id: number
  classroom_id: number
  target: AiTarget
  status: AiJobStatus
  provider: string | null
  original_name: string | null
  page_count: number | null
  error: string | null
  quiz_id: number | null
  deck_id: number | null
  started_at: string | null
  finished_at: string | null
  created_at: string | null
}

/* GET /me/progress */
export interface ApiStudentProgress {
  totals: {
    quizzes_taken: number
    attempts: number
    average_percentage: number
    best_percentage: number
  }
  history: {
    attempt_id: number
    quiz_id: number
    quiz_title: string | null
    classroom_id: number | null
    attempt_no: number
    score: number
    max_score: number
    percentage: number
    submitted_at: string | null
  }[]
}

/* GET /classes/{id}/progress */
export interface ApiClassroomProgress {
  totals: {
    students: number
    quizzes: number
    attempts: number
    participation_rate: number
    class_average: number
  }
  inactive_student_ids: number[]
  most_missed: {
    question_id: number
    misses: number
    attempts: number
    miss_rate: number
    statement: string | null
    quiz_title: string | null
  }[]
}

/* GET /me/deadlines */
export interface ApiDeadline {
  kind: 'assignment' | 'quiz'
  id: number
  title: string
  classroom: string | null
  due_at: string | null
  is_overdue: boolean
}

/* AdminController::users */
export interface ApiAdminUser {
  id: number
  email: string
  display_name: string
  role: Role
  role_locked: boolean
  is_active: boolean
  last_login_at: string | null
  created_at: string | null
}

/* AdminController::classes */
export interface ApiAdminClassroom {
  id: number
  name: string
  subject: string
  group_label: string
  school_year: string
  status: ClassStatus
  join_code: string
  join_enabled: boolean
  members_count: number
  teacher: { id: number | null; display_name: string | null } | null
}

/* AdminController::aiProviders */
export interface ApiAiProvider {
  id: number
  name: string
  priority: number
  enabled: boolean
  daily_limit: number
  used_today: number
  has_key: boolean
}

/* AdminController::auditLogs */
export interface ApiAuditLog {
  id: number
  action: string
  user: { id: number; display_name: string } | null
  context: Record<string, unknown> | null
  ip: string | null
  created_at: string | null
}

/* AdminController::stats */
export interface ApiStats {
  users: { total: number; by_role: Record<string, number>; active: number; pending: number }
  classes: { total: number; active: number; archived: number }
  memberships: { pending: number; accepted: number }
  quizzes: { total: number; published: number; draft: number }
  ai_jobs: { total: number; failed: number }
}

/* Envelopes paginées de l'administration */
export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

export interface ListResponse<T> {
  data: T[]
}
