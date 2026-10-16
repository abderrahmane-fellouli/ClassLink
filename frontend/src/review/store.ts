import { ApiError, type RequestOptions } from "../lib/api";
import type { Locale } from "../lib/types";
import { fr } from "../i18n/fr";
import { en } from "../i18n/en";
import { ids, iso, makeFixtures, type ReviewRole, type ReviewState } from "./fixtures";
import type { ReviewTransport } from "./transport";

/** In-memory presentation adapter. It has no fetch, credentials, storage or server client. */
export class ReviewStore implements ReviewTransport {
  data: ReturnType<typeof makeFixtures>;
  locale: Locale;
  readonly actions: string[] = [];
  readonly unknownReads = new Set<string>();
  private nextId = 3000;

  constructor(
    readonly role: ReviewRole,
    locale: Locale,
    readonly state: ReviewState,
    private notice: (message: string) => void = () => {},
  ) {
    this.locale = locale;
    this.data = makeFixtures(role, locale);
  }

  private get dictionary() {
    return this.locale === "en" ? en : fr;
  }
  private page<T>(rows: T[]) {
    const data = this.state === "empty" ? [] : rows;
    return {
      data,
      current_page: 1,
      last_page: 1,
      total: data.length,
      meta: { current_page: 1, last_page: 1, total: data.length },
    };
  }

  async request(path: string, options: RequestOptions): Promise<unknown> {
    this.locale = options.locale ?? this.locale;
    if (options.signal?.aborted) throw new DOMException("Aborted", "AbortError");
    if (path.startsWith("/auth/")) throw new ApiError(403, this.dictionary["review.noAuth"]);
    if (this.state === "loading")
      return new Promise((_, reject) =>
        options.signal?.addEventListener(
          "abort",
          () => reject(new DOMException("Aborted", "AbortError")),
          { once: true },
        ),
      );
    if (this.state === "error") throw new ApiError(503, this.dictionary["review.errorMessage"]);
    const url = new URL(path, "https://review.example.invalid");
    const value =
      (options.method ?? "GET") === "GET" ? this.read(url) : this.write(url.pathname, options);
    return structuredClone(value);
  }

  async download(_path: string, _fileName: string): Promise<void> {
    this.actions.push("LOCAL download demonstration");
    this.notice(this.dictionary["review.download"]);
  }

