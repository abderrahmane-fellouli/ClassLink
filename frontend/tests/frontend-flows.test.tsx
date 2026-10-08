import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import type { ReactNode } from "react";
import { I18nProvider } from "../src/i18n";
import { en } from "../src/i18n/en";
import { setStoredLocale } from "../src/lib/session";
import { ConfirmButton, Input } from "../src/components/UI";
import { AppShell } from "../src/components/AppShell";
import {
  AssignmentDetailScreen,
  DeadlinesScreen,
  FlashcardStudyScreen,
  JoinClassScreen,
  MyClassesScreen,
  PartnersScreen,
  QuizAttemptScreen,
  StudentDashboard,
} from "../src/screens/StudentScreens";
import {
  GradeScreen,
  TeacherClassScreen,
  QuizEditorScreen,
  QuizResultsScreen,
} from "../src/screens/TeacherClassScreens";
import { ClassProgressScreen } from "../src/screens/TeacherScreens";
import { AdminClassesScreen, AdminUsersScreen } from "../src/screens/AdminScreens";
import { ProfileScreen } from "../src/screens/ProfileScreen";
import { LoginScreen, MicrosoftCallbackScreen, PendingScreen } from "../src/screens/PublicScreens";
import { jsonResponse } from "./setup";

const auth = vi.hoisted(() => ({
  user: {
    id: 7,
    display_name: "Student",
    role: "student",
    initials: "ST",
    locale: "en",
  },
  role: "student",
  signOut: vi.fn().mockResolvedValue(undefined),
  updateProfile: vi.fn(),
  signInWithOtp: vi.fn(),
  microsoftRedirectUrl: () => "/api/auth/microsoft/redirect",
  adoptCallbackToken: vi.fn(),
}));
vi.mock("../src/context/AuthContext", () => ({ useAuth: () => auth }));

function mount(child: ReactNode, path = "/", pattern = path) {
  return render(
    <I18nProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={pattern} element={child} />
          <Route path="*" element={<p>Destination</p>} />
        </Routes>
      </MemoryRouter>
    </I18nProvider>,
  );
}
function mockApi(responses: Record<string, unknown>) {
  const fetcher = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const path = new URL(String(input), "http://localhost").pathname.replace(/^\/api/, "");
    const key = `${init?.method ?? "GET"} ${path}`;
    if (!(key in responses)) throw new Error(`Unexpected request: ${key}`);
    return responses[key] === null
      ? new Response(null, { status: 204 })
      : jsonResponse(responses[key]);
  });
  vi.stubGlobal("fetch", fetcher);
  return fetcher;
}
const classroom = {
  id: 1,
  name: "Web",
  subject: "React",
  group_label: "A",
  school_year: "2026",
  status: "active",
  is_read_only: false,
  join_code: "ABCDEFGH",
  join_enabled: true,
};
function manageResponses() {
  return {
    "GET /classes/1": classroom,
    ...Object.fromEntries(
      [
        "members",
        "join-requests",
        "announcements",
        "materials",
        "quizzes",
        "assignments",
        "flashcards",
      ].map((route) => [`GET /classes/1/${route}`, { data: [] }]),
    ),
  };
}
beforeEach(() => {
  setStoredLocale("en");
  auth.role = "student";
  auth.signOut.mockClear();
});

