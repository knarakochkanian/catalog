import { Routes } from "@angular/router";
import { authGuard } from "./core/auth.guard";

export const routes: Routes = [
  {
    path: "login",
    loadComponent: () =>
      import("./pages/login/login.component").then((m) => m.LoginComponent),
  },
  {
    path: "",
    canActivateChild: [authGuard],
    loadComponent: () =>
      import("./layout/shell.component").then((m) => m.ShellComponent),
    children: [
      { path: "", pathMatch: "full", redirectTo: "products" },
      {
        path: "products",
        loadComponent: () =>
          import("./pages/products/products.component").then(
            (m) => m.ProductsComponent,
          ),
      },
      {
        path: "products/:id",
        loadComponent: () =>
          import("./pages/product-detail/product-detail.component").then(
            (m) => m.ProductDetailComponent,
          ),
      },
      {
        path: "import",
        loadComponent: () =>
          import("./pages/import/import.component").then(
            (m) => m.ImportComponent,
          ),
      },
    ],
  },
  { path: "**", redirectTo: "products" },
];
