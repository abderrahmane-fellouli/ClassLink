import { useEffect, useState, type ReactNode } from "react";
import { NavLink, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { useI18n, type Translate } from "../i18n";
import { notifications as notificationsApi } from "../lib/endpoints";
import { useAction, useAsync } from "../lib/useAsync";
import type { Locale, Role } from "../lib/types";
import { Alert, Btn, ConfirmButton, Dialog, Icons, LocaleSwitch, ThemeSwitch } from "./UI";
import { errorMessage } from "../lib/api";

type NavEntry = {
  to: string;
  labelKey: Parameters<Translate>[0];
  icon: ReactNode;
  end?: boolean;
};

function navFor(role: Role): NavEntry[] {
  if (role === "teacher") {
    return [
      {
        to: "/app",
        labelKey: "nav.dashboard",
        icon: <Icons.Home />,
        end: true,
      },
      { to: "/app/classes", labelKey: "nav.classes", icon: <Icons.Class /> },
      { to: "/app/school", labelKey: "nav.school", icon: <Icons.Book /> },
      {
        to: "/app/school/messages",
        labelKey: "school.messages",
        icon: <Icons.Users />,
      },
      { to: "/app/requests", labelKey: "nav.requests", icon: <Icons.Bell /> },
      {
        to: "/app/progression",
        labelKey: "nav.progression",
        icon: <Icons.Chart />,
      },
      { to: "/app/profile", labelKey: "nav.profile", icon: <Icons.User /> },
    ];
  }

  if (role === "admin") {
    return [
      {
        to: "/app/admin",
        labelKey: "nav.adminOverview",
        icon: <Icons.Chart />,
        end: true,
      },
      {
        to: "/app/admin/users",
        labelKey: "nav.adminUsers",
        icon: <Icons.Users />,
      },
      { to: "/app/school", labelKey: "nav.school", icon: <Icons.Book /> },
      {
        to: "/app/school/messages",
        labelKey: "school.messages",
        icon: <Icons.Users />,
      },
      {
        to: "/app/admin/classes",
        labelKey: "nav.classes",
        icon: <Icons.Class />,
      },
      { to: "/app/admin/ai", labelKey: "nav.adminAi", icon: <Icons.Cpu /> },
      {
        to: "/app/admin/audit",
        labelKey: "nav.adminAudit",
        icon: <Icons.Log />,
      },
      { to: "/app/profile", labelKey: "nav.profile", icon: <Icons.User /> },
    ];
  }

  return [
    { to: "/app", labelKey: "nav.dashboard", icon: <Icons.Home />, end: true },
    { to: "/app/classes", labelKey: "nav.classes", icon: <Icons.Class /> },
    { to: "/app/school", labelKey: "nav.school", icon: <Icons.Book /> },
    {
      to: "/app/school/grades",
      labelKey: "school.grades",
      icon: <Icons.Chart />,
    },
    {
      to: "/app/school/messages",
      labelKey: "school.messages",
      icon: <Icons.Users />,
    },
    {
      to: "/app/deadlines",
      labelKey: "nav.deadlines",
      icon: <Icons.Calendar />,
    },
    { to: "/app/partners", labelKey: "nav.partners", icon: <Icons.Heart /> },
    {
      to: "/app/progression",
      labelKey: "nav.progression",
      icon: <Icons.Chart />,
    },
    { to: "/app/join", labelKey: "nav.join", icon: <Icons.Plus /> },
    { to: "/app/profile", labelKey: "nav.profile", icon: <Icons.User /> },
  ];
}

function roleLabelKey(role: Role | null) {
  if (role === "teacher") return "role.teacher" as const;
  if (role === "admin") return "role.admin" as const;
  return "role.student" as const;
}

function LinkProfile({ name, initials }: { name: string; initials: string }) {
  const { t } = useI18n();
  return (
    <NavLink
      to="/app/profile"
      aria-label={t("nav.profile")}
      className="flex items-center gap-2 min-h-11 min-w-11"
    >
      <span className="shell-tools-name">{name}</span>
      <span className="shell-avatar" aria-hidden="true">
        {initials}
      </span>
    </NavLink>
  );
}

export function AppShell({ children }: { children: ReactNode }) {
  const { user, role, signOut, updateProfile, retryError, refresh } = useAuth();
  const { t, locale, setLocale, formatRelative } = useI18n();
  const location = useLocation();
  const navigate = useNavigate();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [panelOpen, setPanelOpen] = useState(false);
  const [localeError, setLocaleError] = useState(false);
  async function changeLocale(value: Locale) {
    setLocale(value);
    setLocaleError(false);
    try {
      await updateProfile({ locale: value });
    } catch {
      setLocaleError(true);
    }
  }

  const notifs = useAsync((signal) => notificationsApi.list({ locale, signal }), [locale]);

  const unread = notifs.data?.unread_count ?? 0;
  useEffect(() => {
    window.addEventListener("classlink:notifications-changed", notifs.reload);
    return () => window.removeEventListener("classlink:notifications-changed", notifs.reload);
  }, [notifs.reload]);
  const nav = navFor(role ?? "student");

  useEffect(() => {
    setMobileOpen(false);
    setPanelOpen(false);
  }, [role, location.pathname]);

  const sidebarContent = (
    <div className="shell-sidebar-inner">
      <div className="shell-brand">
        <Icons.Logo />
        <span className="font-display text-lg font-semibold tracking-tight">
          {t("common.appName")}
        </span>
      </div>

      <div className="px-5 pt-4 pb-1">
        <span
          className="text-xs font-medium px-2 py-1 rounded-md"
          style={{
            background: "var(--selected)",
            color: "var(--foreground)",
          }}
        >
          {t(roleLabelKey(role))}
        </span>
      </div>

      <nav className="shell-nav">
        {nav.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={
              item.end ||
              (item.labelKey === "nav.school" &&
                /^\/app\/school\/(grades|messages)/.test(location.pathname))
            }
            onClick={() => setMobileOpen(false)}
            className={({ isActive }) =>
              `w-full min-h-11 flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all ${
                isActive &&
                !(
                  item.labelKey === "nav.school" &&
                  /^\/app\/school\/(grades|messages)/.test(location.pathname)
                )
                  ? "bg-[var(--selected)] text-[var(--foreground)]"
                  : "text-[var(--sidebar-muted)]"
              }`
            }
          >
            {item.icon}
            <span className="flex-1 text-left">{t(item.labelKey)}</span>
          </NavLink>
        ))}
      </nav>

      <div className="shell-account">
        <div className="flex items-center gap-3">
          <div className="shell-avatar">{user?.initials ?? "—"}</div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium break-words">{user?.display_name ?? ""}</p>
            <p className="text-xs text-[var(--muted-foreground)] break-all">
              {user?.email ?? t("common.tagline")}
            </p>
          </div>
        </div>
        <div className="flex items-center justify-between gap-3">
          <LocaleSwitch locale={locale} onChange={(value) => void changeLocale(value)} />
          <ThemeSwitch />
          <ConfirmButton
            variant="ghost"
            onClick={() => {
              setPanelOpen(false);
              navigate("/", { replace: true });
              void signOut();
            }}
            className="text-[var(--sidebar-muted)]"
            title={t("nav.logout")}
            aria-label={t("nav.logout")}
          >
            <Icons.Logout />
          </ConfirmButton>
        </div>
      </div>
    </div>
  );

  return (
    <div className="flex min-h-dvh">
      <a className="skip-link" href="#main-content">
        {t("ux.skip")}
      </a>
      {retryError && (
        <div className="fixed bottom-4 right-4 left-4 sm:left-auto z-50 max-w-sm bg-[var(--surface-elevated)] border rounded-lg p-3">
          <Alert type="warning" message={retryError} />
          <button className="min-h-11 px-3 text-[var(--primary)]" onClick={() => void refresh()}>
            {t("common.retry")}
          </button>
        </div>
      )}
      <aside
        className="app-sidebar hidden lg:flex flex-col"
        style={{ background: "var(--sidebar)" }}
      >
        {sidebarContent}
      </aside>

      {mobileOpen && (
        <Dialog drawer title={t("nav.menu")} onClose={() => setMobileOpen(false)}>
          <aside className="relative w-full flex flex-col rounded-xl overflow-hidden">
            {sidebarContent}
          </aside>
        </Dialog>
      )}

      <div className="flex-1 flex flex-col min-w-0">
        <header className="shell-topbar">
          <div className="flex items-center gap-2 min-w-0">
            <button
              onClick={() => setMobileOpen(true)}
              aria-expanded={mobileOpen}
              aria-haspopup="dialog"
              className="ui-icon-button lg:hidden"
              aria-label={t("nav.menu")}
            >
              <Icons.Menu />
            </button>
            <div className="flex items-center gap-2 lg:hidden">
              <Icons.Logo />
              <span className="hidden min-[375px]:inline font-display font-semibold">
                {t("common.appName")}
              </span>
            </div>
            <span className="shell-topbar-context">{t("nav.school")}</span>
          </div>
          <div className="shell-tools">
            <span className="hidden sm:inline-flex">
              <ThemeSwitch />
            </span>
            <span className="lg:hidden">
              <LocaleSwitch light locale={locale} onChange={(value) => void changeLocale(value)} />
            </span>
            <button
              onClick={() => {
                if (!panelOpen) notifs.reload();
                setPanelOpen((v) => !v);
              }}
              className="ui-icon-button relative"
              aria-label={t("nav.notifications")}
              aria-haspopup="dialog"
              aria-expanded={panelOpen}
            >
              <Icons.Bell />
              {unread > 0 && (
                <span className="absolute top-0 right-0 min-w-4 rounded bg-[var(--primary)] text-[var(--primary-foreground)] text-xs px-1 flex items-center justify-center">
                  {unread}
                </span>
              )}
            </button>
            <LinkProfile name={user?.display_name ?? ""} initials={user?.initials ?? "—"} />
          </div>
        </header>

        <main
          id="main-content"
          tabIndex={-1}
          className={`app-content flex-1 w-full max-w-[var(--page-width)] mx-auto ${
            role === "student" ? "has-bottom-nav" : ""
          }`}
        >
          {localeError && <Alert type="warning" message={t("profile.languageSaveError")} />}
          {panelOpen && (
            <NotificationPanel
              onClose={() => setPanelOpen(false)}
              items={notifs.data?.data ?? []}
              loading={notifs.loading}
              error={notifs.error}
              onReload={notifs.reload}
              onMarkRead={(id) => {
                return notificationsApi.markRead(id, { locale });
              }}
              onMarkAllRead={() => {
                return notificationsApi.markAllRead({ locale });
              }}
              formatRelative={formatRelative}
              onNavigate={(path) => {
                setPanelOpen(false);
                navigate(path);
              }}
            />
          )}

          <div className="route-page">{children}</div>
        </main>
        {role === "student" && (
          <nav
            aria-label={t("nav.dashboard")}
            className="mobile-bottom-nav md:hidden fixed bottom-0 inset-x-0 z-40 border-t border-[var(--border-subtle)] flex justify-around pb-[env(safe-area-inset-bottom)]"
          >
            {nav
              .filter((item) =>
                ["nav.dashboard", "nav.deadlines", "school.grades", "school.messages"].includes(
                  item.labelKey,
                ),
              )
              .map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.labelKey === "nav.school" || item.end}
                  className={({ isActive }) =>
                    `flex flex-col items-center justify-center gap-1 px-1 py-2 text-[10px] ${
                      isActive
                        ? "text-[var(--primary)] font-semibold"
                        : "text-[var(--muted-foreground)]"
                    }`
                  }
                >
                  {item.icon}
                  <span>{t(item.labelKey)}</span>
                </NavLink>
              ))}
          </nav>
        )}
      </div>
    </div>
  );
}