describe("Shared accessibility and navigation", () => {
  it("shows Microsoft-verified pending copy only after validating the server receipt", async () => {
    const receipt = "a".repeat(64);
    window.history.replaceState({}, "", `/pending#verification=${receipt}`);
    const fetcher = mockApi({
      "POST /auth/microsoft/pending-verification": {
        verification_source: "microsoft",
        role_candidate: "teacher",
        status: "pending",
      },
    });
    mount(<PendingScreen />, "/pending");
    expect(await screen.findByText(en["auth.teacherVerifiedPending"])).toBeInTheDocument();
    expect(window.location.hash).toBe("");
    expect(fetcher.mock.calls[0][1]?.body).toBe(JSON.stringify({ verification: receipt }));
  });

  it("a typed pending-page URL cannot claim Microsoft verification", async () => {
    window.history.replaceState({}, "", "/pending?verification_source=microsoft&role=teacher");
    const fetcher = mockApi({});
    mount(<PendingScreen />, "/pending");
    expect(screen.getByText(en["auth.pendingGeneric"])).toBeInTheDocument();
    expect(screen.queryByText(en["auth.teacherVerifiedPending"])).not.toBeInTheDocument();
    expect(fetcher).not.toHaveBeenCalled();
  });
  it("changes language immediately and preserves local preference when account saving fails", async () => {
    mockApi({ "GET /notifications": { data: [], unread_count: 0 } });
    auth.updateProfile.mockRejectedValueOnce(new Error("offline"));
    mount(
      <AppShell>
        <p>Content</p>
      </AppShell>,
    );
    await userEvent.selectOptions(
      screen.getAllByRole("combobox", { name: en["profile.language"] })[0],
      "fr",
    );
    expect(document.documentElement.lang).toBe("fr");
    expect(screen.getAllByText("Tableau de bord").length).toBeGreaterThan(0);
    expect(auth.updateProfile).toHaveBeenCalledWith({ locale: "fr" });
    expect(await screen.findByText(/la préférence du compte/)).toBeInTheDocument();
  });

  it("mobile menu exposes language and account controls and closes on Escape", async () => {
    mockApi({ "GET /notifications": { data: [], unread_count: 0 } });
    mount(
      <AppShell>
        <p>Content</p>
      </AppShell>,
    );
    const trigger = screen.getByRole("button", { name: "Navigation menu" });
    await userEvent.click(trigger);
    const dialog = screen.getByRole("dialog");
    expect(
      within(dialog).getByRole("combobox", { name: en["profile.language"] }),
    ).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Sign out" })).toBeInTheDocument();
    await userEvent.keyboard("{Escape}");
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });

  it("clears the OAuth fragment before validating the token and shows recovery on provider errors", async () => {
    window.history.replaceState({}, "", "/auth/microsoft/callback#token=test-token");
    auth.adoptCallbackToken.mockImplementationOnce(async () => {
      expect(window.location.hash).toBe("");
      return null;
    });
    const view = mount(<MicrosoftCallbackScreen />);
    expect(await screen.findByRole("link", { name: "Back" })).toBeInTheDocument();
    expect(auth.adoptCallbackToken).toHaveBeenCalledWith("test-token");
    view.unmount();
    window.history.replaceState({}, "", "/auth/microsoft/callback#error=invalid_state");
    mount(<MicrosoftCallbackScreen />);
    expect(await screen.findByText(/Microsoft sign-in could not/)).toBeInTheDocument();
    expect(window.location.hash).toBe("");
  });
  it("associates field labels, traps focus, cancels with Escape and restores focus", async () => {
    const action = vi.fn();
    mount(
      <>
        <Input label="Display name" value="Test" />
        <ConfirmButton onClick={action}>Remove</ConfirmButton>
      </>,
    );
    expect(screen.getByLabelText("Display name")).toHaveValue("Test");
    const trigger = screen.getByRole("button", { name: "Remove" });
    await userEvent.click(trigger);
    expect(screen.getByRole("dialog")).toHaveAttribute("aria-modal", "true");
    expect(screen.getByRole("button", { name: "Close" })).toHaveFocus();
    await userEvent.tab({ shift: true });
    expect(screen.getByRole("button", { name: "Confirm" })).toHaveFocus();
    await userEvent.keyboard("{Escape}");
    expect(action).not.toHaveBeenCalled();
    expect(trigger).toHaveFocus();
  });

  it("renders student bottom navigation and confirms logout before invoking it", async () => {
    mockApi({ "GET /notifications": { data: [], unread_count: 0 } });
    mount(
      <AppShell>
        <p>Content</p>
      </AppShell>,
      "/app",
    );
    expect(screen.getAllByRole("navigation").length).toBeGreaterThanOrEqual(2);
    await userEvent.click(screen.getByRole("button", { name: "Sign out" }));
    expect(auth.signOut).not.toHaveBeenCalled();
    await userEvent.click(
      within(screen.getByRole("dialog")).getByRole("button", {
        name: "Confirm",
      }),
    );
    expect(auth.signOut).toHaveBeenCalledOnce();
  });
});

