import type { ReactNode } from "react";
import { Navigate, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { ConfirmButton, Card, Icons, Btn, Alert } from "./UI";
import { useI18n } from "../i18n";
import type { Role } from "../lib/types";
import { appReturnPath } from "../lib/session";

function FullPageLoader() {
  const { t } = useI18n();
  const { retryError, refresh } = useAuth();
  if (retryError)
    return (
      <div className="min-h-dvh flex flex-col gap-4 items-center justify-center p-5">
        <Alert type="error" message={retryError} />
        <Btn onClick={() => void refresh()}>{t("common.retry")}</Btn>
      </div>
    );
  return (
    <div className="min-h-dvh flex items-center justify-center bg-[var(--background)]">
      <div
        className="w-6 h-6 border-2 border-[var(--primary)] border-t-transparent rounded-full animate-spin"
        role="status"
        aria-label={t("common.loading")}
      />
    </div>
  );
}

/** Redirige vers la connexion en mémorisant la page demandée. */
export function GuestRoute({ children }: { children: ReactNode }) {
  const { status } = useAuth();
  const location = useLocation();

  if (status === "loading") return <FullPageLoader />;
  if (status === "authenticated") {
    const from = (location.state as { from?: unknown } | null)?.from;
    return <Navigate to={appReturnPath(from)} replace />;
  }
  return <>{children}</>;
}

/** Exige une session ; les rôles `pending` et `denied` sont isolés (§17.6). */
export function ProtectedRoute({ children, roles }: { children: ReactNode; roles?: Role[] }) {
  const { status, role, isDenied, isPending } = useAuth();
  const location = useLocation();

  if (status === "loading") return <FullPageLoader />;

  if (status === "anonymous") {
    return (
      <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}` }} />
    );
  }

  if (isDenied) return <AccessDeniedScreen />;
  if (isPending) return <Navigate to="/pending" replace />;

  if (roles && role && !roles.includes(role)) {
    return <Navigate to="/app" replace />;
  }

  return <>{children}</>;
}

export function AccessDeniedScreen() {
  const { signOut } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  return (
    <div className="min-h-dvh flex items-center justify-center bg-[var(--background)] p-5">
      <Card className="max-w-md w-full p-8 text-center">
        <div
          className="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-4"
          style={{ background: "var(--danger)", color: "#fff" }}
        >
          <Icons.Shield />
        </div>
        <h1 className="font-display text-xl font-semibold mb-2">{t("denied.title")}</h1>
        <p className="text-sm text-[var(--muted-foreground)] mb-6">{t("ux.accountUnavailable")}</p>
        <p className="text-xs text-[var(--muted-foreground)] mb-6">{t("denied.step2")}</p>
        <ConfirmButton
          variant="secondary"
          full
          onClick={() => {
            navigate("/", { replace: true });
            void signOut();
          }}
        >
          {t("nav.logout")}
        </ConfirmButton>
      </Card>
    </div>
  );
}
