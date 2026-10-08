import {
  Children,
  cloneElement,
  isValidElement,
  useEffect,
  useId,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { createPortal } from "react-dom";
import { useI18n } from "../i18n";
import type { Locale } from "../lib/types";

/* ─── Types ─────────────────────────────────────────────────────────── */
export type Role = "student" | "teacher" | "admin";

export type BadgeColor = "green" | "orange" | "red" | "blue" | "purple" | "default";

/* ─── Icons ────────────────────────────────────────────────────────── */
export const Icons = {
  Logo: () => (
    <svg viewBox="0 0 32 32" fill="none" className="w-8 h-8 shrink-0" aria-hidden="true">
      <rect x="2" y="3" width="20" height="18" rx="7" fill="#1E5AA8" />
      <rect x="10" y="11" width="20" height="18" rx="7" fill="#6FA8DC" />
      <path d="M12 17h8" stroke="white" strokeWidth="3" strokeLinecap="round" />
    </svg>
  ),
  Microsoft: () => (
    <svg className="w-5 h-5 shrink-0" viewBox="0 0 21 21" aria-hidden="true">
      <rect x="0" y="0" width="10" height="10" fill="#F25022" />
      <rect x="11" y="0" width="10" height="10" fill="#7FBA00" />
      <rect x="0" y="11" width="10" height="10" fill="#00A4EF" />
      <rect x="11" y="11" width="10" height="10" fill="#FFB900" />
    </svg>
  ),
  Home: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z" />
    </svg>
  ),
  Class: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3z" />
      <path d="M3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z" />
    </svg>
  ),
  Quiz: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Bell: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" />
    </svg>
  ),
  User: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Users: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z" />
    </svg>
  ),
  Check: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 1.414z"
        clipRule="evenodd"
      />
    </svg>
  ),
  X: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Clock: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Plus: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"
        clipRule="evenodd"
      />
    </svg>
  ),
  ChevRight: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
        clipRule="evenodd"
      />
    </svg>
  ),
  ChevDown: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Book: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12a1 1 0 11-2 0V4.804z" />
    </svg>
  ),
  Chart: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z" />
    </svg>
  ),
  Calendar: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Upload: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Settings: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Shield: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Log: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Cpu: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M9 3a1 1 0 012 0v.5h.5a.5.5 0 010 1H11V5h1a2 2 0 012 2v1h.5a.5.5 0 010 1H14v1h.5a.5.5 0 010 1H14v1a2 2 0 01-2 2h-1v.5a.5.5 0 01-1 0V14H9v.5a.5.5 0 01-1 0V14H7a2 2 0 01-2-2v-1H4.5a.5.5 0 010-1H5V9H4.5a.5.5 0 010-1H5V7a2 2 0 012-2h1V4.5a.5.5 0 01.5-.5H9V3zM7 7v6h6V7H7z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Pencil: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
    </svg>
  ),
  Trash: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a2 2 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Star: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
    </svg>
  ),
  Search: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Heart: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"
        clipRule="evenodd"
      />
    </svg>
  ),
  External: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-3.5 h-3.5" aria-hidden="true">
      <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" />
      <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" />
    </svg>
  ),
  Menu: () => (
    <svg
      className="w-5 h-5"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
    </svg>
  ),
  Logout: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M3 4a1 1 0 011-1h6a1 1 0 110 2H5v10h5a1 1 0 110 2H4a1 1 0 01-1-1V4zm10.293 4.293a1 1 0 011.414 0l2.5 2.5a1 1 0 010 1.414l-2.5 2.5a1 1 0 01-1.414-1.414L15.086 12H8a1 1 0 110-2h7.086l-1.793-1.707a1 1 0 010-2z"
        clipRule="evenodd"
      />
    </svg>
  ),
  Alert: () => (
    <svg viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4" aria-hidden="true">
      <path
        fillRule="evenodd"
        d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
        clipRule="evenodd"
      />
    </svg>
  ),
};

