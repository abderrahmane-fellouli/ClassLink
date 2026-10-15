import { RouterProvider } from "react-router-dom";
import { AuthProvider } from "./context/AuthContext";
import { I18nProvider } from "./i18n";
import { router } from "./router";
import { ThemeProvider } from "./context/ThemeContext";

export default function App() {
  return (
    <I18nProvider>
      <ThemeProvider>
        <AuthProvider>
          <RouterProvider router={router} />
        </AuthProvider>
      </ThemeProvider>
    </I18nProvider>
  );
}
