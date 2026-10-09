import { createFeatureSelector, createSelector } from "@ngrx/store";
import { ProductsState } from "./products.reducer";

const selectProductsState = createFeatureSelector<ProductsState>("products");
export const selectAllProducts = createSelector(
  selectProductsState,
  (state) => state.items,
);
export const selectProductsLoading = createSelector(
  selectProductsState,
  (state) => state.loading,
);
export const selectTotalPages = createSelector(
  selectProductsState,
  (state) => state.totalPages,
);
export const selectProductsView = createSelector(
  selectProductsState,
  selectAllProducts,
  selectProductsLoading,
  selectTotalPages,
  (state, items, loading, totalPages) => ({
    items,
    loading,
    totalPages,
    page: state.page,
    total: state.total,
    error: state.error,
    query: state.query,
  }),
);