  private read(url: URL): unknown {
    const p = url.pathname,
      d = this.data,
      page = <T>(rows: T[]) => this.page(rows);
    if (p === "/me") return d.user;
    if (p === "/school")
      return this.state === "empty"
        ? {
            ...d.overview,
            groups: [],
            offerings: [],
            teachers: [],
            delegates: [],
            available_offerings: [],
          }
        : { ...d.overview, delegates: d.overview.delegates };
    if (p === "/notifications")
      return {
        ...page(d.notifications),
        unread_count: this.state === "empty" ? 0 : d.notifications.filter((n) => !n.is_read).length,
      };
    if (p === "/me/notification-preferences") return d.prefs;
    if (p === "/me/announcements" || /\/announcements$/.test(p)) return page(d.announcements);
    if (p === "/school/deadlines")
      return page(
        d.assignments
          .filter((a) => a.publication_status !== "closed")
          .map((a) => ({
            kind: "assignment",
            id: a.id,
            title: a.title,
            due_at: a.due_at,
            url: `/app/assignments/${a.id}`,
          }))
          .concat([
            {
              kind: "assessment",
              id: ids.assessment,
              title: d.gradeEditor.assessment.title,
              due_at: iso(5).slice(0, 10),
              url: "/app/school/grades",
            },
          ]),
      );
    if (p === "/me/deadlines")
      return page(
        d.assignments.map((a) => ({
          kind: "assignment",
          id: a.id,
          title: a.title,
          classroom: d.overview.groups[0].name,
          due_at: a.due_at,
          is_overdue: false,
          url: `/app/assignments/${a.id}`,
        })),
      );
    if (p === "/school/people") {
      const role = url.searchParams.get("role"),
        search = (url.searchParams.get("search") ?? "").toLowerCase();
      return page(
        d.users.filter(
          (u) => (!role || u.role === role) && u.display_name.toLowerCase().includes(search),
        ),
      );
    }
    if (p.endsWith("/roster"))
      return page(
        d.learners
          .filter((u) =>
            u.display_name
              .toLowerCase()
              .includes((url.searchParams.get("search") ?? "").toLowerCase()),
          )
          .map((u, i) => ({
            id: 2100 + i,
            student_id: u.id,
            display_name: u.display_name,
            ...(this.role === "teacher" || this.role === "admin"
              ? { school_identifier: `SYN-${i + 1}` }
              : {}),
          })),
      );
    if (p.endsWith("/requests") && p.includes("/groups/"))
      return page([
        {
          id: 2201,
          student_id: ids.peer,
          display_name: "Alex Démonstration — Admission en attente",
          requested_at: iso(-1),
        },
      ]);
    if (p === "/school/assignment-requests") return page(d.requests);
    if (p.endsWith("/access-grants"))
      return page([
        {
          student_id: ids.peer,
          display_name: d.learners[2].display_name,
          reason: "Accès exceptionnel synthétique",
          expires_at: iso(10),
        },
      ]);
    if (p === "/school/support-contacts" || p.endsWith("/contacts"))
      return page([d.teacher, d.admin, ...d.learners]);
    if (p === "/school/reports") return page(d.reports);
    if (p === "/school/threads") return page(d.threads);
    if (/^\/school\/threads\/\d+$/.test(p))
      return {
        thread: d.threads.find((t) => t.id === Number(p.split("/").at(-1))) ?? d.threads[0],
        messages: this.state === "empty" ? [] : d.messages,
        participants: [d.user, d.teacher, ...(this.role === "delegate" ? [d.admin] : [])].map(
          (u) => ({ id: u.id, display_name: u.display_name, eligibility: "active" }),
        ),
      };
    if (/^\/school\/assessments\/\d+$/.test(p))
      return this.state === "empty" ? { ...d.gradeEditor, entries: [] } : d.gradeEditor;
    if (p.endsWith("/assessments")) return page([d.gradeEditor.assessment]);
    if (p === "/school/my-grades") return page(d.grades);
    if (/^\/school\/my-grades\/\d+$/.test(p)) return d.grades[0];
    const tool = p.match(/\/tools\/(materials|assignments|announcements|quizzes|flashcards)$/)?.[1];
    if (tool)
      return page(
        {
          materials: d.materials,
          assignments: d.assignments,
          announcements: d.announcements,
          quizzes: [
            d.quiz,
            {
              ...d.quiz,
              id: ids.draft,
              status: "draft",
              title: "Quiz de démonstration — Brouillon",
            },
          ],
          flashcards: [d.deck],
        }[tool as "materials"],
      );
    if (p === "/classes" || p === "/admin/classes")
      return page([{ ...d.classroom, join_code: "REVIEW01", join_enabled: true }]);
    if (/^\/classes\/\d+$/.test(p)) return d.classroom;
    if (p.endsWith("/members") || p.endsWith("/join-requests"))
      return page(
        d.learners.map((u, i) => ({
          membership_id: 2300 + i,
          status: p.endsWith("/join-requests") ? "pending" : "accepted",
          requested_at: iso(-3),
          decided_at: iso(-2),
          user: u,
        })),
      );
    if (p === "/join-requests/mine")
      return page([
        {
          id: 2301,
          classroom_id: ids.legacy,
          status: "accepted",
          requested_at: iso(-3),
          decided_at: iso(-2),
          classroom: { ...d.classroom, teacher_name: d.teacher.display_name },
        },
      ]);
    if (p.endsWith("/materials")) return page(d.materials);
    if (p.endsWith("/assignments")) return page(d.assignments);
    if (/^\/assignments\/\d+$/.test(p))
      return d.assignments.find((a) => a.id === Number(p.split("/").at(-1))) ?? d.assignments[0];
    if (p.endsWith("/submissions"))
      return page(
        d.learners
          .slice(0, 2)
          .map((u, i) => ({
            id: 2400 + i,
            assignment_id: ids.assignment,
            file_name: `synthese-exemple-${i + 1}.txt`,
            mime_type: "text/plain",
            file_size: 4200,
            download_endpoint: `/submissions/${2400 + i}/download`,
            is_late: i === 1,
            grade: i === 0 ? 14 : null,
            feedback: i === 0 ? "Retour synthétique : comparaison claire." : null,
            submitted_at: iso(-1),
            student: u,
          })),
      );
    if (p.endsWith("/quizzes"))
      return page([
        d.quiz,
        {
          ...d.quiz,
          id: ids.draft,
          status: "draft",
          title: "Brouillon de quiz — Données synthétiques",
        },
      ]);
    if (p.endsWith("/attempts/active")) return { attempt: null };
    if (p.endsWith("/results"))
      return {
        quiz: d.quiz,
        summary: {
          students: 3,
          participants: 2,
          participation_rate: 67,
          average_percentage: 75,
          highest_percentage: 100,
          lowest_percentage: 50,
          attempts_count: 2,
        },
        distribution: [
          { min: 0, max: 50, count: 1 },
          { min: 50, max: 100, count: 1 },
        ],
        attempts: d.learners
          .slice(0, 2)
          .map((u, i) => ({
            id: 2500 + i,
            attempt_no: 1,
            score: i + 1,
            max_score: 2,
            percentage: (i + 1) * 50,
            expired: false,
            started_at: iso(-1),
            submitted_at: iso(-1),
            student: u,
          })),
        most_missed: [
          {
            question_id: 1501,
            statement: d.quiz.questions[0].statement,
            misses: 1,
            attempts: 2,
            miss_rate: 50,
          },
        ],
      };
    if (/^\/quizzes\/\d+(\/editor)?$/.test(p))
      return {
        ...d.quiz,
        ...(p.includes(String(ids.draft))
          ? {
              id: ids.draft,
              title: "Brouillon — Quiz de démonstration",
              status: "draft",
              published_at: null,
            }
          : {}),
      };
    if (p.startsWith("/attempts/")) return this.attempt();
    if (p.endsWith("/flashcards")) return page([d.deck]);
    if (p.startsWith("/flashcard-decks/"))
      return this.state === "empty" ? { ...d.deck, cards: [], cards_count: 0 } : d.deck;
    if (p === "/me/partner-profile")
      return {
        user_id: d.user.id,
        opt_in: true,
        skills: ["Communication", "Analyse", "Collaboration"],
        availability: ["Mardi 14h", "Jeudi 10h"],
      };
    if (p === "/me/partner-requests") return page([]);
    if (p.endsWith("/partners"))
      return page(
        d.learners
          .filter((u) => u.id !== d.user.id)
          .map((u) => ({
            user_id: u.id,
            id: u.id,
            display_name: u.display_name,
            skills: ["Analyse", "Écoute"],
            shared_skills: ["Analyse"],
            availability: ["Mardi 14h"],
            compatibility: 85,
            score: 85,
          })),
      );
    if (p === "/me/progress")
      return {
        totals: { quizzes_taken: 2, attempts: 3, average_percentage: 75, best_percentage: 100 },
        trend: {
          direction: "up",
          delta_percentage_points: 25,
          series: [
            { submitted_at: iso(-3), percentage: 50 },
            { submitted_at: iso(-1), percentage: 100 },
          ],
        },
        history:
          this.state === "empty"
            ? []
            : [
                {
                  attempt_id: 2500,
                  quiz_id: ids.quiz,
                  quiz_title: d.quiz.title,
                  classroom_id: ids.group,
                  attempt_no: 1,
                  score: 1,
                  max_score: 2,
                  percentage: 50,
                  submitted_at: iso(-3),
                },
                {
                  attempt_id: 2501,
                  quiz_id: ids.quiz,
                  quiz_title: d.quiz.title,
                  classroom_id: ids.group,
                  attempt_no: 2,
                  score: 2,
                  max_score: 2,
                  percentage: 100,
                  submitted_at: iso(-1),
                },
              ],
      };
    if (p.endsWith("/progress"))
      return {
        totals: { students: 3, quizzes: 2, attempts: 3, participation_rate: 67, class_average: 75 },
        inactive_student_ids: [ids.peer],
        inactive_students: [d.learners[2]],
        most_missed: [
          {
            question_id: 1501,
            statement: d.quiz.questions[0].statement,
            quiz_title: d.quiz.title,
            misses: 1,
            attempts: 2,
            miss_rate: 50,
          },
        ],
      };
    if (p === "/admin/users" || p === "/admin/users/pending")
      return page(
        d.users.filter(
          (u) =>
            (p.endsWith("pending")
              ? u.role === "pending"
              : !url.searchParams.get("role") || u.role === url.searchParams.get("role")) &&
            u.display_name.toLowerCase().includes((url.searchParams.get("q") ?? "").toLowerCase()),
        ),
      );
    if (p === "/admin/ai/providers") return page(d.providers);
    if (p === "/admin/audit-logs") return page(d.audit);
    if (p === "/admin/stats")
      return {
        users: {
          total: 6,
          by_role: { student: 3, teacher: 1, admin: 1, pending: 1 },
          active: 5,
          pending: 1,
        },
        classes: { total: 2, active: 1, archived: 1 },
        memberships: { pending: 1, accepted: 3 },
        quizzes: { total: 2, published: 1, draft: 1 },
        ai_jobs: { total: 0, failed: 0 },
      };
    if (p.startsWith("/ai/jobs/"))
      return {
        id: 2901,
        classroom_id: ids.legacy,
        target: "quiz",
        status: "done",
        provider: null,
        original_name: "exemple-synthetique.txt",
        page_count: 1,
        error: null,
        quiz_id: ids.draft,
        deck_id: null,
        started_at: iso(),
        finished_at: iso(),
        created_at: iso(),
      };
    this.unknownReads.add(p);
    throw new ApiError(404, `${this.dictionary["review.unsupported"]} (${p})`);
  }

