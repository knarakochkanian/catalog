import { describe, expect, it } from "vitest";
import { of, throwError } from "rxjs";
import { TestScheduler } from "rxjs/testing";
import { pollImport } from "./poll-import";

describe("pollImport", () => {
  it("does not rerender an unchanged status but still emits the final report", () => {
    const scheduler = new TestScheduler((actual, expected) =>
      expect(actual).toEqual(expected),
    );
    scheduler.run(({ expectObservable }) => {
      let calls = 0;
      const waiting = {
        id: "job",
        status: "queued" as const,
        total: 0,
        processed: 0,
        created: 0,
        updated: 0,
        skipped: 0,
        errors: [],
      };
      const completed = {
        ...waiting,
        status: "completed" as const,
        total: 1,
        processed: 1,
        created: 1,
      };
      expectObservable(
        pollImport(
          () => of(++calls < 3 ? { ...waiting } : completed),
          10,
          scheduler,
        ),
      ).toBe("a 19ms (b|)", { a: waiting, b: completed });
      scheduler.flush();
      expect(calls).toBe(3);
    });
  });
  it("includes the final report and stops requesting a completed job", () => {
    const scheduler = new TestScheduler((actual, expected) =>
      expect(actual).toEqual(expected),
    );
    scheduler.run(({ expectObservable }) => {
      let calls = 0;
      const statuses = ["queued", "processing", "completed"] as const;
      const request = () =>
        of({
          id: "job",
          status: statuses[calls++] ?? "completed",
          total: 2,
          processed: calls - 1,
          created: 1,
          updated: 1,
          skipped: 0,
          errors: [],
        });
      expectObservable(pollImport(request, 10, scheduler)).toBe(
        "a 9ms b 9ms (c|)",
        {
          a: {
            id: "job",
            status: "queued",
            total: 2,
            processed: 0,
            created: 1,
            updated: 1,
            skipped: 0,
            errors: [],
          },
          b: {
            id: "job",
            status: "processing",
            total: 2,
            processed: 1,
            created: 1,
            updated: 1,
            skipped: 0,
            errors: [],
          },
          c: {
            id: "job",
            status: "completed",
            total: 2,
            processed: 2,
            created: 1,
            updated: 1,
            skipped: 0,
            errors: [],
          },
        },
      );
      scheduler.flush();
      expect(calls).toBe(3);
    });
  });
  it("lets callers unsubscribe when leaving the import page", () => {
    const scheduler = new TestScheduler((actual, expected) =>
      expect(actual).toEqual(expected),
    );
    scheduler.run(({ expectObservable }) => {
      let calls = 0;
      const job = {
        id: "job",
        status: "processing" as const,
        total: 2,
        processed: 0,
        created: 0,
        updated: 0,
        skipped: 0,
        errors: [],
      };
      expectObservable(
        pollImport(
          () => {
            calls++;
            return of(job);
          },
          10,
          scheduler,
        ),
        "^ 14ms !",
      ).toBe("a", { a: job });
      scheduler.flush();
      expect(calls).toBe(2);
    });
  });
  it("includes failed reports and stops polling", () => {
    const scheduler = new TestScheduler((actual, expected) =>
      expect(actual).toEqual(expected),
    );
    scheduler.run(({ expectObservable }) => {
      const job = {
        id: "job",
        status: "failed" as const,
        total: 0,
        processed: 0,
        created: 0,
        updated: 0,
        skipped: 0,
        errors: [],
      };
      expectObservable(pollImport(() => of(job), 10, scheduler)).toBe("(a|)", {
        a: job,
      });
    });
  });
  it("surfaces a polling HTTP failure so the page can offer retry", () => {
    const scheduler = new TestScheduler((actual, expected) =>
      expect(actual).toEqual(expected),
    );
    scheduler.run(({ expectObservable }) => {
      const failure = new Error("connection lost");
      expectObservable(
        pollImport(() => throwError(() => failure), 10, scheduler),
      ).toBe("#", undefined, failure);
    });
  });
});
