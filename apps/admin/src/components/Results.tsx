import { Button, Result, Spin } from 'antd';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { toApiError } from '../api/errors';
import { ApiErrorAlert } from './ApiErrorAlert';

export function ForbiddenResult() {
  const { t } = useTranslation();
  return (
    <Result
      status="403"
      title={t('errors.forbiddenTitle')}
      subTitle={t('errors.forbidden')}
      extra={
        <Link to="/">
          <Button>{t('nav.home')}</Button>
        </Link>
      }
    />
  );
}

export function NotFoundResult() {
  const { t } = useTranslation();
  return (
    <Result
      status="404"
      title={t('errors.notFoundTitle')}
      subTitle={t('errors.notFound')}
      extra={
        <Link to="/">
          <Button>{t('nav.home')}</Button>
        </Link>
      }
    />
  );
}

export function Loading() {
  return (
    <div style={{ padding: 48, textAlign: 'center' }}>
      <Spin size="large" />
    </div>
  );
}

/** Loading, 404 and other errors for a detail page's main query. */
export function QueryState({ isPending, error }: { isPending: boolean; error: unknown }) {
  if (error) {
    const apiError = toApiError(error);
    if (apiError.status === 404) return <NotFoundResult />;
    if (apiError.status === 403) return <ForbiddenResult />;
    return <ApiErrorAlert error={error} />;
  }
  if (isPending) return <Loading />;
  return null;
}
