import {
  Observable,
  SchedulerLike,
  distinctUntilChanged,
  exhaustMap,
  takeWhile,
  timer,
} from "rxjs";
import { ImportJob } from "../models/import-job";

export function pollImport(
  request: () => Observable<ImportJob>,
  intervalMs = 1500,
  scheduler?: SchedulerLike,
): Observable<ImportJob> {
  return timer(0, intervalMs, scheduler).pipe(
    exhaustMap(() => request()),
    distinctUntilChanged(
      (a, b) =>
        a.id === b.id &&
        a.status === b.status &&
        a.total === b.total &&
        a.processed === b.processed &&
        a.created === b.created &&
        a.updated === b.updated &&
        a.skipped === b.skipped &&
        a.errors.length === b.errors.length &&
        a.message === b.message,
    ),
    takeWhile(
      (job) => job.status !== "completed" && job.status !== "failed",
      true,
    ),
  );
}