/* ─── Btn ──────────────────────────────────────────────────────────── */
export function Btn({
  children,
  variant = "primary",
  className = "",
  onClick,
  disabled,
  full,
  size = "md",
  type = "button",
  title,
  "aria-label": ariaLabel,
}: {
  children: ReactNode;
  variant?: "primary" | "secondary" | "ghost" | "danger" | "accent" | "success";
  className?: string;
  onClick?: () => void;
  disabled?: boolean;
  full?: boolean;
  size?: "sm" | "md" | "lg";
  type?: "button" | "submit";
  title?: string;
  "aria-label"?: string;
}) {
  const sizes = {
    sm: "min-h-9 px-3 py-2 text-xs",
    md: "min-h-11 px-4 py-2 text-sm",
    lg: "min-h-12 px-5 py-3 text-base",
  };
  const base = `inline-flex items-center justify-center gap-2 font-medium rounded-[var(--radius)] cursor-pointer border-0 transition-all select-none ${sizes[size]} ${
    full ? "w-full" : ""
  } ${disabled ? "opacity-50 cursor-not-allowed" : ""}`;
  const v = {
    primary: "bg-[var(--primary)] text-white hover:bg-[#142d54] active:scale-[0.98]",
    secondary:
      "bg-[var(--secondary)] text-[var(--primary)] hover:bg-[var(--muted)] active:scale-[0.98]",
    ghost:
      "bg-transparent text-[var(--muted-foreground)] hover:bg-[var(--muted)] active:scale-[0.98]",
    danger: "bg-[var(--danger-strong)] text-white hover:bg-red-700 active:scale-[0.98]",
    accent: "bg-[var(--accent)] text-white hover:bg-[var(--primary)] active:scale-[0.98]",
    success: "bg-[var(--success-strong)] text-white hover:bg-green-800 active:scale-[0.98]",
  };

  return (
    <button
      type={type}
      className={`ui-button ${base} ${v[variant]} ${className}`}
      onClick={onClick}
      disabled={disabled}
      title={title}
      aria-label={ariaLabel}
    >
      {children}
    </button>
  );
}

