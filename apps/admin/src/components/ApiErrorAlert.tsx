import { Alert, Typography } from 'antd';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { toApiError } from '../api/errors';

/** Shows an API error with its code and request ID, so support can find it in the logs. */
export function ApiErrorAlert({ error, style }: { error: unknown; style?: React.CSSProperties }) {
  const { t } = useTranslation();
  if (!error) return null;
  const apiError = toApiError(error);
  const fieldMessages = Object.entries(apiError.fieldErrors).flatMap(([field, messages]) =>
    messages.map((m) => `${field}: ${m}`),
  );
  return (
    <Alert
      type="error"
      showIcon
      style={style}
      title={apiErrorMessage(t, apiError)}
      description={
        (fieldMessages.length > 0 || apiError.requestId) && (
          <>
            {fieldMessages.length > 0 && (
              <ul style={{ margin: 0, paddingInlineStart: 18 }}>
                {fieldMessages.map((m) => (
                  <li key={m}>{m}</li>
                ))}
              </ul>
            )}
            {apiError.requestId && (
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                {t('errors.requestId')}:{' '}
                <Typography.Text code>{apiError.requestId}</Typography.Text>
              </Typography.Text>
            )}
          </>
        )
      }
    />
  );
}

/** The API's localized message, or ours for codes the portal knows better. */
export function apiErrorMessage(t: TFunction, error: unknown): string {
  const apiError = toApiError(error);
  if (apiError.status === 0) return t('errors.network');
  if (apiError.status === 403 && !apiError.code) return t('errors.forbidden');
  // A list cursor the API no longer accepts (tampered with or out of date).
  if (apiError.status === 422 && 'cursor' in apiError.fieldErrors) return t('errors.badCursor');
  if (apiError.status === 409 && apiError.code === 'invalid_transition') {
    return t('errors.invalidTransition');
  }
  return apiError.message || t('errors.generic');
}
