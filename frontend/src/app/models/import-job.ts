export type ImportStatus = "queued" | "processing" | "completed" | "failed";
export interface ImportRowError {
  sheet: string;
  row: number;
  message: string;
}
export interface ImportJob {
  id: string;
  status: ImportStatus;
  total: number;
  processed: number;
  created: number;
  updated: number;
  skipped: number;
  errors: ImportRowError[];
  message?: string;
}
export interface QueuedImport {
  id: string;
  status: "queued";
}
