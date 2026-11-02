import i18n from '@/i18n';

type ErrorBody = {
  message?: unknown;
  code?: unknown;
  errors?: unknown;
};

/**
 * Every failed call becomes an ApiError. `message` is already localized by the API (or by us
 * for network failures); branch on `code`, never on the text.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | undefined;
  readonly fieldErrors: Record<string, string[]>;
  readonly body: unknown;
  readonly retryAfterSeconds: number | undefined;

  constructor(options: {
    status: number;
    message: string;
    code?: string;
    fieldErrors?: Record<string, string[]>;
    body?: unknown;
    retryAfterSeconds?: number;
  }) {
    super(options.message);
    this.name = 'ApiError';
    this.status = options.status;
    this.code = options.code;
    this.fieldErrors = options.fieldErrors ?? {};
    this.body = options.body;
    this.retryAfterSeconds = options.retryAfterSeconds;
  }

  get isNetworkError(): boolean {
    return this.status === 0;
  }

  /** First message for a request field, for showing under an input. */
  fieldError(field: string): string | undefined {
    return this.fieldErrors[field]?.[0];
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError;
}

function parseFieldErrors(value: unknown): Record<string, string[]> {
  if (!value || typeof value !== 'object') return {};
  const out: Record<string, string[]> = {};
  for (const [field, messages] of Object.entries(value)) {
    if (Array.isArray(messages)) out[field] = messages.filter((m) => typeof m === 'string');
  }
  return out;
}

export function errorFromResponse(response: Response, body: unknown): ApiError {
  const parsed: ErrorBody = body && typeof body === 'object' ? (body as ErrorBody) : {};
  const retryAfter = Number(response.headers.get('Retry-After'));
  const fallback = response.status === 404 ? i18n.t('errors.notFound') : i18n.t('errors.generic');
  return new ApiError({
    status: response.status,
    message: typeof parsed.message === 'string' && parsed.message ? parsed.message : fallback,
    code: typeof parsed.code === 'string' ? parsed.code : undefined,
    fieldErrors: parseFieldErrors(parsed.errors),
    body,
    retryAfterSeconds: Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter : undefined,
  });
}

export function networkError(cause: unknown): ApiError {
  return new ApiError({
    status: 0,
    message: i18n.t('errors.network'),
    code: 'network_error',
    body: cause,
  });
}

/** A message safe to show for any thrown value. */
export function errorMessage(error: unknown): string {
  if (isApiError(error)) return error.message;
  return i18n.t('errors.generic');
}
