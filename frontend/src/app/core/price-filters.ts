export const PRICE_FILTER_PATTERN = /^\d+([,.]\d{1,2})?$/;
export function priceFilters(
  minimum: string,
  maximum: string,
): { min_price: string; max_price: string; valid: boolean } {
  const min_price = minimum.trim().replace(",", ".");
  const max_price = maximum.trim().replace(",", ".");
  const valid =
    (!min_price || PRICE_FILTER_PATTERN.test(min_price)) &&
    (!max_price || PRICE_FILTER_PATTERN.test(max_price)) &&
    (!min_price || !max_price || Number(min_price) <= Number(max_price));
  return { min_price, max_price, valid };
}