describe("Student journeys", () => {
  it("submits a join code and renders localized pending status", async () => {
    const fetcher = mockApi({
      "GET /join-requests/mine": { data: [] },
      "POST /join-requests": { id: 2, status: "pending" },
    });
    mount(<JoinClassScreen />);
    await userEvent.type(screen.getByLabelText("Group code"), "abcdefgh");
    await userEvent.click(screen.getByRole("button", { name: "Send the request" }));
    expect(await screen.findByText("Request sent")).toBeInTheDocument();
    expect(
      fetcher.mock.calls.some(([, init]) => init?.body === JSON.stringify({ code: "ABCDEFGH" })),
    ).toBe(true);
  });

  it("does not expose pending or archived classes as accessible active classes", async () => {
    mockApi({
      "GET /classes": {
        data: [
          { ...classroom, membership: { status: "accepted" } },
          {
            ...classroom,
            id: 2,
            name: "Pending class",
            membership: { status: "pending" },
          },
          {
            ...classroom,
            id: 3,
            name: "Archive",
            status: "archived",
            membership: { status: "accepted" },
          },
        ],
      },
    });
    mount(<MyClassesScreen />);
    expect(await screen.findByText("Web")).toBeInTheDocument();
    expect(screen.queryByText("Pending class")).not.toBeInTheDocument();
    expect(screen.queryByText("Archive")).not.toBeInTheDocument();
  });

  it("renders a retryable data error and recovers without changing screens", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValueOnce(jsonResponse({ message: "Unavailable" }, { status: 503 }))
        .mockResolvedValueOnce(
          jsonResponse({
            data: [{ ...classroom, membership: { status: "accepted" } }],
          }),
        ),
    );
    mount(<MyClassesScreen />);
    await userEvent.click(await screen.findByRole("button", { name: en["common.retry"] }));
    expect(await screen.findByText("Web")).toBeInTheDocument();
  });

  it("takes a quiz and submits selected option IDs only after confirmation", async () => {
    const fetcher = mockApi({
      "GET /quizzes/2/attempts/active": { attempt: null },
      "POST /quizzes/2/attempts": {
        attempt_id: 9,
        attempt_no: 1,
        time_limit_min: null,
        remaining_seconds: 0,
        questions: [
          {
            id: 20,
            statement: "Pick one",
            type: "single",
            options: [
              { id: 30, label: "Answer A" },
              { id: 31, label: "Answer B" },
            ],
          },
        ],
      },
      "POST /attempts/9/submit": {
        score: 1,
        max_score: 1,
        percentage: 100,
        show_answers: false,
        answers: [],
      },
    });
    mount(
      <QuizAttemptScreen />,
      "/app/classes/1/quizzes/2",
      "/app/classes/:classroomId/quizzes/:id",
    );
    await userEvent.click(await screen.findByRole("button", { name: "Start the quiz" }));
    await userEvent.click(await screen.findByRole("button", { name: "Answer A" }));
    await userEvent.click(screen.getByRole("button", { name: "Submit" }));
    expect(fetcher.mock.calls.filter(([, init]) => init?.method === "POST")).toHaveLength(1);
    await userEvent.click(screen.getByRole("button", { name: "Confirm" }));
    expect(await screen.findByText("100%")).toBeInTheDocument();
    expect(fetcher.mock.calls.at(-1)?.[1]?.body).toBe(
      JSON.stringify({ answers: [{ question_id: 20, option_ids: [30] }] }),
    );
  });

  it("resumes a server attempt with its frozen order and saved answers", async () => {
    // F-QUI-04 : la reprise vient du serveur, pas du sessionStorage : elle
    // doit donc survivre à un changement d'appareil.
    const fetcher = mockApi({
      "GET /quizzes/2/attempts/active": {
        attempt: {
          attempt_id: 9,
          attempt_no: 1,
          started_at: "2026-10-03T10:00:00+00:00",
          time_limit_min: null,
          remaining_seconds: 0,
          questions: [
            {
              id: 20,
              statement: "Server question",
              type: "single",
              options: [{ id: 30, label: "Server answer" }],
            },
          ],
          answers: { 20: { option_ids: [30] } },
        },
      },
    });
    mount(
      <QuizAttemptScreen />,
      "/app/classes/1/quizzes/2",
      "/app/classes/:classroomId/quizzes/:id",
    );
    // Aucune tentative locale : seule la requête serveur peut la retrouver.
    expect(screen.queryByRole("button", { name: "Start the quiz" })).not.toBeInTheDocument();
    await userEvent.click(await screen.findByRole("button", { name: "Resume attempt" }));
    expect(await screen.findByRole("button", { name: "Server answer" })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    // Reprendre ne consomme pas de tentative supplémentaire.
    expect(fetcher.mock.calls.every(([, init]) => init?.method !== "POST")).toBe(true);
  });

  it("falls back to the local cache when the server has no active attempt", async () => {
    sessionStorage.setItem(
      "classlink.attempt.7.2",
      JSON.stringify({
        attempt: {
          attempt_id: 9,
          time_limit_min: null,
          remaining_seconds: 0,
          questions: [
            {
              id: 20,
              statement: "Resume question",
              type: "single",
              options: [{ id: 30, label: "Preserved answer" }],
            },
          ],
        },
        answers: { 20: [30] },
        savedAt: Date.now(),
      }),
    );
    const fetcher = mockApi({
      "GET /quizzes/2/attempts/active": { attempt: null },
      "GET /attempts/9": { id: 9, submitted_at: null },
    });
    mount(
      <QuizAttemptScreen />,
      "/app/classes/1/quizzes/2",
      "/app/classes/:classroomId/quizzes/:id",
    );
    await userEvent.click(await screen.findByRole("button", { name: "Resume attempt" }));
    expect(await screen.findByRole("button", { name: "Preserved answer" })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    expect(
      fetcher.mock.calls.map(([url]) => String(url)).some((url) => url.endsWith("/attempts/9")),
    ).toBe(true);
  });

  it("saves answers through PATCH before moving to the next question", async () => {
    const fetcher = mockApi({
      "GET /quizzes/2/attempts/active": { attempt: null },
      "POST /quizzes/2/attempts": {
        attempt_id: 9,
        time_limit_min: null,
        remaining_seconds: 0,
        questions: [
          {
            id: 20,
            statement: "First",
            type: "single",
            options: [{ id: 30, label: "Answer A" }],
          },
          {
            id: 21,
            statement: "Second",
            type: "single",
            options: [{ id: 31, label: "Answer B" }],
          },
        ],
      },
      "PATCH /attempts/9/answers": { saved: 1 },
    });
    mount(
      <QuizAttemptScreen />,
      "/app/classes/1/quizzes/2",
      "/app/classes/:classroomId/quizzes/:id",
    );
    await userEvent.click(await screen.findByRole("button", { name: "Start the quiz" }));
    await userEvent.click(await screen.findByRole("button", { name: "Answer A" }));
    await userEvent.click(screen.getByRole("button", { name: en["quiz.next"] }));
    expect(await screen.findByText("Second")).toBeInTheDocument();
    expect(fetcher.mock.calls.at(-1)?.[1]?.method).toBe("PATCH");
    expect(fetcher.mock.calls.at(-1)?.[1]?.body).toBe(
      JSON.stringify({ answers: [{ question_id: 20, option_ids: [30] }] }),
    );
  });

  it("uploads an assignment file using multipart", async () => {
    const fetcher = mockApi({
      "GET /assignments/3": {
        id: 3,
        title: "Homework",
        instructions: "Instructions",
        my_submission: null,
      },
      "POST /assignments/3/submissions": { id: 8 },
    });
    mount(<AssignmentDetailScreen />, "/assignments/3", "/assignments/:assignmentId");
    const file = new File(["%PDF-test"], "homework.pdf", {
      type: "application/pdf",
    });
    await userEvent.upload(await screen.findByLabelText(en["assignment.submit.choose"]), file);
    await userEvent.click(screen.getByRole("button", { name: en["assignment.submit.send"] }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(
          ([, init]) => init?.body instanceof FormData && init.body.get("file") === file,
        ),
      ).toBe(true),
    );
  });

  it("flips a flashcard and records the known answer using the actual review route", async () => {
    const fetcher = mockApi({
      "GET /flashcard-decks/2": {
        title: "Deck",
        cards: [{ id: 4, front: "Front", back: "Back answer", known: null }],
      },
      "POST /flashcard-decks/2/cards/4/review": {
        data: { card_id: 4, known: true },
      },
    });
    mount(<FlashcardStudyScreen />, "/decks/2", "/decks/:deckId");
    await userEvent.click(await screen.findByRole("button", { name: en["flashcards.flip"] }));
    expect(screen.getByText("Back answer")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: en["flashcards.known"] }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(([, init]) => init?.body === JSON.stringify({ known: true })),
      ).toBe(true),
    );
  });

  it("lets a teacher correct a generated card, which unlocks publication", async () => {
    // F-QUI-08 : un deck IA ne pouvait être corrigé que par recréation.
    auth.role = "teacher";
    const fetcher = mockApi({
      "GET /flashcard-decks/2": {
        title: "AI deck",
        source: "ai",
        status: "draft",
        reviewed: false,
        cards: [{ id: 4, front: "Wrong front", back: "Wrong back", known: null }],
      },
      "PATCH /flashcard-decks/2/cards/4": {
        data: { id: 4, front: "Right front", back: "Right back" },
      },
    });
    mount(<FlashcardStudyScreen />, "/decks/2", "/decks/:deckId");
    await userEvent.click(await screen.findByRole("button", { name: en["flashcards.editCard"] }));
    const front = screen.getByLabelText(en["flashcards.front"]);
    const back = screen.getByLabelText(en["flashcards.back"]);
    expect(front).toHaveValue("Wrong front");
    await userEvent.clear(front);
    await userEvent.type(front, "Right front");
    await userEvent.clear(back);
    await userEvent.type(back, "Right back");
    await userEvent.click(screen.getByRole("button", { name: en["common.save"] }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(
          ([url, init]) =>
            init?.method === "PATCH" && String(url).endsWith("/flashcard-decks/2/cards/4"),
        ),
      ).toBe(true),
    );
    expect(fetcher.mock.calls.at(-1)?.[1]?.body).toBe(
      JSON.stringify({ front: "Right front", back: "Right back" }),
    );
    // La carte corrigée s'affiche : le contenu IA est enfin exploitable.
    expect(await screen.findByText("Right front")).toBeInTheDocument();
    auth.role = "student";
  });

  it("renders real sorted assignment and quiz deadlines and server progression trend", async () => {
    mockApi({
      "GET /classes": { data: [] },
      "GET /me/announcements": { data: [] },
      "GET /me/progress": {
        totals: {},
        history: [
          { attempt_id: 1, percentage: 20 },
          { attempt_id: 2, percentage: 80 },
        ],
        trend: { delta_percentage_points: 60 },
      },
    });
    const dashboard = mount(<StudentDashboard />);
    expect(await screen.findByText(/Change from first to latest attempt: 60/)).toBeInTheDocument();
    dashboard.unmount();
    mockApi({
      "GET /me/deadlines": {
        data: [
          {
            kind: "quiz",
            id: 2,
            title: "Later quiz",
            due_at: "2026-12-02T00:00:00Z",
            is_overdue: false,
          },
          {
            kind: "assignment",
            id: 1,
            title: "Earlier homework",
            due_at: "2026-12-01T00:00:00Z",
            is_overdue: false,
          },
        ],
      },
    });
    mount(<DeadlinesScreen />);
    await screen.findByText("Earlier homework");
    expect(
      screen.getByText("Earlier homework").compareDocumentPosition(screen.getByText("Later quiz")) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it("responds to partner requests without displaying an email", async () => {
    const fetcher = mockApi({
      "GET /classes": { data: [] },
      "GET /me/partner-profile": {
        opt_in: false,
        skills: [],
        availability: [],
      },
      "GET /me/partner-requests": {
        data: [
          {
            id: 9,
            status: "pending",
            direction: "incoming",
            counterpart: { display_name: "Sara" },
          },
        ],
      },
      "POST /partner-requests/9/respond": { id: 9, status: "accepted" },
    });
    mount(<PartnersScreen />);
    await userEvent.click(screen.getByRole("tab", { name: en["partners.requests"] }));
    await userEvent.click(await screen.findByRole("button", { name: en["partners.accept"] }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(
          ([, init]) => init?.body === JSON.stringify({ status: "accepted" }),
        ),
      ).toBe(true),
    );
    expect(document.body.textContent).not.toContain("@");
  });
});

describe("Teacher journeys", () => {
  it("confirms accept-all and calls the class-scoped standard route", async () => {
    const fetcher = mockApi({
      ...manageResponses(),
      "GET /classes/1/join-requests": {
        data: [{ membership_id: 8, user: { id: 77, display_name: "Sara" } }],
      },
      "POST /classes/1/join-requests/accept-all": { accepted: 1 },
    });
    mount(<TeacherClassScreen />, "/app/classes/1/manage?tab=requests", "/app/classes/:id/manage");
    await screen.findByText("Sara");
    await userEvent.click(screen.getByRole("button", { name: /Accepted.*1/ }));
    expect(fetcher.mock.calls.some(([, init]) => init?.method === "POST")).toBe(false);
    await userEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() =>
      expect(fetcher.mock.calls.some(([url]) => String(url).endsWith("/accept-all"))).toBe(true),
    );
  });

  it("removes the student ID, not the membership ID", async () => {
    const fetcher = mockApi({
      ...manageResponses(),
      "GET /classes/1/members": {
        data: [
          {
            membership_id: 8,
            status: "accepted",
            user: { id: 77, display_name: "Sara" },
          },
        ],
      },
      "DELETE /classes/1/members/77": null,
    });
    mount(<TeacherClassScreen />, "/app/classes/1/manage?tab=members", "/app/classes/:id/manage");
    await screen.findByText("Sara");
    await userEvent.click(screen.getByRole("button", { name: "Remove from the class" }));
    await userEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(
          ([url, init]) => String(url).endsWith("/members/77") && init?.method === "DELETE",
        ),
      ).toBe(true),
    );
  });

  it("edits announcement title, body and pin through PATCH", async () => {
    const fetcher = mockApi({
      ...manageResponses(),
      "GET /classes/1/announcements": {
        data: [{ id: 3, title: "Notice", body: "Old", pinned: false }],
      },
      "PATCH /announcements/3": { id: 3 },
    });
    mount(
      <TeacherClassScreen />,
      "/app/classes/1/manage?tab=announcements",
      "/app/classes/:id/manage",
    );
    await userEvent.click(await screen.findByRole("button", { name: "Edit" }));
    const dialog = within(screen.getByRole("dialog"));
    await userEvent.clear(dialog.getByLabelText("Title"));
    await userEvent.type(dialog.getByLabelText("Title"), "Updated");
    await userEvent.click(dialog.getByRole("button", { name: "Save" }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(
          ([, init]) => init?.method === "PATCH" && String(init.body).includes("Updated"),
        ),
      ).toBe(true),
    );
  });

  it("loads an existing draft in the same quiz editor and offers reorder/delete", async () => {
    mockApi({
      "GET /quizzes/2/editor": {
        title: "Draft",
        source: "ai",
        due_at: null,
        max_attempts: 1,
        shuffle: false,
        show_answers: true,
        questions: [
          {
            id: 3,
            statement: "Question",
            type: "single",
            explanation: "",
            options: [
              { label: "A", is_correct: true },
              { label: "B", is_correct: false },
            ],
          },
        ],
      },
    });
    mount(
      <QuizEditorScreen />,
      "/app/classes/1/manage/quizzes/2/edit",
      "/app/classes/:id/manage/quizzes/:quizId/edit",
    );
    await waitFor(() => expect(screen.getByLabelText("Quiz title")).toHaveValue("Draft"));
    await userEvent.click(screen.getByRole("button", { name: /Questions/ }));
    expect(screen.getByRole("button", { name: "Move up" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Delete" })).toBeDisabled();
    expect(screen.getByLabelText("Option 1")).toHaveValue("A");
  });

  it("renders the server score distribution, not raw status values", async () => {
    mockApi({
      "GET /quizzes/2/results": {
        quiz: { title: "Results" },
        summary: {},
        attempts: [{ id: 1, percentage: 80, student: { display_name: "Sara" } }],
        most_missed: [],
        distribution: [{ min: 80, max: 100, count: 1 }],
      },
    });
    mount(<QuizResultsScreen />, "/results/2", "/results/:quizId");
    expect(await screen.findByText("Score distribution")).toBeInTheDocument();
    expect(screen.getByText("80 - 100% : 1")).toBeInTheDocument();
  });

  it("uploads a CSV and renders localized per-row failures", async () => {
    const fetcher = mockApi({
      ...manageResponses(),
      "POST /classes/1/members/import": {
        imported: 1,
        accepted: [{ row: 2, student_id: 7, display_name: "Sara" }],
        errors: [
          { row: 3, reason: "invalid_email" },
          { row: 4, reason: "column_count" },
        ],
      },
    });
    mount(<TeacherClassScreen />, "/app/classes/1/manage?tab=members", "/app/classes/:id/manage");
    const file = new File(["email\nstudent@ofppt-edu.ma"], "roster.csv", {
      type: "text/csv",
    });
    await userEvent.upload(await screen.findByLabelText("Import CSV roster"), file);
    await userEvent.click(screen.getByRole("button", { name: "Import CSV roster" }));
    await userEvent.click(screen.getByRole("button", { name: "Confirm" }));
    expect(await screen.findByText("Row 3: Invalid email address")).toBeInTheDocument();
    expect(screen.getByText("Row 4: Invalid column count")).toBeInTheDocument();
    expect(fetcher.mock.calls.some(([, init]) => init?.body instanceof FormData)).toBe(true);
  });

  it("grades a submission with the correct submission ID and server payload", async () => {
    const fetcher = mockApi({
      "GET /classes/1/assignments": { data: [{ id: 3, title: "Homework" }] },
      "GET /assignments/3/submissions": {
        data: [
          {
            id: 8,
            student: { display_name: "Sara" },
            grade: null,
            feedback: null,
          },
        ],
      },
      "PATCH /submissions/8": { id: 8, grade: 15, feedback: "Good" },
    });
    mount(
      <GradeScreen />,
      "/app/classes/1/manage/assignments/3/grade",
      "/app/classes/:id/manage/assignments/:assignmentId/grade",
    );
    await userEvent.click(await screen.findByRole("button", { name: /Sara/ }));
    await userEvent.type(screen.getByLabelText(en["grade.gradeField"]), "15");
    await userEvent.type(screen.getByLabelText(en["grade.feedback"]), "Good");
    await userEvent.click(screen.getByRole("button", { name: en["grade.save"] }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(
          ([, init]) => init?.body === JSON.stringify({ grade: 15, feedback: "Good" }),
        ),
      ).toBe(true),
    );
  });

  it("renders actionable inactive student names rather than bare IDs", async () => {
    mockApi({
      "GET /classes": { data: [classroom] },
      "GET /classes/1/progress": {
        totals: { attempts: 1 },
        most_missed: [],
        inactive_student_ids: [7],
        inactive_students: [{ id: 7, display_name: "Inactive Sara" }],
      },
    });
    mount(<ClassProgressScreen />);
    expect(await screen.findByText("Inactive Sara")).toBeInTheDocument();
  });

  it("creates a manual flashcard deck using title and front/back card payloads", async () => {
    const fetcher = mockApi({
      ...manageResponses(),
      "POST /classes/1/flashcards": { id: 2 },
    });
    mount(<TeacherClassScreen />, "/app/classes/1/manage?tab=quizzes", "/app/classes/:id/manage");
    await userEvent.type(await screen.findByLabelText(en["publish.title"]), "Revision");
    await userEvent.type(screen.getByLabelText(en["createQuiz.statement"]), "Front");
    await userEvent.type(screen.getByLabelText(en["aiQuiz.answer"]), "Back");
    await userEvent.click(screen.getByRole("button", { name: "Save" }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(
          ([, init]) =>
            init?.body ===
            JSON.stringify({
              title: "Revision",
              cards: [{ front: "Front", back: "Back" }],
            }),
        ),
      ).toBe(true),
    );
  });

  it("requires opening and flipping the deck before teacher review validation", async () => {
    auth.role = "teacher";
    const fetcher = mockApi({
      "GET /flashcard-decks/2": {
        title: "AI deck",
        status: "draft",
        source: "ai",
        reviewed: false,
        cards: [{ id: 4, front: "Front", back: "Back answer", known: null }],
      },
      "POST /flashcard-decks/2/reviewed": { id: 2 },
    });
    mount(<FlashcardStudyScreen />, "/decks/2", "/decks/:deckId");
    const review = await screen.findByRole("button", {
      name: en["aiQuiz.review"],
    });
    expect(review).toBeDisabled();
    await userEvent.click(screen.getByRole("button", { name: en["flashcards.flip"] }));
    await userEvent.click(review);
    await userEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() =>
      expect(fetcher.mock.calls.some(([url]) => String(url).endsWith("/reviewed"))).toBe(true),
    );
    expect(screen.getByRole("button", { name: en["createQuiz.publish"] })).toBeDisabled();
  });
});

