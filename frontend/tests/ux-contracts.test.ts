import { describe, expect, it, vi } from "vitest";
import { ApiError, api, request } from "../src/lib/api";
import { schoolDateTimeInput, schoolDateTimeToIso } from "../src/lib/dates";
import { notificationDestination } from "../src/components/AppShell";
import type { ApiNotification } from "../src/lib/types";
import { appReturnPath, setStoredLocale } from "../src/lib/session";
import { jsonResponse } from "./setup";

describe("school wall-clock dates", () => {
  it("converts Casablanca UTC+1 inputs without using the device timezone", () => {
    expect(schoolDateTimeToIso("2026-10-08T12:30")).toBe("2026-10-08T11:30:00.000Z");
    expect(schoolDateTimeInput("2026-10-08T11:30:00Z")).toBe("2026-10-08T12:30");
  });
  it("respects Ramadan UTC and preserves seconds and explicitly zoned input", () => {
    expect(schoolDateTimeToIso("2026-03-01T12:30")).toBe("2026-03-01T12:30:00.000Z");
    expect(schoolDateTimeToIso("2026-10-08T23:59:59")).toBe("2026-10-08T22:59:59.000Z");
    expect(schoolDateTimeToIso("2026-10-08T12:30:00Z")).toBe("2026-10-08T12:30:00Z");
  });
  it("sends date-only assessment context unchanged and converts a naive deadline", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(jsonResponse({ id: 1 })));
    await api.post("/synthetic", {
      assessed_on: "2026-10-08",
      due_at: "2026-10-08T12:30",
    });
    expect(vi.mocked(fetch).mock.calls[0][1]?.body).toBe(
      JSON.stringify({
        assessed_on: "2026-10-08",
        due_at: "2026-10-08T11:30:00.000Z",
      }),
    );
  });
});

describe("safe localized failure recovery", () => {
  it("preserves internal workflow/query destinations and refuses external or escaping paths", () => {
    expect(appReturnPath("/app/school/messages?group=2")).toBe("/app/school/messages?group=2");
    expect(appReturnPath("/app?tab=notes")).toBe("/app?tab=notes");
    for (const unsafe of [
      "https://example.invalid",
      "//example.invalid",
      "/app/../../outside",
      "/application",
      "javascript:alert(1)",
      null,
    ])
      expect(appReturnPath(unsafe)).toBe("/app");
  });
  it("replaces raw network errors with actionable French copy", async () => {
    setStoredLocale("fr");
    vi.stubGlobal(
      "fetch",
      vi.fn().mockRejectedValue(new TypeError("Failed to fetch internal-host")),
    );
    await expect(request("/synthetic")).rejects.toMatchObject({
      status: 0,
      message: "Serveur injoignable. Vérifiez votre connexion.",
    });
  });
  it("does not display server exception details", async () => {
    setStoredLocale("en");
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        jsonResponse(
          {
            message: "SQLSTATE private content",
            errors: { title: ["SQLSTATE private details"] },
            context: { private: "synthetic sensitive details" },
          },
          {
            status: 500,
          },
        ),
      ),
    );
    const error = (await request("/synthetic").catch((e) => e)) as ApiError;
    expect(error.message).not.toContain("SQLSTATE");
    expect(error.message).toMatch(/Retry/);
    expect(error.errors).toEqual({});
    expect(error.context).toBeNull();
  });
});

describe("notification destinations", () => {
  const notification = (payload: object) => ({ payload }) as ApiNotification;
  it("opens a module deep link and preserves the allowlist", () => {
    expect(
      notificationDestination(notification({ url: "/app/school/offerings/4" }), "student"),
    ).toBe("/app/school/offerings/4");
    expect(
      notificationDestination(notification({ url: "https://example.invalid/unsafe" }), "student"),
    ).toBeNull();
    expect(
      notificationDestination(notification({ url: "//example.invalid/unsafe" }), "student"),
    ).toBeNull();
  });
  it("routes historical teacher notifications to management rather than a student-only page", () => {
    expect(notificationDestination(notification({ classroom_id: 8 }), "teacher")).toBe(
      "/app/classes/8/manage",
    );
    expect(notificationDestination(notification({ classroom_id: 8 }), "student")).toBe(
      "/app/classes/8",
    );
    expect(notificationDestination(notification({ classroom_id: 8 }), "admin")).toBe(
      "/app/admin/classes",
    );
  });
});
