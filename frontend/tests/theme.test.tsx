import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { ThemeProvider, applyTheme, initialTheme } from "../src/context/ThemeContext";
import { I18nProvider } from "../src/i18n";
import { ThemeSwitch } from "../src/components/UI";

afterEach(() => applyTheme("light"));

it("restores an explicit visual preference before considering the device preference", () => {
  vi.stubGlobal("matchMedia", () => ({ matches: true }));
  expect(initialTheme()).toBe("dark");
  localStorage.setItem("classlink.theme", "light");
  expect(initialTheme()).toBe("light");
});

it("switches and persists the theme without changing locale, session or contacting an API", () => {
  localStorage.setItem("classlink.locale", "en");
  localStorage.setItem("classlink.theme", "light");
  sessionStorage.setItem("classlink.token", "synthetic-session");
  const fetch = vi.fn();
  vi.stubGlobal("fetch", fetch);
  render(
    <I18nProvider>
      <ThemeProvider>
        <ThemeSwitch />
      </ThemeProvider>
    </I18nProvider>,
  );
  fireEvent.click(screen.getByRole("button", { name: "Switch to Dark mode" }));
  expect(document.documentElement.dataset.theme).toBe("dark");
  expect(localStorage.getItem("classlink.theme")).toBe("dark");
  expect(localStorage.getItem("classlink.locale")).toBe("en");
  expect(sessionStorage.getItem("classlink.token")).toBe("synthetic-session");
  expect(fetch).not.toHaveBeenCalled();
  expect(screen.getByRole("button", { name: "Switch to Light mode" })).toBeEnabled();
});

it("remains usable when the browser refuses preference storage", () => {
  vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
    throw new Error("Storage blocked");
  });
  vi.stubGlobal("matchMedia", () => ({ matches: false }));
  expect(initialTheme()).toBe("light");
});
