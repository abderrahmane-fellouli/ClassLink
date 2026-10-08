import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router-dom";
import { I18nProvider, useI18n } from "../src/i18n";
import { NoticeComposer, OfficialGradeEditor, SchoolWorkspace } from "../src/screens/SchoolScreens";
import { api } from "../src/lib/api";
import type { Group } from "../src/lib/school";

const auth = vi.hoisted(() => ({
  user: { id: 1, display_name: "Fixture", role: "admin", locale: "en" },
  role: "admin",
  token: null,
  signOut: vi.fn().mockResolvedValue(undefined),
}));
vi.mock("../src/context/AuthContext", () => ({ useAuth: () => auth }));
vi.mock("../src/lib/api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../src/lib/api")>();
  return {
    ...actual,
    api: {
      ...actual.api,
      get: vi.fn(),
      put: vi.fn(),
      post: vi.fn(),
      postForm: vi.fn(),
      download: vi.fn(),
    },
    errorMessage: (e: Error) => e.message,
  };
});

const editor = {
  assessment: {
    id: 1,
    offering_id: 1,
    title: "Synthetic assessment",
    type: "exam",
    assessed_on: "2026-10-07",
    maximum_score: 20,
    coefficient: 1,
    state: "draft",
    version: 1,
    roster_version: 3,
    draft_revision_id: 1,
    published_revision_id: null,
  },
  current_roster_version: 3,
  read_only: false,
  entries: [
    {
      student_id: 42,
      display_name: "Synthetic Student",
      score: null,
      status: "ungraded",
      feedback: null,
    },
  ],
  history: [],
};
function ChangeLocale() {
  const { setLocale } = useI18n();
  return <button onClick={() => setLocale("fr")}>Change locale</button>;
}
function mount() {
  const router = createMemoryRouter(
    [
      {
        path: "/app/school/assessments/:id",
        element: (
          <>
            <ChangeLocale />
            <OfficialGradeEditor />
          </>
        ),
      },
      { path: "/app/school", element: <p>Workspace destination</p> },
    ],
    { initialEntries: ["/app/school/assessments/1"] },
  );
  render(
    <I18nProvider>
      <RouterProvider router={router} />
    </I18nProvider>,
  );
  return router;
}

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.setItem("classlink.locale", "en");
  vi.mocked(api.get).mockResolvedValue(structuredClone(editor));
  vi.mocked(api.put).mockResolvedValue({ version: 2 });
});

describe("multi-group class notices", () => {
  const groups: Group[] = [
    {
      id: 1,
      name: "Group Alpha",
      official_code: "A",
      school_year: "2026",
      academic_year_id: 1,
      status: "active",
      join_enabled: true,
      coordinator_id: null,
      coordinator_can_manage_roster: false,
    },
    {
      id: 2,
      name: "Group Beta",
      official_code: "B",
      school_year: "2026",
      academic_year_id: 1,
      status: "active",
      join_enabled: true,
      coordinator_id: null,
      coordinator_can_manage_roster: false,
    },
    {
      id: 3,
      name: "Group Archived",
      official_code: "C",
      school_year: "2026",
      academic_year_id: 1,
      status: "archived",
      join_enabled: true,
      coordinator_id: null,
      coordinator_can_manage_roster: false,
    },
  ];

  it("sends the union of the selected groups and shows the per-group breakdown", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      preview_id: "notice-1",
      audience: "classes",
      recipient_count: 9,
      breakdown: [
        { classroom_id: 1, recipient_count: 5 },
        { classroom_id: 2, recipient_count: 4 },
      ],
      recipients_preview: [{ id: 10, display_name: "Synthetic Student" }],
    });
    render(
      <I18nProvider>
        <NoticeComposer groups={groups} />
      </I18nProvider>,
    );

    await user.click(screen.getByRole("checkbox", { name: "Group Alpha" }));
    await user.click(screen.getByRole("checkbox", { name: "Group Beta" }));
    await user.click(screen.getByRole("button", { name: "Preview recipients" }));

    await waitFor(() =>
      expect(api.post).toHaveBeenCalledWith(
        "/school/notices/preview",
        { audience: "classes", group_ids: [1, 2] },
        { locale: "en" },
      ),
    );
    expect(await screen.findByText("Recipients: 9")).toBeInTheDocument();
    expect(screen.getByText(/Group Alpha: 5/)).toBeInTheDocument();
    expect(screen.getByText(/Group Beta: 4/)).toBeInTheDocument();
  });

  it("keeps the preview disabled until a group is selected and ignores archived groups", async () => {
    const user = userEvent.setup();
    render(
      <I18nProvider>
        <NoticeComposer groups={groups} />
      </I18nProvider>,
    );

    expect(screen.getByRole("button", { name: "Preview recipients" })).toBeDisabled();
    expect(screen.queryByRole("checkbox", { name: "Group Archived" })).not.toBeInTheDocument();

    await user.click(screen.getByRole("checkbox", { name: "Group Alpha" }));
    expect(screen.getByRole("button", { name: "Preview recipients" })).toBeEnabled();
  });
});

