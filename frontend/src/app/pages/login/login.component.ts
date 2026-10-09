import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from "@angular/core";
import { FormBuilder, ReactiveFormsModule, Validators } from "@angular/forms";
import { ActivatedRoute, Router } from "@angular/router";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { finalize } from "rxjs";
import { AuthService } from "../../core/auth.service";
import { errorMessage } from "../../core/error-message";

@Component({
  selector: "app-login",
  imports: [ReactiveFormsModule],
  template: `
    <main class="login-screen">
      <div class="login-brand"><span class="brand-mark">К</span> Каталог</div>
      <section class="login-card">
        <span class="eyebrow">РАБОЧЕЕ ПРОСТРАНСТВО</span>
        <h1>Вход в каталог</h1>
        <p class="text-muted">
          Войдите, чтобы импортировать товары и работать с каталогом.
        </p>
        <form [formGroup]="form" (ngSubmit)="login()">
          <div class="mb-3">
            <label class="form-label" for="username">Имя пользователя</label
            ><input
              class="form-control"
              id="username"
              formControlName="username"
              autocomplete="username"
              placeholder="Введите имя пользователя"
              [attr.aria-invalid]="
                form.controls.username.touched && form.controls.username.invalid
              "
            />
            @if (
              form.controls.username.touched && form.controls.username.invalid
            ) {
              <p class="field-error">Введите имя пользователя.</p>
            }
          </div>
          <div class="mb-4">
            <label class="form-label" for="password">Пароль</label>
            <div class="password-field">
              <input
                class="form-control"
                id="password"
                [type]="passwordVisible() ? 'text' : 'password'"
                formControlName="password"
                autocomplete="current-password"
                placeholder="Введите пароль"
                [attr.aria-invalid]="
                  form.controls.password.touched && form.controls.password.invalid
                "
              />
              <button
                class="password-toggle"
                type="button"
                aria-controls="password"
                [attr.aria-pressed]="passwordVisible()"
                (click)="passwordVisible.set(!passwordVisible())"
              >
                {{ passwordVisible() ? "Скрыть пароль" : "Показать пароль" }}
              </button>
            </div>
            @if (
              form.controls.password.touched && form.controls.password.invalid
            ) {
              <p class="field-error">Введите пароль.</p>
            }
          </div>
          @if (error()) {
            <div class="alert alert-danger" role="alert">{{ error() }}</div>
          }
          <button
            type="submit"
            class="btn btn-primary w-100"
            [disabled]="loading() || !sessionReady()"
          >
            {{
              loading() ? "Входим…" : !sessionReady() ? "Подключение…" : "Войти"
            }}
          </button>
          @if (!sessionReady() && error()) {
            <button
              class="btn btn-quiet w-100 mt-2"
              type="button"
              (click)="loadSession()"
            >
              Повторить подключение
            </button>
          }
        </form>
        <p class="login-help">
          Данные для входа задаются администратором приложения.
        </p>
      </section>
    </main>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LoginComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
  readonly form = inject(FormBuilder).nonNullable.group({
    username: ["", Validators.required],
    password: ["", Validators.required],
  });
  readonly loading = signal(false);
  readonly passwordVisible = signal(false);
  readonly sessionReady = signal(false);
  readonly error = signal("");
  constructor() {
    this.loadSession();
  }
  loadSession(): void {
    this.error.set("");
    this.auth
      .ensureSession()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (session) => {
          this.sessionReady.set(true);
          if (session.authenticated)
            void this.router.navigateByUrl(this.returnUrl());
        },
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }
  login(): void {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.loading() || !this.sessionReady()) return;
    const { username, password } = this.form.getRawValue();
    this.loading.set(true);
    this.error.set("");
    this.auth
      .login(username.trim(), password)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: () => {
          void this.router.navigateByUrl(this.returnUrl());
        },
        error: (error: unknown) => this.error.set(errorMessage(error)),
      });
  }
  private returnUrl(): string {
    const requested = this.route.snapshot.queryParamMap.get("returnUrl");
    return requested &&
      requested.startsWith("/") &&
      !requested.startsWith("//") &&
      !requested.startsWith("/login")
      ? requested
      : "/products";
  }
}
