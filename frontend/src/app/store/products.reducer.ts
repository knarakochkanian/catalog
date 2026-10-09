import { createReducer, on } from "@ngrx/store";
import { Product, ProductQuery, defaultProductQuery } from "../models/product";
import {
  loadProducts,
  loadProductsFailure,
  loadProductsSuccess,
} from "./products.actions";

export interface ProductsState {
  items: Product[];
  loading: boolean;
  error: string | null;
  query: ProductQuery;
  page: number;
  total: number;
  totalPages: number;
}
export const initialProductsState: ProductsState = {
  items: [],
  loading: false,
  error: null,
  query: defaultProductQuery,
  page: 1,
  total: 0,
  totalPages: 0,
};
export const productsReducer = createReducer(
  initialProductsState,
  on(loadProducts, (state, { query }) => ({
    ...state,
    query,
    loading: true,
    error: null,
  })),
  on(loadProductsSuccess, (state, { response }) => ({
    ...state,
    items: response.items,
    loading: false,
    error: null,
    page: response.page,
    total: response.total,
    totalPages: response.total_pages,
  })),
  on(loadProductsFailure, (state, { error }) => ({
    ...state,
    loading: false,
    error,
  })),
);
