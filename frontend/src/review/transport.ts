import type { RequestOptions } from "../lib/api";

/** Temporary presentation-only entry. Never use this adapter on a normal app route. */
export function isV2ReviewPath(): boolean {
  return typeof window !== "undefined" && /^\/v2-review\/?$/.test(window.location.pathname);
}

export interface ReviewTransport {
  request(path: string, options: RequestOptions): Promise<unknown>;
  download(path: string, fileName: string): Promise<void>;
}

let transport: ReviewTransport | null = null;

export function installReviewTransport(value: ReviewTransport): () => void {
  transport = value;
  return () => {
    if (transport === value) transport = null;
  };
}

export const getReviewTransport = () => transport;
