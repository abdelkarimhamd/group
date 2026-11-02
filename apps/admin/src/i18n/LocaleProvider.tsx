import { App as AntApp, ConfigProvider, theme } from 'antd';
import arEG from 'antd/locale/ar_EG';
import enUS from 'antd/locale/en_US';
import 'dayjs/locale/ar';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { configureApiSession } from '../api/client';
import { dayjs } from '../lib/time';
import i18n, { directionOf, rememberLocale, storedLocale, type Locale } from './index';

type LocaleContextValue = { locale: Locale; setLocale: (locale: Locale) => void };

const LocaleContext = createContext<LocaleContextValue | null>(null);

export function LocaleProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(() => storedLocale());

  useEffect(() => {
    const dir = directionOf(locale);
    document.documentElement.lang = locale;
    document.documentElement.dir = dir;
    dayjs.locale(locale);
    void i18n.changeLanguage(locale);
    configureApiSession({ getLocale: () => locale });
  }, [locale]);

  const setLocale = useCallback((next: Locale) => {
    rememberLocale(next);
    setLocaleState(next);
  }, []);

  const value = useMemo(() => ({ locale, setLocale }), [locale, setLocale]);

  return (
    <LocaleContext.Provider value={value}>
      <ConfigProvider
        direction={directionOf(locale)}
        locale={locale === 'ar' ? arEG : enUS}
        theme={{
          algorithm: theme.defaultAlgorithm,
          token: {
            colorPrimary: '#0f6e56',
            borderRadius: 6,
            fontFamily:
              locale === 'ar'
                ? "'IBM Plex Sans Arabic', 'Segoe UI', Tahoma, sans-serif"
                : "'Inter', 'Segoe UI', system-ui, sans-serif",
          },
        }}
      >
        <AntApp>{children}</AntApp>
      </ConfigProvider>
    </LocaleContext.Provider>
  );
}

export function useLocale(): LocaleContextValue {
  const ctx = useContext(LocaleContext);
  if (!ctx) throw new Error('useLocale must be used inside LocaleProvider');
  return ctx;
}

/** Picks the Arabic or English field of an admin record for the current language. */
export function useBilingual() {
  const { locale } = useLocale();
  return <T extends Record<string, unknown>>(record: T, field: string): string => {
    const preferred = record[`${field}_${locale}`];
    const other = record[`${field}_${locale === 'ar' ? 'en' : 'ar'}`];
    if (typeof preferred === 'string' && preferred !== '') return preferred;
    return typeof other === 'string' ? other : '';
  };
}
