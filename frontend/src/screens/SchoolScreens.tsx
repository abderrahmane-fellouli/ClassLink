import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Link, useNavigate, useParams, useSearchParams, useBlocker } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { useI18n } from "../i18n";
import type { TranslationKey } from "../i18n/fr";
import { api, ApiError, errorMessage } from "../lib/api";
import { useAction, useAsync, useDebouncedValue, type AsyncState } from "../lib/useAsync";
import type {
  Assessment,
  GradeEditorData,
  GradeEntry,
  GradeStatus,
  Group,
  ImportPreview,
  Page,
  Person,
  PersonalGrade,
  RosterRow,
  SchoolOverview,
  SchoolThread,
  ThreadView,
  SchoolAssignmentRequest,
  SchoolReport,
  SchoolDeadline,
} from "../lib/school";
import {
  Alert,
  Badge,
  Btn,
  ConfirmButton,
  EmptyState,
  ErrorState,
  Field,
  Dialog,
  PageHeader,
  Spinner,
  Tabs,
  Toggle,
} from "../components/UI";
import { EditButton } from "../components/EditButton";

const inputClass = "w-full min-h-11 rounded-lg border border-[var(--border)] bg-white px-3 py-2";
const panelClass = "ui-card border bg-white p-4 sm:p-6 space-y-4";
const importReasons: Record<string, TranslationKey> = {
  unknown_or_ineligible_student: "ux.importStudent",
  duplicate_student: "ux.importDuplicate",
  invalid_status: "ux.importStatus",
  invalid_score: "ux.importScore",
  score_requires_graded_status: "ux.importGraded",
  feedback_too_long: "ux.importFeedback",
  wrong_column_count: "ux.importColumns",
  wrong_template_context: "ux.importContext",
  invalid_roster_identity: "ux.importIdentity",
  duplicate_roster_identity: "ux.importIdentityDuplicate",
  identifier_email_conflict: "ux.importConflict",
  existing_account_conflict: "ux.importConflict",
  transfer_required: "ux.importTransfer",
};
type FormField = {
  name: string;
  key: TranslationKey;
  type?: string;
  options?: {
    id: number | string;
    name: string;
  }[];
  optional?: boolean;
  value?: string;
};

function Form({
  fields,
  submit,
  onSubmit,
  disabled = false,
}: {
  fields: FormField[];
  submit: TranslationKey;
  onSubmit: (data: Record<string, string>) => Promise<void>;
  disabled?: boolean;
}) {
  const { t } = useI18n();
  const action = useAction();
  const [success, setSuccess] = useState(false);
  const handle = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSuccess(false);
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(event.currentTarget).entries()) as Record<
      string,
      string
    >;
    void action.run(async () => {
      await onSubmit(data);
      if (!fields.some((f) => f.value !== undefined)) form.reset();
      setSuccess(true);
    });
  };
  return (
    <form onSubmit={handle} aria-busy={action.pending} className="grid gap-4 sm:grid-cols-2">
      {fields.map((f) => (
        <Field
          key={f.name}
          label={t(f.key)}
          hint={f.optional ? t("ux.optional") : undefined}
          error={action.error instanceof ApiError ? action.error.errors[f.name]?.[0] : undefined}
        >
          {"options" in f ? (
            <select
              name={f.name}
              className={inputClass}
              required={!f.optional}
              disabled={disabled || action.pending}
              defaultValue={f.value ?? ""}
            >
              <option value="">{t("school.choose")}</option>
              {(f.options ?? []).map((o) => (
                <option key={o.id} value={o.id}>
                  {o.name}
                </option>
              ))}
            </select>
          ) : f.type === "textarea" ? (
            <textarea
              name={f.name}
              className={inputClass}
              rows={3}
              disabled={disabled || action.pending}
              required={!f.optional}
              defaultValue={f.value}
            />
          ) : (
            <input
              name={f.name}
              className={inputClass}
              type={f.type ?? "text"}
              disabled={disabled || action.pending}
              required={!f.optional}
              defaultValue={f.value}
              step={f.type === "number" ? "0.01" : undefined}
            />
          )}
        </Field>
      ))}
      {action.error && (
        <div className="sm:col-span-2">
          <Alert type="error" message={errorMessage(action.error, t("common.error"))} />
        </div>
      )}
      {success && (
        <div className="sm:col-span-2">
          <Alert type="success" message={t("school.success")} />
        </div>
      )}
      <div className="sm:col-span-2">
        <Btn type="submit" disabled={disabled || action.pending}>
          {action.pending ? t("common.loading") : t(submit)}
        </Btn>
      </div>
    </form>
  );
}

function State<T>({ state, children }: { state: AsyncState<T>; children: ReactNode }) {
  const { t } = useI18n();
  if (state.loading && state.data === null) return <Spinner />;
  if (state.error)
    return (
      <ErrorState message={errorMessage(state.error, t("common.error"))} onRetry={state.reload} />
    );
  return (
    <>
      {state.loading && <Spinner />}
      {children}
    </>
  );
}
function Pages({
  page,
  setPage,
  last,
}: {
  page: number;
  setPage: (p: number) => void;
  last: number;
}) {
  const { t } = useI18n();
  if (last <= 1) return null;
  return (
    <nav
      aria-label={t("common.page", { page, total: last })}
      className="flex gap-3 items-center flex-wrap"
    >
      <Btn variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
        {t("school.previous")}
      </Btn>
      <span>
        {page} / {last}
      </span>
      <Btn variant="secondary" disabled={page >= last} onClick={() => setPage(page + 1)}>
        {t("school.next")}
      </Btn>
    </nav>
  );
}

