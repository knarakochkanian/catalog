import { HttpClient, HttpParams } from "@angular/common/http";
import { Injectable, inject } from "@angular/core";
import { Observable } from "rxjs";
import { Product, ProductPage, ProductQuery } from "../models/product";

@Injectable({ providedIn: "root" })
export class ProductService {
  private readonly http = inject(HttpClient);
  getProducts(query: ProductQuery): Observable<ProductPage> {
    let params = new HttpParams()
      .set("page", query.page)
      .set("limit", query.limit);
    for (const key of ["name", "min_price", "max_price"] as const) {
      if (query[key] !== "") params = params.set(key, query[key]);
    }
    return this.http.get<ProductPage>("/api/products", { params });
  }
  getProduct(id: number): Observable<Product> {
    return this.http.get<Product>(`/api/products/${id}`);
  }
  exportProducts(): Observable<Blob> {
    return this.http.get("/api/products/export", { responseType: "blob" });
  }
}
