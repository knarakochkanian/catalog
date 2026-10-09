export interface ProductAttribute {
  id: number;
  key: string;
  value: string;
}
export interface ProductImage {
  id: number;
  url: string;
  path: string;
}
export interface Product {
  id: number;
  external_code: string;
  name: string;
  description: string;
  price: string;
  purchase_price: string;
  discount: string;
  attributes: ProductAttribute[];
  images: ProductImage[];
}
export interface ProductQuery {
  page: number;
  limit: number;
  name: string;
  min_price: string;
  max_price: string;
}
export interface ProductPage {
  items: Product[];
  page: number;
  limit: number;
  total: number;
  total_pages: number;
}
export const defaultProductQuery: ProductQuery = {
  page: 1,
  limit: 20,
  name: "",
  min_price: "",
  max_price: "",
};