export function SchoolWorkspace() {
  const { role, user } = useAuth();
  const { t, locale, formatDate } = useI18n();
  const data = useAsync((s) => api.get<SchoolOverview>("/school", { locale, signal: s }), [locale]);
  const [selected, setSelected] = useState(0);
  const workspaceAction = useAction();
  const [teacherSearch, setTeacherSearch] = useState("");
  const teacherQuery = useDebouncedValue(teacherSearch);
  const teachers = useAsync(
    (s) =>
      role === "admin"
        ? api.get<Page<Person>>(
            `/school/people?role=teacher${
              teacherQuery ? `&search=${encodeURIComponent(teacherQuery)}` : ""
            }`,
            {
              locale,
              signal: s,
            },
          )
        : Promise.resolve({ data: [] } as unknown as Page<Person>),
    [role, locale, teacherQuery],
  );
  const [search, setSearch] = useState("");
  const groups = data.data?.groups ?? [];
  useEffect(() => {
    if (data.data?.groups?.length && !data.data.groups.some((g) => g.id === selected)) {
      setSelected(
        (
          data.data.groups.find(
            (g) => !g.is_read_only && g.status !== "archived" && !g.module_only_access,
          ) ?? data.data.groups[0]
        ).id,
      );
    }
  }, [data.data, selected]);
  const group = groups.find((g) => g.id === selected);
  return (
    <div className="school-page space-y-5">
      <PageHeader
        title={t("school.title")}
        subtitle={t(
          role === "admin"
            ? "ux.workspaceAdmin"
            : role === "teacher"
              ? "ux.workspaceTeacher"
              : "ux.workspaceStudent",
        )}
        actions={
          <Btn variant="secondary" disabled={data.loading} onClick={data.reload}>
            {t("ux.refresh")}
          </Btn>
        }
      />
      {workspaceAction.error && <Alert type="error" message={workspaceAction.error.message} />}
      <nav className="ui-quick-nav" aria-label={t("ux.quickActions")}>
        <Link to="/app/school/messages">{t("school.messages")}</Link>
        {role === "student" && (
          <>
            <Link to="/app/school/grades">{t("school.grades")}</Link>
            <Link to="/app/progression">{t("progress.fullHistory")}</Link>
            <Link to="/app/join">{t("nav.join")}</Link>
          </>
        )}
        <Link to="/app/classes">{t("school.legacy")}</Link>
      </nav>
      <State state={data}>
        {(role === "teacher" || role === "admin") && (
          <AssignmentRequests overview={data.data} reload={data.reload} />
        )}
        {role === "student" && <SchoolDeadlinesPanel />}
        {role === "student" && <SchoolStudentUpdates />}
        {role === "admin" && (
          <details className={panelClass} open={groups.length === 0}>
            <summary>{t("ux.setup")}</summary>
            <h2 className="font-semibold">{t("school.setup")}</h2>
            <p>{t("school.setupGuide")}</p>
            {data.data?.years?.map((y) => (
              <details key={y.id} className="border rounded-lg p-3">
                <summary className="min-h-11 cursor-pointer flex items-center gap-3 flex-wrap">
                  <span>{y.name}</span>
                  <span className="text-sm opacity-70">
                    {formatDate(y.starts_on)} → {formatDate(y.ends_on)}
                  </span>
                  {y.status === "archived" && <span>{t("school.archived")}</span>}
                </summary>
                <Form
                  key={`year-edit-${y.id}`}
                  fields={[
                    { name: "name", key: "school.name", value: y.name },
                    ...(y.status === "archived"
                      ? []
                      : ([
                          {
                            name: "starts_on",
                            key: "school.starts",
                            type: "date",
                            value: y.starts_on ?? "",
                          },
                          {
                            name: "ends_on",
                            key: "school.ends",
                            type: "date",
                            value: y.ends_on ?? "",
                          },
                        ] as FormField[])),
                  ]}
                  submit="school.edit"
                  onSubmit={async (d) => {
                    await api.patch(`/school/years/${y.id}`, d, { locale });
                    data.reload();
                  }}
                />
                {y.status !== "archived" && (
                  <ConfirmButton
                    disabled={workspaceAction.pending}
                    message={t("school.actionConfirm")}
                    onClick={() => {
                      void workspaceAction.run(async () => {
                        await api.post(
                          `/school/years/${y.id}/archive`,
                          {},
                          {
                            locale,
                          },
                        );
                        data.reload();
                      });
                    }}
                  >
                    {t("school.archive")}
                  </ConfirmButton>
                )}
              </details>
            ))}
            <Link to="/app/admin/users">{t("nav.adminUsers")}</Link>
            <details>
              <summary className="min-h-11 cursor-pointer">{t("school.year")}</summary>
              <Form
                fields={[
                  { name: "name", key: "school.name" },
                  { name: "starts_on", key: "school.starts", type: "date" },
                  { name: "ends_on", key: "school.ends", type: "date" },
                ]}
                submit="school.create"
                onSubmit={async (d) => {
                  await api.post("/school/years", d, { locale });
                  data.reload();
                }}
              />
            </details>
            <details>
              <summary className="min-h-11 cursor-pointer">{t("school.groups")}</summary>
              <Form
                fields={[
                  {
                    name: "academic_year_id",
                    key: "school.year",
                    options: data.data?.years?.map((y) => ({
                      id: y.id,
                      name: y.name,
                    })),
                  },
                  { name: "official_code", key: "school.code" },
                  { name: "name", key: "school.name" },
                  { name: "filiere", key: "school.filiere" },
                  { name: "level", key: "school.level" },
                ]}
                submit="school.create"
                onSubmit={async (d) => {
                  await api.post("/school/groups", d, { locale });
                  data.reload();
                }}
              />
            </details>
            <details>
              <summary className="min-h-11 cursor-pointer">{t("school.modules")}</summary>
              <Form
                fields={[
                  { name: "code", key: "school.code" },
                  { name: "name", key: "school.name" },
                ]}
                submit="school.create"
                onSubmit={async (d) => {
                  await api.post("/school/modules", d, { locale });
                  data.reload();
                }}
              />
              {data.data?.modules?.map((m) => (
                <Form
                  key={`module-edit-${m.id}`}
                  fields={[
                    { name: "code", key: "school.code", value: m.code },
                    { name: "name", key: "school.name", value: m.name },
                    {
                      name: "status",
                      key: "school.status",
                      value: m.status ?? "active",
                      options: [
                        { id: "active", name: t("school.statusActive") },
                        { id: "archived", name: t("school.statusArchived") },
                      ],
                    },
                  ]}
                  submit="school.edit"
                  onSubmit={async (d) => {
                    await api.patch(`/school/modules/${m.id}`, d, {
                      locale,
                    });
                    data.reload();
                  }}
                />
              ))}
            </details>
          </details>
        )}
        {groups.length === 0 && (
          <Alert message={t(role === "teacher" ? "school.noOffering" : "school.noGroup")} />
        )}
        {role === "student" && !groups.length && (
          <nav className="ui-quick-nav">
            <Link to="/app/join">{t("join.title")}</Link>
          </nav>
        )}
        {role === "student" && data.data?.delegates.some((d) => d.student_id === user?.id) && (
          <section className={panelClass}>
            <Badge label={t("ux.delegateRole")} color="blue" />
            <p>{t("ux.delegateHelp")}</p>
            <nav className="ui-quick-nav">
              <Link to="/app/school/messages">{t("school.representation")}</Link>
            </nav>
          </section>
        )}
        <Field label={t("school.search")}>
          <input
            className={inputClass}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </Field>
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {groups
            .filter((g) =>
              `${g.name} ${g.official_code}`.toLowerCase().includes(search.toLowerCase()),
            )
            .map((g) => (
              <button
                key={g.id}
                className={`${panelClass} text-left ${
                  selected === g.id ? "border-[var(--primary)]" : ""
                }`}
                aria-pressed={selected === g.id}
                onClick={() => setSelected(g.id)}
              >
                <strong>{g.name}</strong>
                <p>
                  {g.official_code} · {g.school_year}
                </p>
                {g.status === "archived" && <p>{t("school.archived")}</p>}
              </button>
            ))}
        </div>
        {groups.length > 0 &&
          !groups.some((g) =>
            `${g.name} ${g.official_code}`.toLowerCase().includes(search.toLowerCase()),
          ) && <EmptyState message={t("ux.noSearchResults")} />}
        {group && (
          <section className={panelClass}>
            <h2 className="text-xl font-semibold">{group.name}</h2>
            {role === "admin" && (
              <Field label={t("school.teacher")} hint={t("common.search")}>
                <input
                  className={inputClass}
                  type="search"
                  value={teacherSearch}
                  onChange={(e) => setTeacherSearch(e.target.value)}
                />
              </Field>
            )}
            {role === "admin" && teachers.error && (
              <ErrorState
                message={errorMessage(teachers.error, t("common.error"))}
                onRetry={teachers.reload}
              />
            )}
            {role === "admin" && (
              <details>
                <summary>{t("ux.groupSettings")}</summary>
                <Form
                  key={`group-edit-${group.id}`}
                  fields={[
                    { name: "name", key: "school.name", value: group.name },
                    {
                      name: "filiere",
                      key: "school.filiere",
                      value: group.filiere ?? "",
                    },
                    {
                      name: "level",
                      key: "school.level",
                      value: group.level ?? "",
                    },
                  ]}
                  submit="school.edit"
                  onSubmit={async (d) => {
                    await api.patch(
                      `/school/groups/${group.id}`,
                      Object.fromEntries(Object.entries(d).filter(([, v]) => v !== "")),
                      { locale },
                    );
                    data.reload();
                  }}
                />
                <Toggle
                  label={t("ux.delegateNotices")}
                  checked={group.delegate_notices_enabled ?? false}
                  disabled={workspaceAction.pending || !!group.is_read_only}
                  onChange={(enabled) =>
                    void workspaceAction.run(async () => {
                      await api.patch(
                        `/school/groups/${group.id}`,
                        { delegate_notices_enabled: enabled },
                        { locale },
                      );
                      data.reload();
                    })
                  }
                />
                <p className="text-sm text-[var(--muted-foreground)]">
                  {t("ux.delegateNoticesHelp")}
                </p>
              </details>
            )}
            {role === "admin" && group.status === "archived" && (
              <ConfirmButton
                message={t("school.actionConfirm")}
                disabled={workspaceAction.pending}
                onClick={() => {
                  void workspaceAction.run(async () => {
                    await api.post(
                      `/school/groups/${group.id}/restore`,
                      {},
                      {
                        locale,
                      },
                    );
                    data.reload();
                  });
                }}
              >
                {t("school.restore")}
              </ConfirmButton>
            )}
            {role === "admin" && group.status !== "archived" && !group.is_read_only && (
              <>
                <Form
                  key={`module-${group.id}`}
                  fields={[
                    {
                      name: "module_id",
                      key: "school.modules",
                      options: data.data?.modules?.map((m) => ({
                        id: m.id,
                        name: `${m.code} — ${m.name}`,
                      })),
                    },
                  ]}
                  submit="school.assign"
                  onSubmit={async (d) => {
                    await api.post(`/school/groups/${group.id}/offerings`, d, {
                      locale,
                    });
                    data.reload();
                  }}
                />
                <Form
                  key={`coordinator-${group.id}`}
                  fields={[
                    {
                      name: "coordinator_id",
                      key: "school.coordinator",
                      options:
                        teachers.data?.data.map((p) => ({
                          id: p.id,
                          name: p.display_name,
                        })) ?? [],
                    },
                    {
                      name: "grant",
                      key: "school.status",
                      options: [
                        { id: "true", name: t("ux.allowRoster") },
                        { id: "false", name: t("ux.denyRoster") },
                      ],
                    },
                  ]}
                  submit="school.approve"
                  onSubmit={async (d) => {
                    await api.put(
                      `/school/groups/${group.id}/coordinator`,
                      {
                        coordinator_id: +d.coordinator_id,
                        coordinator_can_manage_roster: d.grant === "true",
                      },
                      { locale },
                    );
                    data.reload();
                  }}
                />
                <GroupActions group={group} reload={data.reload} />
              </>
            )}
            {(data.data?.offerings ?? [])
              .filter((o) => o.classroom_id === group.id)
              .map((o) => (
                <div className="border rounded-lg p-3 space-y-3" key={o.id}>
                  <h3 className="font-semibold">
                    {o.module_code} — {o.module_name}
                  </h3>
                  <p>
                    {data.data?.teachers
                      .filter((a) => a.offering_id === o.id)
                      .map((a) => a.display_name)
                      .join(", ") || t("school.noOffering")}
                  </p>
                  {role !== "admin" && (
                    <Link to={`/app/school/offerings/${o.id}`}>{t("school.open")}</Link>
                  )}
                  {role === "admin" && (
                    <>
                      <Form
                        fields={[
                          {
                            name: "teacher_id",
                            key: "school.teacher",
                            options:
                              teachers.data?.data.map((p) => ({
                                id: p.id,
                                name: p.display_name,
                              })) ?? [],
                          },
                        ]}
                        submit="school.assign"
                        onSubmit={async (d) => {
                          await api.post(`/school/offerings/${o.id}/teachers`, d, { locale });
                          data.reload();
                        }}
                      />
                      {data.data?.teachers
                        .filter((a) => a.offering_id === o.id)
                        .map((a) => (
                          <RevokeAssignment
                            key={a.id}
                            id={a.id}
                            name={a.display_name}
                            reload={data.reload}
                          />
                        ))}
                      <div className="flex gap-3 items-center flex-wrap">
                        {o.status === "archived" && <span>{t("school.archived")}</span>}
                        {o.status === "archived" ? (
                          <Btn
                            variant="secondary"
                            disabled={workspaceAction.pending}
                            onClick={() => {
                              void workspaceAction.run(async () => {
                                await api.patch(
                                  `/school/offerings/${o.id}`,
                                  { status: "active" },
                                  { locale },
                                );
                                data.reload();
                              });
                            }}
                          >
                            {t("school.restore")}
                          </Btn>
                        ) : (
                          <ConfirmButton
                            message={t("school.actionConfirm")}
                            disabled={workspaceAction.pending}
                            onClick={() => {
                              void workspaceAction.run(async () => {
                                await api.patch(
                                  `/school/offerings/${o.id}`,
                                  { status: "archived" },
                                  { locale },
                                );
                                data.reload();
                              });
                            }}
                          >
                            {t("school.archive")}
                          </ConfirmButton>
                        )}
                      </div>
                      <ModuleAccessPanel offeringId={o.id} readOnly={!!group.is_read_only} />
                    </>
                  )}
                </div>
              ))}
            {!group.module_only_access && (
              <RosterPanel
                key={group.id}
                group={group}
                overview={data.data!}
                reload={data.reload}
              />
            )}
          </section>
        )}
      </State>
    </div>
  );
}

