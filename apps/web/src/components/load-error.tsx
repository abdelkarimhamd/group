import { getI18n } from "@/i18n/server";
import { Alert } from "./ui";

/** Shown in place of a section when the API can't be reached. */
export async function LoadError() {
  const { t } = await getI18n();
  return (
    <Alert tone="warning" title={t.common.errorTitle}>
      {t.common.networkError}
    </Alert>
  );
}
