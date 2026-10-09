import {
  ApplicationConfig,
  LOCALE_ID,
  provideBrowserGlobalErrorListeners,
  provideZonelessChangeDetection,
} from "@angular/core";
import { provideHttpClient, withInterceptors } from "@angular/common/http";
import { provideRouter, withComponentInputBinding } from "@angular/router";
import { provideStore } from "@ngrx/store";
import { provideEffects } from "@ngrx/effects";
import { routes } from "./app.routes";
import { apiInterceptor } from "./core/api.interceptor";
import { productsReducer } from "./store/products.reducer";
import { ProductsEffects } from "./store/products.effects";

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZonelessChangeDetection(),
    { provide: LOCALE_ID, useValue: "ru" },
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withInterceptors([apiInterceptor])),
    provideStore({ products: productsReducer }),
    provideEffects(ProductsEffects),
  ],
};
