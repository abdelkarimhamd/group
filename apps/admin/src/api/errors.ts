/** Any non-2xx answer from the API, normalised from the contract's Error and ValidationError bodies. */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | undefined;
  readonly fieldErrors: Record<string, string[]>;
  readonly body: unknown;
  readonly requestId: string | undefined;

  constructor(options: {
    status: number;
    message: string;
    code?: string;
    fieldErrors?: Record<string, string[]>;
    body?: unknown;
    requestId?: string;
  }) {
    super(options.message);
    this.name = 'ApiError';
    this.status = options.status;
    this.code = options.code;
    this.fieldErrors = options.fieldErrors ?? {};
    this.body = options.body;
    this.requestId = options.requestId;
  }

  static async fromResponse(response: Response): Promise<ApiError> {
    let body: unknown = undefined;
    try {
      const text = await response.clone().text();
      body = text ? JSON.parse(text) : undefined;
    } catch {
      body = undefined;
    }
    const record = isRecord(body) ? body : {};
    const message =
      typeof record.message === 'string' && record.message !== ''
        ? record.message
        : `${response.status} ${response.statusText}`.trim();
    return new ApiError({
      status: response.status,
      message,
      code: typeof record.code === 'string' ? record.code : undefined,
      fieldErrors: isFieldErrors(record.errors) ? record.errors : undefined,
      body,
      requestId: response.headers.get('X-Request-Id') ?? undefined,
    });
  }
}

/** Network failures and bugs become an ApiError with status 0 so screens handle one type. */
export function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) return error;
  const message = error instanceof Error ? error.message : String(error);
  return new ApiError({ status: 0, message, code: 'network_error' });
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isFieldErrors(value: unknown): value is Record<string, string[]> {
  return (
    isRecord(value) &&
    Object.values(value).every((v) => Array.isArray(v) && v.every((m) => typeof m === 'string'))
  );
}