function AssignmentRequests({
  overview,
  reload,
}: {
  overview: SchoolOverview | null;
  reload: () => void;
}) {
  const { role } = useAuth();
  const { t, locale } = useI18n();
  const [page, setPage] = useState(1);
  const requests = useAsync(
    (s) =>
      api.get<Page<SchoolAssignmentRequest>>(`/school/assignment-requests?page=${page}`, {
        locale,
        signal: s,
      }),
    [page, locale],
  );
  return (
    <section className={panelClass}>
      <h2 className="font-semibold">
        {t(role === "admin" ? "nav.requests" : "school.requestAssignment")}
      </h2>
      {role === "teacher" && (
        <>
          <details>
            <summary>{t("school.requestAssignment")}</summary>
            <p className="mb-3 text-sm">{t("ux.requestHelp")}</p>
            <Form
              fields={[
                {
                  name: "offering_id",
                  key: "school.modules",
                  options:
                    overview?.available_offerings?.map((o) => ({
                      id: o.id,
                      name: `${o.classroom_name} — ${o.module_name} (${o.school_year})`,
                    })) ?? [],
                },
                { name: "reason", key: "ux.reason", type: "textarea" },
              ]}
              submit="school.requestAssignment"
              onSubmit={async (d) => {
                await api.post(
                  `/school/offerings/${d.offering_id}/assignment-request`,
                  { reason: d.reason },
                  { locale },
                );
                requests.reload();
              }}
            />
          </details>
          <details>
            <summary className="min-h-11 cursor-pointer">{t("school.missingSpace")}</summary>
            <Form
              fields={[
                { name: "requested_group_code", key: "school.groups" },
                { name: "requested_module_code", key: "school.modules" },
                { name: "requested_year", key: "school.year" },
                { name: "reason", key: "ux.reason", type: "textarea" },
              ]}
              submit="school.send"
              onSubmit={async (d) => {
                await api.post("/school/setup-requests", d, { locale });
                requests.reload();
              }}
            />
          </details>
        </>
      )}
      <State state={requests}>
        <ul className="space-y-3">
          {requests.data?.data.map((r) => (
            <li key={r.id} className="border rounded-lg p-3 space-y-2">
              <p>
                {r.display_name} — {r.classroom_name ?? r.requested_group_code} —{" "}
                {r.module_name ?? r.requested_module_code} ·{" "}
                {t(
                  r.status === "pending"
                    ? "ux.statusPending"
                    : r.status === "approved"
                      ? "ux.statusApproved"
                      : "ux.statusRejected",
                )}
              </p>
              <p className="whitespace-pre-wrap break-words">{r.reason}</p>
              {role === "admin" && r.status === "pending" && (
                <Form
                  fields={[
                    {
                      name: "offering_id",
                      key: "school.modules",
                      options:
                        overview?.offerings?.map((o) => ({
                          id: o.id,
                          name: `${overview.groups.find((g) => g.id === o.classroom_id)?.name ?? ""} — ${o.module_name}`,
                        })) ?? [],
                    },
                  ]}
                  submit="school.assign"
                  onSubmit={async (d) => {
                    await api.post(`/school/assignment-requests/${r.id}/resolve`, d, { locale });
                    requests.reload();
                    reload();
                  }}
                />
              )}
            </li>
          ))}
        </ul>
        {!requests.data?.data.length && (
          <p className="text-sm text-[var(--muted-foreground)]">{t("ux.requestsEmpty")}</p>
        )}
        <Pages page={page} setPage={setPage} last={requests.data?.last_page ?? 1} />
      </State>
    </section>
  );
}

function RevokeAssignment({ id, name, reload }: { id: number; name: string; reload: () => void }) {
  const { t, locale } = useI18n();
  const action = useAction();
  return (
    <div>
      <ConfirmButton
        disabled={action.pending}
        message={t("school.actionConfirm")}
        onClick={() => {
          void action.run(async () => {
            await api.delete(`/school/teaching-assignments/${id}`, { locale });
            reload();
          });
        }}
      >
        {t("school.revoke")} — {name}
      </ConfirmButton>
      {action.error && <Alert type="error" message={action.error.message} />}
    </div>
  );
}
interface GroupActionProps {
  group: Group;
  reload: () => void;
}
function GroupActions({ group, reload }: GroupActionProps) {
  const { t, locale } = useI18n();
  const action = useAction();
  const [code, setCode] = useState("");
  const invite = (enabled: boolean, regenerate = false) => {
    void action.run(async () => {
      const d = await api.post<{ join_code: string }>(
        `/school/groups/${group.id}/invitation`,
        { enabled, ...(regenerate ? { regenerate: true } : {}) },
        { locale },
      );
      setCode(d.join_code);
      reload();
    });
  };
  return (
    <div className="flex gap-3 flex-wrap items-center">
      <Btn
        disabled={action.pending}
        onClick={() => {
          void action.run(async () => {
            const d = await api.post<{ join_code: string }>(
              `/school/groups/${group.id}/activate`,
              {},
              { locale },
            );
            setCode(d.join_code);
            reload();
          });
        }}
      >
        {t("school.activate")}
      </Btn>
      <ConfirmButton
        message={t("school.actionConfirm")}
        disabled={action.pending}
        onClick={() => {
          void action.run(async () => {
            await api.post(`/school/groups/${group.id}/archive`, {}, { locale });
            reload();
          });
        }}
      >
        {t("school.archive")}
      </ConfirmButton>
      <span className="text-sm">
        {code || (group.join_enabled ? t("school.joinEnabled") : t("school.joinDisabled"))}
      </span>
      <Btn
        variant="secondary"
        disabled={action.pending}
        onClick={() => invite(!group.join_enabled)}
      >
        {group.join_enabled ? t("school.invitationOff") : t("school.invitationOn")}
      </Btn>
      <ConfirmButton
        variant="secondary"
        message={t("school.actionConfirm")}
        disabled={action.pending}
        onClick={() => invite(group.join_enabled, true)}
      >
        {t("school.regenerateCode")}
      </ConfirmButton>
      {action.error && <Alert type="error" message={action.error.message} />}
    </div>
  );
}

function ModuleAccessPanel({ offeringId, readOnly }: { offeringId: number; readOnly: boolean }) {
  const { t, locale, formatDateTime } = useI18n();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const query = useDebouncedValue(search);
  const people = useAsync(
    (s) =>
      open
        ? api.get<Page<Person>>(
            `/school/people?role=student${query ? `&search=${encodeURIComponent(query)}` : ""}`,
            { signal: s, locale },
          )
        : Promise.resolve(null),
    [open, query, locale],
  );
  const grants = useAsync(
    (s) =>
      open
        ? api.get<{
            data: {
              student_id: number;
              display_name: string;
              reason: string;
              expires_at: string;
            }[];
          }>(`/school/offerings/${offeringId}/access-grants`, {
            signal: s,
            locale,
          })
        : Promise.resolve(null),
    [open, offeringId, locale],
  );
  const action = useAction();
  return (
    <details onToggle={(e) => setOpen(e.currentTarget.open)}>
      <summary>{t("ux.moduleAccess")}</summary>
      <p className="mb-3">{t("ux.moduleAccessHelp")}</p>
      <State state={grants}>
        <ul className="space-y-2">
          {grants.data?.data.map((g) => (
            <li key={g.student_id} className="flex flex-wrap gap-3 items-center">
              <span>
                {g.display_name} · {formatDateTime(g.expires_at)}
              </span>
              <ConfirmButton
                size="sm"
                variant="danger"
                disabled={action.pending || readOnly}
                onClick={() =>
                  void action.run(async () => {
                    await api.delete(
                      `/school/offerings/${offeringId}/access-grants/${g.student_id}`,
                      { locale },
                    );
                    grants.reload();
                  })
                }
              >
                {t("school.revoke")}
              </ConfirmButton>
            </li>
          ))}
        </ul>
      </State>
      {!readOnly && (
        <>
          <Field label={t("school.search")}>
            <input
              className={inputClass}
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </Field>
          <State state={people}>
            <Form
              fields={[
                {
                  name: "student_id",
                  key: "school.student",
                  options:
                    people.data?.data.map((p) => ({
                      id: p.id,
                      name: p.display_name,
                    })) ?? [],
                },
                { name: "reason", key: "ux.reason", type: "textarea" },
                {
                  name: "expires_at",
                  key: "school.ends",
                  type: "datetime-local",
                },
              ]}
              submit="school.assign"
              onSubmit={async (d) => {
                await api.post(`/school/offerings/${offeringId}/access-grants`, d, { locale });
                grants.reload();
              }}
            />
          </State>
        </>
      )}
      {action.error && (
        <Alert type="error" message={errorMessage(action.error, t("common.error"))} />
      )}
    </details>
  );
}