describe("Admin and profile journeys", () => {
  it("transfers ownership only after confirmation with teacher_id payload", async () => {
    const fetcher = mockApi({
      "GET /admin/classes": {
        data: [{ ...classroom, teacher: { display_name: "Teacher" } }],
      },
      "GET /school/people": {
        data: [{ id: 22, display_name: "Synthetic teacher" }],
      },
      "POST /admin/classes/1/transfer": { teacher_id: 22 },
    });
    mount(<AdminClassesScreen />);
    await userEvent.selectOptions(await screen.findByLabelText("Teacher"), "22");
    await userEvent.click(screen.getByRole("button", { name: "Transfer class" }));
    await userEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(([, init]) => init?.body === JSON.stringify({ teacher_id: 22 })),
      ).toBe(true),
    );
  });

  it("renders the pending-user queue and validates a teacher role", async () => {
    const fetcher = mockApi({
      "GET /admin/users": { data: [], meta: { total: 0 } },
      "GET /admin/users/pending": {
        data: [
          {
            id: 4,
            display_name: "Pending account",
            email: "trainer.name@ofppt-edu.ma",
            role_candidate: "teacher",
            verification_source: "microsoft",
            is_active: true,
          },
        ],
      },
      "PATCH /admin/users/4": { id: 4, role: "teacher" },
    });
    mount(<AdminUsersScreen />);
    await screen.findByText("Pending account");
    expect(screen.getByText("trainer.name@ofppt-edu.ma")).toBeInTheDocument();
    expect(screen.getByText(en["auth.teacherCandidate"])).toBeInTheDocument();
    expect(screen.getByText(en["auth.sourceMicrosoft"])).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Teacher" }));
    await userEvent.click(screen.getByRole("button", { name: "Confirm" }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(([, init]) => init?.body === JSON.stringify({ role: "teacher" })),
      ).toBe(true),
    );
  });

  it("localizes notification statuses and persists email preferences", async () => {
    const fetcher = mockApi({
      "GET /notifications": {
        data: [
          {
            id: 1,
            type: "partner_request_answered",
            payload: { status: "accepted" },
          },
          { id: 2, type: "ai_job_finished", payload: { status: "done" } },
        ],
      },
      "GET /me/notification-preferences": { email_digest: true, types: {} },
      "PUT /me/notification-preferences": { email_digest: false, types: {} },
    });
    mount(<ProfileScreen />);
    await userEvent.click(screen.getByRole("tab", { name: "Notifications" }));
    expect(await screen.findByText("AI generation finished (Completed).")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("switch", { name: "Daily email digest" }));
    await waitFor(() =>
      expect(
        fetcher.mock.calls.some(
          ([, init]) => init?.body === JSON.stringify({ email_digest: false, types: {} }),
        ),
      ).toBe(true),
    );
  });

  it("renders notification payload statuses in French rather than raw accepted/done", async () => {
    setStoredLocale("fr");
    mockApi({
      "GET /notifications": {
        data: [
          {
            id: 1,
            type: "partner_request_answered",
            payload: { status: "accepted" },
          },
          { id: 2, type: "ai_job_finished", payload: { status: "done" } },
        ],
      },
      "GET /me/notification-preferences": { email_digest: true, types: {} },
    });
    mount(<ProfileScreen />);
    await userEvent.click(screen.getByRole("tab", { name: "Notifications" }));
    expect(await screen.findByText(/Votre demande de binôme a été Acceptée/)).toBeInTheDocument();
    expect(screen.getByText(/Génération IA terminée \(Terminée\)/)).toBeInTheDocument();
    expect(document.body.textContent).not.toContain("(done)");
    expect(document.body.textContent).not.toContain("accepted");
  });

  it("shows a ten-minute OTP countdown and locks resend during a request", async () => {
    let resolve: (value: Response) => void = () => {};
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ message: "Sent" }))
      .mockImplementationOnce(
        () =>
          new Promise<Response>((done) => {
            resolve = done;
          }),
      );
    vi.stubGlobal("fetch", fetcher);
    mount(<LoginScreen />);
    await userEvent.click(screen.getByRole("button", { name: /email code/ }));
    await userEvent.type(screen.getByLabelText("School address"), "student@ofppt-edu.ma");
    await userEvent.click(screen.getByRole("button", { name: "Send the code" }));
    expect(await screen.findByRole("timer")).toHaveTextContent("10:00");
    const resend = screen.getByRole("button", { name: /Resend/ });
    fireEvent.click(resend);
    await waitFor(() => expect(resend).toBeDisabled());
    fireEvent.click(resend);
    expect(fetcher).toHaveBeenCalledTimes(2);
    await act(async () => {
      resolve(jsonResponse({ message: "Sent" }));
    });
  });
});
