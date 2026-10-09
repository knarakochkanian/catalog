import { HttpClient } from "@angular/common/http";
import { Injectable, inject, signal } from "@angular/core";
import { Observable, finalize, shareReplay, switchMap, tap, of } from "rxjs";

export interface Session {
  authenticated: boolean;
  username?: string;
  csrf_token: string;
}

@Injectable({ providedIn: "root" })
export class AuthService {
  private readonly http = inject(HttpClient);
  readonly session = signal<Session | null>(null);
  private sessionRequest: Observable<Session> | undefined;

  ensureSession(): Observable<Session> {
    const existing = this.session();
    if (existing) return of(existing);
    if (!this.sessionRequest) {
      this.sessionRequest = this.http.get<Session>("/api/auth/session").pipe(
        tap((session) => this.session.set(session)),
        finalize(() => {
          this.sessionRequest = undefined;
        }),
        shareReplay({ bufferSize: 1, refCount: true }),
      );
    }
    return this.sessionRequest;
  }

  login(username: string, password: string): Observable<Session> {
    return this.ensureSession().pipe(
      switchMap(() =>
        this.http.post<Session>("/api/auth/login", { username, password }),
      ),
      tap((session) => this.session.set(session)),
    );
  }

  logout(): Observable<unknown> {
    return this.http
      .post("/api/auth/logout", {})
      .pipe(tap(() => this.clearSession()));
  }

  clearSession(): void {
    this.session.set(null);
  }
  csrfToken(): string | null {
    return this.session()?.csrf_token ?? null;
  }
}
