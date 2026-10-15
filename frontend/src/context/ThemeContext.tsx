import { createContext, useContext, useEffect, useState, type ReactNode } from "react";

export type Theme = "light" | "dark";
const key = "classlink.theme";
export function initialTheme(): Theme {
  try {
    const stored = localStorage.getItem(key);
    if (stored === "light" || stored === "dark") return stored;
  } catch {
    /* The interface remains usable when storage is unavailable. */
  }
  return typeof matchMedia === "function" && matchMedia("(prefers-color-scheme: dark)").matches
    ? "dark"
    : "light";
}
export function applyTheme(theme: Theme) {
  document.documentElement.dataset.theme = theme;
  document.documentElement.style.colorScheme = theme;
}
const ThemeContext = createContext<{ theme: Theme; setTheme: (theme: Theme) => void }>({
  theme: "light",
  setTheme: () => {},
});
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setValue] = useState<Theme>(initialTheme);
  const setTheme = (value: Theme) => {
    applyTheme(value);
    setValue(value);
    try {
      localStorage.setItem(key, value);
    } catch {
      /* No server preference is implied. */
    }
  };
  useEffect(() => {
    applyTheme(theme);
    const synchronize = (event: StorageEvent) => {
      if (event.key === key) setValue(initialTheme());
    };
    window.addEventListener("storage", synchronize);
    return () => window.removeEventListener("storage", synchronize);
  }, [theme]);
  return <ThemeContext.Provider value={{ theme, setTheme }}>{children}</ThemeContext.Provider>;
}
export const useTheme = () => useContext(ThemeContext);
