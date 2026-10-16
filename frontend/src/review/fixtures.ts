import type { ApiUser, Locale } from "../lib/types";

export type ReviewRole = "student" | "delegate" | "teacher" | "admin";
export type ReviewState = "normal" | "empty" | "loading" | "error";
export const REVIEW_ROLES: ReviewRole[] = ["student", "delegate", "teacher", "admin"];
export const ids = {
  student: 90001,
  delegate: 90002,
  teacher: 90003,
  admin: 90004,
  peer: 90005,
  group: 101,
  archived: 102,
  legacy: 103,
  offering: 201,
  module: 301,
  assessment: 401,
  year: 501,
  assignment: 601,
  feedback: 602,
  quiz: 701,
  draft: 702,
  deck: 801,
  thread: 901,
};
export const iso = (days = 0) => new Date(Date.now() + days * 86400000).toISOString();

export function reviewUser(role: ReviewRole, locale: Locale): ApiUser {
  const names = {
    student: "Camille Exemple",
    delegate: "Noor Démonstration",
    teacher: "Morgan Exemple",
    admin: "Sam Démonstration",
  };
  return {
    id: ids[role],
    display_name: names[role],
    role: role === "delegate" ? "student" : role,
    email: `v2.${role}@example.invalid`,
    locale,
    is_active: true,
    last_login_at: null,
    initials: names[role]
      .split(" ")
      .map((p) => p[0])
      .join(""),
  };
}