function RosterPanel({
  group,
  overview,
  reload,
}: {
  group: Group;
  overview: SchoolOverview;
  reload: () => void;
}) {
  const { user, role } = useAuth();
  const { t, locale, formatDate } = useI18n();
  const [page, setPage] = useState(1);
  const canManage =
    role === "admin" || (user?.id === group.coordinator_id && group.coordinator_can_manage_roster);
  const [search, setSearch] = useState("");
  const lookup = useDebouncedValue(search);
  const people = useAsync(
    (s) =>
      role === "admin"
        ? api.get<Page<Person>>(
            `/school/people?role=student${lookup ? `&search=${encodeURIComponent(lookup)}` : ""}`,
            { locale, signal: s },
          )
        : Promise.resolve(null),
    [role, lookup, locale],
  );
  const [rosterSearch, setRosterSearch] = useState("");
  const rosterQuery = useDebouncedValue(rosterSearch);
  const roster = useAsync(
    (s) =>
      api.get<Page<RosterRow>>(
        `/school/groups/${group.id}/roster?page=${page}${
          rosterQuery ? `&search=${encodeURIComponent(rosterQuery)}` : ""
        }`,
        { locale, signal: s },
      ),
    [group.id, page, locale, rosterQuery],
  );
  const requests = useAsync(
    (s) =>
      canManage
        ? api.get<
            Page<{
              id: number;
              student_id: number;
              display_name: string;
            }>
          >(`/school/groups/${group.id}/requests`, { locale, signal: s })
        : Promise.resolve({ data: [] } as unknown as Page<{
            id: number;
            student_id: number;
            display_name: string;
          }>),
    [group.id, canManage, locale],
  );
  const action = useAction();
  const update = () => {
    roster.reload();
    requests.reload();
    reload();
  };
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<{
    batch_id: string;
    errors: {
      row: number;
      reason: string;
    }[];
    new_accounts: number;
    rows: {
      student_identifier: string;
      display_name: string;
    }[];
  } | null>(null);
  return (
    <div className="space-y-4">
      <h3 className="font-semibold">{t("school.roster")}</h3>
      <Field label={t("school.search")}>
        <input
          className={inputClass}
          type="search"
          value={rosterSearch}
          onChange={(e) => {
            setRosterSearch(e.target.value);
            setPage(1);
          }}
        />
      </Field>
      <State state={roster}>
        <ul>
          {roster.data?.data.map((p) => (
            <li
              key={p.student_id}
              className="py-2 flex items-center justify-between gap-3 flex-wrap"
            >
              <span>
                {role === "student"
                  ? p.display_name
                  : `${p.school_identifier ?? p.student_id} — ${p.display_name}`}
              </span>
              {canManage && group.status !== "archived" && !group.is_read_only && (
                <ConfirmButton
                  variant="danger"
                  message={t("school.actionConfirm")}
                  disabled={action.pending}
                  onClick={() => {
                    void action.run(async () => {
                      await api.delete(`/school/groups/${group.id}/enrollments/${p.student_id}`, {
                        locale,
                      });
                      update();
                    });
                  }}
                >
                  {t("school.removeStudent")}
                </ConfirmButton>
              )}
            </li>
          ))}
        </ul>
        {!roster.data?.data.length && (
          <p className="text-sm text-[var(--muted-foreground)]">
            {t(rosterSearch ? "ux.noSearchResults" : "ux.noRoster")}
          </p>
        )}
        <Pages page={page} setPage={setPage} last={roster.data?.last_page ?? 1} />
      </State>
      {canManage && group.status !== "archived" && !group.is_read_only && (
        <details>
          <summary>{t("ux.manageRoster")}</summary>
          {role === "admin" && (
            <>
              <Field label={t("common.search")} hint={t("school.student")}>
                <input
                  className={inputClass}
                  type="search"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </Field>
              <State state={people}>
                <Form
                  fields={[
                    {
                      name: "student_id",
                      key: "school.student",
                      options:
                        people.data?.data.map((p) => ({
                          id: p.id,
                          name: p.display_name,
                        })) ?? [],
                    },
                  ]}
                  submit="school.enroll"
                  onSubmit={async (d) => {
                    await api.post(`/school/groups/${group.id}/enrollments`, d, {
                      locale,
                    });
                    update();
                  }}
                />
              </State>
            </>
          )}
          {requests.data?.data.map((p) => (
            <div key={p.id} className="flex gap-3 items-center flex-wrap">
              <span>{p.display_name}</span>
              {(["accepted", "rejected"] as const).map((decision) => (
                <Btn
                  key={decision}
                  disabled={action.pending}
                  onClick={() => {
                    void action.run(async () => {
                      await api.post(
                        `/school/memberships/${p.id}/decision`,
                        { decision },
                        { locale },
                      );
                      update();
                    });
                  }}
                >
                  {t(decision === "accepted" ? "school.approve" : "school.reject")}
                </Btn>
              ))}
            </div>
          ))}
          <h4>{t("school.rosterImport")}</h4>
          <p>{t("school.rosterHelp")}</p>
          <Field label={t("school.rosterText")}>
            <input
              type="file"
              accept=".csv,.txt"
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null);
                setPreview(null);
              }}
            />
          </Field>
          <Btn
            disabled={!file || action.pending}
            onClick={() => {
              void action.run(async () => {
                const fd = new FormData();
                fd.set("file", file!);
                setPreview(
                  await api.postForm(`/school/groups/${group.id}/roster-imports/preview`, fd, {
                    locale,
                  }),
                );
              });
            }}
          >
            {t("school.import")}
          </Btn>
          {preview && (
            <div>
              <p>
                {preview.rows.length} · {t("school.errors")}: {preview.errors.length}
              </p>
              <ul>
                {preview.rows.slice(0, 10).map((p) => (
                  <li key={p.student_identifier}>
                    {p.student_identifier} — {p.display_name}
                  </li>
                ))}
                {preview.errors.map((e) => (
                  <li key={e.row}>
                    {e.row}: {t(importReasons[e.reason] ?? "ux.importUnknown")}
                  </li>
                ))}
              </ul>
              <ConfirmButton
                message={t("school.actionConfirm")}
                disabled={preview.errors.length > 0 || action.pending}
                onClick={() => {
                  void action.run(async () => {
                    await api.post(
                      `/school/groups/${group.id}/roster-imports/commit`,
                      { batch_id: preview.batch_id },
                      { locale },
                    );
                    setPreview(null);
                    update();
                  });
                }}
              >
                {t("school.confirmRoster")}
              </ConfirmButton>
            </div>
          )}
        </details>
      )}
      <h3>{t("school.delegate")}</h3>
      {role === "admin" && group.status !== "archived" && !group.is_read_only && (
        <Form
          fields={[
            {
              name: "student_id",
              key: "school.student",
              options:
                roster.data?.data.map((p) => ({
                  id: p.student_id,
                  name: p.display_name,
                })) ?? [],
            },
            {
              name: "target_group_id",
              key: "school.groups",
              options: overview.groups
                .filter(
                  (g) =>
                    g.id !== group.id &&
                    g.academic_year_id === group.academic_year_id &&
                    !g.is_read_only &&
                    g.status !== "archived",
                )
                .map((g) => ({ id: g.id, name: g.name })),
            },
          ]}
          submit="school.transfer"
          onSubmit={async (d) => {
            await api.post(
              `/school/groups/${group.id}/enrollments/${d.student_id}/transfer`,
              { target_group_id: +d.target_group_id },
              { locale },
            );
            update();
          }}
        />
      )}
      {overview.delegates
        .filter((d) => d.classroom_id === group.id)
        .map((d) => (
          <div key={d.id} className="flex gap-3 flex-wrap">
            <span>
              {d.display_name} · {formatDate(d.ends_at)}
            </span>
            {role === "admin" && (
              <ConfirmButton
                message={t("school.actionConfirm")}
                disabled={action.pending}
                onClick={() => {
                  void action.run(async () => {
                    await api.delete(`/school/delegates/${d.id}`, { locale });
                    update();
                  });
                }}
              >
                {t("school.revoke")}
              </ConfirmButton>
            )}
          </div>
        ))}
      {role === "admin" && group.status !== "archived" && !group.is_read_only && (
        <Form
          fields={[
            {
              name: "student_id",
              key: "school.student",
              options:
                roster.data?.data.map((p) => ({
                  id: p.student_id,
                  name: p.display_name,
                })) ?? [],
            },
            { name: "ends_at", key: "school.ends", type: "date" },
          ]}
          submit="school.appoint"
          onSubmit={async (d) => {
            await api.post(`/school/groups/${group.id}/delegates`, d, {
              locale,
            });
            update();
          }}
        />
      )}{" "}
      {action.error && <Alert type="error" message={action.error.message} />}
    </div>
  );
}

