import { StrictMode } from "react";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import App from "../src/App";
import { api } from "../src/lib/api";
import { installReviewTransport } from "../src/review/transport";
import { ReviewStore } from "../src/review/store";
import { ids } from "../src/review/fixtures";

afterEach(() => {
  window.history.replaceState(null, "", "/");
});

it("fails closed for every API verb and protected download while review data is not installed", async () => {
  window.history.replaceState(null, "", "/v2-review");
  const fetch = vi.fn();
  vi.stubGlobal("fetch", fetch);
  sessionStorage.setItem("classlink.token", "existing-session-sentinel");
  await expect(api.get("/school")).rejects.toMatchObject({ status: 503 });
  await expect(api.post("/school/groups", {})).rejects.toMatchObject({ status: 503 });
  await expect(api.patch("/me", {})).rejects.toMatchObject({ status: 503 });
  await expect(api.put("/school/assessments/401/draft", {})).rejects.toMatchObject({ status: 503 });
  await expect(api.delete("/me/sessions")).rejects.toMatchObject({ status: 503 });
  await expect(api.download("/materials/1/download", "example.txt")).rejects.toMatchObject({
    status: 503,
  });
  expect(fetch).not.toHaveBeenCalled();
  expect(sessionStorage.getItem("classlink.token")).toBe("existing-session-sentinel");
});

it("simulates writes and downloads locally without touching the fetch transport or existing auth token", async () => {
  window.history.replaceState(null, "", "/v2-review");
  const fetch = vi.fn(),
    notice = vi.fn();
  vi.stubGlobal("fetch", fetch);
  sessionStorage.setItem("classlink.token", "existing-session-sentinel");
  const store = new ReviewStore("teacher", "en", "normal", notice);
  const cleanup = installReviewTransport(store);
  try {
    await api.put(`/school/assessments/${ids.assessment}/draft`, {
      rows: [
        {
          student_id: ids.student,
          display_name: "Synthetic learner",
          score: "0",
          status: "graded",
          feedback: null,
        },
      ],
    });
    expect(store.data.gradeEditor.entries[0].score).toBe("0");
    const file = new File(["Synthetic visual review only"], "review-work.txt");
    const form = new FormData();
    form.set("file", file);
    await api.postForm(`/assignments/${ids.assignment}/submissions`, form);
    expect(store.data.assignments[0].my_submission?.file_name).toBe("review-work.txt");
    await api.download("/materials/1101/download", "example.pdf");
    await expect(
      api.post("/auth/otp/request", { email: "v2.student@example.invalid" }),
    ).rejects.toMatchObject({ status: 403 });
    expect(fetch).not.toHaveBeenCalled();
    expect(notice).toHaveBeenCalled();
    expect(sessionStorage.getItem("classlink.token")).toBe("existing-session-sentinel");
  } finally {
    cleanup();
  }
});

it("mounts the actual shell/screens in StrictMode, changes roles and navigates without real auth or API traffic", async () => {
  window.history.replaceState(null, "", "/v2-review");
  localStorage.setItem("classlink.locale", "en");
  sessionStorage.setItem("classlink.token", "existing-session-sentinel");
  const fetch = vi.fn();
  vi.stubGlobal("fetch", fetch);
  render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
  await screen.findByText("Temporary V2 visual review");
  await screen.findByRole("heading", { level: 1, name: "Hello Camille Exemple." });
  expect(document.querySelector(".app-sidebar")).not.toBeNull();
  const roles = () => screen.getByRole("group", { name: "Preview role" });
  fireEvent.click(within(roles()).getByRole("button", { name: "Delegate" }));
  await screen.findByRole("heading", { level: 1, name: "Hello Noor Démonstration." });
  await screen.findByText("Your delegate responsibility");
  fireEvent.click(within(roles()).getByRole("button", { name: "Teacher" }));
  await screen.findByRole("heading", { level: 1, name: "My school workspace" });
  fireEvent.change(screen.getByLabelText("Preview screen"), {
    target: { value: `/app/school/assessments/${ids.assessment}` },
  });
  await screen.findByRole("heading", {
    level: 1,
    name: /Évaluation individuelle de démonstration/,
  });
  await waitFor(() =>
    expect(screen.getByLabelText(`Score Camille Exemple ${ids.student}`)).toHaveValue("14"),
  );
  fireEvent.click(within(roles()).getByRole("button", { name: "Super Admin" }));
  await screen.findByRole("heading", { level: 1, name: "Overview" });
  expect(window.location.pathname).toBe("/v2-review");
  expect(fetch).not.toHaveBeenCalled();
  expect(sessionStorage.getItem("classlink.token")).toBe("existing-session-sentinel");
});

it("fails closed if the URL escapes a mounted review, then restores normal transport after cleanup", async () => {
  window.history.replaceState(null, "", "/app");
  sessionStorage.setItem("classlink.token", "existing-session-sentinel");
  const fetch = vi
    .fn()
    .mockResolvedValue(
      new Response(JSON.stringify({ id: 42, role: "student" }), {
        status: 200,
        headers: { "content-type": "application/json" },
      }),
    );
  vi.stubGlobal("fetch", fetch);
  const cleanup = installReviewTransport(new ReviewStore("admin", "en", "normal"));
  try {
    await expect(api.post("/school/groups", {})).rejects.toMatchObject({ status: 503 });
    await expect(api.get("/me")).rejects.toMatchObject({ status: 503 });
    expect(fetch).not.toHaveBeenCalled();
  } finally {
    cleanup();
  }
  {
    await expect(api.get("/me")).resolves.toEqual({ id: 42, role: "student" });
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][1].headers.get("Authorization")).toBe(
      "Bearer existing-session-sentinel",
    );
  }
});