function NotificationPanel({
  items,
  loading,
  error,
  onReload,
  onMarkRead,
  onMarkAllRead,
  onClose,
  onNavigate,
  formatRelative,
}: {
  items: import("../lib/types").ApiNotification[];
  loading: boolean;
  error: Error | null;
  onReload: () => void;
  onMarkRead: (id: number) => Promise<void>;
  onMarkAllRead: () => Promise<void>;
  onClose: () => void;
  onNavigate: (path: string) => void;
  formatRelative: (value: string | null | undefined) => string;
}) {
  const { t } = useI18n();
  const action = useAction();
  const { role } = useAuth();

  return (
    <Dialog title={t("nav.notifications")} onClose={onClose}>
      <div className="flex flex-col">
        <div className="flex items-center justify-between px-5 py-4 border-b border-[var(--border)]">
          <p className="text-sm text-[var(--muted-foreground)]">{t("ux.notifyHelp")}</p>
          <div className="flex items-center gap-1">
            <button
              disabled={action.pending || loading || !items.some((item) => !item.is_read)}
              onClick={() => void action.run(onMarkAllRead)}
              className="text-xs text-[var(--primary)] hover:underline"
            >
              {t("profile.markAllRead")}
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-4 space-y-2">
          {action.error && (
            <Alert type="error" message={errorMessage(action.error, t("common.networkError"))} />
          )}
          {loading && (
            <p className="text-sm text-[var(--muted-foreground)] py-6 text-center">
              {t("common.loading")}
            </p>
          )}
          {error && (
            <div className="text-center py-6">
              <p className="text-sm text-[var(--muted-foreground)] mb-3">{t("common.error")}</p>
              <button onClick={onReload} className="text-sm text-[var(--primary)] hover:underline">
                {t("common.retry")}
              </button>
            </div>
          )}
          {!loading && !error && items.length === 0 && (
            <p className="text-sm text-[var(--muted-foreground)] py-6 text-center">
              {t("profile.noNotifications")}
            </p>
          )}

          {items.map((item) => (
            <button
              key={item.id}
              disabled={action.pending}
              onClick={() =>
                void action.run(async () => {
                  if (!item.is_read) await onMarkRead(item.id);
                  const target = notificationDestination(item, role);
                  if (target) onNavigate(target);
                  else onClose();
                })
              }
              className={`notification-row w-full text-left px-3.5 py-3 text-sm ${
                item.is_read ? "" : "notification-row-unread"
              }`}
            >
              <span className="block font-medium">{describeNotification(item, t)}</span>
              {!item.is_read && (
                <span className="text-xs font-medium text-[var(--primary)]">
                  {t("school.unread")}
                </span>
              )}
              <span className="block text-xs text-[var(--muted-foreground)] mt-1">
                {formatRelative(item.created_at)}
              </span>
            </button>
          ))}
        </div>

        <div className="p-3 border-t border-[var(--border)]">
          <Btn
            variant="secondary"
            full
            onClick={() => onNavigate("/app/profile?tab=notifications")}
          >
            {t("profile.tab.notifications")}
          </Btn>
        </div>
      </div>
    </Dialog>
  );
}

