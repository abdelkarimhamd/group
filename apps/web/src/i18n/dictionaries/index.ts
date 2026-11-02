import { ar } from "./ar";
import { en } from "./en";
import type { Locale } from "../config";

export type Dictionary = typeof ar;

const dictionaries: Record<Locale, Dictionary> = { ar, en };

export function getDictionary(locale: Locale): Dictionary {
  return dictionaries[locale];
}
