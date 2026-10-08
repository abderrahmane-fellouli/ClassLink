import { useState } from "react";
import { Link } from "react-router-dom";
import { useI18n } from "../i18n";
import { api, errorMessage } from "../lib/api";
import { admin } from "../lib/endpoints";
import { EditButton } from "../components/EditButton";
import { useAction, useAsync, useDebouncedValue } from "../lib/useAsync";
import type { Page, Person } from "../lib/school";
import type { Role } from "../lib/types";
import {
  Alert,
  AsyncBoundary,
  Badge,
  Btn,
  ConfirmButton,
  Card,
  Dialog,
  EmptyState,
  Icons,
  Input,
  PageHeader,
  ProgressBar,
  Select,
  SkeletonList,
  SkeletonStats,
  StatTile,
  Toggle,
} from "../components/UI";

/* ══ Vue d'ensemble ═════════════════════════════════════════════════ */

export function AdminDashboard() {
  const { t } = useI18n();
  const stats = useAsync((signal) => admin.stats({ signal }), []);
  const providers = useAsync((signal) => admin.aiProviders({ signal }), []);

  if (stats.error) {
    return <Alert message={errorMessage(stats.error, t("common.error"))} type="error" />;
  }

  const data = stats.data;

  return (
    <div>
      <PageHeader title={t("admin.overview.title")} subtitle={t("admin.overview.subtitle")} />
      <nav aria-label={t("ux.quickActions")} className="ui-quick-nav mb-5">
        <Link to="/app/school">{t("ux.setup")}</Link>
        <Link to="/app/admin/users">{t("admin.stat.pendingRoles")}</Link>
        <Link to="/app/school/messages">{t("school.messages")}</Link>
      </nav>

      {stats.loading ? (
        <SkeletonStats />
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
          <StatTile label={t("admin.stat.activeUsers")} value={String(data?.users.active ?? 0)} />
          <StatTile
            label={t("admin.stat.activeClasses")}
            value={String(data?.classes.active ?? 0)}
            color="var(--accent)"
          />
          <StatTile
            label={t("teacher.stats.quizzes")}
            value={String(data?.quizzes.published ?? 0)}
            color="green"
          />
          <StatTile
            label={t("admin.stat.pendingRoles")}
            value={String(data?.users.pending ?? 0)}
            color="var(--danger)"
          />
        </div>
      )}

      <div className="grid lg:grid-cols-2 gap-5">
        <Card className="p-5">
          <p className="text-sm font-semibold mb-3">{t("admin.usageToday")}</p>
          <div className="space-y-2.5">
            {Object.entries(data?.users.by_role ?? {}).map(([role, count]) => (
              <div key={role}>
                <div className="flex items-center justify-between text-xs mb-1">
                  <span>{t(`role.${role}` as "role.student")}</span>
                  <span className="text-[var(--muted-foreground)]">{count}</span>
                </div>
                <ProgressBar
                  value={data && data.users.total > 0 ? (count / data.users.total) * 100 : 0}
                />
              </div>
            ))}
          </div>

          <div className="grid grid-cols-3 gap-2 mt-5 text-center">
            <div className="p-3 rounded-lg bg-[var(--muted)]">
              <p className="text-lg font-semibold">{data?.memberships.pending ?? 0}</p>
              <p className="text-[10px] text-[var(--muted-foreground)]">
                {t("manage.stat.pending")}
              </p>
            </div>
            <div className="p-3 rounded-lg bg-[var(--muted)]">
              <p className="text-lg font-semibold">{data?.quizzes.draft ?? 0}</p>
              <p className="text-[10px] text-[var(--muted-foreground)]">{t("quiz.status.draft")}</p>
            </div>
            <div className="p-3 rounded-lg bg-[var(--muted)]">
              <p className="text-lg font-semibold" style={{ color: "var(--danger)" }}>
                {data?.ai_jobs.failed ?? 0}
              </p>
              <p className="text-[10px] text-[var(--muted-foreground)]">{t("aiQuiz.failed")}</p>
            </div>
          </div>
        </Card>

        <Card className="p-5">
          <div className="flex items-center justify-between mb-3">
            <p className="text-sm font-semibold">{t("admin.aiStatus")}</p>
          </div>
          {providers.loading ? (
            <SkeletonList rows={3} className="h-12" />
          ) : (providers.data?.data.length ?? 0) === 0 ? (
            <p className="text-sm text-[var(--muted-foreground)]">{t("admin.ai.noProviders")}</p>
          ) : (
            <div className="space-y-2.5">
              {(providers.data?.data ?? []).map((provider) => (
                <div key={provider.id} className="flex items-center gap-3">
                  <span
                    className="w-2 h-2 rounded-full shrink-0"
                    style={{
                      background: provider.enabled ? "green" : "var(--muted-foreground)",
                    }}
                  />
                  <p className="text-sm flex-1">{provider.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">
                    {provider.used_today}/{provider.daily_limit}
                  </p>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}

/* ══ Utilisateurs ═══════════════════════════════════════════════════ */

export function AdminUsersScreen() {
  const { t, formatDate, locale } = useI18n();
  const [query, setQuery] = useState("");
  const settledQuery = useDebouncedValue(query);
  const [roleChange, setRoleChange] = useState<{
    id: number;
    role: string;
    name: string;
  } | null>(null);
  const [role, setRole] = useState("");
  const [page, setPage] = useState(1);
  const [notice, setNotice] = useState<string | null>(null);

  const users = useAsync(
    (signal) =>
      admin.users(
        { q: settledQuery || undefined, role: role || undefined, page },
        { locale, signal },
      ),
    [settledQuery, role, page, locale],
  );
  const pendingUsers = useAsync((signal) => admin.pendingUsers({ signal, locale }), [locale]);
  const update = useAction();

  async function changeRole(id: number, nextRole: string) {
    const result = await update.run(() => admin.updateUser(id, { role: nextRole }, { locale }));
    if (result) {
      setNotice(t("admin.users.roleChange"));
      users.reload();
    }
  }

  async function toggleActive(id: number, isActive: boolean) {
    await update.run(() => admin.updateUser(id, { is_active: !isActive }, { locale }));
    users.reload();
  }

  const meta = users.data?.meta;

  return (
    <div>
      <PageHeader
        title={t("admin.users.title")}
        subtitle={t("admin.users.subtitle", { count: meta?.total ?? 0 })}
      />

      {notice && (
        <div className="mb-4">
          <Alert message={notice} type="success" />
        </div>
      )}
      {update.error && (
        <div className="mb-4">
          <Alert message={errorMessage(update.error, t("error.unknown"))} type="error" />
        </div>
      )}

      <div className="flex flex-col sm:flex-row gap-3 mb-4">
        <div className="flex-1">
          <Input
            value={query}
            onChange={(value) => {
              setQuery(value);
              setPage(1);
            }}
            placeholder={t("admin.users.search")}
          />
        </div>
        <div className="sm:w-56">
          <Select
            value={role}
            onChange={(value) => {
              setRole(value);
              setPage(1);
            }}
            options={[
              { value: "", label: t("admin.users.filter.all") },
              { value: "student", label: t("admin.users.filter.student") },
              { value: "teacher", label: t("admin.users.filter.teacher") },
              { value: "admin", label: t("role.admin") },
              { value: "pending", label: t("admin.users.filter.pending") },
              { value: "denied", label: t("role.denied") },
            ]}
          />
        </div>
      </div>

      <AsyncBoundary
        loading={users.loading}
        error={users.error}
        onRetry={users.reload}
        errorMessage={t("common.error")}
        isEmpty={(users.data?.data.length ?? 0) === 0}
        empty={<EmptyState message={t("admin.users.empty")} />}
      >
        <Card className="overflow-x-auto">
          <table className="ui-table ui-responsive-table w-full text-sm min-w-[640px]">
            <thead>
              <tr className="text-left text-xs text-[var(--muted-foreground)] border-b border-[var(--border)]">
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.users.col.user")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.users.col.role")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.users.col.lastLogin")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.users.col.state")}
                </th>
                <th scope="col" className="px-4 py-3">
                  {t("common.edit")}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--border)]">
              {(users.data?.data ?? []).map((user) => (
                <tr key={user.id}>
                  <td data-label={t("admin.users.col.user")} className="px-4 py-3">
                    <p className="font-medium">{user.display_name}</p>
                    {/* L'email n'est visible que dans l'administration (RG-18). */}
                    <p className="text-xs text-[var(--muted-foreground)]">{user.email}</p>
                    <p className="text-xs text-[var(--muted-foreground)]">
                      {t(
                        user.verification_source === "microsoft"
                          ? "auth.sourceMicrosoft"
                          : "auth.sourceUnverified",
                      )}
                    </p>
                  </td>
                  <td data-label={t("admin.users.col.role")} className="px-4 py-3">
                    {user.role_locked && (
                      <Badge
                        label={
                          user.role === "teacher"
                            ? t("auth.approvedTeacher")
                            : t(`role.${user.role}` as "role.admin")
                        }
                        color="purple"
                      />
                    )}
                    <select
                      value={user.role}
                      onChange={(event) =>
                        setRoleChange({
                          id: user.id,
                          role: event.target.value,
                          name: user.display_name,
                        })
                      }
                      disabled={update.pending}
                      className="text-xs px-2 py-1 border border-[var(--border)] rounded-lg bg-white outline-none focus:ring-2 focus:ring-[var(--primary)]"
                      aria-label={`${t("admin.users.col.role")} ${user.display_name}`}
                    >
                      {(["student", "teacher", "admin", "pending", "denied"] as Role[]).map(
                        (option) => (
                          <option key={option} value={option}>
                            {t(`role.${option}` as "role.student")}
                          </option>
                        ),
                      )}
                    </select>
                  </td>
                  <td
                    data-label={t("admin.users.col.lastLogin")}
                    className="px-4 py-3 text-xs text-[var(--muted-foreground)]"
                  >
                    {user.last_login_at ? formatDate(user.last_login_at) : t("admin.users.never")}
                  </td>
                  <td data-label={t("admin.users.col.state")} className="px-4 py-3">
                    <Badge
                      label={user.is_active ? t("admin.users.active") : t("admin.users.inactive")}
                      color={user.is_active ? "green" : "red"}
                    />
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Toggle
                      checked={user.is_active}
                      onChange={() => void toggleActive(user.id, user.is_active)}
                      label={`${t("admin.users.col.state")} ${user.display_name}`}
                      disabled={update.pending}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      </AsyncBoundary>
      {roleChange && (
        <Dialog title={t("confirm.title")} onClose={() => setRoleChange(null)}>
          <p className="mb-4">
            {roleChange.name} → {t(`role.${roleChange.role}` as "role.student")}
          </p>
          <p className="mb-4">{t("school.actionConfirm")}</p>
          <div className="flex gap-2 flex-wrap justify-end">
            <Btn variant="secondary" onClick={() => setRoleChange(null)}>
              {t("common.cancel")}
            </Btn>
            <Btn
              disabled={update.pending}
              onClick={() => {
                void changeRole(roleChange.id, roleChange.role);
                setRoleChange(null);
              }}
            >
              {t("confirm.proceed")}
            </Btn>
          </div>
        </Dialog>
      )}
      <section className="mt-6">
        <h2 className="font-display text-xl mb-3">{t("admin.stat.pendingRoles")}</h2>
        <AsyncBoundary
          loading={pendingUsers.loading}
          error={pendingUsers.error}
          onRetry={pendingUsers.reload}
          errorMessage={t("common.error")}
          isEmpty={!pendingUsers.data?.data.length}
          empty={<EmptyState message={t("admin.users.empty")} />}
        >
          {(pendingUsers.data?.data ?? []).map((user) => (
            <Card key={user.id} className="p-4 flex flex-wrap items-center gap-3 mb-2">
              <div className="flex-1 min-w-0 space-y-1">
                <p className="font-medium">{user.display_name}</p>
                <p className="text-xs break-all text-[var(--muted-foreground)]">{user.email}</p>
                <p className="text-xs">
                  {t(
                    user.role_candidate === "teacher"
                      ? "auth.teacherCandidate"
                      : user.role_candidate === "student"
                        ? "auth.studentCandidate"
                        : "role.pending",
                  )}
                </p>
                <p className="text-xs text-[var(--muted-foreground)]">
                  {t(
                    user.verification_source === "microsoft"
                      ? "auth.sourceMicrosoft"
                      : "auth.sourceUnverified",
                  )}
                </p>
                <Badge
                  label={t(user.is_active === false ? "auth.accountRejected" : "role.pending")}
                  color={user.is_active === false ? "red" : "orange"}
                />
              </div>
              {(["student", "teacher"] as const).map((nextRole) => (
                <ConfirmButton
                  key={nextRole}
                  size="sm"
                  disabled={update.pending || user.is_active === false}
                  onClick={() => void changeRole(user.id, nextRole).then(pendingUsers.reload)}
                >
                  {t(`role.${nextRole}`)}
                </ConfirmButton>
              ))}
              <ConfirmButton
                variant="danger"
                size="sm"
                disabled={update.pending || user.is_active === false}
                onClick={() => void toggleActive(user.id, true).then(pendingUsers.reload)}
              >
                {t("auth.rejectCandidate")}
              </ConfirmButton>
            </Card>
          ))}
        </AsyncBoundary>
      </section>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between mt-4">
          <p className="text-xs text-[var(--muted-foreground)]">
            {t("common.page", {
              page: meta.current_page,
              total: meta.last_page,
            })}
          </p>
          <div className="flex gap-2">
            <Btn
              size="sm"
              variant="secondary"
              disabled={meta.current_page <= 1}
              onClick={() => setPage((value) => value - 1)}
            >
              {t("school.previous")}
            </Btn>
            <Btn
              size="sm"
              variant="secondary"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => setPage((value) => value + 1)}
            >
              {t("school.next")}
            </Btn>
          </div>
        </div>
      )}
    </div>
  );
}

/* ══ Classes ════════════════════════════════════════════════════════ */

export function AdminClassesScreen() {
  const { t, locale } = useI18n();
  const classes = useAsync((signal) => admin.classes({ signal }), []);
  const [notice, setNotice] = useState<string | null>(null);
  const action = useAction();
  const [teacherIds, setTeacherIds] = useState<Record<number, string>>({});
  const teachers = useAsync(
    (signal) => api.get<Page<Person>>("/school/people?role=teacher", { signal, locale }),
    [locale],
  );

  async function archive(id: number) {
    const result = await action.run(() => admin.archiveClass(id, { locale }));
    if (result) {
      setNotice(t("common.saved"));
      classes.reload();
    }
  }

  const list = classes.data?.data ?? [];
  const active = list.filter((item) => item.status === "active");

  return (
    <div>
      <PageHeader
        title={t("admin.classes.title")}
        subtitle={t("admin.classes.subtitle", {
          count: list.length,
          active: active.length,
        })}
      />
      <Alert message={t("ux.legacyHelp")} />
      <nav className="ui-quick-nav my-4">
        <Link to="/app/school">{t("nav.school")}</Link>
      </nav>

      {notice && (
        <div className="mb-4">
          <Alert message={notice} type="success" />
        </div>
      )}
      {action.error && (
        <div className="mb-4">
          <Alert message={errorMessage(action.error, t("error.unknown"))} type="error" />
        </div>
      )}

      <AsyncBoundary
        loading={classes.loading}
        error={classes.error}
        onRetry={classes.reload}
        errorMessage={t("common.error")}
        isEmpty={list.length === 0}
        empty={<EmptyState message={t("admin.classes.empty")} />}
      >
        <Card className="overflow-x-auto">
          <table className="ui-table ui-responsive-table w-full text-sm min-w-[640px]">
            <thead>
              <tr className="text-left text-xs text-[var(--muted-foreground)] border-b border-[var(--border)]">
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.classes.col.class")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.classes.col.teacher")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.classes.col.students")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.classes.col.state")}
                </th>
                <th scope="col" className="px-4 py-3">
                  {t("common.edit")}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--border)]">
              {list.map((item) => (
                <tr key={item.id}>
                  <td data-label={t("admin.classes.col.class")} className="px-4 py-3">
                    <p className="font-medium">{item.name}</p>
                    <p className="text-xs text-[var(--muted-foreground)]">
                      {item.subject} · {item.group_label} · {item.school_year}
                    </p>
                  </td>
                  <td data-label={t("admin.classes.col.teacher")} className="px-4 py-3 text-xs">
                    {item.teacher?.display_name ?? "—"}
                  </td>
                  <td data-label={t("admin.classes.col.students")} className="px-4 py-3 text-xs">
                    {item.members_count}
                    <span className="text-[var(--muted-foreground)] ml-2 font-mono">
                      {item.join_code}
                    </span>
                  </td>
                  <td data-label={t("admin.classes.col.state")} className="px-4 py-3">
                    <Badge
                      label={t(
                        item.status === "archived"
                          ? "class.status.archived"
                          : "class.status.active",
                      )}
                      color={item.status === "archived" ? "default" : "green"}
                    />
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Select
                      label={t("school.teacher")}
                      value={teacherIds[item.id] ?? ""}
                      onChange={(value) => setTeacherIds({ ...teacherIds, [item.id]: value })}
                      options={[
                        { value: "", label: t("school.choose") },
                        ...(teachers.data?.data ?? []).map((p) => ({
                          value: String(p.id),
                          label: p.display_name,
                        })),
                      ]}
                    />
                    <ConfirmButton
                      size="sm"
                      disabled={action.pending || !Number(teacherIds[item.id])}
                      onClick={() =>
                        void action.run(async () => {
                          await admin.transferClass(item.id, Number(teacherIds[item.id]), {
                            locale,
                          });
                          setNotice(t("common.saved"));
                          classes.reload();
                        })
                      }
                    >
                      {t("admin.transfer")}
                    </ConfirmButton>
                    {item.status === "active" && (
                      <ConfirmButton
                        size="sm"
                        variant="ghost"
                        onClick={() => void archive(item.id)}
                        disabled={action.pending}
                      >
                        {t("manage.settings.archive")}
                      </ConfirmButton>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      </AsyncBoundary>
    </div>
  );
}

/* ══ Fournisseurs IA ═══════════════════════════════════════════════ */

export function AdminAiScreen() {
  const { t, locale } = useI18n();
  const providers = useAsync((signal) => admin.aiProviders({ signal }), []);
  const [notice, setNotice] = useState<string | null>(null);
  const update = useAction();
  const reset = useAction();

  async function patch(id: number, payload: { enabled?: boolean; priority?: number }) {
    const result = await update.run(() => admin.updateAiProvider(id, payload, { locale }));
    if (result) {
      setNotice(t("common.saved"));
      providers.reload();
    }
  }

  async function resetQuota(id: number) {
    await reset.run(() => admin.resetAiQuota(id, { locale }));
    providers.reload();
  }

  return (
    <div className="max-w-3xl">
      <PageHeader title={t("admin.ai.title")} subtitle={t("admin.ai.subtitle")} />

      <div className="mb-4">
        <Alert message={t("admin.ai.info")} type="info" />
      </div>
      {notice && (
        <div className="mb-4">
          <Alert message={notice} type="success" />
        </div>
      )}
      {(update.error || reset.error) && (
        <div className="mb-4">
          <Alert
            message={errorMessage(update.error ?? reset.error, t("error.unknown"))}
            type="error"
          />
        </div>
      )}

      <AsyncBoundary
        loading={providers.loading}
        error={providers.error}
        onRetry={providers.reload}
        errorMessage={t("common.error")}
        isEmpty={(providers.data?.data.length ?? 0) === 0}
        empty={<EmptyState message={t("admin.ai.noProviders")} />}
      >
        <div className="space-y-3">
          {(providers.data?.data ?? []).map((provider) => (
            <Card key={provider.id} className="p-5">
              <div className="flex items-center gap-3 mb-3">
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold">{provider.name}</p>
                  <p className="text-xs text-[var(--muted-foreground)]">
                    {t("admin.ai.quota", { count: provider.daily_limit })}
                    {!provider.has_key && ` · ${t("admin.ai.noKey")}`}
                  </p>
                </div>
                <Badge label={t("admin.ai.priority", { n: provider.priority })} color="blue" />
              </div>

              <div className="mb-3">
                <div className="flex items-center justify-between text-xs mb-1">
                  <span className="text-[var(--muted-foreground)]">{t("admin.usageToday")}</span>
                  <span className="font-mono">
                    {provider.used_today}/{provider.daily_limit}
                  </span>
                </div>
                <ProgressBar
                  value={
                    provider.daily_limit > 0
                      ? (provider.used_today / provider.daily_limit) * 100
                      : 0
                  }
                  color="var(--accent)"
                />
              </div>

              <div className="flex items-center gap-4 flex-wrap">
                <EditButton
                  initial={{
                    priority: String(provider.priority),
                    daily_limit: String(provider.daily_limit),
                  }}
                  labels={{
                    priority: t("admin.ai.priority", { n: "" }),
                    daily_limit: t("admin.ai.quota", { count: "" }),
                  }}
                  save={(values) =>
                    admin.updateAiProvider(
                      provider.id,
                      {
                        priority: Number(values.priority),
                        daily_limit: Number(values.daily_limit),
                      },
                      { locale },
                    )
                  }
                  onSaved={providers.reload}
                />
                <Toggle
                  checked={provider.enabled}
                  onChange={(value) => void patch(provider.id, { enabled: value })}
                  label={provider.enabled ? t("class.status.active") : t("admin.ai.disabled")}
                  disabled={update.pending}
                />
                <div className="flex-1" />
                <Btn
                  size="sm"
                  variant="secondary"
                  onClick={() => void resetQuota(provider.id)}
                  disabled={reset.pending}
                >
                  {t("admin.ai.reset")}
                </Btn>
              </div>
            </Card>
          ))}
        </div>
      </AsyncBoundary>

      <Card className="p-5 mt-5">
        <p className="text-sm font-semibold mb-2">{t("admin.ai.globalRules")}</p>
        <ul className="text-xs text-[var(--muted-foreground)] space-y-1.5 list-disc pl-4">
          <li>{t("aiQuiz.needsReview")}</li>
          <li>{t("error.pdfOnly")}</li>
          <li>{t("aiQuiz.quota")}</li>
        </ul>
        <p className="text-xs text-[var(--muted-foreground)] mt-3 flex items-center gap-1.5">
          <Icons.Shield /> RG-14
        </p>
      </Card>
    </div>
  );
}

/* ══ Journal d'audit ════════════════════════════════════════════════ */

const AUDIT_FILTERS = [
  { value: "", key: "admin.audit.filter.all" },
  { value: "auth", key: "admin.audit.filter.auth" },
  { value: "quiz", key: "admin.audit.filter.quiz" },
  { value: "class", key: "admin.audit.filter.class" },
  { value: "ai", key: "admin.audit.filter.ai" },
  { value: "user", key: "admin.audit.filter.user" },
] as const;

export function AdminAuditScreen() {
  const { t, formatDateTime, locale } = useI18n();
  const [action, setAction] = useState("");
  const [page, setPage] = useState(1);
  const [userId, setUserId] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");

  const logs = useAsync(
    (signal) =>
      admin.auditLogs(
        {
          action: action || undefined,
          user_id: userId ? Number(userId) : undefined,
          from: from || undefined,
          to: to || undefined,
          page,
        },
        { locale, signal },
      ),
    [action, page, userId, from, to],
  );

  const meta = logs.data?.meta;
  const list = logs.data?.data ?? [];

  return (
    <div>
      <PageHeader
        title={t("admin.audit.title")}
        subtitle={t("admin.audit.total", { count: meta?.total ?? 0 })}
      />

      <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <Input
          label={t("admin.audit.col.user")}
          type="number"
          value={userId}
          onChange={(value) => {
            setUserId(value);
            setPage(1);
          }}
        />
        <Input
          label={t("audit.from")}
          type="date"
          value={from}
          onChange={(value) => {
            setFrom(value);
            setPage(1);
          }}
        />
        <Input
          label={t("audit.to")}
          type="date"
          value={to}
          onChange={(value) => {
            setTo(value);
            setPage(1);
          }}
        />
        <Select
          value={action}
          onChange={(value) => {
            setAction(value);
            setPage(1);
          }}
          options={AUDIT_FILTERS.map((filter) => ({
            value: filter.value,
            label: t(filter.key),
          }))}
        />
      </div>

      <AsyncBoundary
        loading={logs.loading}
        error={logs.error}
        onRetry={logs.reload}
        errorMessage={t("common.error")}
        isEmpty={list.length === 0}
        empty={<EmptyState message={t("admin.audit.empty")} />}
      >
        <Card className="overflow-x-auto">
          <table className="ui-table ui-responsive-table w-full text-sm min-w-[640px]">
            <thead>
              <tr className="text-left text-xs text-[var(--muted-foreground)] border-b border-[var(--border)]">
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.audit.col.action")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.audit.col.user")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.audit.col.date")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("admin.audit.col.ip")}
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[var(--border)]">
              {list.map((entry) => (
                <tr key={entry.id}>
                  <td data-label={t("admin.audit.col.action")} className="px-4 py-3">
                    <p className="font-mono text-xs">{entry.action}</p>
                    {entry.context && (
                      <p className="text-[10px] text-[var(--muted-foreground)] truncate max-w-xs">
                        {JSON.stringify(entry.context)}
                      </p>
                    )}
                  </td>
                  <td data-label={t("admin.audit.col.user")} className="px-4 py-3 text-xs">
                    {entry.user?.display_name ?? t("admin.audit.system")}
                  </td>
                  <td
                    data-label={t("admin.audit.col.date")}
                    className="px-4 py-3 text-xs text-[var(--muted-foreground)]"
                  >
                    {formatDateTime(entry.created_at)}
                  </td>
                  <td
                    data-label={t("admin.audit.col.ip")}
                    className="px-4 py-3 text-xs font-mono text-[var(--muted-foreground)]"
                  >
                    {entry.ip ?? "—"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      </AsyncBoundary>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between mt-4">
          <p className="text-xs text-[var(--muted-foreground)]">
            {t("common.page", {
              page: meta.current_page,
              total: meta.last_page,
            })}
          </p>
          <div className="flex gap-2">
            <Btn
              size="sm"
              variant="secondary"
              disabled={meta.current_page <= 1}
              onClick={() => setPage((value) => value - 1)}
            >
              {t("school.previous")}
            </Btn>
            <Btn
              size="sm"
              variant="secondary"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => setPage((value) => value + 1)}
            >
              {t("school.next")}
            </Btn>
          </div>
        </div>
      )}
    </div>
  );
}