/** Explicitly fictional fixture graph. No production roster, grades, IDs or messages. */
export function makeFixtures(role: ReviewRole, locale: Locale) {
  const user = reviewUser(role, locale);
  const learners = [
    reviewUser("student", locale),
    reviewUser("delegate", locale),
    {
      ...reviewUser("student", locale),
      id: ids.peer,
      display_name: "Ariane Solène Démonstration des Horizons — Exemple de nom long",
      email: "v2.peer@example.invalid",
      initials: "AS",
    },
  ];
  const teacher = reviewUser("teacher", locale),
    admin = reviewUser("admin", locale);
  const group = {
    id: ids.group,
    name: "Groupe Horizon — Démonstration V2",
    official_code: "REVIEW-V2",
    school_year: "Année de démonstration",
    academic_year_id: ids.year,
    status: "active",
    is_read_only: false,
    module_only_access: false,
    filiere: "Développement — Exemple",
    level: "2",
    join_enabled: true,
    delegate_notices_enabled: true,
    coordinator_id: teacher.id,
    coordinator_can_manage_roster: false,
    roster_version: 3,
  };
  const offering = {
    id: ids.offering,
    classroom_id: group.id,
    module_id: ids.module,
    module_name:
      "Communication professionnelle et collaboration interdisciplinaire — Module synthétique",
    module_code: "RV-101",
    status: "active",
  };
  const year = {
    id: ids.year,
    name: group.school_year,
    starts_on: iso(-120).slice(0, 10),
    ends_on: iso(240).slice(0, 10),
    status: "active",
  };
  const delegates = [
    {
      id: 951,
      classroom_id: group.id,
      student_id: ids.delegate,
      display_name: learners[1].display_name,
      ends_at: iso(120),
    },
  ];
  const overview = {
    groups: [
      group,
      {
        ...group,
        id: ids.archived,
        name: "Groupe précédent — Exemple archivé",
        official_code: "REVIEW-OLD",
        status: "archived",
        is_read_only: true,
      },
    ],
    offerings: [offering],
    available_offerings: [
      {
        id: offering.id,
        module_name: offering.module_name,
        classroom_name: group.name,
        school_year: group.school_year,
      },
    ],
    years: [year],
    modules: [
      { id: ids.module, code: offering.module_code, name: offering.module_name, status: "active" },
    ],
    teachers: [
      {
        id: 961,
        offering_id: offering.id,
        teacher_id: teacher.id,
        display_name: teacher.display_name,
      },
    ],
    delegates,
  };
  const classroom = {
    id: ids.legacy,
    name: "Archives pédagogiques — Groupe de démonstration",
    subject: "Entraînement synthétique",
    group_label: "REVIEW",
    school_year: "Année précédente — exemple",
    status: "active",
    archived_at: null,
    is_read_only: false,
    join_code: "REVIEW01",
    join_enabled: true,
    teacher: { id: teacher.id, display_name: teacher.display_name },
    membership: { status: "accepted" },
    members_count: learners.length,
    created_at: iso(-60),
  };
  const materials = [
    {
      id: 1101,
      classroom_id: group.id,
      title: "Grille de lecture des situations de communication — Document de démonstration",
      chapter: "Ressources synthétiques",
      type: "file",
      url: null,
      has_file: true,
      download_endpoint: "/materials/1101/download",
      file_name: "grille-de-lecture-exemple.pdf",
      mime_type: "application/pdf",
      file_size: 128000,
      uploaded_by: teacher,
      created_at: iso(-2),
    },
    {
      id: 1102,
      classroom_id: group.id,
      title: "Collaborer dans une équipe éducative",
      chapter: "Pour aller plus loin",
      type: "link",
      url: "https://example.invalid/v2-resource",
      has_file: false,
      download_endpoint: null,
      file_name: null,
      mime_type: null,
      file_size: null,
      uploaded_by: teacher,
      created_at: iso(-1),
    },
  ];
  const announcements = [
    {
      id: 1201,
      classroom_id: group.id,
      classroom: { id: group.id, name: group.name },
      title: "Nouvelles pistes pour votre analyse — Annonce synthétique",
      body: "Comparez les objectifs, les publics et les contraintes. Cette annonce est fictive : elle illustre la présentation des informations et ne provient d’aucune conversation réelle.",
      pinned: true,
      author: teacher,
      created_at: iso(-1),
    },
  ];
  const assignments = [
    {
      id: ids.assignment,
      classroom_id: group.id,
      title:
        "Analyse comparative des stratégies de communication dans les organisations éducatives contemporaines",
      instructions:
        "Données de démonstration uniquement.\n\n1. Décrire les situations\nPrésentez les contextes, les interlocuteurs et les objectifs.\n\n2. Construire une comparaison argumentée\nComparez les pratiques et proposez une synthèse.\n\nLe choix et l’envoi de fichier sont simulés localement dans cette revue.",
      due_at: iso(3),
      is_overdue: false,
      publication_status: "published",
      submissions_count: 2,
      my_submission: null as null | Record<string, unknown>,
      author: teacher,
      created_at: iso(-3),
    },
    {
      id: ids.feedback,
      classroom_id: group.id,
      title: "Étude de cas — Travail rendu et retour individuel synthétiques",
      instructions: "Exemple de retour après dépôt. Aucun travail réel n’est affiché.",
      due_at: iso(-2),
      is_overdue: false,
      publication_status: "closed",
      submissions_count: 1,
      my_submission: {
        id: 1302,
        assignment_id: ids.feedback,
        file_name: "analyse-communication-exemple.txt",
        mime_type: "text/plain",
        file_size: 4200,
        download_endpoint: "/submissions/1302/download",
        is_late: false,
        grade: 16,
        feedback:
          "Exemple de retour : argumentation claire et synthèse structurée. Cette note est fictive.",
        submitted_at: iso(-3),
      },
      author: teacher,
      created_at: iso(-8),
    },
  ];
  const entries = learners.map((person, i) => ({
    student_id: person.id,
    display_name: person.display_name,
    score: i === 0 ? "14" : i === 1 ? "0" : null,
    status: i < 2 ? "graded" : "ungraded",
    feedback: i === 0 ? "Comparaison structurée — appréciation synthétique." : null,
  }));
  const assessment = {
    id: ids.assessment,
    offering_id: offering.id,
    title: "Analyse de situation — Évaluation individuelle de démonstration",
    type: "exam",
    assessed_on: iso(-2).slice(0, 10),
    maximum_score: 20,
    coefficient: 1,
    state: "draft",
    version: 3,
    roster_version: 3,
    draft_revision_id: 1401,
    published_revision_id: null as number | null,
  };
  const gradeEditor = {
    assessment,
    entries,
    current_roster_version: 3,
    read_only: false,
    history: [{ sequence: 1, reason: "Exemple de brouillon", published_at: null as string | null }],
  };
  const grades = [
    {
      id: ids.assessment,
      classroom_id: group.id,
      classroom_name: group.name,
      offering_id: offering.id,
      title: assessment.title,
      module_name: offering.module_name,
      school_year: group.school_year,
      assessed_on: assessment.assessed_on,
      score: 14,
      maximum_score: 20,
      coefficient: 1,
      status: "graded",
      feedback: "Résultat entièrement fictif pour la revue du design.",
      sequence: 1,
    },
  ];
  const questions = [
    {
      id: 1501,
      statement: "Quel élément favorise une communication claire ? — Question synthétique",
      type: "single",
      explanation: "Une écoute attentive aide à comprendre le contexte.",
      position: 0,
      options: [
        { id: 1511, label: "Écouter et reformuler", is_correct: true },
        { id: 1512, label: "Ignorer le contexte", is_correct: false },
      ],
    },
  ];
  const quiz = {
    id: ids.quiz,
    classroom_id: group.id,
    title: "Quiz d’entraînement — Situations de communication",
    status: "published",
    source: "manual",
    reviewed: true,
    can_publish: true,
    time_limit_min: 15,
    max_attempts: 2,
    shuffle: true,
    show_answers: true,
    published_at: iso(-2),
    due_at: iso(5),
    questions_count: 1,
    questions,
    created_at: iso(-3),
    classroom: { id: group.id, name: group.name, subject: classroom.subject },
    attempts: [],
    attempts_used: 0,
  };
  const deck = {
    id: ids.deck,
    classroom_id: group.id,
    title: "Réviser les notions de communication — Flashcards synthétiques",
    source: "manual",
    status: "published",
    reviewed: true,
    cards_count: 3,
    cards: [
      {
        id: 1611,
        front: "Écoute active",
        back: "Comprendre, reformuler et vérifier sa compréhension — exemple.",
        known: null as boolean | null,
      },
      {
        id: 1612,
        front: "Communication non verbale",
        back: "Gestes, posture et expression — exemple synthétique.",
        known: null as boolean | null,
      },
      {
        id: 1613,
        front: "Travail en équipe",
        back: "Partager les objectifs et les responsabilités.",
        known: null as boolean | null,
      },
    ],
    created_at: iso(-4),
  };
  const threads = [
    {
      id: ids.thread,
      classroom_id: group.id,
      subject: "Question sur l’analyse — Conversation de démonstration",
      kind: "private_question",
      status: "open",
      updated_at: iso(-1),
      created_by: learners[0].id,
      latest_message_id: 1702,
      last_read_message_id: 1701,
    },
    {
      id: 902,
      classroom_id: group.id,
      subject: "Organisation du groupe — Annonce synthétique",
      kind: "class_notice",
      status: "open",
      updated_at: iso(-2),
      created_by: ids.delegate,
      latest_message_id: 1703,
      last_read_message_id: 1703,
    },
  ];
  const messages = [
    {
      id: 1701,
      author_id: learners[0].id,
      display_name: learners[0].display_name,
      body: "Bonjour, pouvez-vous préciser les attentes de la synthèse ? Message fictif de revue.",
      created_at: iso(-1),
    },
    {
      id: 1702,
      author_id: teacher.id,
      display_name: teacher.display_name,
      body: "Comparez les deux situations et justifiez votre proposition. Réponse synthétique, sans information privée.",
      created_at: iso(),
    },
  ];
  const notifications = [
    {
      id: 1801,
      type: "assignment_published",
      is_read: false,
      payload: { title: assignments[0].title, url: `/app/assignments/${ids.assignment}` },
      created_at: iso(-1),
    },
    {
      id: 1802,
      type: "official_grade_published",
      is_read: false,
      payload: { url: "/app/school/grades" },
      created_at: iso(-2),
    },
    {
      id: 1803,
      type: "school_message_received",
      is_read: true,
      payload: { url: `/app/school/messages/${ids.thread}` },
      created_at: iso(-3),
    },
  ];
  const users = [
    admin,
    teacher,
    ...learners,
    {
      ...teacher,
      id: 90006,
      display_name: "Alex Démonstration — Candidature",
      email: "v2.candidate@example.invalid",
      role: "pending",
      is_active: false,
    },
  ].map((p) => ({
    ...p,
    role_locked: p.role !== "pending",
    role_candidate: p.role === "pending" ? "teacher" : null,
    verification_source: null,
    created_at: iso(-60),
  }));
  return {
    user,
    learners,
    teacher,
    admin,
    overview,
    classroom,
    materials,
    announcements,
    assignments,
    gradeEditor,
    grades,
    quiz,
    deck,
    threads,
    messages,
    notifications,
    users,
    requests: [
      {
        id: 1901,
        teacher_id: teacher.id,
        offering_id: null,
        display_name: "Morgan Exemple",
        module_name: "Module de démonstration",
        classroom_name: group.name,
        requested_group_code: "REVIEW-B",
        requested_module_code: "RV-202",
        status: "pending",
        reason: "Exemple de demande d’affectation — aucune affectation réelle.",
      },
    ],
    reports: [
      {
        id: 1951,
        reason: "Signalement synthétique pour vérifier le support et les états de résolution.",
        response: null as string | null,
        status: "open",
      },
    ],
    providers: [
      {
        id: 1961,
        name: "openai",
        priority: 1,
        enabled: true,
        daily_limit: 30,
        used_today: 8,
        has_key: false,
      },
      {
        id: 1962,
        name: "groq",
        priority: 2,
        enabled: false,
        daily_limit: 20,
        used_today: 0,
        has_key: false,
      },
    ],
    audit: [
      {
        id: 1971,
        action: "school.roster.import",
        user: { id: admin.id, display_name: admin.display_name },
        context: { demonstration: true, count: 3 },
        ip: null,
        created_at: iso(-1),
      },
      {
        id: 1972,
        action: "school.assignment.create",
        user: { id: admin.id, display_name: admin.display_name },
        context: { demonstration: true },
        ip: null,
        created_at: iso(-2),
      },
    ],
    prefs: { email_digest: false, types: {} as Record<string, boolean> },
  };
}
