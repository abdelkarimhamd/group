import { Button, Result } from 'antd';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate, useLocation } from 'react-router-dom';
import { ApiErrorAlert } from '../components/ApiErrorAlert';
import { ForbiddenResult, Loading } from '../components/Results';
import { useAuth } from './AuthProvider';
import type { Permission } from './permissions';

/** Sends anyone without a valid token to the sign-in page, then back. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { token, admin, loading, error, reload } = useAuth();
  const location = useLocation();
  const { t } = useTranslation();

  if (!token) {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }
  if (loading) return <Loading />;
  if (!admin) {
    return (
      <Result
        status="warning"
        title={t('auth.sessionCheckFailed')}
        extra={<Button onClick={reload}>{t('common.retry')}</Button>}
      >
        <ApiErrorAlert error={error} />
      </Result>
    );
  }
  return <>{children}</>;
}

export function RequirePermission({
  permission,
  children,
}: {
  permission: Permission;
  children: ReactNode;
}) {
  const { can } = useAuth();
  return can(permission) ? <>{children}</> : <ForbiddenResult />;
}
