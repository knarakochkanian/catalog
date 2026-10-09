import { HttpClient } from "@angular/common/http";
import { Injectable, inject } from "@angular/core";
import { Observable } from "rxjs";
import { ImportJob, QueuedImport } from "../models/import-job";
import { pollImport } from "../core/poll-import";

@Injectable({ providedIn: "root" })
export class ImportService {
  private readonly http = inject(HttpClient);
  upload(file: File): Observable<QueuedImport> {
    const body = new FormData();
    body.append("file", file);
    return this.http.post<QueuedImport>("/api/imports", body);
  }
  getStatus(id: string): Observable<ImportJob> {
    return this.http.get<ImportJob>(`/api/imports/${encodeURIComponent(id)}`);
  }
  watchStatus(id: string): Observable<ImportJob> {
    return pollImport(() => this.getStatus(id));
  }
}
