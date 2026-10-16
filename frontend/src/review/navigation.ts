import { createContext } from "react";

/** Review-only back navigation stays inside the memory router. */
export const ReviewBackContext = createContext<(() => void) | null>(null);
