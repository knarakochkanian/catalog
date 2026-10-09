import { HttpErrorResponse, HttpInterceptorFn } from "@angular/common/http";
import { inject } from "@angular/core";
import { Router } from "@angular/router";
import { catchError, throwError } from "rxjs";
import { AuthService } from "./auth.service";

export const apiInterceptor: HttpInterceptorFn = (request, next) => {
  if (!request.url.startsWith("/api/")) return next(request);
  const auth = inject(AuthService);
  const router = inject(Router);
  const token = auth.csrfToken();
  const mutates = !["GET", "HEAD", "OPTIONS"].includes(request.method);
  const authenticatedRequest = request.clone({
    withCredentials: true,
    ...(mutates && token ? { setHeaders: { "X-CSRF-Token": token } } : {}),
  });
  return next(authenticatedRequest).pipe(
    catchError((error: unknown) => {
      if (
        error instanceof HttpErrorResponse &&
        error.status === 401 &&
        !request.url.startsWith("/api/auth/")
      ) {
        auth.clearSession();
        void router.navigate(["/login"], {
          queryParams: { returnUrl: router.url },
        });
      }
      return throwError(() => error);
    }),
  );
};
