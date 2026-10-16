import {
  createContext,
  useContext,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type MouseEvent,
} from "react";
import {
  createMemoryRouter,
  Navigate,
  Outlet,
  RouterProvider,
  useLocation,
  useNavigate,
} from "react-router-dom";
import { AuthPresentationProvider, type AuthValue } from "../context/AuthContext";
import { useTheme } from "../context/ThemeContext";
import { useI18n } from "../i18n";
import type { TranslationKey } from "../i18n/fr";
import type { Locale } from "../lib/types";
import { Btn, Field } from "../components/UI";
import { router as applicationRouter } from "../router";
import { ids, REVIEW_ROLES, type ReviewRole, type ReviewState } from "./fixtures";
import { installReviewTransport } from "./transport";
import { ReviewBackContext } from "./navigation";
import { ReviewStore } from "./store";
import "./review.css";

export interface ReviewScreen {
  key: TranslationKey;
  path: string;
}
export function reviewScreens(role: ReviewRole): ReviewScreen[] {
  const common: ReviewScreen[] = [
    { key: "review.home", path: "/app" },
    { key: "review.messages", path: `/app/school/messages?group=${ids.group}` },
    { key: "review.thread", path: `/app/school/messages/${ids.thread}` },
    { key: "review.profile", path: "/app/profile" },
  ];
  if (role === "admin")
    return [
      { key: "review.overview", path: "/app/admin" },
      { key: "review.users", path: "/app/admin/users" },
      { key: "review.roster", path: "/app/school" },
      { key: "review.history", path: "/app/admin/classes" },
      { key: "review.audit", path: "/app/admin/audit" },
      { key: "review.ai", path: "/app/admin/ai" },
      ...common,
    ];
  if (role === "teacher")
    return [
      ...common,
      { key: "review.teacherDashboard", path: "/app/classes" },
      { key: "review.module", path: `/app/school/offerings/${ids.offering}` },
      { key: "review.gradeEditor", path: `/app/school/assessments/${ids.assessment}` },
      {
        key: "review.submissions",
        path: `/app/classes/${ids.group}/manage/assignments/${ids.assignment}/grade`,
      },
      { key: "review.manage", path: `/app/classes/${ids.legacy}/manage` },
      {
        key: "review.quizEditor",
        path: `/app/classes/${ids.group}/manage/quizzes/${ids.draft}/edit?offering=${ids.offering}`,
      },
      {
        key: "review.quizResults",
        path: `/app/classes/${ids.group}/manage/quizzes/${ids.quiz}/results`,
      },
      { key: "review.requests", path: "/app/requests" },
      { key: "review.progress", path: "/app/progression" },
      { key: "review.cards", path: `/app/flashcard-decks/${ids.deck}` },
    ];
  return [
    ...common,
    { key: "review.module", path: `/app/school/offerings/${ids.offering}` },
    { key: "review.assignment", path: `/app/assignments/${ids.assignment}` },
    { key: "review.feedback", path: `/app/assignments/${ids.feedback}` },
    { key: "review.grades", path: "/app/school/grades" },
    { key: "review.deadlines", path: "/app/deadlines" },
    {
      key: "review.quiz",
      path: `/app/classes/${ids.group}/quizzes/${ids.quiz}?offering=${ids.offering}`,
    },
    { key: "review.cards", path: `/app/flashcard-decks/${ids.deck}` },
    { key: "review.partners", path: "/app/partners" },
    { key: "review.history", path: "/app/classes" },
    { key: "review.class", path: `/app/classes/${ids.legacy}` },
    { key: "review.progress", path: "/app/progression" },
  ];
}

interface Controls {
  role: ReviewRole;
  state: ReviewState;
  setRole: (role: ReviewRole) => void;
  setState: (state: ReviewState) => void;
  reset: () => void;
  localNotice: (message: string) => void;
}