  private attempt() {
    return {
      id: 2500,
      quiz_id: ids.quiz,
      quiz_title: this.data.quiz.title,
      attempt_no: 1,
      score: 1,
      max_score: 1,
      percentage: 100,
      started_at: iso(),
      submitted_at: iso(),
      expired: false,
      show_answers: true,
      answers: this.data.quiz.questions.map((q) => ({
        question_id: q.id,
        statement: q.statement,
        type: q.type,
        is_correct: true,
        awarded_score: 1,
        selected_option_ids: [q.options[0].id],
        explanation: q.explanation,
        options: q.options,
      })),
    };
  }

  private write(p: string, options: RequestOptions): unknown {
    const d = this.data;
    const body: Record<string, unknown> = options.formData
      ? Object.fromEntries(options.formData.entries())
      : options.body && typeof options.body === "object"
        ? (options.body as Record<string, unknown>)
        : {};
    const method = options.method ?? "POST";
    this.actions.push(`LOCAL ${method} ${p}`);
    this.notice(this.dictionary["review.localAction"]);
    if (p === "/me") {
      Object.assign(d.user, body);
      return d.user;
    }
    if (p === "/me/notification-preferences") {
      Object.assign(d.prefs, body);
      return d.prefs;
    }
    if (p === "/school/notices/preview")
      return {
        preview_id: "synthetic-audience",
        recipient_count: d.learners.length,
        recipients_preview: d.learners,
        breakdown: [{ classroom_id: ids.group, recipient_count: d.learners.length }],
      };
    if (p === "/school/notices/publish") return { id: ++this.nextId, demonstration: true };
    if (p === "/school/years") {
      const year = {
        ...d.overview.years[0],
        id: ++this.nextId,
        name: String(body.name ?? "Année synthétique"),
        starts_on: String(body.starts_on ?? iso().slice(0, 10)),
        ends_on: String(body.ends_on ?? iso(365).slice(0, 10)),
      };
      d.overview.years.push(year);
      return year;
    }
    if (p === "/school/groups") {
      const group = {
        ...d.overview.groups[0],
        id: ++this.nextId,
        name: String(body.name ?? "Groupe synthétique"),
        official_code: String(body.official_code ?? "SYN-NEW"),
        academic_year_id: Number(body.academic_year_id ?? ids.year),
      };
      d.overview.groups.push(group);
      return group;
    }
    if (p === "/school/modules") {
      const module = {
        id: ++this.nextId,
        code: String(body.code ?? "SYN-NEW"),
        name: String(body.name ?? "Module synthétique"),
        status: "active",
      };
      d.overview.modules.push(module);
      return module;
    }
    if (p.includes("/notifications/") || p === "/notifications/read-all") {
      d.notifications.forEach((n) => {
        n.is_read = true;
      });
      return undefined;
    }
    if (p.endsWith("/draft")) {
      if (Array.isArray(body.rows))
        d.gradeEditor.entries = structuredClone(body.rows) as typeof d.gradeEditor.entries;
      d.gradeEditor.assessment.version++;
      return { version: d.gradeEditor.assessment.version };
    }
    if (p.includes("/assessments/") && p.endsWith("/correction")) {
      d.gradeEditor.assessment.state = "correction";
      d.gradeEditor.assessment.draft_revision_id = 1402;
      d.gradeEditor.assessment.version++;
      return d.gradeEditor.assessment;
    }
    if (p.includes("/assessments/") && p.endsWith("/publish")) {
      d.gradeEditor.assessment.state = "published";
      d.gradeEditor.assessment.published_revision_id = 1402;
      d.gradeEditor.assessment.draft_revision_id = 0;
      d.gradeEditor.assessment.version++;
      return d.gradeEditor.assessment;
    }
    if (p.endsWith("/imports/preview"))
      return {
        batch_id: "synthetic-review-batch",
        errors: [{ row: 4, reason: "invalid_score" }],
        summary: { changed: 2, unchanged: 1, missing: 0 },
      };
    if (p.endsWith("/roster-imports/preview"))
      return {
        batch_id: "synthetic-review-roster",
        errors: [{ row: 4, reason: "invalid_roster_identity" }],
        new_accounts: 0,
        rows: d.learners
          .slice(0, 2)
          .map((u, i) => ({ student_identifier: `SYN-${i + 1}`, display_name: u.display_name })),
      };
    if (/^\/assignments\/\d+\/submissions$/.test(p)) {
      const assignment =
        d.assignments.find((a) => a.id === Number(p.split("/")[2])) ?? d.assignments[0];
      const file = body.file as File | undefined;
      assignment.my_submission = {
        id: 2401,
        assignment_id: assignment.id,
        file_name: file?.name ?? "exemple-synthetique.txt",
        file_size: file?.size ?? 100,
        mime_type: file?.type ?? "text/plain",
        download_endpoint: "/submissions/2401/download",
        is_late: false,
        grade: null,
        feedback: null,
        submitted_at: iso(),
      };
      return assignment.my_submission;
    }
    if (p === "/school/threads") {
      const thread = {
        ...d.threads[0],
        ...body,
        id: ++this.nextId,
        subject: String(body.subject ?? "Conversation synthétique"),
        created_by: d.user.id,
        updated_at: iso(),
      };
      d.threads.unshift(thread);
      return { id: thread.id };
    }
    if (/^\/school\/threads\/\d+\/messages$/.test(p)) {
      d.messages.push({
        id: ++this.nextId,
        author_id: d.user.id,
        display_name: d.user.display_name,
        body: String(body.body ?? "Message synthétique"),
        created_at: iso(),
      });
      return { id: this.nextId };
    }
    if (p.startsWith("/school/threads/") && p.endsWith("/resolve")) {
      d.threads.forEach((t) => {
        t.status = "resolved";
      });
      return {};
    }
    if (p.endsWith("/attempts"))
      return {
        attempt_id: 2500,
        attempt_no: 1,
        started_at: iso(),
        time_limit_min: 15,
        remaining_seconds: 900,
        questions: d.quiz.questions,
      };
    if (p.endsWith("/submit") && p.startsWith("/attempts/")) return this.attempt();
    if (p.includes("/cards/") && p.endsWith("/review")) {
      const card = d.deck.cards.find((c) => c.id === Number(p.split("/")[4]));
      if (card) card.known = Boolean(body.known);
      return { card_id: card?.id, known: Boolean(body.known) };
    }
    if (p.startsWith("/flashcard-decks/") && method === "PATCH") {
      Object.assign(d.deck, body);
      return d.deck;
    }
    if (p.endsWith("/ai/generate"))
      return { id: 2901, status: "done", quiz_id: ids.draft, target: "quiz" };
    if (p.startsWith("/quizzes/")) {
      Object.assign(d.quiz, body);
      return { ...d.quiz, id: ids.draft };
    }
    if (p.startsWith("/admin/ai/providers/")) {
      const provider = d.providers.find((v) => v.id === Number(p.split("/")[4]));
      if (provider) Object.assign(provider, p.endsWith("reset-quota") ? { used_today: 0 } : body);
      return provider;
    }
    if (p.startsWith("/admin/users/")) {
      const user = d.users.find((u) => u.id === Number(p.split("/")[3]));
      if (user) Object.assign(user, body);
      return user;
    }
    if (p.endsWith("/teachers")) return { id: ++this.nextId };
    if (p.endsWith("/invitation")) return { join_code: "REVIEW01", join_enabled: true };
    if (method === "DELETE" || options.empty) return undefined;
    return { id: ++this.nextId, ...body, saved: true, no_production_request: true };
  }
}
