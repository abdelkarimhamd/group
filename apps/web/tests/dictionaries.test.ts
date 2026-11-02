import { describe, expect, it } from "vitest";
import { ar } from "@/i18n/dictionaries/ar";
import { en } from "@/i18n/dictionaries/en";

type Tree = string | Tree[] | { [key: string]: Tree };

function leaves(tree: Tree, path = ""): Array<[string, string]> {
  if (typeof tree === "string") return [[path, tree]];
  if (Array.isArray(tree)) return tree.flatMap((item, index) => leaves(item, `${path}[${index}]`));
  return Object.entries(tree).flatMap(([key, value]) => leaves(value, path ? `${path}.${key}` : key));
}

const placeholders = (text: string) => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

describe("dictionaries", () => {
  const arLeaves = new Map(leaves(ar as unknown as Tree));
  const enLeaves = new Map(leaves(en as unknown as Tree));

  // Plural forms differ by language (Arabic has six, English two), so only
  // the plural group itself has to exist in both.
  const PLURAL = /\.(zero|one|two|few|many|other)$/;
  const shape = (keys: Iterable<string>) => [...new Set([...keys].map((key) => key.replace(PLURAL, ".*")))].sort();

  it("have the same keys and list lengths", () => {
    expect(shape(enLeaves.keys())).toEqual(shape(arLeaves.keys()));
  });

  it("use the same placeholders in both languages", () => {
    for (const [key, text] of arLeaves) {
      // Plural forms may drop {count} ("وحدة واحدة" / "one unit").
      if (PLURAL.test(key)) continue;
      expect(placeholders(enLeaves.get(key) ?? ""), key).toEqual(placeholders(text));
    }
  });

  it("have no empty strings", () => {
    for (const [key, text] of [...arLeaves, ...enLeaves]) expect(text.trim(), key).not.toBe("");
  });
});
