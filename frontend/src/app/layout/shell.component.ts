import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from "@angular/core";
import {
  Router,
  RouterLink,
  RouterLinkActive,
  RouterOutlet,
} from "@angular/router";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { finalize } from "rxjs";
import { AuthService } from "../core/auth.service";
import { errorMessage } from "../core/error-message";

@Component({
  selector: "app-shell",
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  template: `
    <div class="workspace">
      <aside class="sidebar">
        <a
          class="brand"
          routerLink="/products"
          aria-label="Каталог, главная страница"
          ><span class="brand-mark">К</span
          ><span
            >Каталог<span class="brand-sub">Рабочее пространство</span></span
          ></a
        >
        <span class="nav-caption">УПРАВЛЕНИЕ ТОВАРАМИ</span>
        <nav aria-label="Главная навигация">
          <a
            routerLink="/products"
            routerLinkActive="active"
            [routerLinkActiveOptions]="{ exact: false }"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <rect x="3" y="3" width="7" height="7" rx="1.5" />
              <rect x="14" y="3" width="7" height="7" rx="1.5" />
              <rect x="3" y="14" width="7" height="7" rx="1.5" />
              <rect x="14" y="14" width="7" height="7" rx="1.5" /></svg
            >Товары
          </a>
          <a routerLink="/import" routerLinkActive="active">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path
                d="M12 16V3m-5 5 5-5 5 5M4 15v5a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"
              /></svg
            >Импорт из Excel
          </a>
        </nav>
        <div class="sidebar-note">
          <span class="note-dot"></span>
          <div>Единый каталог<span>Импорт, проверка и экспорт</span></div>
        </div>
      </aside>
      <div class="main-area">
        <header class="topbar">
          <span class="topbar-label">Управление каталогом</span>
          <div class="user-area">
            <span class="avatar">{{
              auth.session()?.username?.slice(0, 1)?.toUpperCase() || "А"
            }}</span
            ><span class="username">{{ auth.session()?.username }}</span
            ><button
              type="button"
              class="btn btn-quiet"
              [disabled]="loggingOut()"
              (click)="logout()"
            >
              {{ loggingOut() ? "Выходим…" : "Выйти" }}
            </button>
          </div>
        </header>
        <main class="page-content" id="main-content">
          @if (logoutError()) {
            <div class="alert alert-danger" role="alert">
              {{ logoutError() }}
            </div>
          }
          <router-outlet />
        </main>
      </div>
    </div>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShellComponent {
  readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  readonly loggingOut = signal(false);
  readonly logoutError = signal("");
  logout(): void {
    this.loggingOut.set(true);
    this.logoutError.set("");
    this.auth
      .logout()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loggingOut.set(false)),
      )
      .subscribe({
        next: () => {
          void this.router.navigate(["/login"]);
        },
        error: (error: unknown) => this.logoutError.set(errorMessage(error)),
      });
  }
}