export function ModuleSpace() {
  const { offeringId = "" } = useParams();
  const navigate = useNavigate();
  const { role } = useAuth();
  const { t, locale, formatDateTime } = useI18n();
  const [tab, setTab] = useState("materials");
  const [page, setPage] = useState(1);
  const overview = useAsync(
    (s) => api.get<SchoolOverview>("/school", { locale, signal: s }),
    [locale],
  );
  const offering = overview.data?.offerings.find((o) => o.id === +offeringId);
  const group = overview.data?.groups.find((g) => g.id === offering?.classroom_id);
  const items = useAsync(
    (s) =>
      api.get<{
        data: {
          id: number;
          title: string;
          instructions?: string;
          body?: string;
          url?: string;
          has_file?: boolean;
          status?: string;
          publication_status?: string;
          due_at?: string;
        }[];
      }>(`/school/offerings/${offeringId}/tools/${tab}`, { locale, signal: s }),
    [offeringId, tab, locale],
  );
  const assessments = useAsync(
    (s) =>
      role === "teacher"
        ? api.get<Page<Assessment>>(`/school/offerings/${offeringId}/assessments?page=${page}`, {
            locale,
            signal: s,
          })
        : Promise.resolve({ data: [] } as unknown as Page<Assessment>),
    [offeringId, role, locale, page],
  );
  const action = useAction();
  const [file, setFile] = useState<File | null>(null);
  const resourceFileRef = useRef<HTMLInputElement>(null);
  const [title, setTitle] = useState("");
  const [deckTitle, setDeckTitle] = useState("");
  const [deckCards, setDeckCards] = useState<{ front: string; back: string }[]>([
    { front: "", back: "" },
  ]);
  return (
    <div className="school-page space-y-5">
      <Link to="/app/school">{t("school.back")}</Link>
      <PageHeader
        title={offering?.module_name ?? t("school.modules")}
        subtitle={group ? `${group.name} · ${group.school_year}` : undefined}
      />
      {group?.status === "archived" && <Alert message={t("school.archived")} />}
      <Tabs
        active={tab}
        onChange={setTab}
        tabs={(["materials", "assignments", "announcements", "quizzes", "flashcards"] as const).map(
          (kind) => ({
            id: kind,
            label: t(
              (
                {
                  materials: "school.resources",
                  assignments: "school.assignment",
                  announcements: "school.notice",
                  quizzes: "school.practice",
                  flashcards: "school.flashcards",
                } as const
              )[kind],
            ),
          }),
        )}
      />
      <State state={items}>
        <div className="grid sm:grid-cols-2 gap-3">
          {items.data?.data.map((item) => (
            <article key={item.id} className={panelClass}>
              <h2 className="font-semibold">{item.title}</h2>
              {item.due_at && <p>{formatDateTime(item.due_at)}</p>}
              {item.body && <p className="whitespace-pre-wrap break-words">{item.body}</p>}
              {item.instructions && (
                <p className="whitespace-pre-wrap break-words">{item.instructions}</p>
              )}
              {item.publication_status && (
                <p>
                  {t(
                    (
                      {
                        draft: "school.draft",
                        published: "school.published",
                        closed: "school.closed",
                      } as const
                    )[item.publication_status as "draft" | "published" | "closed"] ??
                      "school.status",
                  )}
                </p>
              )}
              {tab === "materials" &&
                (item.url ? (
                  <a href={item.url} target="_blank" rel="noopener noreferrer">
                    {t("school.open")}
                  </a>
                ) : (
                  <Btn
                    onClick={() => {
                      void action.run(() =>
                        api.download(`/materials/${item.id}/download`, `resource-${item.id}`, {
                          locale,
                        }),
                      );
                    }}
                  >
                    {t("school.download")}
                  </Btn>
                ))}
              {tab === "assignments" && (
                <>
                  {role === "student" ? (
                    <Link to={`/app/assignments/${item.id}`}>{t("school.open")}</Link>
                  ) : (
                    <>
                      <Link to={`/app/classes/${group?.id}/manage/assignments/${item.id}/grade`}>
                        {t("school.roster")}
                      </Link>
                      {(item.publication_status === "draft"
                        ? ["publish"]
                        : item.publication_status === "published"
                          ? ["close"]
                          : []
                      ).map((change) => (
                        <ConfirmButton
                          key={change}
                          disabled={
                            action.pending ||
                            !!group?.is_read_only ||
                            offering?.status === "archived"
                          }
                          message={t("school.actionConfirm")}
                          onClick={() => {
                            void action.run(async () => {
                              await api.post(
                                `/school/offerings/${offeringId}/tools/assignments/${item.id}/${change}`,
                                {},
                                { locale },
                              );
                              items.reload();
                            });
                          }}
                        >
                          {t(change === "publish" ? "school.publishWork" : "school.closeWork")}
                        </ConfirmButton>
                      ))}
                    </>
                  )}
                </>
              )}
              {tab === "quizzes" && (
                <Link
                  to={
                    role === "teacher"
                      ? `/app/classes/${group?.id}/manage/quizzes/${item.id}/${
                          item.status === "published" ? "results" : "edit"
                        }?offering=${offeringId}`
                      : `/app/classes/${group?.id}/quizzes/${item.id}?offering=${offeringId}`
                  }
                >
                  {t("school.open")}
                </Link>
              )}
              {tab === "flashcards" && (
                <Link to={`/app/flashcard-decks/${item.id}`}>{t("school.open")}</Link>
              )}
              {role === "teacher" &&
                group &&
                !group.is_read_only &&
                offering?.status !== "archived" &&
                ["materials", "assignments", "announcements"].includes(tab) && (
                  <div className="flex gap-2 flex-wrap border-t border-[var(--border-subtle)] pt-3">
                    <EditButton
                      initial={{
                        title: item.title,
                        ...(tab === "assignments"
                          ? {
                              instructions: item.instructions ?? "",
                              due_at: item.due_at ?? "",
                            }
                          : tab === "announcements"
                            ? { body: item.body ?? "" }
                            : {}),
                      }}
                      labels={{
                        title: t("common.title"),
                        instructions: t("school.instructions"),
                        due_at: t("school.deadline"),
                        body: t("school.body"),
                      }}
                      save={(values) =>
                        api.patch(
                          `/school/offerings/${offeringId}/tools/${tab}/${item.id}`,
                          {
                            ...values,
                            ...(tab === "assignments" && !values.due_at ? { due_at: null } : {}),
                          },
                          { locale },
                        )
                      }
                      onSaved={items.reload}
                    />
                    <ConfirmButton
                      variant="ghost"
                      disabled={action.pending}
                      onClick={() =>
                        void action.run(async () => {
                          await api.delete(
                            `/school/offerings/${offeringId}/tools/${tab}/${item.id}`,
                            { locale },
                          );
                          items.reload();
                        })
                      }
                    >
                      {t("common.delete")}
                    </ConfirmButton>
                  </div>
                )}
            </article>
          ))}
        </div>
        {items.data?.data.length === 0 && (
          <EmptyState
            message={t(
              (
                {
                  materials: "ux.emptyMaterials",
                  assignments: "ux.emptyAssignments",
                  announcements: "ux.emptyAnnouncements",
                  quizzes: "ux.emptyQuizzes",
                  flashcards: "ux.emptyFlashcards",
                } as Record<string, TranslationKey>
              )[tab],
            )}
          />
        )}
      </State>
      {role === "teacher" && group && !group.is_read_only && offering?.status !== "archived" && (
        <section className={panelClass}>
          <h2>{t("ux.addContent")}</h2>
          {tab === "materials" && (
            <>
              <h3>{t("ux.addLink")}</h3>
              <Form
                fields={[
                  { name: "title", key: "school.name" },
                  { name: "url", key: "school.link", type: "url" },
                ]}
                submit="school.create"
                onSubmit={async (d) => {
                  await api.post(
                    `/school/offerings/${offeringId}/tools/materials`,
                    { ...d, type: "link" },
                    { locale },
                  );
                  items.reload();
                }}
              />
              <h3 className="border-t border-[var(--border-subtle)] pt-4">{t("ux.addFile")}</h3>
              <Field label={t("school.name")}>
                <input
                  className={inputClass}
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                />
              </Field>
              <Field label={t("school.file")}>
                <input
                  ref={resourceFileRef}
                  type="file"
                  onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                />
              </Field>
              <Btn
                disabled={!file || !title || action.pending}
                onClick={() => {
                  void action.run(async () => {
                    const fd = new FormData();
                    fd.set("file", file!);
                    fd.set("title", title);
                    fd.set("type", "file");
                    await api.postForm(`/school/offerings/${offeringId}/tools/materials`, fd, {
                      locale,
                    });
                    setFile(null);
                    if (resourceFileRef.current) resourceFileRef.current.value = "";
                    setTitle("");
                    items.reload();
                  });
                }}
              >
                {t("school.create")}
              </Btn>
            </>
          )}
          {tab === "assignments" && (
            <Form
              fields={[
                { name: "title", key: "school.name" },
                {
                  name: "instructions",
                  key: "school.instructions",
                  type: "textarea",
                },
                {
                  name: "due_at",
                  key: "school.deadline",
                  type: "datetime-local",
                },
              ]}
              submit="school.create"
              onSubmit={async (d) => {
                await api.post(`/school/offerings/${offeringId}/tools/assignments`, d, { locale });
                items.reload();
              }}
            />
          )}
          {tab === "announcements" && (
            <Form
              fields={[
                { name: "title", key: "school.subject" },
                { name: "body", key: "school.body", type: "textarea" },
              ]}
              submit="school.create"
              onSubmit={async (d) => {
                await api.post(`/school/offerings/${offeringId}/tools/announcements`, d, {
                  locale,
                });
                items.reload();
              }}
            />
          )}
          {tab === "quizzes" && (
            <Form
              fields={[
                { name: "title", key: "school.name" },
                {
                  name: "statement",
                  key: "school.question",
                  type: "textarea",
                },
                { name: "correct", key: "school.correctOption" },
                { name: "other", key: "school.otherOption" },
              ]}
              submit="school.create"
              onSubmit={async (d) => {
                await api.post(
                  `/school/offerings/${offeringId}/tools/quizzes`,
                  {
                    title: d.title,
                    questions: [
                      {
                        statement: d.statement,
                        type: "single",
                        options: [
                          { label: d.correct, is_correct: true },
                          { label: d.other, is_correct: false },
                        ],
                      },
                    ],
                  },
                  { locale },
                );
                items.reload();
              }}
            />
          )}
          {tab === "flashcards" && (
            <div className="space-y-3">
              <Field label={t("school.name")}>
                <input
                  className={inputClass}
                  value={deckTitle}
                  onChange={(e) => setDeckTitle(e.target.value)}
                />
              </Field>
              {deckCards.map((card, index) => (
                <div key={index} className="grid sm:grid-cols-2 gap-3">
                  <Field label={t("flashcards.front")}>
                    <input
                      className={inputClass}
                      maxLength={2000}
                      value={card.front}
                      onChange={(e) =>
                        setDeckCards((old) =>
                          old.map((c, i) => (i === index ? { ...c, front: e.target.value } : c)),
                        )
                      }
                    />
                  </Field>
                  <Field label={t("flashcards.back")}>
                    <input
                      className={inputClass}
                      maxLength={2000}
                      value={card.back}
                      onChange={(e) =>
                        setDeckCards((old) =>
                          old.map((c, i) => (i === index ? { ...c, back: e.target.value } : c)),
                        )
                      }
                    />
                  </Field>
                </div>
              ))}
              <Btn
                variant="secondary"
                onClick={() => setDeckCards((old) => [...old, { front: "", back: "" }])}
              >
                {t("createQuiz.addQuestion")}
              </Btn>
              <Btn
                disabled={
                  action.pending ||
                  !deckTitle.trim() ||
                  deckCards.some((c) => !c.front.trim() || !c.back.trim())
                }
                onClick={() => {
                  void action.run(async () => {
                    await api.post(
                      `/school/offerings/${offeringId}/tools/flashcards`,
                      {
                        title: deckTitle.trim(),
                        cards: deckCards.map((c) => ({
                          front: c.front.trim(),
                          back: c.back.trim(),
                        })),
                      },
                      { locale },
                    );
                    setDeckTitle("");
                    setDeckCards([{ front: "", back: "" }]);
                    items.reload();
                  });
                }}
              >
                {t("school.addDeck")}
              </Btn>
            </div>
          )}
        </section>
      )}
      {role === "teacher" && (
        <section className={panelClass}>
          <h2 className="text-xl font-semibold">{t("school.assessments")}</h2>
          <State state={assessments}>
            <ul className="space-y-2">
              {assessments.data?.data.map((a) => (
                <li key={a.id}>
                  <Link to={`/app/school/assessments/${a.id}`}>{a.title}</Link> ·{" "}
                  {t(
                    (
                      {
                        draft: "school.draft",
                        published: "school.published",
                        correction: "school.correction",
                      } as const
                    )[a.state as "draft" | "published" | "correction"] ?? "school.status",
                  )}
                </li>
              ))}
            </ul>
            <Pages page={page} setPage={setPage} last={assessments.data?.last_page ?? 1} />
          </State>
          <Form
            disabled={!group || !!group.is_read_only || offering?.status === "archived"}
            fields={[
              { name: "title", key: "school.assessmentTitle" },
              {
                name: "type",
                key: "school.type",
                options: ["exam", "continuous", "project", "assignment"].map((k) => ({
                  id: k,
                  name: t(`school.${k}` as TranslationKey),
                })),
              },
              { name: "assessed_on", key: "school.date", type: "date" },
              {
                name: "maximum_score",
                key: "school.maximum",
                type: "number",
                value: "20",
              },
              {
                name: "coefficient",
                key: "school.weight",
                type: "number",
                value: "1",
              },
            ]}
            submit="school.create"
            onSubmit={async (d) => {
              const created = await api.post<{ id: number }>(
                `/school/offerings/${offeringId}/assessments`,
                d,
                {
                  locale,
                },
              );
              assessments.reload();
              navigate(`/app/school/assessments/${created.id}`);
            }}
          />
        </section>
      )}
      {action.error && <Alert type="error" message={action.error.message} />}
    </div>
  );
}

