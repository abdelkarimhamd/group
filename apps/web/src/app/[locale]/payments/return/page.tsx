import type { Metadata } from "next";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { PaymentReturn } from "@/components/payment-return";
import { Card, Container, Skeleton } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/payments/return">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).pages.paymentReturn, robots: { index: false } };
}

// Moyasar sends the customer here with ?id=<moyasar payment id>. The page
// never trusts the redirect's own status: it asks the API to check the
// payment with Moyasar and shows what the API decided.
export default async function PaymentReturnPage() {
  await getI18n();
  return (
    <Container className="flex justify-center py-10 sm:py-16">
      <div className="w-full max-w-xl">
        <Suspense
          fallback={
            <Card>
              <Skeleton className="h-32 w-full" />
            </Card>
          }
        >
          <PaymentReturn />
        </Suspense>
      </div>
    </Container>
  );
}
