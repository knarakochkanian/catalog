import { AsyncPipe, DecimalPipe } from "@angular/common";
import { ChangeDetectionStrategy, Component, inject } from "@angular/core";
import {
  AbstractControl,
  FormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  Validators,
} from "@angular/forms";
import { RouterLink } from "@angular/router";
import { Store } from "@ngrx/store";
import {
  Subject,
  catchError,
  debounceTime,
  distinctUntilChanged,
  exhaustMap,
  fromEvent,
  map,
  of,
  shareReplay,
  startWith,
  tap,
} from "rxjs";
import { ProductQuery, defaultProductQuery } from "../../models/product";
import { ProductService } from "../../services/product.service";
import { loadProducts } from "../../store/products.actions";
import { selectProductsView } from "../../store/products.selectors";
import { errorMessage } from "../../core/error-message";
import { downloadBlob } from "../../core/download";
import { PRICE_FILTER_PATTERN, priceFilters } from "../../core/price-filters";

function priceRange(control: AbstractControl): ValidationErrors | null {
  const value = control.value as { minPrice?: string; maxPrice?: string };
  return priceFilters(value.minPrice ?? "", value.maxPrice ?? "").valid
    ? null
    : { priceRange: true };
}

@Component({
  selector: "app-products",
  imports: [AsyncPipe, DecimalPipe, ReactiveFormsModule, RouterLink],
  templateUrl: "./products.component.html",
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductsComponent {
  private readonly store = inject(Store);
  private readonly service = inject(ProductService);
  readonly view$ = this.store.select(selectProductsView);
  private readonly mobileQuery = window.matchMedia("(max-width: 700px)");
  readonly isMobile$ = fromEvent<MediaQueryListEvent>(
    this.mobileQuery,
    "change",
  ).pipe(
    map((event) => event.matches),
    startWith(this.mobileQuery.matches),
    distinctUntilChanged(),
  );
  readonly filters = inject(FormBuilder).nonNullable.group(
    {
      name: [""],
      minPrice: ["", [Validators.pattern(PRICE_FILTER_PATTERN)]],
      maxPrice: ["", [Validators.pattern(PRICE_FILTER_PATTERN)]],
    },
    { validators: priceRange },
  );
  private currentQuery: ProductQuery = { ...defaultProductQuery };
  readonly filtersChanged$ = this.filters.valueChanges.pipe(
    startWith(this.filters.getRawValue()),
    debounceTime(350),
    distinctUntilChanged(
      (a, b) =>
        a.name === b.name &&
        a.minPrice === b.minPrice &&
        a.maxPrice === b.maxPrice,
    ),
    tap((value) => {
      if (this.filters.valid) {
        const prices = priceFilters(value.minPrice ?? "", value.maxPrice ?? "");
        this.currentQuery = {
          ...defaultProductQuery,
          name: (value.name ?? "").trim(),
          min_price: prices.min_price,
          max_price: prices.max_price,
        };
        this.store.dispatch(loadProducts({ query: this.currentQuery }));
      }
    }),
    map(() => true),
  );
  private readonly exportClicks = new Subject<void>();
  readonly exportState$ = this.exportClicks.pipe(
    exhaustMap(() =>
      this.service.exportProducts().pipe(
        tap((blob) => downloadBlob(blob, "products.xlsx")),
        map(() => ({ loading: false, error: "" })),
        catchError((error: unknown) =>
          of({ loading: false, error: errorMessage(error) }),
        ),
        startWith({ loading: true, error: "" }),
      ),
    ),
    startWith({ loading: false, error: "" }),
    shareReplay({ bufferSize: 1, refCount: true }),
  );
  export(): void {
    this.exportClicks.next();
  }
  reload(): void {
    this.store.dispatch(loadProducts({ query: this.currentQuery }));
  }
  goToPage(page: number): void {
    this.currentQuery = { ...this.currentQuery, page };
    this.reload();
  }
  resetFilters(): void {
    this.filters.reset({ name: "", minPrice: "", maxPrice: "" });
  }
}
