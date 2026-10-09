import { DecimalPipe } from "@angular/common";
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from "@angular/core";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { RouterLink } from "@angular/router";
import { Subscription, finalize } from "rxjs";
import { ImportJob, ImportStatus } from "../../models/import-job";
import { ImportService } from "../../services/import.service";
import { errorMessage } from "../../core/error-message";

@Component({
  selector: "app-import",
  imports: [RouterLink, DecimalPipe],
  templateUrl: "./import.component.html",
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportComponent {
  private readonly service = inject(ImportService);
  private readonly destroyRef = inject(DestroyRef);
  private pollingSubscription: Subscription | undefined;
  readonly file = signal<File | null>(null);
  readonly fileError = signal("");
  readonly requestError = signal("");
  readonly uploading = signal(false);
  readonly polling = signal(false);
  readonly job = signal<ImportJob | null>(null);
  readonly busy = computed(() => this.uploading() || this.polling());
  readonly progress = computed(() => {
    const job = this.job();
    return job?.total
      ? Math.min(100, Math.round((job.processed / job.total) * 100))
      : 0;
  });
  readonly labels: Record<ImportStatus, string> = {
    queued: "В очереди",
    processing: "Обрабатывается",
    completed: "Завершён",
    failed: "Ошибка задания",
  };

  selectFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.file.set(null);
    this.fileError.set("");
    this.requestError.set("");
    if (!file) return;
    if (!file.name.toLowerCase().endsWith(".xlsx")) {
      this.fileError.set("Выберите файл Excel в формате .xlsx.");
      input.value = "";
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      this.fileError.set("Файл слишком большой. Максимальный размер — 10 МБ.");
      input.value = "";
      return;
    }
    if (file.size === 0) {
      this.fileError.set("Выбранный файл пуст.");
      input.value = "";
      return;
    }
    this.file.set(file);
  }
  startImport(): void {
    const file = this.file();
    if (!file || this.busy()) return;
    this.uploading.set(true);
    this.requestError.set("");
    this.job.set(null);
    this.service
      .upload(file)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.uploading.set(false)),
      )
      .subscribe({
        next: (queued) => {
          this.job.set({
            ...queued,
            total: 0,
            processed: 0,
            created: 0,
            updated: 0,
            skipped: 0,
            errors: [],
          });
          this.watchStatus(queued.id);
        },
        error: (error: unknown) => this.requestError.set(errorMessage(error)),
      });
  }
  retryStatus(): void {
    const id = this.job()?.id;
    if (id && !this.polling()) this.watchStatus(id);
  }
  private watchStatus(id: string): void {
    this.pollingSubscription?.unsubscribe();
    this.polling.set(true);
    this.requestError.set("");
    this.pollingSubscription = this.service
      .watchStatus(id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.polling.set(false)),
      )
      .subscribe({
        next: (job) => this.job.set(job),
        error: (error: unknown) => this.requestError.set(errorMessage(error)),
      });
  }
}