describe("institutional grade workflow safety", () => {
  it("typing a numeric score sets graded status rather than submitting an ungraded score", async () => {
    const user = userEvent.setup();
    mount();
    await user.type(await screen.findByLabelText("Score Synthetic Student 42"), "15,5");
    expect(screen.getByLabelText("Status Synthetic Student 42")).toHaveValue("graded");
    await user.click(screen.getByRole("button", { name: "Save draft" }));
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith(
        "/school/assessments/1/draft",
        expect.objectContaining({
          rows: [expect.objectContaining({ score: "15,5", status: "graded" })],
        }),
        { locale: "en" },
      ),
    );
  });

  it("explains out-of-range scores and blocks save without losing the entered value", async () => {
    const user = userEvent.setup();
    mount();
    const score = await screen.findByLabelText("Score Synthetic Student 42");
    await user.type(score, "21");
    expect(score).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByRole("button", { name: "Save draft" })).toBeDisabled();
    expect(screen.getByText(/Enter a value between 0 and 20/)).toBeInTheDocument();
    expect(api.put).not.toHaveBeenCalled();
    expect(score).toHaveValue("21");
  });

  it("does not overwrite unsaved grades or adopt a newer revision on a locale refresh", async () => {
    const user = userEvent.setup();
    mount();
    await user.type(await screen.findByLabelText("Score Synthetic Student 42"), "13");
    vi.mocked(api.get).mockResolvedValue({
      ...structuredClone(editor),
      assessment: { ...editor.assessment, version: 2 },
    });
    await user.click(screen.getByRole("button", { name: "Change locale" }));
    await waitFor(() =>
      expect(api.get).toHaveBeenCalledWith(
        "/school/assessments/1",
        expect.objectContaining({ locale: "fr" }),
      ),
    );
    expect(await screen.findByLabelText("Note Synthetic Student 42")).toHaveValue("13");
    await user.click(screen.getByRole("button", { name: "Enregistrer le brouillon" }));
    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith(
        "/school/assessments/1/draft",
        expect.objectContaining({ version: 1 }),
        { locale: "fr" },
      ),
    );
  });

  it("keeps zero distinct from missing scores when saving a graded draft", async () => {
    const user = userEvent.setup();
    mount();
    await screen.findByRole("heading", { name: "Synthetic assessment" });
    await user.selectOptions(await screen.findByLabelText("Status Synthetic Student 42"), "graded");
    await user.type(screen.getByLabelText("Score Synthetic Student 42"), "0");
    await user.click(screen.getByRole("button", { name: "Save draft" }));
    await waitFor(() => expect(api.put).toHaveBeenCalled());
    const body = vi.mocked(api.put).mock.calls[0][1] as {
      rows: { score: string; status: string }[];
    };
    expect(body.rows[0]).toMatchObject({ score: "0", status: "graded" });
  });

  it("blocks confirming an import that still contains validation errors", async () => {
    vi.mocked(api.postForm).mockResolvedValue({
      batch_id: "synthetic-preview",
      errors: [{ row: 2, reason: "invalid_score" }],
      summary: { changed: 1, unchanged: 0, missing: 0 },
    });
    const user = userEvent.setup();
    mount();
    await screen.findByRole("heading", { name: "Synthetic assessment" });
    await user.upload(
      screen.getByLabelText("Private file"),
      new File(["synthetic CSV"], "notes.csv", { type: "text/csv" }),
    );
    await user.click(screen.getByRole("button", { name: "Preview file" }));
    expect(await screen.findByRole("button", { name: "Confirm draft import" })).toBeDisabled();
    expect(api.post).not.toHaveBeenCalled();
  });

  it("requires an explicit choice before losing unsaved grades on navigation", async () => {
    const user = userEvent.setup();
    const router = mount();
    await screen.findByRole("heading", { name: "Synthetic assessment" });
    await user.type(await screen.findByLabelText("Score Synthetic Student 42"), "12");
    await user.click(screen.getByRole("link", { name: "Back to my workspace" }));
    expect(await screen.findByRole("dialog")).toBeVisible();
    expect(router.state.location.pathname).toBe("/app/school/assessments/1");
    await user.click(screen.getByRole("button", { name: "Stay and save" }));
    expect(await screen.findByLabelText("Score Synthetic Student 42")).toHaveValue("12");
    expect(api.put).not.toHaveBeenCalled();
  });
});

it("keeps admission disclosure and search state mounted during a workspace background refresh", async () => {
  const overview = {
    groups: [
      {
        id: 1,
        name: "Synthetic group",
        official_code: "SYN",
        school_year: "2026",
        academic_year_id: 1,
        status: "active",
        join_enabled: true,
        is_read_only: false,
      },
    ],
    years: [],
    modules: [],
    offerings: [],
    teachers: [],
    delegates: [],
  };
  let overviewCalls = 0;
  vi.mocked(api.get).mockImplementation(<T,>(path: string) => {
    if (path === "/school") {
      overviewCalls++;
      return overviewCalls === 1 ? Promise.resolve(overview as T) : new Promise<T>(() => {});
    }
    return Promise.resolve({
      data: path.includes("/people?role=student")
        ? [{ id: 44, display_name: "Synthetic candidate" }]
        : [],
      current_page: 1,
      last_page: 1,
    } as T);
  });
  vi.mocked(api.post).mockResolvedValue({});
  const router = createMemoryRouter([{ path: "/app/school", element: <SchoolWorkspace /> }], {
    initialEntries: ["/app/school"],
  });
  render(
    <I18nProvider>
      <RouterProvider router={router} />
    </I18nProvider>,
  );
  const user = userEvent.setup();
  const summary = await screen.findByText("Admissions and roster import");
  await user.click(summary);
  const details = summary.closest("details")!;
  const search = within(details).getByRole("searchbox");
  await user.type(search, "Synthetic");
  await user.selectOptions(within(details).getByLabelText("Student", { exact: true }), "44");
  await user.click(within(details).getByRole("button", { name: "Enroll" }));
  await waitFor(() => expect(overviewCalls).toBe(2));
  expect(details).toBeInTheDocument();
  expect(details.open).toBe(true);
  expect(search).toHaveValue("Synthetic");
});
