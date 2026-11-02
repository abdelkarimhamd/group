import { createInstance } from 'i18next';
import { initReactI18next } from 'react-i18next';

import ar from './ar';
import en from './en';
import { initialLocale } from './locale';

const i18n = createInstance();

void i18n.use(initReactI18next).init({
  resources: { ar: { translation: ar }, en: { translation: en } },
  lng: initialLocale(),
  fallbackLng: 'ar',
  initAsync: false,
  interpolation: { escapeValue: false },
  returnNull: false,
});

export default i18n;
