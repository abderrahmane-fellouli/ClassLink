import { api } from './api'
import type {
  ApiAdminClassroom,
  ApiAdminUser,
  ApiAiProvider,
  ApiAiJob,
  ApiAnnouncement,
  ApiAssignment,
  ApiAttemptResult,
  ApiAttemptStart,
  ApiAuditLog,
  ApiClassroom,
  ApiClassroomProgress,
  ApiDeadline,
  ApiFlashcardDeck,
  ApiMaterial,
  ApiMember,
  ApiMembership,
  ApiNotification,
  ApiPartnerCandidate,
  ApiPartnerProfile,
  ApiPartnerRequest,
  ApiQuiz,
  ApiQuizResults,
  ApiStats,
  ApiStudentProgress,
  ApiStudentQuiz,
  ApiSubmission,
  ApiUser,
  Locale,
  ListResponse,
  Paginated,
} from './types'

/** Contexte de langue propagé à chaque appel (Accept-Language). */
type Ctx = { locale?: Locale; signal?: AbortSignal }

/* ── 12.1 Authentification ─────────────────────────────────────────── */

export interface AuthResult {
  token: string
  user: ApiUser
}

export const auth = {
  /** §17.6 : le serveur redirige vers Microsoft, puis revient avec #token=. */
  microsoftRedirectUrl: () => api.absolute('/auth/microsoft/redirect'),

  /** F-AUTH-02 : réponse 202, identique que l'adresse existe ou non. */
  requestOtp: (email: string, ctx?: Ctx) =>
    api.post<{ message: string }>('/auth/otp/request', { email }, { ...ctx, anonymous: true }),

  verifyOtp: (email: string, code: string, ctx?: Ctx) =>
    api.post<AuthResult>('/auth/otp/verify', { email, code }, { ...ctx, anonymous: true }),

  /** §25 : développement uniquement, absent de la production. */
  devLogin: (role: 'student' | 'teacher' | 'admin', ctx?: Ctx) =>
    api.post<AuthResult>('/auth/dev/login', { role }, { ...ctx, anonymous: true }),

  logout: (ctx?: Ctx) => api.post<void>('/auth/logout', undefined, ctx),
}

/* ── 12.1 Profil ───────────────────────────────────────────────────── */

export const profile = {
  me: (ctx?: Ctx) => api.get<ApiUser>('/me', ctx),

  update: (payload: { display_name?: string; locale?: Locale }, ctx?: Ctx) =>
    api.patch<ApiUser>('/me', payload, ctx),

  /** F-AUTH-06 : révoque toutes les sessions. */
  destroySessions: (ctx?: Ctx) => api.delete<void>('/me/sessions', ctx),
}

/* ── 12.2 Classes et adhésions ─────────────────────────────────────── */

