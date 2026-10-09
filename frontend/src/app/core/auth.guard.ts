import { inject } from "@angular/core";
import { CanActivateChildFn, Router } from "@angular/router";
import { catchError, map, of } from "rxjs";
import { AuthService } from "./auth.service";

export const authGuard: CanActivateChildFn = (_route, state) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const login = router.createUrlTree(["/login"], {
    queryParams: { returnUrl: state.url },
  });
  return auth.ensureSession().pipe(
    map((session) => (session.authenticated ? true : login)),
    catchError(() => of(login)),
  );
};
