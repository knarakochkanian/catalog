import { describe, expect, it } from "vitest";
import { priceFilters } from "./price-filters";

describe("price filters", () => {
  it("normalizes decimal commas for the server without losing zero bounds", () => {
    expect(priceFilters("0,00", "10,50")).toEqual({
      min_price: "0.00",
      max_price: "10.50",
      valid: true,
    });
  });
  it("rejects a lower bound above a zero upper bound", () => {
    expect(priceFilters("10", "0").valid).toBe(false);
  });
  it("permits an unbounded price range but rejects malformed and negative amounts", () => {
    expect(priceFilters("", "").valid).toBe(true);
    expect(priceFilters("", "0").valid).toBe(true);
    expect(priceFilters("-1", "10").valid).toBe(false);
    expect(priceFilters("1,234", "10").valid).toBe(false);
  });
});