function ReviewLayout(props: Controls) {
  const { t, locale, setLocale } = useI18n();
  const { theme, setTheme } = useTheme();
  const notice = useContext(ReviewNotice);
  const navigate = useNavigate(),
    location = useLocation();
  const screens = reviewScreens(props.role),
    current = location.pathname + location.search;
  const screenValue = screens.some((s) => s.path === current) ? current : "";
  function containNavigation(event: MouseEvent<HTMLDivElement>) {
    const anchor = (event.target as HTMLElement).closest?.("a");
    if (!anchor) return;
    const href = anchor.getAttribute("href") ?? "";
    if (href.startsWith("#")) return;
    event.preventDefault();
    event.stopPropagation();
    if (href.startsWith("/app")) navigate(href);
    else props.localNotice(t("review.localAction"));
  }
  return (
    <div className="v2-review" data-review-role={props.role} onClickCapture={containNavigation}>
      <header className="review-toolbar">
        <div>
          <strong>{t("review.title")}</strong>
          <p className="text-sm">{t("review.notice")}</p>
        </div>
        <div className="review-toolbar-controls">
          <fieldset>
            <legend>{t("review.role")}</legend>
            <div className="flex flex-wrap gap-2">
              {REVIEW_ROLES.map((role) => (
                <Btn
                  key={role}
                  size="sm"
                  aria-label={t(`review.${role}`)}
                  aria-pressed={props.role === role}
                  variant={props.role === role ? "primary" : "secondary"}
                  onClick={() => props.setRole(role)}
                >
                  {t(`review.${role}`)}
                </Btn>
              ))}
            </div>
          </fieldset>
          <fieldset>
            <legend>{t("review.theme")}</legend>
            <div className="flex gap-2">
              {(["light", "dark"] as const).map((value) => (
                <Btn
                  key={value}
                  size="sm"
                  aria-pressed={theme === value}
                  variant={theme === value ? "primary" : "secondary"}
                  onClick={() => setTheme(value)}
                >
                  {t(`review.${value}`)}
                </Btn>
              ))}
            </div>
          </fieldset>
          <fieldset>
            <legend>{t("review.language")}</legend>
            <div className="flex gap-2">
              {(["fr", "en"] as Locale[]).map((value) => (
                <Btn
                  key={value}
                  size="sm"
                  aria-pressed={locale === value}
                  variant={locale === value ? "primary" : "secondary"}
                  onClick={() => setLocale(value)}
                >
                  {value.toUpperCase()}
                </Btn>
              ))}
            </div>
          </fieldset>
          <Field label={t("review.screen")}>
            <select
              className="ui-field-control"
              value={screenValue}
              data-review-screen
              onChange={(e) => navigate(e.target.value)}
            >
              {!screenValue && <option value="">{location.pathname}</option>}
              {screens.map((s) => (
                <option key={s.path} value={s.path}>
                  {t(s.key)}
                </option>
              ))}
            </select>
          </Field>
          <Field label={t("review.state")}>
            <select
              className="ui-field-control"
              value={props.state}
              data-review-state
              onChange={(e) => props.setState(e.target.value as ReviewState)}
            >
              {(["normal", "empty", "loading", "error"] as const).map((value) => (
                <option key={value} value={value}>
                  {t(`review.${value}`)}
                </option>
              ))}
            </select>
          </Field>
          <Btn size="sm" variant="ghost" onClick={props.reset}>
            {t("review.reset")}
          </Btn>
        </div>
        <p role="status" aria-live="polite" className="review-action-notice">
          {notice}
        </p>
      </header>
      <ReviewBackContext.Provider value={() => navigate(-1)}>
        <Outlet />
      </ReviewBackContext.Provider>
    </div>
  );
}

/** No real AuthProvider is mounted here; no real token is read, created or written. */
export default function V2Review() {
  const { locale, setLocale, t } = useI18n();
  const [role, setRole] = useState<ReviewRole>("student");
  const [state, setState] = useState<ReviewState>("normal");
  const [resetVersion, reset] = useState(0);
  const [notice, setNotice] = useState("");
  const [, redraw] = useState(0);
  const store = useMemo(
    () => new ReviewStore(role, locale, state, setNotice),
    [role, state, resetVersion],
  );
  store.locale = locale;
  useLayoutEffect(() => installReviewTransport(store), [store]);
  const memory = useMemo(
    () =>
      createMemoryRouter(
        [
          {
            id: "synthetic-review-layout",
            element: (
              <ReviewLayout
                role={role}
                state={state}
                setRole={setRole}
                setState={setState}
                reset={() => reset((n) => n + 1)}
                localNotice={setNotice}
              />
            ),
            children: [
              ...applicationRouter.routes
                .filter((r) => r.path?.startsWith("/app"))
                .map((r) => ({ ...r })),
              {
                path: "*",
                element: <Navigate to={role === "admin" ? "/app/admin" : "/app"} replace />,
              },
            ],
          },
        ],
        { initialEntries: [role === "admin" ? "/app/admin" : "/app"] },
      ),
    [store],
  );
  // Toolbar consumes live context without recreating the data router on language/theme edits.
  const value: AuthValue = {
    status: "authenticated",
    user: { ...store.data.user, locale },
    token: null,
    role: store.data.user.role,
    isDenied: false,
    isPending: false,
    sessionNotice: null,
    signInWithResult: () => setNotice(t("review.noAuth")),
    signInWithOtp: async () => {
      throw new Error(t("review.noAuth"));
    },
    signInWithDevRole: async () => {
      throw new Error(t("review.noAuth"));
    },
    adoptCallbackToken: async () => null,
    microsoftRedirectUrl: () => "/v2-review",
    signOut: async () => {
      setNotice(t("review.localAction"));
    },
    refresh: async () => {
      redraw((n) => n + 1);
    },
    updateProfile: async (payload) => {
      Object.assign(store.data.user, payload);
      if (payload.locale) setLocale(payload.locale);
      redraw((n) => n + 1);
      setNotice(t("review.localAction"));
      return store.data.user;
    },
  };
  const routerVersions = useRef(new WeakMap<object, number>()).current;
  useEffect(() => {
    const version = (routerVersions.get(memory) ?? 0) + 1;
    routerVersions.set(memory, version);
    return () =>
      queueMicrotask(() => {
        if (routerVersions.get(memory) === version) {
          memory.dispose();
          routerVersions.delete(memory);
        }
      });
  }, [memory, routerVersions]);
  return (
    <ReviewNotice.Provider value={notice}>
      <AuthPresentationProvider value={value}>
        <RouterProvider key={`${role}-${state}-${resetVersion}`} router={memory} />
      </AuthPresentationProvider>
    </ReviewNotice.Provider>
  );
}

const ReviewNotice = createContext("");