const gradeStatuses: GradeStatus[] = ["graded", "ungraded", "absent", "exempt", "makeup"];
export function OfficialGradeEditor() {
  const { id = "" } = useParams();
  const { t, locale, formatDateTime } = useI18n();
  const data = useAsync(
    (s) =>
      api.get<GradeEditorData>(`/school/assessments/${id}`, {
        locale,
        signal: s,
      }),
    [id, locale],
  );
  const [rows, setRows] = useState<GradeEntry[]>([]);
  const [dirty, setDirty] = useState(false);
  const dirtyRef = useRef(false);
  dirtyRef.current = dirty;
  const versionRef = useRef(0);
  const loadedId = useRef("");
  const [notice, setNotice] = useState(false);
  const blocker = useBlocker(dirty);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [summary, setSummary] = useState("");
  const [reason, setReason] = useState("");
  const action = useAction();
  useEffect(() => {
    if (data.data && (!dirtyRef.current || loadedId.current !== id)) {
      setRows(data.data.entries);
      versionRef.current = data.data.assessment.version;
      loadedId.current = id;
      setDirty(false);
      setPreview(null);
    }
  }, [data.data]);
  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = "";
      }
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);
  const a = data.data?.assessment;
  const readOnly = data.data?.read_only ?? false;
  const rosterChanged =
    !!a?.draft_revision_id && !!data.data && data.data.current_roster_version !== a.roster_version;
  const missing = useAsync(
    async (s) => {
      if (!rosterChanged || !a) return null;
      const ov = await api.get<SchoolOverview>("/school", {
        locale,
        signal: s,
      });
      const off = ov.offerings.find((o) => o.id === a.offering_id);
      if (!off) return [];
      const rows: RosterRow[] = [];
      let page = 1;
      let last = 1;
      do {
        const p = await api.get<Page<RosterRow>>(
          `/school/groups/${off.classroom_id}/roster?page=${page}`,
          { locale, signal: s },
        );
        rows.push(...p.data);
        last = p.last_page;
        page += 1;
      } while (page <= last && page <= 20);
      const present = new Set((data.data?.entries ?? []).map((e) => e.student_id));
      return rows.filter((r) => !present.has(r.student_id));
    },
    [rosterChanged, a?.offering_id, a?.roster_version, data.data, locale],
  );
  const [picked, setPicked] = useState<number[]>([]);
  useEffect(() => {
    setPicked((missing.data ?? []).map((r) => r.student_id));
  }, [missing.data]);
  const update = (index: number, value: Partial<GradeEntry>) => {
    setRows((old) => old.map((r, i) => (i === index ? { ...r, ...value } : r)));
    setDirty(true);
    setNotice(false);
  };
  const invalidScore = (r: GradeEntry) =>
    r.status === "graded" &&
    (!/^\d{1,5}([.,]\d{1,2})?$/.test(String(r.score ?? "")) ||
      Number(String(r.score).replace(",", ".")) > Number(a?.maximum_score));
  const invalidCount = rows.filter(invalidScore).length;
  return (
    <div className="school-page space-y-5">
      <Link to="/app/school">{t("school.back")}</Link>
      <h1 className="text-2xl font-semibold">{a?.title ?? t("school.assessments")}</h1>
      <p>{t("school.privacy")}</p>
      <Alert message={t("ux.assessmentGuide")} />
      {readOnly && <Alert message={t("school.archived")} />}
      {blocker.state === "blocked" && (
        <Dialog title={t("school.dirty")} onClose={() => blocker.reset()}>
          <p>{t("school.discardConfirm")}</p>
          <div className="flex gap-3">
            <Btn onClick={() => blocker.reset()}>{t("school.stay")}</Btn>
            <Btn variant="danger" onClick={() => blocker.proceed()}>
              {t("school.discard")}
            </Btn>
          </div>
        </Dialog>
      )}
      <State state={data}>
        {dirty && <Alert type="warning" message={t("school.dirty")} />}
        {notice && <Alert type="success" message={t("ux.savedDraft")} />}
        {a && (
          <div className="flex gap-3 flex-wrap items-center">
            <Badge
              label={t(
                a.state === "published"
                  ? "school.published"
                  : a.state === "correction"
                    ? "school.correction"
                    : "school.draft",
              )}
              color={a.state === "published" ? "green" : "orange"}
            />
            <p>
              {t("ux.completedGrades", {
                done: rows.filter((r) => r.status !== "ungraded" && !invalidScore(r)).length,
                total: rows.length,
              })}
            </p>
            <p>{t("ux.scoreHint", { max: a.maximum_score })}</p>
          </div>
        )}
        {invalidCount > 0 && (
          <Alert
            type="error"
            message={t("ux.invalidScores", {
              count: invalidCount,
              max: a?.maximum_score ?? 20,
            })}
          />
        )}
        {rosterChanged && (
          <div className={panelClass}>
            <Alert type="warning" message={t("school.rosterChanged")} />
            {missing.loading && <p>{t("school.loading")}</p>}
            {missing.error && (
              <Alert type="error" message={errorMessage(missing.error, t("common.error"))} />
            )}
            {(missing.data?.length ?? 0) > 0 && (
              <div>
                <p>{t("school.reconcileHint")}</p>
                <ul className="grid sm:grid-cols-2 gap-2">
                  {missing.data?.map((r) => (
                    <li key={r.student_id}>
                      <label className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={picked.includes(r.student_id)}
                          onChange={(e) =>
                            setPicked((old) =>
                              e.target.checked
                                ? [...old, r.student_id]
                                : old.filter((sid) => sid !== r.student_id),
                            )
                          }
                        />
                        {r.school_identifier ? `${r.school_identifier} — ` : ""}
                        {r.display_name}
                      </label>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <ConfirmButton
              message={t("school.actionConfirm")}
              disabled={dirty || action.pending || readOnly || missing.loading}
              onClick={() => {
                void action.run(async () => {
                  await api.post(
                    `/school/assessments/${id}/roster-reconcile`,
                    { version: a?.version, add_student_ids: picked },
                    { locale },
                  );
                  data.reload();
                });
              }}
            >
              {t("school.reconcile")}
            </ConfirmButton>
          </div>
        )}
        {action.error && (
          <Alert type="error" message={errorMessage(action.error, t("common.error"))} />
        )}
        <div className="overflow-x-auto">
          <table className="grade-table w-full border-collapse text-sm">
            <thead>
              <tr>
                {["student", "score", "status", "feedback"].map((k) => (
                  <th scope="col" key={k} className="text-left p-2">
                    {t(`school.${k}` as TranslationKey)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, index) => (
                <tr key={r.student_id} className="border-t">
                  <td className="p-2 min-w-40">
                    {r.student_id} — {r.display_name}
                  </td>
                  <td data-label={t("school.score")} className="p-2">
                    <input
                      aria-label={`${t("school.score")} ${r.display_name} ${r.student_id}`}
                      className={`${inputClass} min-w-24`}
                      inputMode="decimal"
                      aria-invalid={invalidScore(r)}
                      aria-describedby={invalidScore(r) ? `score-error-${r.student_id}` : undefined}
                      value={r.score ?? ""}
                      disabled={
                        !a?.draft_revision_id ||
                        action.pending ||
                        readOnly ||
                        ["absent", "exempt", "makeup"].includes(r.status)
                      }
                      onChange={(e) =>
                        update(index, {
                          score: e.target.value,
                          status: e.target.value === "" ? "ungraded" : "graded",
                        })
                      }
                    />
                    {invalidScore(r) && (
                      <p
                        id={`score-error-${r.student_id}`}
                        className="text-xs text-[var(--danger-strong)] mt-1"
                      >
                        {t("ux.scoreError", { max: a?.maximum_score ?? 20 })}
                      </p>
                    )}
                  </td>
                  <td data-label={t("school.status")} className="p-2">
                    <select
                      aria-label={`${t("school.status")} ${r.display_name} ${r.student_id}`}
                      className={`${inputClass} min-w-44`}
                      disabled={!a?.draft_revision_id || action.pending || readOnly || data.loading}
                      value={r.status}
                      onChange={(e) =>
                        update(index, {
                          status: e.target.value as GradeStatus,
                          score: e.target.value === "graded" ? r.score : null,
                        })
                      }
                    >
                      {gradeStatuses.map((s) => (
                        <option key={s} value={s}>
                          {t(`school.${s}`)}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td data-label={t("school.feedback")} className="p-2">
                    <textarea
                      aria-label={`${t("school.feedback")} ${r.display_name} ${r.student_id}`}
                      className={`${inputClass} min-w-48`}
                      disabled={!a?.draft_revision_id || action.pending || readOnly || data.loading}
                      value={r.feedback ?? ""}
                      maxLength={2000}
                      onChange={(e) => update(index, { feedback: e.target.value })}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="grade-actions flex gap-3 flex-wrap">
          <Btn
            disabled={
              !dirty || !a?.draft_revision_id || action.pending || readOnly || invalidCount > 0
            }
            onClick={() => {
              void action.run(async () => {
                const saved = await api.put<{ version: number }>(
                  `/school/assessments/${id}/draft`,
                  { version: versionRef.current, rows },
                  { locale },
                );
                versionRef.current = saved.version;
                setDirty(false);
                dirtyRef.current = false;
                setNotice(true);
                data.reload();
              });
            }}
          >
            {t("school.save")}
          </Btn>
          {(["xlsx", "csv"] as const).map((format) => (
            <Btn
              key={format}
              variant="secondary"
              disabled={action.pending}
              onClick={() => {
                void action.run(() =>
                  api.download(
                    `/school/assessments/${id}/template?format=${format}`,
                    `assessment-${id}.${format}`,
                    { locale },
                  ),
                );
              }}
            >
              {t("school.template")} {format.toUpperCase()}
            </Btn>
          ))}
        </div>
        {a?.draft_revision_id ? (
          <>
            <p>{t("school.importHelp")}</p>
            <Field label={t("school.file")}>
              <input
                type="file"
                accept=".xlsx,.csv,.txt"
                disabled={dirty || action.pending || readOnly}
                onChange={(e) => {
                  setFile(e.target.files?.[0] ?? null);
                  setPreview(null);
                }}
              />
            </Field>
            <Btn
              disabled={!file || dirty || action.pending || readOnly}
              onClick={() => {
                void action.run(async () => {
                  const fd = new FormData();
                  fd.set("file", file!);
                  setPreview(
                    await api.postForm(`/school/assessments/${id}/imports/preview`, fd, { locale }),
                  );
                });
              }}
            >
              {t("school.import")}
            </Btn>
            {preview && (
              <section className={panelClass}>
                <p>
                  {t("school.changed")}: {preview.summary.changed} · {t("school.unchanged")}:{" "}
                  {preview.summary.unchanged} · {t("school.missing")}: {preview.summary.missing}
                </p>
                <ul>
                  {preview.errors.map((e, i) => (
                    <li key={i}>
                      {e.row} — {t(importReasons[e.reason] ?? "ux.importUnknown")}
                    </li>
                  ))}
                </ul>
                <ConfirmButton
                  message={t("school.actionConfirm")}
                  disabled={preview.errors.length > 0 || action.pending || readOnly}
                  onClick={() => {
                    void action.run(async () => {
                      await api.post(
                        `/school/assessments/${id}/imports/commit`,
                        { batch_id: preview.batch_id },
                        { locale },
                      );
                      data.reload();
                    });
                  }}
                >
                  {t("school.confirmImport")}
                </ConfirmButton>
              </section>
            )}
            <Field label={t("school.summary")}>
              <textarea
                className={inputClass}
                value={summary}
                maxLength={1000}
                onChange={(e) => setSummary(e.target.value)}
              />
            </Field>
            <p className="text-sm text-[var(--muted-foreground)]">{t("ux.publicationBlocked")}</p>
            <ConfirmButton
              disabled={
                dirty ||
                summary.trim().length < 3 ||
                rows.some((r) => r.status === "ungraded") ||
                action.pending ||
                readOnly ||
                invalidCount > 0
              }
              message={t("school.publishConfirm")}
              onClick={() => {
                void action.run(async () => {
                  await api.post(
                    `/school/assessments/${id}/publish`,
                    { version: a.version, summary },
                    { locale },
                  );
                  data.reload();
                });
              }}
            >
              {t("school.publish")}
            </ConfirmButton>
          </>
        ) : (
          <>
            <Field label={t("school.reason")}>
              <textarea
                className={inputClass}
                value={reason}
                maxLength={1000}
                onChange={(e) => setReason(e.target.value)}
              />
            </Field>
            <Btn
              disabled={reason.trim().length < 3 || action.pending || readOnly}
              onClick={() => {
                void action.run(async () => {
                  await api.post(
                    `/school/assessments/${id}/correction`,
                    { version: a?.version, reason },
                    { locale },
                  );
                  data.reload();
                });
              }}
            >
              {t("school.startCorrection")}
            </Btn>
          </>
        )}
        <h2>{t("school.history")}</h2>
        <ul>
          {data.data?.history.map((h) => (
            <li key={h.sequence}>
              {h.sequence} · {h.published_at ? formatDateTime(h.published_at) : t("school.draft")}{" "}
              {h.reason}
            </li>
          ))}
        </ul>
      </State>
    </div>
  );
}

export function PersonalGradesScreen() {
  const { t, locale, formatDate } = useI18n();
  const [page, setPage] = useState(1);
  const [year, setYear] = useState("");
  const download = useAction();
  const grades = useAsync(
    (s) =>
      api.get<Page<PersonalGrade>>(
        `/school/my-grades?page=${page}${year ? `&year=${encodeURIComponent(year)}` : ""}`,
        { locale, signal: s },
      ),
    [page, year, locale],
  );
  return (
    <div className="school-page max-w-5xl space-y-4">
      <h1 className="text-2xl font-semibold">{t("school.grades")}</h1>
      <p>{t("school.privacy")}</p>
      <Field label={t("school.year")}>
        <input
          className={inputClass}
          value={year}
          onChange={(e) => {
            setYear(e.target.value);
            setPage(1);
          }}
        />
      </Field>
      <Btn
        disabled={download.pending}
        onClick={() => {
          void download.run(() =>
            api.download(
              `/school/my-grades/export${year ? `?year=${encodeURIComponent(year)}` : ""}`,
              "my-private-results.csv",
              { locale },
            ),
          );
        }}
      >
        {t("school.exportOwn")}
      </Btn>
      {download.error && <Alert type="error" message={download.error.message} />}
      <State state={grades}>
        <div className="grid sm:grid-cols-2 gap-4">
          {grades.data?.data.map((g) => (
            <article className={panelClass} key={g.id}>
              <h2 className="font-semibold">
                {g.module_name} — {g.title}
              </h2>
              <p>
                {g.school_year} · {formatDate(g.assessed_on)}
              </p>
              <p>
                {t("school.status")}: {t(`school.${g.status}`)}
              </p>
              {g.status === "graded" && (
                <p className="text-xl font-semibold">
                  {g.score} / {g.maximum_score}
                </p>
              )}
              <p>
                {t("school.weight")}: {g.coefficient}
              </p>
              {g.feedback && <p className="whitespace-pre-wrap break-words">{g.feedback}</p>}
              {g.sequence > 1 && (
                <p>
                  {t("school.correction")} · {g.sequence}
                </p>
              )}
              <Link to={`/app/school/messages?group=${g.classroom_id}&assessment=${g.id}`}>
                {t("school.private_question")}
              </Link>
            </article>
          ))}
        </div>
        {grades.data?.data.length === 0 && <EmptyState message={t("ux.noGrades")} />}
        <Pages page={page} setPage={setPage} last={grades.data?.last_page ?? 1} />
      </State>
    </div>
  );
}

type ThreadKind = "private_question" | "delegate_contact" | "representation" | "organization";
export function SchoolMessagesScreen() {
  const { role, user } = useAuth();
  const { t, locale, formatDateTime } = useI18n();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [page, setPage] = useState(1);
  const [groupId, setGroupId] = useState(Number(params.get("group") ?? 0));
  const [offeringId, setOfferingId] = useState(0);
  const assessmentId = params.get("assessment");
  const [kind, setKind] = useState<ThreadKind>(
    role === "admin" ? "organization" : "private_question",
  );
  const [recipient, setRecipient] = useState("");
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const overview = useAsync(
    (s) => api.get<SchoolOverview>("/school", { locale, signal: s }),
    [locale],
  );
  const ownGrade = useAsync(
    (s) =>
      assessmentId
        ? api.get<PersonalGrade>(`/school/my-grades/${assessmentId}`, {
            locale,
            signal: s,
          })
        : Promise.resolve(null),
    [assessmentId, locale],
  );
  useEffect(() => {
    if (ownGrade.data) {
      setGroupId(ownGrade.data.classroom_id);
      setOfferingId(ownGrade.data.offering_id);
    }
  }, [ownGrade.data]);
  const inbox = useAsync(
    (s) =>
      api.get<Page<SchoolThread>>(`/school/threads?page=${page}`, {
        locale,
        signal: s,
      }),
    [page, locale],
  );
  const contacts = useAsync(
    (s) =>
      assessmentId
        ? api.get<{ data: Person[] }>(`/school/my-grades/${assessmentId}/contacts`, {
            locale,
            signal: s,
          })
        : groupId
          ? api.get<{ data: Person[] }>(
              `/school/groups/${groupId}/contacts${
                kind === "private_question" && offeringId ? `?offering_id=${offeringId}` : ""
              }`,
              { locale, signal: s },
            )
          : Promise.resolve({ data: [] }),
    [groupId, offeringId, kind, assessmentId, locale],
  );
  const isDelegate = overview.data?.delegates.some(
    (d) => d.classroom_id === groupId && d.student_id === user?.id,
  );
  const delegateIds =
    overview.data?.delegates.filter((d) => d.classroom_id === groupId).map((d) => d.student_id) ??
    [];
  const eligible = (contacts.data?.data ?? []).filter((p) =>
    kind === "private_question"
      ? p.role === (role === "teacher" ? "student" : "teacher")
      : kind === "delegate_contact"
        ? delegateIds.includes(p.id)
        : kind === "representation"
          ? p.role === (role === "teacher" ? "student" : "teacher") &&
            (role !== "teacher" || delegateIds.includes(p.id))
          : p.role === (role === "admin" ? "student" : "admin") &&
            (role !== "admin" || delegateIds.includes(p.id)),
  );
  const action = useAction();
  return (
    <div className="school-page max-w-5xl space-y-5">
      <Link to="/app/school">{t("school.back")}</Link>
      <h1 className="text-2xl font-semibold">{t("school.messages")}</h1>
      <State state={inbox}>
        <ul className="space-y-3">
          {inbox.data?.data.map((th) => (
            <li className={panelClass} key={th.id}>
              <Link to={`/app/school/messages/${th.id}`} className="font-semibold">
                {th.subject}
              </Link>
              <p>
                {formatDateTime(th.updated_at)} ·{" "}
                {th.kind === "class_notice"
                  ? t("school.notice")
                  : t(`school.${th.kind}` as TranslationKey)}
                {(th.latest_message_id ?? 0) > (th.last_read_message_id ?? 0) &&
                  ` · ${t("school.unread")}`}
              </p>
            </li>
          ))}
        </ul>
        {inbox.data?.data.length === 0 && <EmptyState message={t("ux.noMessages")} />}
        <Pages page={page} setPage={setPage} last={inbox.data?.last_page ?? 1} />
      </State>
      <section className={panelClass}>
        <h2 className="font-semibold">{t("school.compose")}</h2>
        <p>{t("school.privateHelp")}</p>
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            void action.run(async () => {
              const created = await api.post<{ id: number }>(
                "/school/threads",
                {
                  classroom_id: groupId,
                  offering_id: kind === "private_question" ? offeringId : null,
                  kind,
                  subject,
                  body,
                  participant_ids: [+recipient],
                  ...(assessmentId ? { assessment_id: Number(assessmentId) } : {}),
                },
                { locale },
              );
              navigate(`/app/school/messages/${created.id}`);
            });
          }}
        >
          <Field label={t("school.groups")}>
            <select
              className={inputClass}
              value={groupId || ""}
              disabled={Boolean(assessmentId)}
              required
              onChange={(e) => {
                setGroupId(+e.target.value);
                setOfferingId(0);
                setRecipient("");
              }}
            >
              <option value="">{t("school.choose")}</option>
              {ownGrade.data ? (
                <option value={ownGrade.data.classroom_id}>{ownGrade.data.classroom_name}</option>
              ) : (
                overview.data?.groups
                  .filter((g) => g.status !== "archived")
                  .map((g) => (
                    <option key={g.id} value={g.id}>
                      {g.name}
                    </option>
                  ))
              )}
            </select>
          </Field>
          {kind === "private_question" && (
            <Field label={t("school.modules")}>
              <select
                className={inputClass}
                value={offeringId || ""}
                disabled={Boolean(assessmentId)}
                required
                onChange={(e) => {
                  setOfferingId(+e.target.value);
                  setRecipient("");
                }}
              >
                <option value="">{t("school.choose")}</option>
                {ownGrade.data ? (
                  <option value={ownGrade.data.offering_id}>{ownGrade.data.module_name}</option>
                ) : (
                  overview.data?.offerings
                    .filter((o) => o.classroom_id === groupId)
                    .map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.module_name}
                      </option>
                    ))
                )}
              </select>
            </Field>
          )}
          <Field label={t("school.type")}>
            <select
              className={inputClass}
              value={kind}
              onChange={(e) => {
                setKind(e.target.value as ThreadKind);
                setRecipient("");
              }}
            >
              {(role === "admin"
                ? ["organization"]
                : [
                    "private_question",
                    ...(role === "student" ? ["delegate_contact"] : ["representation"]),
                    ...(isDelegate ? ["representation", "organization"] : []),
                  ]
              ).map((k) => (
                <option key={k} value={k}>
                  {t(`school.${k}` as TranslationKey)}
                </option>
              ))}
            </select>
          </Field>
          <State state={contacts}>
            {groupId > 0 && eligible.length === 0 && <Alert message={t("ux.noContacts")} />}
            <Field label={t("school.recipient")}>
              <select
                className={inputClass}
                required
                value={recipient}
                onChange={(e) => setRecipient(e.target.value)}
              >
                <option value="">{t("school.choose")}</option>
                {eligible.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.display_name}
                  </option>
                ))}
              </select>
            </Field>
          </State>
          <p>
            {t("school.participants")}: {user?.display_name} ·{" "}
            {eligible.find((p) => p.id === +recipient)?.display_name ?? t("school.choose")}
          </p>
          <Field label={t("school.subject")}>
            <input
              className={inputClass}
              required
              maxLength={191}
              value={subject}
              onChange={(e) => setSubject(e.target.value)}
            />
          </Field>
          <Field label={t("school.body")}>
            <textarea
              className={inputClass}
              rows={4}
              required
              maxLength={5000}
              value={body}
              onChange={(e) => setBody(e.target.value)}
            />
          </Field>
          <Btn type="submit" disabled={action.pending || !recipient}>
            {t("school.send")}
          </Btn>
        </form>
        {action.error && <Alert type="error" message={action.error.message} />}
      </section>
      {(role === "teacher" ||
        role === "admin" ||
        (isDelegate &&
          overview.data?.groups.some((g) => g.id === groupId && g.delegate_notices_enabled))) && (
        <NoticeComposer
          groups={(overview.data?.groups ?? []).filter(
            (g) =>
              role !== "student" ||
              (g.delegate_notices_enabled &&
                overview.data?.delegates.some(
                  (d) => d.classroom_id === g.id && d.student_id === user?.id,
                )),
          )}
        />
      )}
      <SchoolReportsPanel />
    </div>
  );
}

export function NoticeComposer({ groups }: { groups: Group[] }) {
  const { role } = useAuth();
  const { t, locale } = useI18n();
  const action = useAction();
  const [audience, setAudience] = useState("classes");
  const [groupIds, setGroupIds] = useState<number[]>([]);
  const [subject, setSubject] = useState("");
  const [body, setBody] = useState("");
  const [preview, setPreview] = useState<{
    preview_id: string;
    recipient_count: number;
    recipients_preview: Person[];
    breakdown?: { classroom_id: number | null; recipient_count: number }[];
  } | null>(null);
  const toggleGroup = (id: number) => {
    setGroupIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
    setPreview(null);
  };
  return (
    <section className={panelClass}>
      <h2 className="font-semibold">{t("school.notice")}</h2>
      <Field label={t("school.audience")}>
        <select
          className={inputClass}
          value={audience}
          onChange={(e) => {
            setAudience(e.target.value);
            setPreview(null);
          }}
        >
          <option value="classes">{t("school.classesAudience")}</option>
          {role === "admin" && (
            <>
              <option value="delegates">{t("school.delegatesAudience")}</option>
              <option value="all">{t("school.allAudience")}</option>
            </>
          )}
        </select>
      </Field>
      {audience !== "all" && (
        <fieldset className="border border-[var(--border-subtle)] rounded-lg p-3">
          <legend className="text-sm font-medium px-1">{t("school.groups")}</legend>
          <div className="space-y-1">
            {groups
              .filter((g) => g.status !== "archived" && !g.is_read_only)
              .map((g) => (
                <label key={g.id} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={groupIds.includes(g.id)}
                    onChange={() => toggleGroup(g.id)}
                  />
                  {g.name}
                </label>
              ))}
          </div>
        </fieldset>
      )}
      <Field label={t("school.subject")}>
        <input
          className={inputClass}
          maxLength={191}
          value={subject}
          onChange={(e) => setSubject(e.target.value)}
        />
      </Field>
      <Field label={t("school.body")}>
        <textarea
          className={inputClass}
          rows={4}
          maxLength={5000}
          value={body}
          onChange={(e) => setBody(e.target.value)}
        />
      </Field>
      <Btn
        disabled={action.pending || (audience !== "all" && groupIds.length === 0)}
        onClick={() => {
          void action.run(async () =>
            setPreview(
              await api.post(
                "/school/notices/preview",
                {
                  audience,
                  ...(audience === "all" ? {} : { group_ids: groupIds }),
                },
                { locale },
              ),
            ),
          );
        }}
      >
        {t("school.previewAudience")}
      </Btn>
      {preview && (
        <div>
          <p>
            {t("school.recipientCount")}: {preview.recipient_count}
          </p>
          {preview.breakdown && audience !== "all" && (
            <ul className="text-sm opacity-80">
              {preview.breakdown.map((b) => (
                <li key={b.classroom_id ?? "all"}>
                  {groups.find((g) => g.id === b.classroom_id)?.name ?? t("school.allAudience")}:{" "}
                  {b.recipient_count}
                </li>
              ))}
            </ul>
          )}
          <ul>
            {preview.recipients_preview.map((p) => (
              <li key={p.id}>{p.display_name}</li>
            ))}
          </ul>
          <ConfirmButton
            message={`${t("school.publishNotice")} — ${preview.recipient_count}`}
            disabled={action.pending || !subject.trim() || !body.trim()}
            onClick={() => {
              void action.run(async () => {
                await api.post(
                  "/school/notices/publish",
                  { preview_id: preview.preview_id, subject, body },
                  { locale },
                );
                setPreview(null);
                setBody("");
                setSubject("");
              });
            }}
          >
            {t("school.publishNotice")}
          </ConfirmButton>
        </div>
      )}
      {action.error && <Alert type="error" message={action.error.message} />}
    </section>
  );
}

export function SchoolThreadScreen() {
  const { id = "" } = useParams();
  const { user } = useAuth();
  const { t, locale, formatDateTime } = useI18n();
  const [before, setBefore] = useState<number | null>(null);
  const view = useAsync(
    (s) =>
      api.get<ThreadView>(`/school/threads/${id}${before ? `?before=${before}` : ""}`, {
        locale,
        signal: s,
      }),
    [id, before, locale],
  );
  const [body, setBody] = useState("");
  const action = useAction();
  const support = useAsync(
    (s) =>
      api.get<{ data: Person[] }>("/school/support-contacts", {
        locale,
        signal: s,
      }),
    [locale],
  );
  return (
    <div className="school-page max-w-4xl space-y-4">
      <Link to="/app/school/messages">{t("school.messages")}</Link>
      <State state={view}>
        <h1 className="text-2xl font-semibold break-words">{view.data?.thread.subject}</h1>
        <Badge
          label={t(view.data?.thread.status === "resolved" ? "ux.statusResolved" : "ux.statusOpen")}
          color={view.data?.thread.status === "resolved" ? "green" : "blue"}
        />
        <details className={panelClass}>
          <summary className="min-h-11 cursor-pointer">{t("school.report")}</summary>
          <p>{t("school.reportHelp")}</p>
          <Form
            fields={[
              {
                name: "assigned_to",
                key: "school.recipient",
                options:
                  support.data?.data.map((p) => ({
                    id: p.id,
                    name: p.display_name,
                  })) ?? [],
              },
              { name: "reason", key: "ux.reason", type: "textarea" },
            ]}
            submit="school.send"
            onSubmit={async (d) => {
              await api.post(`/school/threads/${id}/report`, d, { locale });
            }}
          />
        </details>
        <p>
          {t("school.participants")}:{" "}
          {view.data?.participants
            .map(
              (p) =>
                `${p.display_name}${
                  p.eligibility === "delegate" ? ` (${t("school.delegate")})` : ""
                }`,
            )
            .join(", ")}
        </p>
        {view.data?.thread.kind === "class_notice" && <Alert message={t("school.notice")} />}
        <div className="space-y-3">
          {view.data?.messages
            .slice()
            .reverse()
            .map((m) => (
              <article key={m.id} className={panelClass}>
                <p className="font-semibold">{m.display_name}</p>
                <time>{formatDateTime(m.created_at)}</time>
                <p className="whitespace-pre-wrap break-words">{m.body}</p>
              </article>
            ))}
        </div>
        <div className="flex gap-3">
          <Btn
            variant="secondary"
            disabled={(view.data?.messages.length ?? 0) < 50}
            onClick={() => setBefore(Math.min(...(view.data?.messages.map((m) => m.id) ?? [])))}
          >
            {t("school.older")}
          </Btn>
          <Btn
            variant="secondary"
            onClick={() => {
              setBefore(null);
              view.reload();
            }}
          >
            {t("school.retry")}
          </Btn>
        </div>
        {view.data?.thread.status === "open" &&
          (view.data.thread.kind !== "class_notice" ||
            view.data.thread.created_by === user?.id) && (
            <>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  void action.run(async () => {
                    await api.post(
                      `/school/threads/${id}/messages`,
                      { body },
                      {
                        locale,
                      },
                    );
                    setBody("");
                    setBefore(null);
                    view.reload();
                  });
                }}
              >
                <Field label={t("school.body")}>
                  <textarea
                    className={inputClass}
                    value={body}
                    maxLength={5000}
                    required
                    rows={4}
                    onChange={(e) => setBody(e.target.value)}
                  />
                </Field>
                <Btn type="submit" disabled={action.pending}>
                  {t("school.send")}
                </Btn>
              </form>
              <ConfirmButton
                disabled={action.pending}
                message={t("school.actionConfirm")}
                onClick={() => {
                  void action.run(async () => {
                    await api.post(
                      `/school/threads/${id}/resolve`,
                      {},
                      {
                        locale,
                      },
                    );
                    view.reload();
                  });
                }}
              >
                {t("school.resolve")}
              </ConfirmButton>
            </>
          )}
        {action.error && <Alert type="error" message={action.error.message} />}
      </State>
    </div>
  );
}

export function SchoolReportsPanel() {
  const { role } = useAuth();
  const { t, locale } = useI18n();
  const [page, setPage] = useState(1);
  const reports = useAsync(
    (s) =>
      api.get<Page<SchoolReport>>(`/school/reports?page=${page}`, {
        locale,
        signal: s,
      }),
    [page, locale],
  );
  return (
    <section className={panelClass}>
      <h2>{t("school.reports")}</h2>
      <State state={reports}>
        {!reports.data?.data.length && (
          <p className="text-sm text-[var(--muted-foreground)]">{t("ux.noReports")}</p>
        )}
        {reports.data?.data.map((r) => (
          <article key={r.id} className="border rounded-lg p-3 space-y-2">
            <p>
              {t("school.status")}:{" "}
              {t(r.status === "resolved" ? "ux.statusResolved" : "ux.statusOpen")}
            </p>
            <p className="whitespace-pre-wrap break-words">{r.reason}</p>
            {r.response && <p className="whitespace-pre-wrap break-words">{r.response}</p>}
            {role === "admin" && (
              <Form
                fields={[
                  {
                    name: "response",
                    key: "school.response",
                    type: "textarea",
                  },
                ]}
                submit="school.resolve"
                onSubmit={async (d) => {
                  await api.patch(
                    `/school/reports/${r.id}`,
                    { ...d, status: "resolved" },
                    { locale },
                  );
                  reports.reload();
                }}
              />
            )}
          </article>
        ))}
        <Pages page={page} setPage={setPage} last={reports.data?.last_page ?? 1} />
      </State>
    </section>
  );
}

interface HomeAnnouncement {
  id: number;
  title: string;
  body: string;
  created_at: string;
}

function SchoolStudentUpdates() {
  const { t, locale, formatDateTime } = useI18n();
  const announcements = useAsync(
    (s) =>
      api.get<{ data: HomeAnnouncement[] }>("/me/announcements", {
        locale,
        signal: s,
      }),
    [locale],
  );
  const notices = useAsync(
    (s) => api.get<Page<SchoolThread>>("/school/threads", { locale, signal: s }),
    [locale],
  );
  return (
    <section className={panelClass}>
      <h2 className="font-semibold">{t("student.latestAnnouncements")}</h2>
      <State state={announcements}>
        {announcements.data?.data.slice(0, 6).map((a) => (
          <article key={a.id} className="border rounded-lg p-3">
            <h3 className="font-semibold break-words">{a.title}</h3>
            <time>{formatDateTime(a.created_at)}</time>
            <p className="whitespace-pre-wrap break-words">{a.body}</p>
          </article>
        ))}
      </State>
      <State state={notices}>
        {!announcements.loading &&
          !announcements.data?.data.length &&
          !notices.data?.data.some((n) => n.kind === "class_notice") && (
            <p className="text-sm text-[var(--muted-foreground)]">{t("ux.noUpdates")}</p>
          )}
        {notices.data?.data
          .filter((n) => n.kind === "class_notice")
          .slice(0, 6)
          .map((n) => (
            <p key={n.id}>
              <Link to={`/app/school/messages/${n.id}`}>{n.subject}</Link>
            </p>
          ))}
      </State>
    </section>
  );
}

function SchoolDeadlinesPanel() {
  const { t, locale, formatDateTime, formatDate } = useI18n();
  const state = useAsync(
    (s) =>
      api.get<{ data: SchoolDeadline[] }>("/school/deadlines", {
        locale,
        signal: s,
      }),
    [locale],
  );
  return (
    <section className={panelClass}>
      <h2 className="font-semibold">{t("school.deadline")}</h2>
      <State state={state}>
        <ul className="space-y-3">
          {state.data?.data.map((item) => (
            <li key={`${item.kind}-${item.id}`}>
              <Link to={item.url}>{item.title}</Link> ·{" "}
              {item.kind === "assessment" ? formatDate(item.due_at) : formatDateTime(item.due_at)}
            </li>
          ))}
        </ul>
        {state.data?.data.length === 0 && <p>{t("ux.noDeadlines")}</p>}
      </State>
    </section>
  );
}