export const classrooms = {
  list: (ctx?: Ctx) => api.get<ListResponse<ApiClassroom>>('/classes', ctx),

  create: (
    payload: { name: string; subject: string; group_label: string; school_year: string },
    ctx?: Ctx,
  ) => api.post<ApiClassroom>('/classes', payload, ctx),

  show: (id: number, ctx?: Ctx) => api.get<ApiClassroom>(`/classes/${id}`, ctx),

  update: (
    id: number,
    payload: Partial<Pick<ApiClassroom, 'name' | 'subject' | 'group_label' | 'school_year'>>,
    ctx?: Ctx,
  ) => api.patch<ApiClassroom>(`/classes/${id}`, payload, ctx),

  archive: (id: number, ctx?: Ctx) => api.post<ApiClassroom>(`/classes/${id}/archive`, undefined, ctx),

  regenerateCode: (id: number, ctx?: Ctx) =>
    api.post<{ join_code: string }>(`/classes/${id}/code/regenerate`, undefined, ctx),

  toggleCode: (id: number, ctx?: Ctx) =>
    api.post<{ join_enabled: boolean }>(`/classes/${id}/code/toggle`, undefined, ctx),

  members: (id: number, ctx?: Ctx) => api.get<ListResponse<ApiMember>>(`/classes/${id}/members`, ctx),

  removeMember: (classroomId: number, studentId: number, ctx?: Ctx) =>
    api.delete<void>(`/classes/${classroomId}/members/${studentId}`, ctx),

  joinRequests: (classroomId: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiMember>>(`/classes/${classroomId}/join-requests`, ctx),

  acceptAll: (classroomId: number, ctx?: Ctx) =>
    api.post<{ accepted: number }>(`/classes/${classroomId}/join-requests/accept-all`, undefined, ctx),
}

export const joinRequests = {
  /** §17.7 : `code` fait exactement 8 caractères. */
  create: (code: string, ctx?: Ctx) => api.post<ApiMembership>('/join-requests', { code }, ctx),

  mine: (ctx?: Ctx) => api.get<ListResponse<ApiMembership>>('/join-requests/mine', ctx),

  accept: (membershipId: number, ctx?: Ctx) =>
    api.post<ApiMembership>(`/join-requests/${membershipId}/accept`, undefined, ctx),

  reject: (membershipId: number, ctx?: Ctx) =>
    api.post<ApiMembership>(`/join-requests/${membershipId}/reject`, undefined, ctx),
}

/* ── 12.3 Contenus ─────────────────────────────────────────────────── */

export const content = {
  materials: (classroomId: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiMaterial>>(`/classes/${classroomId}/materials`, ctx),

  createMaterial: (classroomId: number, payload: Record<string, unknown>, ctx?: Ctx) =>
    api.post<ApiMaterial>(`/classes/${classroomId}/materials`, payload, ctx),

  /** F-CON-01 : ressource fichier ou lien, en multipart. */
  createMaterialForm: (classroomId: number, form: FormData, ctx?: Ctx) =>
    api.postForm<ApiMaterial>(`/classes/${classroomId}/materials`, form, ctx),

  updateMaterial: (id: number, payload: { title?: string; chapter?: string | null }, ctx?: Ctx) =>
    api.patch<ApiMaterial>(`/materials/${id}`, payload, ctx),

  deleteMaterial: (id: number, ctx?: Ctx) => api.delete<void>(`/materials/${id}`, ctx),

  /** F-CON-02 : contrôle d'accès serveur, puis URL signée ou flux direct. */
  downloadMaterial: (id: number, fileName: string, ctx?: Ctx) =>
    api.download(`/materials/${id}/download`, fileName, ctx),

  announcements: (classroomId: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiAnnouncement>>(`/classes/${classroomId}/announcements`, ctx),

  createAnnouncement: (
    classroomId: number,
    payload: { title: string; body: string; pinned?: boolean },
    ctx?: Ctx,
  ) => api.post<ApiAnnouncement>(`/classes/${classroomId}/announcements`, payload, ctx),

  updateAnnouncement: (
    id: number,
    payload: { title?: string; body?: string; pinned?: boolean },
    ctx?: Ctx,
  ) => api.patch<ApiAnnouncement>(`/announcements/${id}`, payload, ctx),

  deleteAnnouncement: (id: number, ctx?: Ctx) => api.delete<void>(`/announcements/${id}`, ctx),
}

/* ── 12.4 Quiz ─────────────────────────────────────────────────────── */

export const quizzes = {
  /**
   * La même route renvoie `QuizResource` côté gestionnaire et
   * `StudentQuizResource` côté étudiant : deux accesseurs typés plutôt
   * qu'une union, pour que l'écran consomme exactement sa ressource.
   */
  listForTeacher: (classroomId: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiQuiz>>(`/classes/${classroomId}/quizzes`, ctx),

  listForStudent: (classroomId: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiStudentQuiz>>(`/classes/${classroomId}/quizzes`, ctx),

  showForTeacher: (id: number, ctx?: Ctx) => api.get<ApiQuiz>(`/quizzes/${id}`, ctx),

  showForStudent: (id: number, ctx?: Ctx) => api.get<ApiStudentQuiz>(`/quizzes/${id}`, ctx),

  create: (classroomId: number, payload: unknown, ctx?: Ctx) =>
    api.post<ApiQuiz>(`/classes/${classroomId}/quizzes`, payload, ctx),

  update: (id: number, payload: Record<string, unknown>, ctx?: Ctx) =>
    api.patch<ApiQuiz>(`/quizzes/${id}`, payload, ctx),

  destroy: (id: number, ctx?: Ctx) => api.delete<void>(`/quizzes/${id}`, ctx),

  publish: (id: number, ctx?: Ctx) => api.post<ApiQuiz>(`/quizzes/${id}/publish`, undefined, ctx),

  /** F-IA-04 : déverrouille la publication d'un quiz IA. */
  review: (id: number, ctx?: Ctx) => api.post<ApiQuiz>(`/quizzes/${id}/review`, undefined, ctx),

  startAttempt: (id: number, ctx?: Ctx) =>
    api.post<ApiAttemptStart>(`/quizzes/${id}/attempts`, undefined, ctx),

  submitAttempt: (
    attemptId: number,
    payload: { answers: { question_id: number; selected_option_ids: number[] }[] },
    ctx?: Ctx,
  ) => api.post<ApiAttemptResult>(`/attempts/${attemptId}/submit`, payload, ctx),

  attempt: (attemptId: number, ctx?: Ctx) => api.get<ApiAttemptResult>(`/attempts/${attemptId}`, ctx),

  results: (id: number, ctx?: Ctx) => api.get<ApiQuizResults>(`/quizzes/${id}/results`, ctx),

  /** F-EVAL-05 : export CSV authentifié, jamais un lien nu. */
  exportResults: (id: number, ctx?: Ctx) => api.download(`/quizzes/${id}/results/export`, `quiz-${id}.csv`, ctx),
}

/* ── 12.4 Intelligence artificielle ─────────────────────────────────── */

export const ai = {
  /** 202 : la tâche est mise en file, le client suit `job()`. */
  generate: (classroomId: number, file: File, target: 'quiz' | 'flashcard' = 'quiz', ctx?: Ctx) => {
    const form = new FormData()
    form.append('file', file)
    form.append('target', target)
    return api.postForm<ApiAiJob>(`/classes/${classroomId}/ai/generate`, form, ctx)
  },

  job: (jobId: number, ctx?: Ctx) => api.get<ApiAiJob>(`/ai/jobs/${jobId}`, ctx),
}

/* ── 12.5 Devoirs ──────────────────────────────────────────────────── */

export const assignments = {
  list: (classroomId: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiAssignment>>(`/classes/${classroomId}/assignments`, ctx),

  show: (id: number, ctx?: Ctx) => api.get<ApiAssignment>(`/assignments/${id}`, ctx),

  create: (
    classroomId: number,
    payload: { title: string; instructions?: string | null; due_at?: string | null },
    ctx?: Ctx,
  ) => api.post<ApiAssignment>(`/classes/${classroomId}/assignments`, payload, ctx),

  update: (id: number, payload: Record<string, unknown>, ctx?: Ctx) =>
    api.patch<ApiAssignment>(`/assignments/${id}`, payload, ctx),

  destroy: (id: number, ctx?: Ctx) => api.delete<void>(`/assignments/${id}`, ctx),

  submit: (id: number, file: File, ctx?: Ctx) => {
    const form = new FormData()
    form.append('file', file)
    return api.postForm<ApiSubmission>(`/assignments/${id}/submissions`, form, ctx)
  },

  /** Rendus reçus — reserve au proprietaire de la classe (F-DEV-03). */
  submissions: (id: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiSubmission>>(`/assignments/${id}/submissions`, ctx),

  /** F-DEV-03 : note et commentaire d'un rendu. */
  grade: (submissionId: number, payload: { grade?: number | null; feedback?: string | null }, ctx?: Ctx) =>
    api.patch<ApiSubmission>(`/submissions/${submissionId}`, payload, ctx),

  downloadSubmission: (submissionId: number, fileName: string, ctx?: Ctx) =>
    api.download(`/submissions/${submissionId}/download`, fileName, ctx),
}

/* ── 12.5 Progression, échéances, partenaires, notifications ──────── */

export const progression = {
  me: (ctx?: Ctx) => api.get<ApiStudentProgress>('/me/progress', ctx),

  classroom: (classroomId: number, ctx?: Ctx) =>
    api.get<ApiClassroomProgress>(`/classes/${classroomId}/progress`, ctx),

  deadlines: (ctx?: Ctx) => api.get<ListResponse<ApiDeadline>>('/me/deadlines', ctx),
}

export const partners = {
  profile: (ctx?: Ctx) => api.get<ApiPartnerProfile>('/me/partner-profile', ctx),

  /** `opt_in` est requis par l'API (PUT complet) : le client renvoie l'état courant. */
  updateProfile: (
    payload: { opt_in: boolean; skills?: string[]; availability?: string[] },
    ctx?: Ctx,
  ) => api.put<ApiPartnerProfile>('/me/partner-profile', payload, ctx),

  candidates: (classroomId: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiPartnerCandidate>>(`/classes/${classroomId}/partners`, ctx),

  myRequests: (ctx?: Ctx) => api.get<ListResponse<ApiPartnerRequest>>('/me/partner-requests', ctx),

  request: (payload: { to_user_id: number; classroom_id: number }, ctx?: Ctx) =>
    api.post<ApiPartnerRequest>('/partner-requests', payload, ctx),

  respond: (requestId: number, status: 'accepted' | 'rejected', ctx?: Ctx) =>
    api.post<ApiPartnerRequest>(`/partner-requests/${requestId}/respond`, { status }, ctx),
}

export const notifications = {
  list: (ctx?: Ctx) =>
    api.get<{ data: ApiNotification[]; unread_count: number }>('/notifications', ctx),

  markRead: (id: number, ctx?: Ctx) => api.post<void>(`/notifications/${id}/read`, undefined, ctx),

  markAllRead: (ctx?: Ctx) => api.post<void>('/notifications/read-all', undefined, ctx),
}

/* ── 12.5 Flashcards ───────────────────────────────────────────────── */

export const flashcards = {
  list: (classroomId: number, ctx?: Ctx) =>
    api.get<ListResponse<ApiFlashcardDeck>>(`/classes/${classroomId}/flashcards`, ctx),

  /** F-QUI-08 : charge les cartes d'un deck publie avec l'etat de revision. */
  show: (deckId: number, ctx?: Ctx) => api.get<ApiFlashcardDeck>(`/flashcard-decks/${deckId}`, ctx),

  /** F-QUI-08 : memorise « su » (true) ou « a revoir » (false) pour une carte. */
  reviewCard: (deckId: number, cardId: number, known: boolean, ctx?: Ctx) =>
    api.post<{ data: { card_id: number; known: boolean } }>(
      `/flashcard-decks/${deckId}/cards/${cardId}/review`,
      { known },
      ctx,
    ),

  create: (
    classroomId: number,
    payload: { title: string; cards: { front: string; back: string }[] },
    ctx?: Ctx,
  ) => api.post<ApiFlashcardDeck>(`/classes/${classroomId}/flashcards`, payload, ctx),

  publish: (deckId: number, ctx?: Ctx) =>
    api.post<ApiFlashcardDeck | { message: string; requires_review: boolean }>(
      `/flashcard-decks/${deckId}/publish`,
      undefined,
      ctx,
    ),

  markReviewed: (deckId: number, ctx?: Ctx) =>
    api.post<ApiFlashcardDeck>(`/flashcard-decks/${deckId}/reviewed`, undefined, ctx),

  destroy: (deckId: number, ctx?: Ctx) => api.delete<void>(`/flashcard-decks/${deckId}`, ctx),
}

/* ── 12.6 Administration ───────────────────────────────────────────── */

export const admin = {
  users: (params: { q?: string; role?: string; page?: number } = {}, ctx?: Ctx) => {
    const search = new URLSearchParams()
    if (params.q) search.set('q', params.q)
    if (params.role) search.set('role', params.role)
    if (params.page) search.set('page', String(params.page))
    const query = search.toString()
    return api.get<Paginated<ApiAdminUser>>(`/admin/users${query ? `?${query}` : ''}`, ctx)
  },

  pendingUsers: (ctx?: Ctx) => api.get<ListResponse<ApiAdminUser>>('/admin/users/pending', ctx),

  updateUser: (
    id: number,
    payload: { role?: string; is_active?: boolean },
    ctx?: Ctx,
  ) => api.patch<ApiAdminUser>(`/admin/users/${id}`, payload, ctx),

  classes: (ctx?: Ctx) => api.get<ListResponse<ApiAdminClassroom>>('/admin/classes', ctx),

  transferClass: (id: number, teacherId: number, ctx?: Ctx) =>
    api.post<{ message: string; teacher_id: number }>(
      `/admin/classes/${id}/transfer`,
      { teacher_id: teacherId },
      ctx,
    ),

  archiveClass: (id: number, ctx?: Ctx) =>
    api.post<{ status: string }>(`/admin/classes/${id}/archive`, undefined, ctx),

  aiProviders: (ctx?: Ctx) => api.get<ListResponse<ApiAiProvider>>('/admin/ai/providers', ctx),

  updateAiProvider: (
    id: number,
    payload: { priority?: number; enabled?: boolean; daily_limit?: number },
    ctx?: Ctx,
  ) => api.patch<ApiAiProvider>(`/admin/ai/providers/${id}`, payload, ctx),

  resetAiQuota: (id: number, ctx?: Ctx) =>
    api.post<{ used_today: number }>(`/admin/ai/providers/${id}/reset-quota`, undefined, ctx),

  auditLogs: (
    params: { action?: string; user_id?: number; from?: string; to?: string; page?: number } = {},
    ctx?: Ctx,
  ) => {
    const search = new URLSearchParams()
    if (params.action) search.set('action', params.action)
    if (params.user_id) search.set('user_id', String(params.user_id))
    if (params.from) search.set('from', params.from)
    if (params.to) search.set('to', params.to)
    if (params.page) search.set('page', String(params.page))
    const query = search.toString()
    return api.get<Paginated<ApiAuditLog>>(`/admin/audit-logs${query ? `?${query}` : ''}`, ctx)
  },

  stats: (ctx?: Ctx) => api.get<ApiStats>('/admin/stats', ctx),
}