/* ─── Badge ────────────────────────────────────────────────────────── */
export function Badge({ label, color = "default" }: { label: string; color?: BadgeColor }) {
  const c = {
    green: "bg-green-100 text-green-700",
    orange: "bg-orange-100 text-orange-700",
    red: "bg-red-100 text-red-700",
    blue: "bg-blue-100 text-blue-700",
    purple: "bg-purple-100 text-purple-700",
    default: "bg-[var(--muted)] text-[var(--muted-foreground)]",
  };
  return (
    <span
      className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium max-w-full whitespace-normal break-words sm:whitespace-nowrap ${c[color]}`}
    >
      {label}
    </span>
  );
}

/* ─── Card ─────────────────────────────────────────────────────────── */
export function Card({
  children,
  className = "",
  onClick,
}: {
  children: ReactNode;
  className?: string;
  onClick?: () => void;
}) {
  const interactive = Boolean(onClick);
  return (
    <div
      className={`ui-card bg-[var(--card)] border ${
        interactive ? "cursor-pointer hover:border-[var(--primary)]/40 transition-colors" : ""
      } ${className}`}
      onClick={onClick}
      onKeyDown={
        interactive
          ? (e) => {
              if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                onClick?.();
              }
            }
          : undefined
      }
      role={interactive ? "button" : undefined}
      tabIndex={interactive ? 0 : undefined}
    >
      {children}
    </div>
  );
}

/* ─── Form fields ──────────────────────────────────────────────────── */
const fieldClasses =
  "w-full px-3.5 py-2.5 text-sm bg-white border border-[var(--border)] rounded-[var(--radius)] outline-none focus:ring-2 focus:ring-[var(--primary)] focus:border-transparent placeholder:text-[var(--muted-foreground)] disabled:bg-[var(--muted)]";

export function Field({
  label,
  hint,
  error,
  children,
}: {
  label?: string;
  hint?: string;
  error?: string | null;
  children: ReactNode;
}) {
  const id = useId();
  const description = `${id}-description`;
  return (
    <div className="ui-field flex flex-col gap-1.5">
      {label && (
        <label htmlFor={id} className="text-sm font-medium text-[var(--foreground)]">
          {label}
        </label>
      )}
      {Children.map(children, (child) =>
        isValidElement(child)
          ? cloneElement(child as React.ReactElement<Record<string, unknown>>, {
              id,
              "aria-describedby": hint || error ? description : undefined,
              "aria-invalid": error ? true : undefined,
            })
          : child,
      )}
      {error ? (
        <p id={description} className="text-xs text-[var(--danger)]">
          {error}
        </p>
      ) : (
        hint && (
          <p id={description} className="text-xs text-[var(--muted-foreground)]">
            {hint}
          </p>
        )
      )}
    </div>
  );
}

export function Input({
  label,
  type = "text",
  placeholder,
  value,
  onChange,
  hint,
  error,
  disabled,
  autoComplete,
  maxLength,
  inputMode,
  required,
}: {
  label?: string;
  type?: string;
  placeholder?: string;
  value?: string;
  onChange?: (v: string) => void;
  hint?: string;
  error?: string | null;
  disabled?: boolean;
  autoComplete?: string;
  maxLength?: number;
  inputMode?: "text" | "numeric" | "decimal" | "tel" | "email" | "search" | "url";
  required?: boolean;
}) {
  return (
    <Field label={label ?? placeholder} hint={hint} error={error}>
      <input
        type={type}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange?.(e.target.value)}
        disabled={disabled}
        autoComplete={autoComplete}
        maxLength={maxLength}
        inputMode={inputMode}
        required={required}
        className={fieldClasses}
      />
    </Field>
  );
}

export function Textarea({
  label,
  placeholder,
  value,
  onChange,
  rows = 4,
  error,
  disabled,
}: {
  label?: string;
  placeholder?: string;
  value?: string;
  onChange?: (v: string) => void;
  rows?: number;
  error?: string | null;
  disabled?: boolean;
}) {
  return (
    <Field label={label ?? placeholder} error={error}>
      <textarea
        rows={rows}
        placeholder={placeholder}
        value={value}
        disabled={disabled}
        onChange={(e) => onChange?.(e.target.value)}
        className={`${fieldClasses} resize-none`}
      />
    </Field>
  );
}

export function Select({
  label,
  value,
  onChange,
  options,
  error,
  disabled,
}: {
  label?: string;
  value: string;
  onChange: (v: string) => void;
  options: { value: string; label: string }[];
  error?: string | null;
  disabled?: boolean;
}) {
  return (
    <Field label={label ?? options.find((option) => option.value === value)?.label} error={error}>
      <select
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        className={fieldClasses}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </Field>
  );
}

/* ─── Tabs ─────────────────────────────────────────────────────────── */
export function Tabs({
  tabs,
  active,
  onChange,
}: {
  tabs: { id: string; label: string }[];
  active: string;
  onChange: (id: string) => void;
}) {
  return (
    <div
      role="tablist"
      className="flex gap-1 p-1 rounded-[var(--radius)] bg-[var(--muted)] overflow-x-auto shrink-0"
      onKeyDown={(event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        const current = tabs.findIndex((tab) => tab.id === active);
        const index =
          event.key === "Home"
            ? 0
            : event.key === "End"
              ? tabs.length - 1
              : (current + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
        onChange(tabs[index].id);
        event.currentTarget.querySelectorAll<HTMLButtonElement>('[role="tab"]')[index]?.focus();
      }}
    >
      {tabs.map((t) => (
        <button
          key={t.id}
          role="tab"
          aria-selected={active === t.id}
          tabIndex={active === t.id ? 0 : -1}
          onClick={() => onChange(t.id)}
          className={`flex-shrink-0 px-4 py-1.5 text-sm font-medium rounded-lg transition-all ${
            active === t.id
              ? "bg-white shadow-sm text-[var(--foreground)]"
              : "text-[var(--muted-foreground)] hover:text-[var(--foreground)]"
          }`}
        >
          {t.label}
        </button>
      ))}
    </div>
  );
}

/* ─── Stat tile ────────────────────────────────────────────────────── */
export function StatTile({
  label,
  value,
  color = "var(--primary)",
  sub,
}: {
  label: string;
  value: string;
  color?: string;
  sub?: string;
}) {
  return (
    <Card className="p-4">
      <p className="text-2xl font-semibold" style={{ color }}>
        {value}
      </p>
      <p className="text-xs text-[var(--muted-foreground)] mt-0.5">{label}</p>
      {sub && <p className="text-[10px] text-[var(--muted-foreground)] mt-1">{sub}</p>}
    </Card>
  );
}

/* ─── Progress bar ─────────────────────────────────────────────────── */
export function ProgressBar({
  value,
  color = "var(--primary)",
}: {
  value: number;
  color?: string;
}) {
  const clamped = Math.max(0, Math.min(100, value));
  const { t } = useI18n();
  return (
    <div
      className="h-1.5 rounded-full bg-[var(--muted)] overflow-hidden"
      role="progressbar"
      aria-label={t("nav.progression")}
      aria-valuenow={Math.round(clamped)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div
        className="h-full rounded-full transition-all"
        style={{ width: `${clamped}%`, background: color }}
      />
    </div>
  );
}

/* ─── Avatar ───────────────────────────────────────────────────────── */
export function Avatar({
  name,
  size = "sm",
  color = "var(--secondary)",
  textColor = "var(--primary)",
}: {
  name: string;
  size?: "sm" | "md" | "lg";
  color?: string;
  textColor?: string;
}) {
  const initials =
    name
      .split(" ")
      .filter(Boolean)
      .map((p) => p[0])
      .join("")
      .slice(0, 2)
      .toUpperCase() || "?";
  const s = {
    sm: "w-8 h-8 text-xs",
    md: "w-10 h-10 text-sm",
    lg: "w-12 h-12 text-base",
  };
  return (
    <div
      className={`${s[size]} rounded-full flex items-center justify-center font-bold shrink-0`}
      style={{ background: color, color: textColor }}
      aria-hidden="true"
    >
      {initials}
    </div>
  );
}

/* ─── Toggle ───────────────────────────────────────────────────────── */
export function Toggle({
  checked,
  onChange,
  label,
  disabled,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  label?: string;
  disabled?: boolean;
}) {
  return (
    <div
      className={`flex items-center gap-2 select-none ${
        disabled ? "opacity-50" : "cursor-pointer"
      }`}
    >
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        aria-label={label}
        disabled={disabled}
        onClick={() => !disabled && onChange(!checked)}
        className="inline-flex min-h-11 min-w-11 items-center justify-center shrink-0"
      >
        <span
          className={`relative block w-10 h-5 rounded-full transition-colors ${
            checked ? "bg-[var(--primary)]" : "bg-[var(--border)]"
          }`}
        >
          <span
            className={`absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-all ${
              checked ? "left-5" : "left-0.5"
            }`}
          />
        </span>
      </button>
      {label && <span className="text-sm">{label}</span>}
    </div>
  );
}

/* ─── Alert ────────────────────────────────────────────────────────── */
export function Alert({
  message,
  type = "info",
}: {
  message: string;
  type?: "info" | "success" | "error" | "warning";
}) {
  const c = {
    info: "bg-blue-50 border-blue-200 text-blue-700",
    success: "bg-green-50 border-green-200 text-green-700",
    error: "bg-red-50 border-red-200 text-red-700",
    warning: "bg-orange-50 border-orange-200 text-orange-700",
  };
  return (
    <div
      role={type === "error" ? "alert" : "status"}
      className={`flex items-center gap-2 px-3.5 py-2.5 rounded-lg text-sm border ${c[type]}`}
    >
      <Icons.Alert />
      <span className="flex-1">{message}</span>
    </div>
  );
}

/* ─── PageHeader ───────────────────────────────────────────────────── */
export function PageHeader({
  title,
  subtitle,
  actions,
  back,
}: {
  title: string;
  subtitle?: string;
  actions?: ReactNode;
  back?: () => void;
}) {
  const { t } = useI18n();
  return (
    <div className="ui-page-header flex items-start justify-between mb-6 gap-3 flex-wrap">
      <div className="min-w-0 break-words">
        {back && (
          <button
            onClick={back}
            className="min-h-11 text-sm text-[var(--muted-foreground)] mb-2 hover:text-[var(--foreground)] flex items-center gap-1"
          >
            {t("common.back")}
          </button>
        )}
        <h1 className="font-display text-2xl font-semibold leading-tight">{title}</h1>
        {subtitle && <p className="text-sm text-[var(--muted-foreground)] mt-0.5">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2 flex-wrap max-w-full">{actions}</div>}
    </div>
  );
}

/* ─── États de données ─────────────────────────────────────────────── */

export function Spinner({ label }: { label?: string }) {
  const { t } = useI18n();
  return (
    <div
      className="flex items-center justify-center gap-2 py-8 text-sm text-[var(--muted-foreground)]"
      role="status"
    >
      <span className="w-4 h-4 border-2 border-[var(--primary)] border-t-transparent rounded-full animate-spin" />
      <span>{label ?? t("common.loading")}</span>
    </div>
  );
}

export function Skeleton({ className = "" }: { className?: string }) {
  return <div className={`animate-pulse rounded-[var(--radius)] bg-[var(--muted)] ${className}`} />;
}

export function SkeletonList({
  rows = 3,
  className = "h-16",
}: {
  rows?: number;
  className?: string;
}) {
  return (
    <div className="space-y-2.5" aria-hidden="true">
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} className={className} />
      ))}
    </div>
  );
}

export function SkeletonStats({ count = 4 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-7">
      {Array.from({ length: count }, (_, i) => (
        <Skeleton key={i} className="h-[86px]" />
      ))}
    </div>
  );
}

export function EmptyState({ message, action }: { message: string; action?: ReactNode }) {
  return (
    <Card className="p-8 text-center">
      <div className="w-12 h-12 rounded-full bg-[var(--muted)] flex items-center justify-center mx-auto mb-3">
        <Icons.Class />
      </div>
      <p className="text-sm text-[var(--muted-foreground)]">{message}</p>
      {action && <div className="mt-4">{action}</div>}
    </Card>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  const { t } = useI18n();
  return (
    <Card className="p-6 text-center">
      <div
        className="w-12 h-12 rounded-full bg-red-50 flex items-center justify-center mx-auto mb-3"
        style={{ color: "var(--danger)" }}
      >
        <Icons.Alert />
      </div>
      <p className="text-sm text-[var(--muted-foreground)] mb-4">{message}</p>
      {onRetry && (
        <Btn size="sm" variant="secondary" onClick={onRetry}>
          {t("common.retry")}
        </Btn>
      )}
    </Card>
  );
}

/**
 * Affiche le bon état pour toute ressource asynchrone : squelette pendant le
 * chargement, erreur avec reprise, contenu sinon.
 */
export function AsyncBoundary({
  loading,
  error,
  isEmpty,
  onRetry,
  errorMessage,
  empty,
  children,
}: {
  loading: boolean;
  error: Error | null;
  isEmpty?: boolean;
  onRetry?: () => void;
  errorMessage: string;
  empty?: ReactNode;
  children: ReactNode;
}) {
  if (loading)
    return (
      <>
        <Spinner />
        <SkeletonList />
      </>
    );
  if (error) return <ErrorState message={errorMessage} onRetry={onRetry} />;
  if (isEmpty && empty) return <>{empty}</>;
  return <>{children}</>;
}

/* ─── Sélecteur de langue ──────────────────────────────────────────── */
export function LocaleSwitch({
  locale,
  onChange,
  light = false,
}: {
  locale: Locale;
  onChange: (locale: Locale) => void;
  light?: boolean;
}) {
  const { t } = useI18n();
  // Native selection remains keyboard/touch accessible inside scrolling dialogs.
  // An absolutely positioned footer menu was clipped by its scroll container.
  return (
    <select
      value={locale}
      onChange={(event) => onChange(event.target.value as Locale)}
      className={`shrink-0 min-h-10 w-20 px-2 text-sm font-medium rounded-lg border cursor-pointer ${
        light
          ? "bg-white border-[var(--border-subtle)] text-[var(--primary)]"
          : "bg-[var(--sidebar)] border-white/25 text-white"
      }`}
      aria-label={t("profile.language")}
    >
      <option value="fr" lang="fr">
        FR
      </option>
      <option value="en" lang="en">
        EN
      </option>
    </select>
  );
}

export function Dialog({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
}) {
  const { t } = useI18n();
  const ref = useRef<HTMLDivElement>(null);
  const closeRef = useRef(onClose);
  closeRef.current = onClose;
  const id = useId();
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const controls = () =>
      Array.from(
        ref.current?.querySelectorAll<HTMLElement>(
          'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]',
        ) ?? [],
      );
    (controls()[0] ?? ref.current)?.focus();
    const keydown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        closeRef.current();
      }
      if (event.key !== "Tab") return;
      const items = controls();
      const first = items[0];
      const last = items[items.length - 1];
      if (!first) {
        event.preventDefault();
        return;
      }
      if (
        event.shiftKey &&
        (document.activeElement === first || document.activeElement === ref.current)
      ) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", keydown);
    return () => {
      document.removeEventListener("keydown", keydown);
      document.body.style.overflow = previousOverflow;
      previous?.focus();
    };
  }, []);
  return createPortal(
    <div
      className="fixed inset-0 z-[100] bg-black/50 flex items-center justify-center p-4"
      onClick={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div
        ref={ref}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby={id}
        className="ui-dialog bg-white rounded-xl border border-[var(--border-subtle)] p-4 sm:p-6 w-full max-w-xl max-h-[90dvh] overflow-y-auto"
      >
        <div className="flex gap-3 items-start justify-between mb-4">
          <h2 id={id} className="font-display text-xl font-semibold">
            {title}
          </h2>
          <button
            type="button"
            aria-label={t("common.close")}
            onClick={onClose}
            className="min-h-11 min-w-11 rounded-lg bg-[var(--secondary)] inline-flex items-center justify-center shrink-0"
          >
            <Icons.X />
          </button>
        </div>
        {children}
      </div>
    </div>,
    document.body,
  );
}

export function ConfirmButton({
  message,
  onClick,
  children,
  ...props
}: React.ComponentProps<typeof Btn> & { message?: string }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  return (
    <>
      <Btn {...props} onClick={() => setOpen(true)}>
        {children}
      </Btn>
      {open && (
        <Dialog title={t("confirm.title")} onClose={() => setOpen(false)}>
          <p className="text-sm mb-5">{message ?? t("confirm.body")}</p>
          <div className="flex justify-end gap-2">
            <Btn variant="secondary" onClick={() => setOpen(false)}>
              {t("common.cancel")}
            </Btn>
            <Btn
              variant="danger"
              onClick={() => {
                setOpen(false);
                onClick?.();
              }}
            >
              {t("confirm.proceed")}
            </Btn>
          </div>
        </Dialog>
      )}
    </>
  );
}
