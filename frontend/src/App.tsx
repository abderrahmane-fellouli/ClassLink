import { RouterProvider } from "react-router-dom";
import { AuthProvider } from "./context/AuthContext";
import { I18nProvider } from "./i18n";
import { router } from "./router";
import { ThemeProvider } from "./context/ThemeContext";
import { lazy, Suspense, useSyncExternalStore } from "react";
import { isV2ReviewPath } from "./review/transport";

const V2Review = lazy(() => import("./review/V2Review"));
const observeReviewEntry = (notify: () => void) => {
  window.addEventListener("popstate", notify);
  return () => window.removeEventListener("popstate", notify);
};

export default function App() {
  const reviewEntry = useSyncExternalStore(observeReviewEntry, isV2ReviewPath, () => false);
  return (
    <I18nProvider>
      <ThemeProvider>
        {reviewEntry ? (
          <Suspense
            fallback={
              <div role="status" className="system-state">
                ClassLink V2
              </div>
            }
          >
            <V2Review />
          </Suspense>
        ) : (
          <AuthProvider>
            <RouterProvider router={router} />
          </AuthProvider>
        )}
      </ThemeProvider>
    </I18nProvider>
  );
}
