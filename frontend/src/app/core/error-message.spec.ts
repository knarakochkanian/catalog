import { describe, expect, it } from "vitest";
import { errorMessage } from "./error-message";

describe("errorMessage", () => {
  it("uses the server error envelope so row/upload failures are actionable", () => {
    expect(
      errorMessage({
        status: 422,
        error: {
          error: {
            code: "invalid_file",
            message: "В файле нет колонки «Внешний код».",
          },
        },
      }),
    ).toBe("В файле нет колонки «Внешний код».");
  });
  it("explains network errors without exposing an internal JavaScript exception", () => {
    expect(errorMessage({ status: 0 })).toBe(
      "Сервер недоступен. Проверьте соединение и повторите попытку.",
    );
  });
  it("uses a safe generic message for malformed response bodies", () => {
    expect(
      errorMessage({ status: 500, error: "<html>private stack trace</html>" }),
    ).toBe("Не удалось выполнить запрос. Повторите попытку.");
  });
});