type NotificationLike = import("../lib/types").ApiNotification;

export function notificationDestination(item: NotificationLike, role: Role | null): string | null {
  if (
    role === "student" &&
    ["partner_request_received", "partner_request_answered"].includes(item.type)
  )
    return "/app/partners?tab=requests";
  const target = item.payload?.url;
  if (
    typeof target === "string" &&
    /^\/app\/(?:school(?:\/grades|\/messages(?:\/\d+)?|\/offerings\/\d+)?|assignments\/\d+)$/.test(
      target,
    )
  )
    return target;
  const classroomId = Number(item.payload?.classroom_id);
  if (Number.isSafeInteger(classroomId) && classroomId > 0) {
    return role === "admin"
      ? "/app/admin/classes"
      : `/app/classes/${classroomId}${role === "teacher" ? "/manage" : ""}`;
  }
  return null;
}

/**
 * Traduit une notification à partir de son `type` et de son `payload`.
 *
 * Les `type` et les clés de `payload` correspondent aux constantes de
 * `backend/app/Services/NotificationService.php` (`join_requested`,
 * `quiz_published`, `announcement_published`…). Le backend n'a jamais émis
 * de type à points : toute notification retombait donc sur `notif.unknown`.
 */
export function describeNotification(item: NotificationLike, t: Translate): string {
  const payload = (item.payload ?? {}) as Record<string, string | number | null | undefined>;
  const field = (key: string): string => (payload[key] == null ? "" : String(payload[key]));

  switch (item.type) {
    case "join_requested":
      return t("notif.join.requested", {
        name: field("student_name"),
        class: field("classroom_name"),
      });
    case "membership_accepted":
      return t("notif.join.accepted", { class: field("classroom_name") });
    case "membership_rejected":
      return t("notif.join.rejected", { class: field("classroom_name") });
    case "membership_removed":
      return t("notif.membership.removed", { class: field("classroom_name") });
    case "partner_request_received":
      return t("notif.partner.received", {
        name: field("from_name"),
        class: field("classroom_name"),
      });
    case "partner_request_answered":
      return t("notif.partner.answered", {
        status: t(
          field("status") === "accepted"
            ? "join.status.accepted"
            : field("status") === "rejected"
              ? "join.status.rejected"
              : "join.status.pending",
        ),
      });
    case "quiz_published":
      return t("notif.quiz.published", {
        title: field("title"),
        class: field("classroom_name"),
      });
    case "announcement_published":
      return t("notif.announcement.published", {
        title: field("title"),
        class: field("classroom_name"),
      });
    case "assignment_published":
      return t("notif.assignment.published", {
        title: field("title"),
        class: field("classroom_name"),
      });
    case "graded":
      return t("school.submissionReviewed");
    case "official_grade_published":
      return t("school.gradeAvailable");
    case "school_message_received":
      return t("school.messageAvailable");
    case "ai_job_finished":
      return t("notif.ai.finished", {
        status: t(
          ["done", "succeeded"].includes(field("status"))
            ? "status.done"
            : field("status") === "failed"
              ? "status.failed"
              : "status.processing",
        ),
      });
    case "teaching_assignment_changed":
      return t(
        field("action") === "revoked" ? "notif.teaching.revoked" : "notif.teaching.assigned",
        {
          class: field("classroom_name"),
          module: field("module_name"),
        },
      );
    case "assignment_request_received":
      return t("notif.assignment.request", {
        name: field("teacher_name"),
        module: field("module_name"),
        class: field("classroom_name"),
      });
    case "delegate_changed":
      return t(
        field("action") === "revoked" ? "notif.delegate.revoked" : "notif.delegate.appointed",
        {
          class: field("classroom_name"),
        },
      );
    case "resource_published":
      return t("notif.resource.published", {
        title: field("title"),
        class: field("classroom_name"),
      });
    case "deadline_changed":
      return t("notif.deadline.changed", {
        title: field("title"),
        class: field("classroom_name"),
      });
    default:
      return t("notif.unknown");
  }
}
