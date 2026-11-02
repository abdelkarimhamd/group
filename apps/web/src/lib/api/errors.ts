import type { ApiErrorBody } from "./types";

/** A non-2xx answer from the API, or no answer at all (status 0). */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | undefined;
  readonly body: ApiErrorBody | undefined;
  readonly retryAfter: number | undefined;

  constructor(status: number, body?: ApiErrorBody, retryAfter?: number) {
    super(body?.message ?? `API request failed with status ${status}`);
    this.name = "ApiError";
    this.status = status;
    this.code = body?.code;
    this.body = body;
    this.retryAfter = retryAfter;
  }

  get fieldErrors(): Record<string, string[]> {
    return this.body?.errors ?? {};
  }
}

/** What a Server Action hands back to the browser when the API says no. */
export type ActionFailure = {
  ok: false;
  status: number;
  code?: string;
  message?: string;
  fieldErrors?: Record<string, string[]>;
  retryAfter?: number;
  currentUnitPrice?: { amount_minor: number; currency: string };
  remainingQty?: number;
};

export function toActionFailure(error: unknown): ActionFailure {
  if (error instanceof ApiError) {
    return {
      ok: false,
      status: error.status,
      code: error.code,
      message: error.body?.message,
      fieldErrors: error.body?.errors,
      retryAfter: error.retryAfter,
      currentUnitPrice: error.body?.current_unit_price,
      remainingQty: error.body?.remaining_qty,
    };
  }
  console.error(error);
  return { ok: false, status: 0, code: "unexpected" };
}

export function parseRetryAfter(value: string | null): number | undefined {
  if (!value) return undefined;
  const seconds = Number.parseInt(value, 10);
  return Number.isFinite(seconds) && seconds >= 0 ? seconds : undefined;
}
