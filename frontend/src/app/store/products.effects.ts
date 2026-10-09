import { Injectable, inject } from "@angular/core";
import { Actions, createEffect, ofType } from "@ngrx/effects";
import { catchError, map, of, switchMap } from "rxjs";
import { ProductService } from "../services/product.service";
import { errorMessage } from "../core/error-message";
import {
  loadProducts,
  loadProductsFailure,
  loadProductsSuccess,
} from "./products.actions";

@Injectable()
export class ProductsEffects {
  private readonly actions = inject(Actions);
  private readonly service = inject(ProductService);
  readonly load = createEffect(() =>
    this.actions.pipe(
      ofType(loadProducts),
      switchMap(({ query }) =>
        this.service.getProducts(query).pipe(
          map((response) => loadProductsSuccess({ response })),
          catchError((error: unknown) =>
            of(loadProductsFailure({ error: errorMessage(error) })),
          ),
        ),
      ),
    ),
  );
}
