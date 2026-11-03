import type { Metadata } from "next";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { AppPaymentForm } from "@/components/app-payment-form";

export async function generateMetadata({ params }: PageProps<"/[locale]/payments/app">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).pages.appPayment, robots: { index: false } };
}

// The mobile app opens this page in a WebView with the API's checkout in the
// URL fragment, so the app uses the same Moyasar form (and Apple Pay domain)
// as the website. The app watches for the return to /payments/return and
// asks the API what happened; nothing here talks to the API.
export default function AppPaymentPage() {
  return <AppPaymentForm />;
}
